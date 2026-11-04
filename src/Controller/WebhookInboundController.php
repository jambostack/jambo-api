<?php

namespace App\Controller;

use App\Repository\AutomationRepository;
use App\Service\Automation\AutomationEngine;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

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
    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationEngine $engine,
        private readonly LoggerInterface $logger,
        private readonly ?RateLimiterFactory $webhookInboundLimiter = null,
    ) {}

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
                return $this->json(['error' => 'Too many webhook requests. Try again later.'], 429);
            }
        }

        // Requête ciblée par UUID — pas de findAll()
        try {
            $automation = $this->automationRepo->findOneByUuid($automationUuid);
        } catch (\Throwable) {
            return $this->json(['error' => 'Webhook endpoint not found'], 404);
        }

        if (!$automation) {
            return $this->json(['error' => 'Webhook endpoint not found'], 404);
        }

        if (!$automation->isActive) {
            return $this->json(['error' => 'Automation is not active'], 410);
        }

        $graph = $automation->flowGraph;
        if (!$graph || empty($graph['nodes'])) {
            return $this->json(['error' => 'Automation has no flow graph'], 400);
        }

        // Cherche le trigger dans tous les nœuds (pas seulement nodes[0])
        $triggerInfo = AutomationRepository::findTriggerNode($graph);
        $triggerNode = $triggerInfo['node'];
        $triggerType = $triggerInfo['type'];

        if (!$triggerNode || $triggerType !== 'trigger.webhook.inbound') {
            return $this->json(['error' => 'Not a webhook inbound automation'], 400);
        }

        // Parse et contrôle strict de la taille du corps (max 2 Mo)
        $body = (string) $request->getContent();
        if (strlen($body) > 2 * 1024 * 1024) {
            return $this->json(['error' => 'Payload too large (max 2MB)'], 413);
        }

        // Vérifie le secret ou signature HMAC si configuré
        $secret = $triggerNode['data']['config']['secret'] ?? null;
        if ($secret !== null && $secret !== '') {
            if (!$this->verifyWebhookSecretOrSignature($request, $body, (string) $secret)) {
                return $this->json(['error' => 'Invalid webhook secret or signature'], 403);
            }
        }

        $decoded = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
        }
        $payload = is_array($decoded) ? $decoded : ['raw_body' => $body];
        $payload['trigger'] = 'webhook.inbound';
        $payload['project_uuid'] = $automation->project?->uuid?->toRfc4122();
        $payload['timestamp'] = time();
        // Sécurité : ne garder que les headers safe (pas Authorization, Cookie, etc.)
        $payload['headers'] = $this->filterSafeHeaders($request);
        $payload['method'] = $request->getMethod();
        $payload['query_params'] = $request->query->all();

        // Exécute
        try {
            $run = $this->engine->execute($automation, $payload);

            return $this->json([
                'success' => $run->status !== 'failed',
                'run_id' => $run->id,
                'status' => $run->status,
            ], $run->status === 'failed' ? 500 : 200);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook inbound execution failed', [
                'automation_uuid' => $automationUuid,
                'error' => $e->getMessage(),
            ]);
            return $this->json([
                'success' => false,
                'error' => 'Internal error processing webhook',
            ], 500);
        }
    }

    /**
     * Valide le secret via token direct, signature HMAC standard (GitHub, Stripe, Svix)
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
                if (abs(time() - (int) $ts) > 300) {
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

        return false;
    }

    private function verifyTimestampHeader(Request $request): bool
    {
        $tsHeader = $request->headers->get('X-Webhook-Timestamp') ?? $request->headers->get('X-Timestamp');
        if ($tsHeader !== null && is_numeric($tsHeader)) {
            if (abs(time() - (int) $tsHeader) > 300) {
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
        $excluded = ['x-webhook-secret', 'authorization', 'cookie', 'set-cookie',
                     'x-api-key', 'x-csrf-token', 'proxy-authorization'];
        $allowed = ['content-type', 'user-agent', 'accept', 'accept-language',
                    'x-forwarded-for', 'x-real-ip', 'x-request-id'];

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
