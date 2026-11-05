<?php

namespace App\Controller;

use App\Repository\AutomationRepository;
use App\Service\Automation\AutomationEngine;
use App\Service\Automation\AutomationQuotaService;
use App\Service\WebhookSecretService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Point d'entrée public pour les webhooks entrants.
 *
 * Chaque automatisation de type trigger.webhook.inbound reçoit
 * une URL unique : POST /api/webhooks/inbound/{automationUuid}
 * Le secret configuré dans le nœud trigger est vérifié (optionnel).
 */
#[Route('/api/webhooks/inbound', name: 'api_webhook_inbound_')]
class WebhookInboundController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 2097152; // 2MB

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Referrer-Policy'        => 'no-referrer',
        'Cache-Control'          => 'no-store',
        'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none';",
    ];

    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationEngine $engine,
        private readonly LoggerInterface $logger,
        private readonly ?RateLimiterFactory $webhookInboundLimiter = null,
        private readonly ?WebhookSecretService $secretService = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?AutomationQuotaService $quotaService = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    /**
     * Reçoit un webhook entrant et déclenche l'automatisation correspondante.
     *
     * L'URL est : POST /api/webhooks/inbound/{automationUuid}
     * Le corps de la requête est passé comme payload au flow.
     */
    #[Route('/{automationUuid}', name: 'receive', methods: ['POST'])]
    public function receive(string $automationUuid, Request $request): JsonResponse
    {
        // Rate limiting : 120 requêtes par minute par IP
        if ($this->webhookInboundLimiter !== null) {
            $limiter = $this->webhookInboundLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('automation.admin.webhook_rate_limit', [], 'Too many webhook requests. Try again later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!preg_match(self::UUID_REGEX, $automationUuid)) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.webhook_endpoint_not_found', [], 'Webhook endpoint not found'),
                'code' => 'ENDPOINT_NOT_FOUND',
            ], 404);
        }

        // Requête ciblée par UUID — pas de findAll()
        try {
            $automation = $this->automationRepo->findOneByUuid($automationUuid);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.webhook_endpoint_not_found', [], 'Webhook endpoint not found'),
                'code' => 'ENDPOINT_NOT_FOUND',
            ], 404);
        }

        if (!$automation) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.webhook_endpoint_not_found', [], 'Webhook endpoint not found'),
                'code' => 'ENDPOINT_NOT_FOUND',
            ], 404);
        }

        if (!$automation->isActive) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.webhook_automation_inactive', [], 'Automation is not active'),
                'code' => 'AUTOMATION_INACTIVE',
            ], 410);
        }

        $graph = $automation->flowGraph;
        if (!$graph || empty($graph['nodes'])) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.no_flow_graph', [], 'Automation has no flow graph'),
                'code' => 'NO_FLOW_GRAPH',
            ], 400);
        }

        // Cherche le trigger dans tous les nœuds (pas seulement nodes[0])
        $triggerInfo = AutomationRepository::findTriggerNode($graph);
        $triggerNode = $triggerInfo['node'];
        $triggerType = $triggerInfo['type'];

        if (!$triggerNode || $triggerType !== 'trigger.webhook.inbound') {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.not_webhook_trigger', [], 'Not a webhook inbound automation'),
                'code' => 'NOT_WEBHOOK_TRIGGER',
            ], 400);
        }

        $triggerConfig = $triggerNode['data']['config'] ?? [];

        // Vérification de la liste blanche d'adresses IP (IP allowlist)
        $allowlist = $triggerConfig['ip_allowlist'] ?? $triggerConfig['allowed_ips'] ?? null;
        if ($allowlist !== null && $allowlist !== '' && $allowlist !== []) {
            $clientIp = $request->getClientIp() ?: '127.0.0.1';
            if (!$this->isIpAllowed($clientIp, $allowlist)) {
                $this->logger->warning('Webhook inbound rejected by IP allowlist', [
                    'automation_uuid' => $automationUuid,
                    'client_ip' => $clientIp,
                ]);
                return $this->secureJson([
                    'error' => $this->trans('automation.admin.webhook_ip_forbidden', [], 'Client IP is not permitted for this webhook.'),
                    'code' => 'IP_FORBIDDEN',
                ], 403);
            }
        }

        // Pré-contrôle Content-Length
        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && is_numeric($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.payload_too_large', [], 'Payload too large (max 2MB)'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        // Parse et contrôle strict de la taille du corps (max 2 Mo)
        $body = (string) $request->getContent();
        if (strlen($body) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.payload_too_large', [], 'Payload too large (max 2MB)'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        // Vérifie le secret ou signature HMAC si configuré
        $secret = $triggerConfig['secret'] ?? null;
        if ($secret !== null && $secret !== '') {
            $resolvedSecret = (string) $secret;
            if (str_starts_with($resolvedSecret, 'enc:') && $this->secretService !== null) {
                try {
                    $resolvedSecret = $this->secretService->decrypt(substr($resolvedSecret, 4));
                } catch (\Throwable) {
                    return $this->secureJson([
                        'error' => $this->trans('automation.admin.webhook_secret_decrypt_error', [], 'Webhook secret decryption failure'),
                        'code' => 'SECRET_DECRYPT_ERROR',
                    ], 500);
                }
            }

            if (!$this->verifyWebhookSecretOrSignature($request, $body, $resolvedSecret)) {
                return $this->secureJson([
                    'error' => $this->trans('automation.admin.webhook_invalid_signature', [], 'Invalid webhook secret or signature'),
                    'code' => 'SIGNATURE_INVALID',
                ], 403);
            }
        }

        // Détection et blocage des attaques par rejeu (Replay prevention)
        if ($this->cache !== null && $this->isReplayedRequest($automationUuid, $request, $body)) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.webhook_replay_detected', [], 'Webhook replay detected'),
                'code' => 'REPLAY_DETECTED',
            ], 409);
        }

        $decoded = null;
        if ($body !== '') {
            $decoded = json_decode($body, true, 32);
        }
        $payload = is_array($decoded) ? $decoded : ['raw_body' => $body];
        $payload['trigger'] = 'webhook.inbound';
        $payload['project_uuid'] = $automation->project?->uuid?->toRfc4122();
        $payload['timestamp'] = time();
        // Sécurité : ne garder que les headers safe (pas Authorization, Cookie, etc.)
        $payload['headers'] = $this->filterSafeHeaders($request);
        $payload['method'] = $request->getMethod();
        $payload['query_params'] = $request->query->all();

        // Contrôle des quotas d'exécution multi-tenant par tier
        if ($automation->project !== null && $this->quotaService !== null) {
            $quota = $this->quotaService->checkQuota($automation->project);
            if (!$quota['allowed']) {
                $isBurst = (bool) ($quota['isBurstLimited'] ?? false);
                $this->logger->warning('Webhook inbound rejected: automation quota exceeded for project', [
                    'automation_uuid' => $automationUuid,
                    'project_id' => $automation->project->id,
                    'tier' => $quota['tier'],
                    'used' => $quota['used'],
                    'limit' => $quota['limit'],
                    'minute_used' => $quota['minuteUsed'] ?? null,
                    'minute_limit' => $quota['minuteLimit'] ?? null,
                ]);

                $errorMsg = $isBurst
                    ? $this->trans('automation.quota.minute_limit_reached', [
                        '%used%' => (string) ($quota['minuteUsed'] ?? 0),
                        '%limit%' => (string) ($quota['minuteLimit'] ?? 0),
                    ], sprintf('Automation execution rate limit exceeded (burst limit reached: %d/%d runs per minute).', $quota['minuteUsed'] ?? 0, $quota['minuteLimit'] ?? 0))
                    : $this->trans('automation.quota.exceeded', [
                        '%used%' => (string) $quota['used'],
                        '%limit%' => (string) $quota['limit'],
                        '%tier%' => (string) $quota['tier'],
                    ], sprintf('Automation run quota exceeded for this project tier (%d/%d runs used today).', $quota['used'], $quota['limit']));

                return $this->secureJson([
                    'success' => false,
                    'error' => $errorMsg,
                    'code' => 'AUTOMATION_QUOTA_EXCEEDED',
                    'tier' => $quota['tier'],
                    'limit' => $quota['limit'],
                    'used' => $quota['used'],
                    'minute_limit' => $quota['minuteLimit'] ?? null,
                    'minute_used' => $quota['minuteUsed'] ?? null,
                    'reset_in_seconds' => $quota['resetInSeconds'],
                ], 429, [
                    'Retry-After' => (string) $quota['resetInSeconds'],
                ]);
            }
        }

        // Exécute
        try {
            $run = $this->engine->execute($automation, $payload);

            return $this->secureJson([
                'success' => $run->status !== 'failed',
                'run_id' => $run->id,
                'status' => $run->status,
            ], $run->status === 'failed' ? 500 : 200);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook inbound execution failed', [
                'automation_uuid' => $automationUuid,
                'error' => $e->getMessage(),
            ]);
            return $this->secureJson([
                'success' => false,
                'error' => $this->trans('automation.admin.webhook_internal_error', [], 'Internal error processing webhook'),
            ], 500);
        }
    }

    /**
     * Valide si l'IP cliente correspond aux règles d'allowlist (IP uniques ou CIDR).
     */
    public function isIpAllowed(string $clientIp, array|string $allowlist): bool
    {
        $entries = is_array($allowlist) ? $allowlist : preg_split('/[\s,]+/', trim($allowlist));
        $entries = array_filter(array_map('trim', (array) $entries), fn ($ip) => $ip !== '');

        if (empty($entries)) {
            return true;
        }

        // Clean client IP (e.g. handle IPv4-mapped IPv6 like ::ffff:192.0.2.1)
        $cleanIp = $clientIp;
        if (str_starts_with($cleanIp, '::ffff:')) {
            $mapped = substr($cleanIp, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $cleanIp = $mapped;
            }
        }

        $isClientIpv4 = filter_var($cleanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isClientIpv6 = filter_var($cleanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (!$isClientIpv4 && !$isClientIpv6) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($cleanIp === $entry) {
                return true;
            }

            if (str_contains($entry, '/')) {
                [$subnet, $mask] = explode('/', $entry, 2);
                $mask = is_numeric($mask) ? (int) $mask : -1;

                if ($isClientIpv4 && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    if ($mask >= 0 && $mask <= 32) {
                        $ipLong = ip2long($cleanIp);
                        $subnetLong = ip2long($subnet);
                        if ($ipLong !== false && $subnetLong !== false) {
                            $networkMask = $mask === 0 ? 0 : (-1 << (32 - $mask));
                            if (($ipLong & $networkMask) === ($subnetLong & $networkMask)) {
                                return true;
                            }
                        }
                    }
                } elseif ($isClientIpv6 && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    if ($mask >= 0 && $mask <= 128) {
                        $ipBin = inet_pton($cleanIp);
                        $subnetBin = inet_pton($subnet);
                        if ($ipBin !== false && $subnetBin !== false) {
                            $bytes = intdiv($mask, 8);
                            $remainder = $mask % 8;
                            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                                continue;
                            }
                            if ($remainder === 0) {
                                return true;
                            }
                            $ipByte = ord($ipBin[$bytes]);
                            $subnetByte = ord($subnetBin[$bytes]);
                            $bitMask = (0xFF << (8 - $remainder)) & 0xFF;
                            if (($ipByte & $bitMask) === ($subnetByte & $bitMask)) {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Détecte les attaques par rejeu en mémorisant les identifiants ou signatures d'événements.
     */
    private function isReplayedRequest(string $automationUuid, Request $request, string $body): bool
    {
        if ($this->cache === null) {
            return false;
        }

        $nonce = null;
        $deliveryId = $request->headers->get('X-GitHub-Delivery')
            ?? $request->headers->get('X-Webhook-ID')
            ?? $request->headers->get('Webhook-Id')
            ?? $request->headers->get('X-Delivery-Id')
            ?? $request->headers->get('X-Webhook-Nonce')
            ?? $request->headers->get('X-Nonce');

        if ($deliveryId !== null && trim($deliveryId) !== '') {
            $nonce = 'deliv:' . trim($deliveryId);
        } else {
            $sig = $request->headers->get('Stripe-Signature')
                ?? $request->headers->get('X-Slack-Signature')
                ?? $request->headers->get('webhook-signature')
                ?? $request->headers->get('X-Hub-Signature-256')
                ?? $request->headers->get('X-Signature-SHA256')
                ?? $request->headers->get('X-Webhook-Signature');
            $ts = $request->headers->get('X-Slack-Request-Timestamp')
                ?? $request->headers->get('webhook-timestamp')
                ?? $request->headers->get('X-Webhook-Timestamp')
                ?? $request->headers->get('X-Timestamp')
                ?? $request->headers->get('X-JamboApi-Timestamp');
            if ($sig !== null && $ts !== null) {
                $nonce = 'sigts:' . hash('sha256', $sig . ':' . $ts);
            }
        }

        if ($nonce === null) {
            return false;
        }

        $cacheKey = 'wh_seen_' . hash('sha256', $automationUuid . ':' . $nonce);
        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return true;
            }
            $item->set(time());
            $item->expiresAfter(300);
            $this->cache->save($item);
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Valide le secret via token direct, signature HMAC standard (GitHub, Stripe, Svix, Slack)
     * et prévient les attaques par rejeu de timestamp.
     */
    private function verifyWebhookSecretOrSignature(Request $request, string $body, string $secret): bool
    {
        // 1. Header direct standard
        $providedSecret = $request->headers->get('X-Webhook-Secret');
        if ($providedSecret !== null && hash_equals($secret, $providedSecret)) {
            return $this->verifyTimestampHeader($request);
        }

        // 2. Standard GitHub : X-Hub-Signature-256 (sha256=<hex>)
        $hubSig = $request->headers->get('X-Hub-Signature-256');
        if ($hubSig !== null) {
            if (str_starts_with($hubSig, 'sha256=')) {
                $hubSig = substr($hubSig, 7);
            }
            $expected = hash_hmac('sha256', $body, $secret);
            if (hash_equals($expected, $hubSig)) {
                return $this->verifyTimestampHeader($request);
            }
        }

        // 3. Standard générique : X-Signature-SHA256 ou X-Webhook-Signature
        $genSig = $request->headers->get('X-Signature-SHA256') ?? $request->headers->get('X-Webhook-Signature');
        if ($genSig !== null) {
            if (str_starts_with($genSig, 'sha256=')) {
                $genSig = substr($genSig, 7);
            }
            $expected = hash_hmac('sha256', $body, $secret);
            if (hash_equals($expected, $genSig)) {
                return $this->verifyTimestampHeader($request);
            }
        }

        // 4. Standard Stripe : Stripe-Signature (t=<ts>,v1=<hex>)
        $stripeSig = $request->headers->get('Stripe-Signature');
        if ($stripeSig !== null) {
            $parts = explode(',', $stripeSig);
            $ts = null;
            $sigs = [];
            foreach ($parts as $part) {
                $kv = explode('=', trim($part), 2);
                if (count($kv) === 2) {
                    if ($kv[0] === 't') {
                        $ts = $kv[1];
                    } elseif ($kv[0] === 'v1') {
                        $sigs[] = $kv[1];
                    }
                }
            }
            if ($ts !== null && !empty($sigs) && is_numeric($ts)) {
                $tInt = (int) $ts;
                $now = time();
                if (($now - $tInt) > 300 || ($tInt - $now) > 60) {
                    return false;
                }
                $expected = hash_hmac('sha256', "{$ts}.{$body}", $secret);
                foreach ($sigs as $s) {
                    if (hash_equals($expected, $s)) {
                        return true;
                    }
                }
            }
        }

        // 5. Standard GitLab : X-Gitlab-Token
        $gitlabToken = $request->headers->get('X-Gitlab-Token');
        if ($gitlabToken !== null && hash_equals($secret, $gitlabToken)) {
            return $this->verifyTimestampHeader($request);
        }

        // 6. Standard Slack : X-Slack-Signature (v0=<hex>) & X-Slack-Request-Timestamp
        $slackSig = $request->headers->get('X-Slack-Signature');
        $slackTs = $request->headers->get('X-Slack-Request-Timestamp');
        if ($slackSig !== null && $slackTs !== null && is_numeric($slackTs)) {
            $tInt = (int) $slackTs;
            $now = time();
            if (($now - $tInt) <= 300 && ($tInt - $now) <= 60) {
                if (str_starts_with($slackSig, 'v0=')) {
                    $slackSig = substr($slackSig, 3);
                }
                $expected = hash_hmac('sha256', "v0:{$slackTs}:{$body}", $secret);
                if (hash_equals($expected, $slackSig)) {
                    return true;
                }
            }
        }

        // 7. Standard Svix / Standard Webhooks : webhook-signature, webhook-id, webhook-timestamp
        $svixSig = $request->headers->get('webhook-signature');
        $svixId = $request->headers->get('webhook-id') ?? $request->headers->get('msg_id');
        $svixTs = $request->headers->get('webhook-timestamp');
        if ($svixSig !== null && $svixId !== null && $svixTs !== null && is_numeric($svixTs)) {
            $ts = (int) $svixTs;
            $now = time();
            if (($now - $ts) <= 300 && ($ts - $now) <= 60) {
                $keyBytes = $secret;
                if (str_starts_with($secret, 'whsec_')) {
                    $decodedKey = base64_decode(substr($secret, 6), true);
                    if ($decodedKey !== false && $decodedKey !== '') {
                        $keyBytes = $decodedKey;
                    }
                }
                $toSign = "{$svixId}.{$svixTs}.{$body}";
                $rawHash = hash_hmac('sha256', $toSign, $keyBytes, true);
                $expectedB64 = base64_encode($rawHash);
                $expectedHex = bin2hex($rawHash);
                $signatures = preg_split('/\s+/', trim($svixSig));
                foreach ($signatures as $sigItem) {
                    $val = str_starts_with($sigItem, 'v1,') ? substr($sigItem, 3) : (str_starts_with($sigItem, 'v1=') ? substr($sigItem, 3) : $sigItem);
                    if (hash_equals($expectedB64, $val) || hash_equals($expectedHex, $val)) {
                        return true;
                    }
                }
            }
        }

        // 8. Standard Authorization: Bearer <secret>
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $bearer = substr($authHeader, 7);
            if (hash_equals($secret, $bearer)) {
                return $this->verifyTimestampHeader($request);
            }
        }

        return false;
    }

    private function verifyTimestampHeader(Request $request): bool
    {
        $tsHeader = $request->headers->get('X-Webhook-Timestamp')
            ?? $request->headers->get('X-Timestamp')
            ?? $request->headers->get('X-JamboApi-Timestamp')
            ?? $request->headers->get('Webhook-Timestamp');
        if ($tsHeader !== null && is_numeric($tsHeader)) {
            $ts = (int) $tsHeader;
            $now = time();
            if (($now - $ts) > 300 || ($ts - $now) > 60) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ne conserve que les headers sûrs pour éviter la fuite de secrets
     * (Authorization, Cookie, X-Webhook-Secret, X-Api-Key, etc.)
     * dans les logs et les payloads de flow.
     */
    private function filterSafeHeaders(Request $request): array
    {
        $safe = [];
        $excluded = [
            'x-webhook-secret', 'authorization', 'cookie', 'set-cookie',
            'x-api-key', 'x-csrf-token', 'proxy-authorization',
        ];
        $allowed = [
            'content-type', 'user-agent', 'accept', 'accept-language',
            'x-forwarded-for', 'x-real-ip', 'x-request-id',
            'x-github-event', 'x-gitlab-event', 'x-event-key',
            'x-delivery-id', 'x-github-delivery', 'webhook-id', 'webhook-timestamp',
            'x-slack-request-timestamp', 'x-webhook-timestamp', 'x-jamboapi-event',
        ];

        foreach ($request->headers->all() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, $excluded, true)) {
                continue;
            }
            if (in_array($lower, $allowed, true)) {
                $safe[$name] = $values;
            }
        }
        return $safe;
    }
}
