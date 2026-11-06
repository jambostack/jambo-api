<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Webhook;
use App\Entity\WebhookLog;
use App\Repository\WebhookRepository;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service de gestion et de déclenchement sécurisé des webhooks sortants.
 *
 * Protections assurées :
 * - Filtrage anti-SSRF rigoureux des URLs cibles (anti-loopback, RFC 1918, metadata cloud, DNS rebinding).
 * - Restriction des protocoles autorisés (uniquement HTTP et HTTPS).
 * - Restriction des ports de destination (80, 443, 8000, 8080, 8443).
 * - Absence de suivi des redirections (max_redirects = 0) pour contrer le rebond SSRF.
 * - DNS Pinning systématique via l'IP résolue et validée.
 * - Timeouts stricts (inactivité 5s, durée maximale 10s).
 * - Signature HMAC SHA256 si un secret est configuré (X-JamboApi-Signature, X-JamboApi-Signature-256, X-Hub-Signature-256).
 * - Bornage mémoire de la réponse lue (max 2 Ko) pour prévenir les bombes OOM.
 * - Rate limiting sortant par projet/tenant pour éviter les tempêtes de requêtes (DDoS outbound).
 * - Internationalisation (I18N) des messages et erreurs.
 */
class WebhookService
{
    public const MAX_URL_LENGTH = 2048;
    public const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    public const MAX_RESPONSE_BYTES = 2048;
    public const IDLE_TIMEOUT = 5.0;
    public const MAX_DURATION = 10.0;
    public const MAX_RETRIES = 2;

    private SsrfProtectionService $ssrfProtection;

    public function __construct(
        private WebhookRepository $webhookRepository,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private WebhookSecretService $secretService,
        ?SsrfProtectionService $ssrfProtection = null,
        private ?TranslatorInterface $translator = null,
        private ?RateLimiterFactory $webhookOutboundLimiter = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

    /**
     * Valide l'URL d'un webhook contre les attaques SSRF et formats invalides.
     *
     * @return string|null Message d'erreur en cas de rejet, null si l'URL est sûre
     */
    public function validateUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return $this->trans('webhook.url_required', [], 'Webhook URL is required.');
        }

        if (strlen($trimmed) > self::MAX_URL_LENGTH) {
            return $this->trans('webhook.url_too_long', ['%max%' => (string) self::MAX_URL_LENGTH], sprintf('Webhook URL cannot exceed %d characters.', self::MAX_URL_LENGTH));
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if (!filter_var($trimmed, FILTER_VALIDATE_URL) || !in_array($scheme, ['https', 'http'], true)) {
            return $this->trans('webhook.invalid_url_format', [], 'Webhook URL must be a valid http or https URL.');
        }

        if (!$this->ssrfProtection->isSafeUrl($trimmed, false, $reason)) {
            return $reason ?? $this->trans('webhook.internal_ip_forbidden', [], 'Internal and private IP addresses are not allowed for webhooks.');
        }

        return null;
    }

    /**
     * Envoie de manière sécurisée un webhook sortant et enregistre le journal d'exécution.
     */
    public function send(Webhook $webhook, string $event, array $payload): ?WebhookLog
    {
        if (!$webhook->isActive) {
            return null;
        }

        try {
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            $log = new WebhookLog();
            $log->webhook        = $webhook;
            $log->event          = $event;
            $log->requestPayload = '{}';
            $log->status         = 'failed';
            $log->errorMessage   = $this->trans('webhook.json_encode_error', ['%error%' => substr($e->getMessage(), 0, 200)], 'JSON encoding error: ' . substr($e->getMessage(), 0, 200));
            $this->em->persist($log);
            $this->em->flush();
            return $log;
        }

        if (strlen($payloadJson) > self::MAX_PAYLOAD_BYTES) {
            $log = new WebhookLog();
            $log->webhook        = $webhook;
            $log->event          = $event;
            $log->requestPayload = substr($payloadJson, 0, 1024) . '... [TRUNCATED]';
            $log->status         = 'failed';
            $log->errorMessage   = $this->trans('webhook.payload_size_exceeded', ['%max%' => (string) self::MAX_PAYLOAD_BYTES], sprintf('Outbound webhook payload size exceeds %d bytes limit.', self::MAX_PAYLOAD_BYTES));
            $this->em->persist($log);
            $this->em->flush();
            return $log;
        }

        // Contrôle de rate limiting sortant par projet / tenant
        if ($this->webhookOutboundLimiter !== null && $webhook->project?->uuid !== null) {
            $limiterKey = 'wh_out_' . $webhook->project->uuid->toRfc4122();
            $limit = $this->webhookOutboundLimiter->create($limiterKey)->consume(1);
            if (!$limit->isAccepted()) {
                $log = new WebhookLog();
                $log->webhook        = $webhook;
                $log->event          = $event;
                $log->requestPayload = substr($payloadJson, 0, 1024);
                $log->status         = 'failed';
                $log->errorMessage   = $this->trans('webhook.outbound_rate_limit_exceeded', [], 'Outbound webhook rate limit exceeded for this project.');
                $this->em->persist($log);
                $this->em->flush();
                return $log;
            }
        }

        $timestamp = time();
        $deliveryId = Uuid::v4()->toRfc4122();

        $headers = [
            'Content-Type'         => 'application/json',
            'User-Agent'           => 'JamboApi-Webhook/1.0',
            'X-JamboApi-Event'     => $event,
            'X-JamboApi-Delivery'  => $deliveryId,
            'X-JamboApi-Timestamp' => (string) $timestamp,
            'X-JamboApi-Project'   => $webhook->project?->uuid?->toRfc4122() ?? '',
            'Webhook-Id'           => $deliveryId,
            'Webhook-Timestamp'    => (string) $timestamp,
        ];

        if ($webhook->secret !== null) {
            try {
                $plainSecret = $this->secretService->decrypt($webhook->secret);
                $headers['X-JamboApi-Signature'] = 'sha256=' . hash_hmac('sha256', $payloadJson, $plainSecret);
                $headers['X-JamboApi-Signature-256'] = "t={$timestamp},v1=" . hash_hmac('sha256', "{$timestamp}.{$payloadJson}", $plainSecret);
                $headers['X-Hub-Signature-256'] = 'sha256=' . hash_hmac('sha256', $payloadJson, $plainSecret);
            } catch (\Throwable $e) {
                // Erreur de déchiffrement du secret
                $log = new WebhookLog();
                $log->webhook        = $webhook;
                $log->event          = $event;
                $log->requestPayload = $payloadJson;
                $log->status         = 'failed';
                $log->errorMessage   = $this->trans('webhook.secret_decrypt_error', ['%error%' => substr($e->getMessage(), 0, 200)], 'Failed to decrypt webhook secret: ' . substr($e->getMessage(), 0, 200));
                $this->em->persist($log);
                $this->em->flush();
                return $log;
            }
        }

        $log = new WebhookLog();
        $log->webhook        = $webhook;
        $log->event          = $event;
        $log->requestPayload = $payloadJson;

        // Contrôle SSRF dynamique au moment de l'appel (Time of Check to Time of Use)
        if (!$this->ssrfProtection->isSafeUrl($webhook->url, false, $reason)) {
            $log->status       = 'failed';
            $reasonText        = $reason ?? 'SSRF blocked';
            $log->errorMessage = $this->trans('webhook.ssrf_blocked', ['%reason%' => $reasonText], 'Blocked outbound request to private/internal IP: ' . $reasonText);
            $this->em->persist($log);
            $this->em->flush();
            return $log;
        }

        $attempt = 0;
        $lastException = null;

        while ($attempt <= self::MAX_RETRIES) {
            try {
                // Configuration sécurisée : DNS pinning anti-rebinding, 0 redirection, timeouts stricts
                $options = $this->ssrfProtection->buildSafeHttpClientOptions($webhook->url, [
                    'headers' => $headers,
                    'body'    => $payloadJson,
                ], idleTimeout: self::IDLE_TIMEOUT, maxDuration: self::MAX_DURATION);

                $response = $this->httpClient->request('POST', $webhook->url, $options);
                $statusCode = $response->getStatusCode();

                if (in_array($statusCode, [502, 503, 504], true) && $attempt < self::MAX_RETRIES) {
                    $attempt++;
                    usleep((int) (min(500, pow(2, $attempt) * 50) * 1000));
                    continue;
                }

                $log->statusCode   = $statusCode;
                $log->responseBody = $this->ssrfProtection->readSafeResponseBody($response, self::MAX_RESPONSE_BYTES);
                $log->status       = ($statusCode >= 200 && $statusCode < 300) ? 'succeeded' : 'failed';
                break;
            } catch (\InvalidArgumentException $e) {
                // Bloqué par SSRF (ne jamais retenter)
                $log->status       = 'failed';
                $log->errorMessage = substr($e->getMessage(), 0, 500);
                break;
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt <= self::MAX_RETRIES) {
                    usleep((int) (min(500, pow(2, $attempt) * 50) * 1000));
                    continue;
                }
                $log->status       = 'failed';
                $log->errorMessage = substr($lastException->getMessage(), 0, 500);
                break;
            }
        }

        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($key, $params);
            if ($translated !== $key) {
                return $translated;
            }
        }

        return strtr($default !== '' ? $default : $key, $params);
    }
}
