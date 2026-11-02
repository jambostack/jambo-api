<?php

namespace App\Controller;

use App\Entity\Webhook;
use App\Message\SendWebhookMessage;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Repository\WebhookLogRepository;
use App\Repository\WebhookRepository;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/webhooks', name: 'api_webhook_')]
class WebhookController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private WebhookRepository $webhookRepository,
        private WebhookLogRepository $logRepository,
        private CollectionRepository $collectionRepository,
        private EntityManagerInterface $em,
        private WebhookSecretService $secretService,
        private ?MessageBusInterface $bus = null,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $webhooks = $this->webhookRepository->findBy(['project' => $project], ['createdAt' => 'DESC']);

        return $this->json([
            'data' => array_map(fn ($w) => $this->serialize($w), $webhooks),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->toArray();

        if (empty($data['name']) || empty($data['url'])) {
            return $this->json(['error' => 'name and url are required'], 422);
        }

        $url = trim($data['url']);
        if ($err = $this->validateWebhookUrl($url)) {
            return $this->json(['error' => $err], 422);
        }

        $webhook = new Webhook();
        $webhook->project  = $project;
        $webhook->name     = $data['name'];
        $webhook->url      = $url;
        $webhook->events   = $data['events'] ?? ['content.created', 'content.updated', 'content.deleted'];
        $webhook->secret   = isset($data['secret']) ? $this->secretService->encrypt($data['secret']) : null;
        $webhook->isActive = (bool) ($data['is_active'] ?? true);

        if (!empty($data['collection_slugs'])) {
            foreach ($data['collection_slugs'] as $slug) {
                $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $slug);
                if ($collection !== null) {
                    $webhook->collections->add($collection);
                }
            }
        }

        $this->em->persist($webhook);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($webhook)], 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $uuid): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid);
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        return $this->json(['data' => $this->serialize($webhook)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid);
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            $webhook->name = $data['name'];
        }
        if (isset($data['url'])) {
            $url = trim($data['url']);
            if ($err = $this->validateWebhookUrl($url)) {
                return $this->json(['error' => $err], 422);
            }
            $webhook->url = $url;
        }
        if (isset($data['events'])) {
            $webhook->events = $data['events'];
        }
        if (array_key_exists('secret', $data)) {
            $webhook->secret = $data['secret'] !== null ? $this->secretService->encrypt($data['secret']) : null;
        }
        if (isset($data['is_active'])) {
            $webhook->isActive = (bool) $data['is_active'];
        }

        if (array_key_exists('collection_slugs', $data)) {
            $webhook->collections->clear();
            foreach ((array) $data['collection_slugs'] as $slug) {
                $collection = $this->collectionRepository->findOneByProjectAndSlug($webhook->project, $slug);
                if ($collection !== null) {
                    $webhook->collections->add($collection);
                }
            }
        }

        $this->em->flush();

        return $this->json(['data' => $this->serialize($webhook)]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $uuid): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid);
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        $this->em->remove($webhook);
        $this->em->flush();

        return $this->json(null, 204);
    }

    #[Route('/{uuid}/logs', name: 'logs', methods: ['GET'])]
    public function logs(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid);
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 20)));

        $logs  = $this->logRepository->findRecentByWebhook($webhook, $page, $perPage);
        $total = $this->logRepository->countByWebhook($webhook);

        return $this->json([
            'data' => array_map(fn ($l) => [
                'id'             => $l->id,
                'event'          => $l->event,
                'statusCode'     => $l->statusCode,
                'status'         => $l->status,
                'errorMessage'   => $l->errorMessage,
                'requestPayload' => $l->requestPayload,
                'responseBody'   => $l->responseBody,
                'createdAt'      => $l->createdAt->format(\DateTimeInterface::ATOM),
            ], $logs),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    #[Route('/{uuid}/test', name: 'test', methods: ['POST'])]
    public function test(string $projectUuid, string $uuid): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid);
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        $payload = [
            'event'     => 'ping',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'project'   => $projectUuid,
            'webhook'   => $webhook->uuid?->toString(),
            'message'   => 'Test webhook ping from Jambo API',
        ];

        if ($this->bus !== null) {
            $this->bus->dispatch(new SendWebhookMessage($webhook->id, 'ping', $payload));
            return $this->json([
                'success' => true,
                'message' => 'Test webhook queued successfully',
                'payload' => $payload,
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => 'Message bus is not configured',
            'payload' => $payload,
        ], 503);
    }

    private function validateWebhookUrl(string $url): ?string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Invalid URL format';
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Webhook URL must use http or https scheme';
        }

        $host = strtolower($parts['host'] ?? '');
        if (empty($host)) {
            return 'Invalid URL host';
        }

        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        if ($appEnv !== 'test' && (
            $host === 'localhost' ||
            str_ends_with($host, '.local') ||
            (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
        )) {
            return 'Internal and private IP addresses are not allowed for webhooks';
        }

        return null;
    }

    private function serialize(Webhook $webhook): array
    {
        return [
            'uuid'        => $webhook->uuid?->toString(),
            'name'        => $webhook->name,
            'url'         => $webhook->url,
            'events'      => $webhook->events,
            'secret'      => $webhook->secret !== null ? '***' : null,
            'isActive'    => $webhook->isActive,
            'collections' => $webhook->collections->map(fn ($c) => $c->slug)->toArray(),
            'createdAt'   => $webhook->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resolveProject(string $projectUuid): \App\Entity\Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) && !$project->hasMember($user)) {
                return $this->json(['error' => 'Access denied'], 403);
            }
        }
        return $project;
    }

    private function resolveWebhook(string $projectUuid, string $uuid): Webhook|JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $webhook = $this->webhookRepository->findOneBy(['uuid' => $uuid, 'project' => $project]);
        if ($webhook === null) {
            return $this->json(['error' => 'Webhook not found'], 404);
        }
        return $webhook;
    }
}
