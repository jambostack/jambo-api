<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\Mercure\MercureTopicSecurityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class MercureFallbackController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Referrer-Policy'        => 'no-referrer',
        'X-XSS-Protection'       => '0',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.realtime_limiter')]
        private readonly ?RateLimiterFactory $realtimeLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?ProjectRepository $projectRepository = null,
        private readonly ?ProjectMemberRepository $memberRepo = null,
        private readonly ?MercureTopicSecurityService $topicSecurity = null,
        private readonly ?ApiTokenChecker $tokenChecker = null,
        #[Autowire(param: 'mercure_jwt_secret')]
        private readonly ?string $mercureJwtSecret = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    private function resolveAllowedOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || $origin === '') {
            return null;
        }

        $host = $request->getSchemeAndHttpHost();
        if ($origin === $host) {
            return $origin;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $serverHost = parse_url($host, PHP_URL_HOST);
        if ($originHost !== false && $serverHost !== false && ($originHost === $serverHost || in_array($originHost, ['localhost', '127.0.0.1'], true))) {
            return $origin;
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractTopics(Request $request): array
    {
        $topics = [];
        $rawQuery = (string) $request->server->get('QUERY_STRING', '');
        if ($rawQuery !== '') {
            foreach (explode('&', $rawQuery) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $parts = explode('=', $pair, 2);
                $k = urldecode($parts[0]);
                if (in_array($k, ['topic', 'topic[]', 'topics', 'topics[]'], true)) {
                    $v = urldecode($parts[1] ?? '');
                    if ($v !== '') {
                        $topics[] = $v;
                    }
                }
            }
        }

        if (empty($topics)) {
            $param = $request->query->all('topic') ?: $request->query->get('topic');
            if (is_array($param)) {
                $topics = $param;
            } elseif (is_string($param) && $param !== '') {
                $topics = [$param];
            }
        }

        return array_values(array_unique(array_filter($topics, static fn($t) => is_string($t) && trim($t) !== '')));
    }

    #[Route('/.well-known/mercure', name: 'mercure_fallback', methods: ['GET', 'POST', 'OPTIONS'])]
    public function handle(Request $request): Response
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigin = null;
        if (is_string($origin) && $origin !== '') {
            $allowedOrigin = $this->resolveAllowedOrigin($request);
            if ($allowedOrigin === null) {
                return new JsonResponse([
                    'error' => $this->trans('mercure.cors_origin_denied', [], 'CORS origin not allowed for realtime stream.'),
                    'code'  => 'CORS_ORIGIN_DENIED',
                ], 403, self::SECURITY_HEADERS);
            }
        }

        $corsHeaders = [];
        if ($allowedOrigin !== null) {
            $corsHeaders['Access-Control-Allow-Origin'] = $allowedOrigin;
            $corsHeaders['Access-Control-Allow-Credentials'] = 'true';
            $corsHeaders['Vary'] = 'Origin';
        } elseif ($origin === null || $origin === '') {
            $corsHeaders['Access-Control-Allow-Origin'] = '*';
        }

        if ($request->isMethod('OPTIONS')) {
            return new Response('', 204, array_merge([
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Last-Event-ID, X-API-Token',
            ], $corsHeaders));
        }

        if ($this->realtimeLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            $limiter = $this->realtimeLimiter->create($identifier);
            $limitResult = $limiter->consume(1);
            if (false === $limitResult->isAccepted()) {
                $retryAfter = max(1, ($limitResult->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
                return new JsonResponse([
                    'error' => $this->trans('mercure.rate_limit_exceeded', [], 'Too many requests. Try again later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, array_merge(self::SECURITY_HEADERS, $corsHeaders, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $limitResult->getLimit(),
                    'X-RateLimit-Remaining' => (string) $limitResult->getRemainingTokens(),
                    'X-RateLimit-Reset' => (string) ($limitResult->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
                ]));
            }
        }

        if ($request->isMethod('POST')) {
            return new JsonResponse([
                'error' => $this->trans('mercure.hub_not_available', [], 'Mercure hub not available on this host.'),
                'code'  => 'MERCURE_NOT_AVAILABLE',
            ], 503, array_merge(self::SECURITY_HEADERS, $corsHeaders));
        }

        // GET request — validate requested topics and authenticate subscriber to prevent passive eavesdropping
        $topics = $this->extractTopics($request);
        if (empty($topics)) {
            $closedMsg = $this->trans('mercure.hub_not_available', [], 'Mercure hub not available on this host.');
            return new StreamedResponse(function () use ($closedMsg) {
                echo "event: close\ndata: " . json_encode(['error' => $closedMsg], JSON_THROW_ON_ERROR) . "\n\n";
                flush();
            }, 200, array_merge(self::SECURITY_HEADERS, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'close',
                'X-Accel-Buffering' => 'no',
            ], $corsHeaders));
        }

        // Validate control characters / null bytes in requested topics
        foreach ($topics as $t) {
            if (preg_match('/[\x00-\x1F\x7F]/', $t)) {
                return new JsonResponse([
                    'error' => $this->trans('realtime.null_byte_detected', [], 'Null bytes or control characters are strictly forbidden in realtime requests.'),
                    'code'  => 'NULL_BYTE_DETECTED',
                ], 400, array_merge(self::SECURITY_HEADERS, $corsHeaders));
            }
        }

        // Extract project UUIDs and enforce single-tenant isolation
        $projectUuids = [];
        foreach ($topics as $t) {
            if (preg_match('#^projects/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $t, $pm)) {
                $projectUuids[] = strtolower($pm[1]);
            } else {
                return new JsonResponse([
                    'error' => $this->trans('realtime.invalid_topic', [], 'Topic format is invalid.'),
                    'code'  => 'INVALID_TOPIC_FORMAT',
                ], 422, array_merge(self::SECURITY_HEADERS, $corsHeaders));
            }
        }

        $uniqueProjectUuids = array_values(array_unique($projectUuids));
        if (count($uniqueProjectUuids) > 1) {
            return new JsonResponse([
                'error' => $this->trans('mercure.multiple_projects_denied', [], 'Subscribing to topics across multiple projects in a single stream is forbidden.'),
                'code'  => 'FORBIDDEN',
            ], 403, array_merge(self::SECURITY_HEADERS, $corsHeaders));
        }

        $projectUuid = $uniqueProjectUuids[0];
        $project = $this->projectRepository?->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse([
                'error' => $this->trans('realtime.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404, array_merge(self::SECURITY_HEADERS, $corsHeaders));
        }

        // Authenticate subscriber to prevent passive eavesdropping
        $actor = null;
        $isAuthenticated = false;

        $rawJwt = null;
        $authHeader = $request->headers->get('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $rawJwt = trim(substr($authHeader, 7));
        } elseif ($request->cookies->has('mercureAuthorization')) {
            $rawJwt = trim((string) $request->cookies->get('mercureAuthorization'));
        }

        if ($rawJwt !== null && $rawJwt !== '' && $this->topicSecurity !== null) {
            $secret = (string) ($this->mercureJwtSecret ?? '');
            $verification = $this->topicSecurity->verifyToken($rawJwt, $secret, $projectUuid);
            if (!$verification['valid']) {
                $status = ($verification['code'] ?? null) === 'FORBIDDEN' ? 403 : 401;
                return new JsonResponse([
                    'error' => $verification['error'] ?? $this->trans('realtime.token_invalid', [], 'Invalid token.'),
                    'code'  => $verification['code'] ?? 'TOKEN_INVALID',
                ], $status, array_merge(self::SECURITY_HEADERS, $corsHeaders));
            }
            $isAuthenticated = true;
        }

        if (!$isAuthenticated) {
            $user = $this->getUser();
            if ($user instanceof User) {
                $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
                $isMember = $this->memberRepo?->findActiveByUserAndProject($user, $project) !== null;
                if ($isSuperAdmin || $isMember) {
                    $actor = $user;
                    $isAuthenticated = true;
                } else {
                    return new JsonResponse([
                        'error' => $this->trans('realtime.access_denied', [], 'Access denied'),
                        'code'  => 'ACCESS_DENIED',
                    ], 403, array_merge(self::SECURITY_HEADERS, $corsHeaders));
                }
            }
        }

        if (!$isAuthenticated && $this->tokenChecker !== null) {
            $apiToken = $this->tokenChecker->resolve($request);
            if ($apiToken !== null && $apiToken->project?->uuid?->toRfc4122() === $project->uuid?->toRfc4122()) {
                if ($apiToken->can('read') || $apiToken->can('*')) {
                    $actor = $apiToken;
                    $isAuthenticated = true;
                } else {
                    return new JsonResponse([
                        'error' => $this->trans('realtime.access_denied', [], 'Access denied'),
                        'code'  => 'ACCESS_DENIED',
                    ], 403, array_merge(self::SECURITY_HEADERS, $corsHeaders));
                }
            }
        }

        if (!$isAuthenticated) {
            return new JsonResponse([
                'error' => $this->trans('mercure.auth_required', [], 'Authentication required to subscribe to realtime events.'),
                'code'  => 'AUTH_REQUIRED',
            ], 401, array_merge(self::SECURITY_HEADERS, $corsHeaders));
        }

        // Validate topics against topic security rules
        if ($this->topicSecurity !== null) {
            $validation = $this->topicSecurity->validateAndFilterTopics($projectUuid, $topics, $actor);
            if (isset($validation['error'])) {
                $status = ($validation['code'] ?? null) === 'FORBIDDEN' ? 403 : 422;
                return new JsonResponse([
                    'error' => $validation['error'],
                    'code'  => $validation['code'] ?? 'VALIDATION_ERROR',
                ], $status, array_merge(self::SECURITY_HEADERS, $corsHeaders));
            }
        }

        $closedMsg = $this->trans('mercure.hub_not_available', [], 'Mercure hub not available on this host.');
        return new StreamedResponse(function () use ($closedMsg) {
            echo "event: close\ndata: " . json_encode(['error' => $closedMsg], JSON_THROW_ON_ERROR) . "\n\n";
            flush();
        }, 200, array_merge(self::SECURITY_HEADERS, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'close',
            'X-Accel-Buffering' => 'no',
        ], $corsHeaders));
    }
}
