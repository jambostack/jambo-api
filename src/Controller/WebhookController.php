<?php

namespace App\Controller;

use App\Entity\Webhook;
use App\Message\SendWebhookMessage;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Repository\WebhookLogRepository;
use App\Repository\WebhookRepository;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/webhooks', name: 'api_webhook_')]
class WebhookController extends AbstractController
{
    private SsrfProtectionService $ssrfProtection;
    private WebhookService $webhookService;

    public function __construct(
        private ProjectRepository $projectRepository,
        private WebhookRepository $webhookRepository,
        private WebhookLogRepository $logRepository,
        private CollectionRepository $collectionRepository,
        private EntityManagerInterface $em,
        private WebhookSecretService $secretService,
        private ?MessageBusInterface $bus = null,
        ?SsrfProtectionService $ssrfProtection = null,
        ?WebhookService $webhookService = null,
        private ?TranslatorInterface $translator = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
        $this->webhookService = $webhookService ?? new WebhookService(
            $this->webhookRepository,
            $this->em,
            new \Symfony\Component\HttpClient\NativeHttpClient(),
            $this->secretService,
            $this->ssrfProtection,
            $this->translator
        );
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid, 'project.view');
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
        $project = $this->resolveProject($projectUuid, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('webhook.invalid_json', [], 'Invalid JSON body')], 400);
        }

        if (empty($data['name']) || empty($data['url'])) {
            return $this->json(['error' => $this->trans('webhook.name_url_required', [], 'name and url are required')], 422);
        }

        $name = trim((string) $data['name']);
        if (strlen($name) > 100) {
            return $this->json(['error' => $this->trans('webhook.name_too_long', [], 'Webhook name cannot exceed 100 characters')], 422);
        }

        $url = trim((string) $data['url']);
        if ($err = $this->validateWebhookUrl($url)) {
            return $this->json(['error' => $err], 422);
        }

        $plainSecret = null;
        if (!empty($data['secret'])) {
            $customSecret = (string) $data['secret'];
            if (strlen($customSecret) < 16 || strlen($customSecret) > 256) {
                return $this->json(['error' => $this->trans('webhook.secret_length_invalid', [], 'Webhook secret must be between 16 and 256 characters long')], 422);
            }
            $plainSecret = $customSecret;
        } elseif (!array_key_exists('secret', $data) || !empty($data['auto_generate_secret'])) {
            $plainSecret = 'whsec_' . bin2hex(random_bytes(24));
        }

        $webhook = new Webhook();
        $webhook->project  = $project;
        $webhook->name     = $name;
        $webhook->url      = $url;
        $webhook->events   = is_array($data['events'] ?? null) ? $data['events'] : ['content.created', 'content.updated', 'content.deleted'];
        $webhook->secret   = $plainSecret !== null ? $this->secretService->encrypt($plainSecret) : null;
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

        $response = ['data' => $this->serialize($webhook)];
        if ($plainSecret !== null) {
            $response['plain_secret'] = $plainSecret;
        }

        return $this->json($response, 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $uuid): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.view');
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        return $this->json(['data' => $this->serialize($webhook)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.manage');
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('webhook.invalid_json', [], 'Invalid JSON body')], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if (strlen($name) > 100) {
                return $this->json(['error' => $this->trans('webhook.name_too_long', [], 'Webhook name cannot exceed 100 characters')], 422);
            }
            $webhook->name = $name;
        }
        if (isset($data['url'])) {
            $url = trim((string) $data['url']);
            if ($err = $this->validateWebhookUrl($url)) {
                return $this->json(['error' => $err], 422);
            }
            $webhook->url = $url;
        }
        if (isset($data['events'])) {
            $webhook->events = is_array($data['events']) ? $data['events'] : [];
        }
        if (array_key_exists('secret', $data)) {
            if ($data['secret'] === null || $data['secret'] === '') {
                $webhook->secret = null;
            } else {
                $customSecret = (string) $data['secret'];
                if (strlen($customSecret) < 16 || strlen($customSecret) > 256) {
                    return $this->json(['error' => $this->trans('webhook.secret_length_invalid', [], 'Webhook secret must be between 16 and 256 characters long')], 422);
                }
                $webhook->secret = $this->secretService->encrypt($customSecret);
            }
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

    #[Route('/{uuid}/rotate-secret', name: 'rotate_secret', methods: ['POST'])]
    public function rotateSecret(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.manage');
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        $data = [];
        try {
            $data = $request->toArray();
        } catch (\Throwable) {}

        $plainSecret = null;
        if (!empty($data['secret'])) {
            $customSecret = (string) $data['secret'];
            if (strlen($customSecret) < 16 || strlen($customSecret) > 256) {
                return $this->json(['error' => $this->trans('webhook.secret_length_invalid', [], 'Webhook secret must be between 16 and 256 characters long')], 422);
            }
            $plainSecret = $customSecret;
        } else {
            $plainSecret = 'whsec_' . bin2hex(random_bytes(24));
        }

        $webhook->secret = $this->secretService->encrypt($plainSecret);
        $this->em->flush();

        return $this->json([
            'success'      => true,
            'message'      => $this->trans('webhook.secret_rotated', [], 'Webhook secret rotated successfully'),
            'plain_secret' => $plainSecret,
            'data'         => $this->serialize($webhook),
        ]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $uuid): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.manage');
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
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.view');
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
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.manage');
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
                'message' => $this->trans('webhook.test_queued', [], 'Test webhook queued successfully'),
                'payload' => $payload,
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => $this->trans('webhook.bus_not_configured', [], 'Message bus is not configured'),
            'payload' => $payload,
        ], 503);
    }

    private function validateWebhookUrl(string $url): ?string
    {
        return $this->webhookService->validateUrl($url);
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

    private function resolveProject(string $projectUuid, string $requiredPermission = 'project.view'): \App\Entity\Project|JsonResponse
    {
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('webhook.project_not_found', [], 'Project not found')], 404);
        }
        if ($project === null) {
            return $this->json(['error' => $this->trans('webhook.project_not_found', [], 'Project not found')], 404);
        }

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                if (!$project->hasMember($user)) {
                    return $this->json(['error' => $this->trans('webhook.access_denied', [], 'Access denied to this project.')], 403);
                }
                $this->denyAccessUnlessGranted($requiredPermission, $project);
            }
            return $project;
        }

        return $project;
    }

    private function resolveWebhook(string $projectUuid, string $uuid, string $requiredPermission = 'project.view'): Webhook|JsonResponse
    {
        $project = $this->resolveProject($projectUuid, $requiredPermission);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        try {
            $webhook = $this->webhookRepository->findOneBy(['uuid' => $uuid, 'project' => $project]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('webhook.not_found', [], 'Webhook not found')], 404);
        }
        if ($webhook === null) {
            return $this->json(['error' => $this->trans('webhook.not_found', [], 'Webhook not found')], 404);
        }
        return $webhook;
    }
}
