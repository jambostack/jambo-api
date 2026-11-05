<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Webhook;
use App\Entity\WebhookLog;
use App\Repository\WebhookRepository;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
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
 * - Signature HMAC SHA256 si un secret est configuré.
 * - Bornage mémoire de la réponse lue (max 2 Ko) pour prévenir les bombes OOM.
 * - Internationalisation (I18N) des messages et erreurs.
 */
class WebhookService
{
    public const MAX_URL_LENGTH = 2048;
    public const MAX_RESPONSE_BYTES = 2048;
    public const IDLE_TIMEOUT = 5.0;
    public const MAX_DURATION = 10.0;

    private SsrfProtectionService $ssrfProtection;

    public function __construct(
        private WebhookRepository $webhookRepository,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private WebhookSecretService $secretService,
        ?SsrfProtectionService $ssrfProtection = null,
        private ?TranslatorInterface $translator = null,
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
            $log->errorMessage   = 'JSON encoding error: ' . substr($e->getMessage(), 0, 255);
            $this->em->persist($log);
            $this->em->flush();
            return $log;
        }

        $headers = [
            'Content-Type'     => 'application/json',
            'X-JamboApi-Event' => $event,
        ];

        if ($webhook->secret !== null) {
            try {
                $plainSecret = $this->secretService->decrypt($webhook->secret);
                $headers['X-JamboApi-Signature'] = 'sha256=' . hash_hmac('sha256', $payloadJson, $plainSecret);
            } catch (\Throwable $e) {
                // Erreur de déchiffrement du secret
                $log = new WebhookLog();
                $log->webhook        = $webhook;
                $log->event          = $event;
                $log->requestPayload = $payloadJson;
                $log->status         = 'failed';
                $log->errorMessage   = 'Failed to decrypt webhook secret: ' . substr($e->getMessage(), 0, 255);
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
            $log->errorMessage = 'Blocked outbound request to private/internal IP: ' . ($reason ?? 'SSRF blocked');
            $this->em->persist($log);
            $this->em->flush();
            return $log;
        }

        try {
            // Configuration sécurisée : DNS pinning anti-rebinding, 0 redirection, timeouts stricts
            $options = $this->ssrfProtection->buildSafeHttpClientOptions($webhook->url, [
                'headers' => $headers,
                'body'    => $payloadJson,
            ], idleTimeout: self::IDLE_TIMEOUT, maxDuration: self::MAX_DURATION);

            $response = $this->httpClient->request('POST', $webhook->url, $options);

            $statusCode = $response->getStatusCode();
            $log->statusCode   = $statusCode;
            $log->responseBody = $this->ssrfProtection->readSafeResponseBody($response, self::MAX_RESPONSE_BYTES);
            $log->status       = ($statusCode >= 200 && $statusCode < 300) ? 'succeeded' : 'failed';
        } catch (\Throwable $e) {
            $log->status       = 'failed';
            $log->errorMessage = substr($e->getMessage(), 0, 500);
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
