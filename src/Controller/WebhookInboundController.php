<?php

namespace App\Controller;

use App\Repository\AutomationRepository;
use App\Service\Automation\AutomationEngine;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        // Requête ciblée par UUID — pas de findAll()
        $automation = $this->automationRepo->findOneByUuid($automationUuid);

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

        // Vérifie le secret si configuré (header uniquement, pas de secret en query string)
        $secret = $triggerNode['data']['config']['secret'] ?? null;
        if ($secret !== null && $secret !== '') {
            $providedSecret = $request->headers->get('X-Webhook-Secret') ?? '';
            if (!hash_equals($secret, $providedSecret)) {
                return $this->json(['error' => 'Invalid webhook secret'], 403);
            }
        }

        // Parse le corps
        $body = $request->getContent();
        $payload = json_decode($body, true) ?? ['raw_body' => $body];
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
