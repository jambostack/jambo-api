<?php

namespace App\Controller;

use App\Entity\Webhook;
use App\Message\SendWebhookMessage;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Repository\WebhookLogRepository;
use App\Repository\WebhookRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/webhooks', name: 'api_webhook_')]
class WebhookController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    private const MAX_EVENTS_COUNT = 50;
    private const MAX_COLLECTIONS_COUNT = 50;
    public const MAX_WEBHOOKS_PER_PROJECT = 50;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Referrer-Policy'        => 'no-referrer',
        'Cache-Control'          => 'no-store',
    ];

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
        private ?AuditService $auditService = null,
        private ?ApiTokenChecker $tokenChecker = null,
        private ?RequestStack $requestStack = null,
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

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid, 'project.view');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $webhooks = $this->webhookRepository->findBy(['project' => $project], ['createdAt' => 'DESC']);

        return $this->secureJson([
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

        $existingWebhooksCount = $this->webhookRepository->count(['project' => $project]);
        if ($existingWebhooksCount >= self::MAX_WEBHOOKS_PER_PROJECT) {
            return $this->secureJson([
                'error' => $this->trans('webhook.limit_exceeded', ['%max%' => self::MAX_WEBHOOKS_PER_PROJECT], sprintf('Project webhook limit reached (max %d webhooks).', self::MAX_WEBHOOKS_PER_PROJECT)),
                'code' => 'WEBHOOK_LIMIT_EXCEEDED',
            ], 422);
        }

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && is_numeric($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('webhook.payload_too_large', [], 'Webhook payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('webhook.payload_too_large', [], 'Webhook payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('webhook.invalid_json', [], 'Invalid JSON body'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        if (empty($data['name']) || empty($data['url'])) {
            return $this->secureJson([
                'error' => $this->trans('webhook.name_url_required', [], 'name and url are required'),
                'code' => 'NAME_URL_REQUIRED',
            ], 422);
        }

        $name = trim((string) $data['name']);
        if (strlen($name) > 100) {
            return $this->secureJson([
                'error' => $this->trans('webhook.name_too_long', [], 'Webhook name cannot exceed 100 characters'),
                'code' => 'NAME_TOO_LONG',
            ], 422);
        }

        $url = trim((string) $data['url']);
        if ($err = $this->validateWebhookUrl($url)) {
            return $this->secureJson([
                'error' => $err,
                'code' => 'INVALID_URL',
            ], 422);
        }

        $plainSecret = null;
        if (!empty($data['secret'])) {
            $customSecret = (string) $data['secret'];
            if (strlen($customSecret) < 16 || strlen($customSecret) > 256) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.secret_length_invalid', [], 'Webhook secret must be between 16 and 256 characters long'),
                    'code' => 'SECRET_LENGTH_INVALID',
                ], 422);
            }
            $plainSecret = $customSecret;
        } elseif (!array_key_exists('secret', $data) || !empty($data['auto_generate_secret'])) {
            $plainSecret = 'whsec_' . bin2hex(random_bytes(24));
        }

        $events = ['content.created', 'content.updated', 'content.deleted'];
        if (array_key_exists('events', $data)) {
            if (!is_array($data['events'])) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.invalid_event_format', [], 'Events must be an array.'),
                    'code' => 'INVALID_EVENT_FORMAT',
                ], 422);
            }
            if (count($data['events']) > self::MAX_EVENTS_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.too_many_events', [], 'Too many events specified (max 50).'),
                    'code' => 'TOO_MANY_EVENTS',
                ], 422);
            }
            $cleanEvents = [];
            foreach ($data['events'] as $evt) {
                if (!is_string($evt)) {
                    return $this->secureJson([
                        'error' => $this->trans('webhook.invalid_event_format', [], 'Invalid event format.'),
                        'code' => 'INVALID_EVENT_FORMAT',
                    ], 422);
                }
                $evtTrim = trim($evt);
                if ($evtTrim === '' || strlen($evtTrim) > 64 || !preg_match('/^[a-z0-9_.-]+$/i', $evtTrim)) {
                    return $this->secureJson([
                        'error' => $this->trans('webhook.invalid_event_format', [], 'Invalid event format.'),
                        'code' => 'INVALID_EVENT_FORMAT',
                    ], 422);
                }
                $cleanEvents[] = $evtTrim;
            }
            $events = array_values(array_unique($cleanEvents));
        }

        $webhook = new Webhook();
        $webhook->project  = $project;
        $webhook->name     = $name;
        $webhook->url      = $url;
        $webhook->events   = $events;
        $webhook->secret   = $plainSecret !== null ? $this->secretService->encrypt($plainSecret) : null;
        $webhook->isActive = (bool) ($data['is_active'] ?? true);

        if (!empty($data['collection_slugs'])) {
            if (!is_array($data['collection_slugs'])) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.invalid_collection_slug', [], 'Collection slugs must be an array.'),
                    'code' => 'INVALID_COLLECTION_SLUG',
                ], 422);
            }
            if (count($data['collection_slugs']) > self::MAX_COLLECTIONS_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.too_many_collections', [], 'Too many collections specified (max 50).'),
                    'code' => 'TOO_MANY_COLLECTIONS',
                ], 422);
            }
            foreach ($data['collection_slugs'] as $slug) {
                if (!is_string($slug) || strlen($slug) > 100 || !preg_match('/^[a-z0-9_-]+$/i', $slug)) {
                    return $this->secureJson([
                        'error' => $this->trans('webhook.invalid_collection_slug', [], 'Invalid collection slug format.'),
                        'code' => 'INVALID_COLLECTION_SLUG',
                    ], 422);
                }
                $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $slug);
                if ($collection !== null) {
                    $webhook->collections->add($collection);
                }
            }
        }

        $this->em->persist($webhook);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'webhook_create',
            project: $project,
            input: ['name' => $webhook->name, 'url' => $webhook->url, 'events' => $webhook->events],
            output: ['success' => true, 'uuid' => $webhook->uuid?->toRfc4122()],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'webhook_management'
        );
        $this->auditService?->flush();

        $response = ['data' => $this->serialize($webhook)];
        if ($plainSecret !== null) {
            $response['plain_secret'] = $plainSecret;
        }

        return $this->secureJson($response, 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $uuid): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.view');
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        return $this->secureJson(['data' => $this->serialize($webhook)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.manage');
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && is_numeric($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('webhook.payload_too_large', [], 'Webhook payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('webhook.payload_too_large', [], 'Webhook payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('webhook.invalid_json', [], 'Invalid JSON body'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if (strlen($name) > 100) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.name_too_long', [], 'Webhook name cannot exceed 100 characters'),
                    'code' => 'NAME_TOO_LONG',
                ], 422);
            }
            $webhook->name = $name;
        }
        if (isset($data['url'])) {
            $url = trim((string) $data['url']);
            if ($err = $this->validateWebhookUrl($url)) {
                return $this->secureJson([
                    'error' => $err,
                    'code' => 'INVALID_URL',
                ], 422);
            }
            $webhook->url = $url;
        }
        if (isset($data['events'])) {
            if (!is_array($data['events'])) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.invalid_event_format', [], 'Events must be an array.'),
                    'code' => 'INVALID_EVENT_FORMAT',
                ], 422);
            }
            if (count($data['events']) > self::MAX_EVENTS_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.too_many_events', [], 'Too many events specified (max 50).'),
                    'code' => 'TOO_MANY_EVENTS',
                ], 422);
            }
            $cleanEvents = [];
            foreach ($data['events'] as $evt) {
                if (!is_string($evt)) {
                    return $this->secureJson([
                        'error' => $this->trans('webhook.invalid_event_format', [], 'Invalid event format.'),
                        'code' => 'INVALID_EVENT_FORMAT',
                    ], 422);
                }
                $evtTrim = trim($evt);
                if ($evtTrim === '' || strlen($evtTrim) > 64 || !preg_match('/^[a-z0-9_.-]+$/i', $evtTrim)) {
                    return $this->secureJson([
                        'error' => $this->trans('webhook.invalid_event_format', [], 'Invalid event format.'),
                        'code' => 'INVALID_EVENT_FORMAT',
                    ], 422);
                }
                $cleanEvents[] = $evtTrim;
            }
            $webhook->events = array_values(array_unique($cleanEvents));
        }
        if (array_key_exists('secret', $data)) {
            if ($data['secret'] === null || $data['secret'] === '') {
                $webhook->secret = null;
            } else {
                $customSecret = (string) $data['secret'];
                if (strlen($customSecret) < 16 || strlen($customSecret) > 256) {
                    return $this->secureJson([
                        'error' => $this->trans('webhook.secret_length_invalid', [], 'Webhook secret must be between 16 and 256 characters long'),
                        'code' => 'SECRET_LENGTH_INVALID',
                    ], 422);
                }
                $webhook->secret = $this->secretService->encrypt($customSecret);
            }
        }
        if (isset($data['is_active'])) {
            $webhook->isActive = (bool) $data['is_active'];
        }

        if (array_key_exists('collection_slugs', $data)) {
            if (!is_array($data['collection_slugs'])) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.invalid_collection_slug', [], 'Collection slugs must be an array.'),
                    'code' => 'INVALID_COLLECTION_SLUG',
                ], 422);
            }
            if (count($data['collection_slugs']) > self::MAX_COLLECTIONS_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.too_many_collections', [], 'Too many collections specified (max 50).'),
                    'code' => 'TOO_MANY_COLLECTIONS',
                ], 422);
            }
            $webhook->collections->clear();
            foreach ((array) $data['collection_slugs'] as $slug) {
                if (!is_string($slug) || strlen($slug) > 100 || !preg_match('/^[a-z0-9_-]+$/i', $slug)) {
                    return $this->secureJson([
                        'error' => $this->trans('webhook.invalid_collection_slug', [], 'Invalid collection slug format.'),
                        'code' => 'INVALID_COLLECTION_SLUG',
                    ], 422);
                }
                $collection = $this->collectionRepository->findOneByProjectAndSlug($webhook->project, $slug);
                if ($collection !== null) {
                    $webhook->collections->add($collection);
                }
            }
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'webhook_update',
            project: $webhook->project,
            input: ['uuid' => $webhook->uuid?->toRfc4122(), 'name' => $webhook->name],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'webhook_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serialize($webhook)]);
    }

    #[Route('/{uuid}/rotate-secret', name: 'rotate_secret', methods: ['POST'])]
    public function rotateSecret(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.manage');
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && is_numeric($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('webhook.payload_too_large', [], 'Webhook payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('webhook.payload_too_large', [], 'Webhook payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        $data = [];
        try {
            $data = $request->toArray();
        } catch (\Throwable) {}

        $plainSecret = null;
        if (!empty($data['secret'])) {
            $customSecret = (string) $data['secret'];
            if (strlen($customSecret) < 16 || strlen($customSecret) > 256) {
                return $this->secureJson([
                    'error' => $this->trans('webhook.secret_length_invalid', [], 'Webhook secret must be between 16 and 256 characters long'),
                    'code' => 'SECRET_LENGTH_INVALID',
                ], 422);
            }
            $plainSecret = $customSecret;
        } else {
            $plainSecret = 'whsec_' . bin2hex(random_bytes(24));
        }

        $webhook->secret = $this->secretService->encrypt($plainSecret);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'webhook_rotate_secret',
            project: $webhook->project,
            input: ['uuid' => $webhook->uuid?->toRfc4122()],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'webhook_management'
        );
        $this->auditService?->flush();

        return $this->secureJson([
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

        $project = $webhook->project;
        $webhookUuid = $webhook->uuid?->toRfc4122();
        $this->em->remove($webhook);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'webhook_delete',
            project: $project,
            input: ['uuid' => $webhookUuid],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'webhook_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    #[Route('/{uuid}/logs', name: 'logs', methods: ['GET'])]
    public function logs(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $webhook = $this->resolveWebhook($projectUuid, $uuid, 'project.view');
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }

        $page    = min(1000, max(1, $request->query->getInt('page', 1)));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 20)));

        $logs  = $this->logRepository->findRecentByWebhook($webhook, $page, $perPage);
        $total = $this->logRepository->countByWebhook($webhook);

        return $this->secureJson([
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
            $this->bus->dispatch(new SendWebhookMessage($webhook->id, 'ping', $payload, null, 86400, $webhook->project?->uuid?->toRfc4122() ?? $projectUuid));
            return $this->secureJson([
                'success' => true,
                'message' => $this->trans('webhook.test_queued', [], 'Test webhook queued successfully'),
                'payload' => $payload,
            ]);
        }

        return $this->secureJson([
            'success' => false,
            'message' => $this->trans('webhook.bus_not_configured', [], 'Message bus is not configured'),
            'code' => 'BUS_NOT_CONFIGURED',
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
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('webhook.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('webhook.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if ($project === null) {
            return $this->secureJson([
                'error' => $this->trans('webhook.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        $user = $this->getUser();
        if ($user === null) {
            $req = $this->requestStack?->getCurrentRequest();
            if ($req !== null && $this->tokenChecker !== null) {
                $token = $this->tokenChecker->resolve($req);
                if ($token !== null && $token->project?->uuid?->toString() === $projectUuid) {
                    if (in_array($requiredPermission, ['project.manage', 'project.edit'], true)) {
                        if ($token->can('write') || $token->can('*') || $token->can('manage')) {
                            return $project;
                        }
                    } else {
                        if ($this->tokenChecker->isAllowedForMethod($token, $req->getMethod())) {
                            return $project;
                        }
                    }
                    return $this->secureJson([
                        'error' => $this->trans('webhook.access_denied', [], 'Access denied to this project.'),
                        'code' => 'ACCESS_DENIED',
                    ], 403);
                }
            }

            return $this->secureJson([
                'error' => $this->trans('webhook.auth_required', [], 'Authentication required to access project webhooks.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($user instanceof \App\Entity\User) {
            if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                if (!$project->hasMember($user)) {
                    return $this->secureJson([
                        'error' => $this->trans('webhook.access_denied', [], 'Access denied to this project.'),
                        'code' => 'ACCESS_DENIED',
                    ], 403);
                }
                $this->denyAccessUnlessGranted($requiredPermission, $project);
            }
            return $project;
        }

        return $this->secureJson([
            'error' => $this->trans('webhook.access_denied', [], 'Access denied to this project.'),
            'code' => 'ACCESS_DENIED',
        ], 403);
    }

    private function resolveWebhook(string $projectUuid, string $uuid, string $requiredPermission = 'project.view'): Webhook|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $uuid)) {
            return $this->secureJson([
                'error' => $this->trans('webhook.not_found', [], 'Webhook not found'),
                'code' => 'WEBHOOK_NOT_FOUND',
            ], 404);
        }

        $project = $this->resolveProject($projectUuid, $requiredPermission);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        try {
            $webhook = $this->webhookRepository->findOneBy(['uuid' => $uuid, 'project' => $project]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('webhook.not_found', [], 'Webhook not found'),
                'code' => 'WEBHOOK_NOT_FOUND',
            ], 404);
        }
        if ($webhook === null) {
            return $this->secureJson([
                'error' => $this->trans('webhook.not_found', [], 'Webhook not found'),
                'code' => 'WEBHOOK_NOT_FOUND',
            ], 404);
        }
        return $webhook;
    }
}
