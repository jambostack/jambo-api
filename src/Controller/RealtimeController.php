<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\Mercure\MercureTopicSecurityService;
use App\Service\MercurePublisher;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Realtime communication endpoints.
 *
 * - GET  /realtime/token   → generates Mercure subscription & optional publish JWT with strict topic validation
 * - GET  /realtime         → short-polling fallback from JSONL
 * - POST /realtime/publish → securely dispatches authorized realtime events
 */
#[Route('/api/projects/{projectUuid}/realtime', name: 'api_realtime_', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
class RealtimeController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                 => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    private readonly MercureTopicSecurityService $topicSecurity;

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectMemberRepository $memberRepo,
        #[Autowire(param: 'mercure_enabled')]
        private readonly bool $mercureEnabled = false,
        private readonly ?RateLimiterFactory $realtimeLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        ?MercureTopicSecurityService $topicSecurity = null,
        private readonly ?MercurePublisher $mercurePublisher = null,
    ) {
        $this->topicSecurity = $topicSecurity ?? new MercureTopicSecurityService($this->memberRepo, $this->translator);
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    /**
     * Generates a signed JWT token to subscribe to the Mercure hub with strict topic boundaries.
     */
    #[Route('/token', name: 'token', methods: ['GET', 'POST'])]
    public function token(string $projectUuid, Request $request): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('realtime.invalid_project_uuid', [], 'Invalid project UUID format')], 400);
        }

        if ($this->realtimeLimiter !== null) {
            $identifier = ($this->getUser()?->getUserIdentifier() ?? $request->getClientIp()) ?: '127.0.0.1';
            $limiter = $this->realtimeLimiter->create($identifier);
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('realtime.rate_limit_exceeded', [], 'Too many realtime requests. Try again later.')], 429);
            }
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('realtime.project_not_found', [], 'Project not found')], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('realtime.project_not_found', [], 'Project not found')], 404);
        }
        if ($error = $this->denyProjectAccess($project, $request)) {
            return $error;
        }

        // Validate requested subscription topics
        $requestedTopics = $request->query->get('topics') ?? $request->request->get('topics');
        if ($request->isMethod('POST') && $request->getContent() !== '') {
            $body = json_decode($request->getContent(), true);
            if (is_array($body) && isset($body['topics'])) {
                $requestedTopics = $body['topics'];
            }
        }

        $validation = $this->topicSecurity->validateAndFilterTopics($projectUuid, $requestedTopics);
        if (isset($validation['error'])) {
            $status = str_contains($validation['error'], 'forbidden') ? 403 : 422;
            return $this->secureJson(['error' => $validation['error']], $status);
        }

        $subscribeTopics = $validation['topics'];

        // Determine publish permissions
        $user = $this->getUser();
        $userEntity = $user instanceof User ? $user : null;
        $canPublish = $this->topicSecurity->canUserPublish($userEntity, $project);

        $requestedPublish = $request->query->get('publish') ?? $request->request->get('publish');
        $requestedPublishTopics = $request->query->get('publish_topics') ?? $request->request->get('publish_topics');
        if ($request->isMethod('POST') && isset($body)) {
            $requestedPublish = $requestedPublish ?? ($body['publish'] ?? false);
            $requestedPublishTopics = $requestedPublishTopics ?? ($body['publish_topics'] ?? null);
        }

        $publishTopics = [];
        if ($requestedPublish || $requestedPublishTopics !== null) {
            if (!$canPublish) {
                return $this->secureJson(['error' => $this->trans('realtime.publish_denied', [], 'Publishing to realtime stream is denied for your role.')], 403);
            }

            $publishValidation = $this->topicSecurity->validateAndFilterTopics($projectUuid, $requestedPublishTopics);
            if (isset($publishValidation['error'])) {
                return $this->secureJson(['error' => $publishValidation['error']], 422);
            }
            $publishTopics = $publishValidation['topics'];
        }

        try {
            $mercureSecret = $this->getParameter('mercure_jwt_secret') ?? '';
            if (!$this->mercureEnabled || $mercureSecret === '' || strlen($mercureSecret) < 32) {
                return $this->secureJson([
                    'enabled' => false,
                    'token'   => null,
                    'hub_url' => null,
                    'topics'  => $subscribeTopics,
                    'message' => $this->trans('realtime.mercure_not_configured', [], 'Mercure not configured on this server. Fallback to polling.'),
                ], 200);
            }

            $jwtConfig = Configuration::forSymmetricSigner(
                new Sha256(),
                InMemory::plainText($mercureSecret),
            );

            $now = new \DateTimeImmutable();
            $token = $jwtConfig->builder()
                ->issuedAt($now)
                ->expiresAt($now->modify('+1 hour'))
                ->withClaim('mercure', [
                    'subscribe' => $subscribeTopics,
                    'publish'   => $publishTopics,
                ])
                ->getToken($jwtConfig->signer(), $jwtConfig->signingKey())
                ->toString();

            $hubUrl = $this->getParameter('mercure_public_url')
                ?? $request->getSchemeAndHttpHost() . '/.well-known/mercure';

            return $this->secureJson([
                'enabled'        => true,
                'token'          => $token,
                'hub_url'        => $hubUrl,
                'topics'         => $subscribeTopics,
                'publish_topics' => $publishTopics,
                'can_publish'    => $canPublish,
            ]);
        } catch (\Throwable) {
            return $this->secureJson([
                'enabled' => false,
                'token'   => null,
                'hub_url' => null,
                'topics'  => $subscribeTopics,
                'message' => $this->trans('realtime.mercure_init_error', [], 'Mercure initialization error. Fallback to polling.'),
            ], 200);
        }
    }

    /**
     * Publishes a realtime message safely within tenant namespace.
     */
    #[Route('/publish', name: 'publish', methods: ['POST'])]
    public function publish(string $projectUuid, Request $request): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('realtime.invalid_project_uuid', [], 'Invalid project UUID format')], 400);
        }

        if ($this->realtimeLimiter !== null) {
            $identifier = ($this->getUser()?->getUserIdentifier() ?? $request->getClientIp()) ?: '127.0.0.1';
            $limiter = $this->realtimeLimiter->create($identifier);
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('realtime.rate_limit_exceeded', [], 'Too many realtime requests. Try again later.')], 429);
            }
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('realtime.project_not_found', [], 'Project not found')], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('realtime.project_not_found', [], 'Project not found')], 404);
        }
        if ($error = $this->denyProjectAccess($project, $request)) {
            return $error;
        }

        $user = $this->getUser();
        $userEntity = $user instanceof User ? $user : null;
        if (!$this->topicSecurity->canUserPublish($userEntity, $project)) {
            return $this->secureJson(['error' => $this->trans('realtime.publish_denied', [], 'Publishing to realtime stream is denied for your role.')], 403);
        }

        $content = $request->getContent();
        if (strlen($content) > MercureTopicSecurityService::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('realtime.payload_too_large', [], 'Realtime payload exceeds maximum allowed size (64KB).')], 413);
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $this->secureJson(['error' => $this->trans('realtime.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        $topic = $data['topic'] ?? "projects/{$projectUuid}";
        $validation = $this->topicSecurity->validateAndFilterTopics($projectUuid, [$topic]);
        if (isset($validation['error'])) {
            return $this->secureJson(['error' => $validation['error']], 422);
        }

        $event = is_string($data['event'] ?? null) ? trim((string) $data['event']) : 'custom';
        if (strlen($event) > 64 || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $event)) {
            return $this->secureJson(['error' => $this->trans('realtime.invalid_event_name', [], 'Invalid event name format.')], 422);
        }

        $payloadData = is_array($data['data'] ?? null) ? $data['data'] : ['message' => (string) ($data['data'] ?? '')];

        if ($this->mercurePublisher !== null) {
            $this->mercurePublisher->contentChanged($projectUuid, $event, $payloadData, $payloadData['title'] ?? null);
        }

        return $this->secureJson([
            'status' => 'published',
            'topic'  => $topic,
            'event'  => $event,
            'time'   => time(),
        ]);
    }

    #[Route('', name: 'poll', methods: ['GET'])]
    public function poll(string $projectUuid, Request $request): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('realtime.invalid_project_uuid', [], 'Invalid project UUID format')], 400);
        }

        if ($this->realtimeLimiter !== null) {
            $identifier = ($this->getUser()?->getUserIdentifier() ?? $request->getClientIp()) ?: '127.0.0.1';
            $limiter = $this->realtimeLimiter->create($identifier);
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('realtime.rate_limit_exceeded', [], 'Too many realtime requests. Try again later.')], 429);
            }
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('realtime.project_not_found', [], 'Project not found')], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('realtime.project_not_found', [], 'Project not found')], 404);
        }
        if ($error = $this->denyProjectAccess($project, $request)) {
            return $error;
        }

        $since = min(1000000, max(0, (int) $request->query->get('since', '0')));
        $limit = min(100, max(1, (int) $request->query->get('limit', 100)));
        $safeUuid = $project->uuid?->toRfc4122() ?? $projectUuid;

        $projectDir = $this->getParameter('kernel.project_dir');
        $baseRealtimeDir = realpath($projectDir . '/var/realtime');
        $eventFile = $projectDir . '/var/realtime/' . $safeUuid . '.jsonl';

        $events = [];
        $lastId = $since;

        if (file_exists($eventFile)) {
            // Guard against traversal
            $realPath = realpath($eventFile);
            if ($baseRealtimeDir !== false && $realPath !== false && str_starts_with($realPath, $baseRealtimeDir)) {
                $lines = file($realPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false && count($lines) > $since) {
                    $end = min(count($lines), $since + $limit);
                    for ($i = $since; $i < $end; $i++) {
                        $data = json_decode($lines[$i], true);
                        if ($data !== null) {
                            $data['_id'] = $i;
                            $events[] = $data;
                            $lastId = $i + 1;
                        }
                    }
                }

                // Cleanup periodically (keep max 500 lines)
                if ($lastId > 500 && is_array($lines) && count($lines) > 500) {
                    $this->trimEventFile($realPath, $lines);
                }
            }
        }

        return $this->secureJson([
            'events' => $events,
            'since'  => $lastId,
            'time'   => time(),
        ]);
    }

    // ─── Private ─────────────────────────────────────────────────────────

    private function denyProjectAccess(Project $project, Request $request): ?JsonResponse
    {
        if ($request->attributes->get('_project') === $project) {
            return null;
        }
        $user = $this->getUser();
        if (!$user) {
            return $this->secureJson(['error' => $this->trans('realtime.auth_required', [], 'Authentication required.')], 401);
        }
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return null;
        }
        if ($this->memberRepo->findActiveByUserAndProject($user, $project) === null) {
            return $this->secureJson(['error' => $this->trans('realtime.access_denied', [], 'Access denied')], 403);
        }
        return null;
    }

    private function trimEventFile(string $path, array $lines): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $keep = array_slice($lines, -200);
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, implode("\n", $keep) . "\n", LOCK_EX);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }
}
