<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/notifications', name: 'api_notifications_')]
class NotificationController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    public const BATCH_MAX_IDS = 100;
    public const MAX_USER_NOTIFICATIONS = 1000;
    public const MAX_USER_UNREAD_NOTIFICATIONS = 500;

    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                 => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    public function __construct(
        private NotificationRepository $notificationRepository,
        #[Autowire(service: 'limiter.notification_limiter')]
        private ?RateLimiterFactory $notificationLimiter = null,
        private ?ProjectRepository $projectRepository = null,
        private ?ProjectMemberRepository $memberRepository = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getAuthUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $page = min(max(1, $request->query->getInt('page', 1)), 1000);
        $perPage = min(50, max(1, $request->query->getInt('per_page', 20)));
        $unreadOnly = $request->query->getBoolean('unread_only');

        $projectFilter = $this->resolveProjectFilter($request, $user);
        if ($projectFilter instanceof JsonResponse) {
            return $projectFilter;
        }

        if ($projectFilter !== null) {
            $notifications = $this->notificationRepository->findByRecipientAndProjectPaginated($user, $projectFilter, $page, $perPage, $unreadOnly);
            $unreadCount = $this->notificationRepository->countUnreadByUserAndProject($user, $projectFilter);
        } else {
            $notifications = $this->notificationRepository->findByRecipientPaginated($user, $page, $perPage, $unreadOnly);
            $unreadCount = $this->notificationRepository->countUnreadByUser($user);
        }

        $totalUserNotifications = $this->notificationRepository->countByRecipient($user);

        return $this->secureJson([
            'data' => array_map(fn ($n) => $this->serializeNotification($n), $notifications),
            'meta' => [
                'unread_count' => $unreadCount,
                'total_user_notifications' => $totalUserNotifications,
                'max_user_notifications' => self::MAX_USER_NOTIFICATIONS,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    #[Route('/{id}/read', name: 'read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function read(int $id, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getAuthUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $notification = $this->notificationRepository->find($id);
        if (!$notification || $notification->recipient?->id !== $user->id) {
            return $this->secureJson([
                'error' => $this->trans('notification.not_found', [], 'Notification not found.'),
                'code' => 'NOTIFICATION_NOT_FOUND',
            ], 404);
        }
        if ($notification->readAt === null) {
            $notification->readAt = new \DateTimeImmutable();
            $this->notificationRepository->getEntityManager()->flush();
        }
        return $this->secureJson(['data' => $this->serializeNotification($notification)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getAuthUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $notification = $this->notificationRepository->find($id);
        if (!$notification || $notification->recipient?->id !== $user->id) {
            return $this->secureJson([
                'error' => $this->trans('notification.not_found', [], 'Notification not found.'),
                'code' => 'NOTIFICATION_NOT_FOUND',
            ], 404);
        }

        $em = $this->notificationRepository->getEntityManager();
        $em->remove($notification);
        $em->flush();

        return $this->secureJson(null, 204);
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    public function readAll(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getAuthUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $projectFilter = $this->resolveProjectFilter($request, $user);
        if ($projectFilter instanceof JsonResponse) {
            return $projectFilter;
        }

        if ($projectFilter !== null) {
            $this->notificationRepository->markAllAsReadForProject($user, $projectFilter);
        } else {
            $this->notificationRepository->markAllAsRead($user);
        }

        return $this->secureJson(['unread_count' => 0]);
    }

    #[Route('/batch-read', name: 'batch_read', methods: ['POST'])]
    public function batchRead(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getAuthUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $ids = $this->extractAndValidateBatchIds($request);
        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $updatedCount = $this->notificationRepository->markBatchAsRead($user, $ids);
        $unreadCount = $this->notificationRepository->countUnreadByUser($user);

        return $this->secureJson([
            'success' => true,
            'updated' => $updatedCount,
            'unread_count' => $unreadCount,
            'message' => $this->trans('notification.batch_read_success', [], 'Notifications marked as read.'),
        ]);
    }

    #[Route('/batch-delete', name: 'batch_delete', methods: ['POST'])]
    public function batchDelete(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getAuthUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $ids = $this->extractAndValidateBatchIds($request);
        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $deletedCount = $this->notificationRepository->deleteBatch($user, $ids);

        return $this->secureJson([
            'success' => true,
            'deleted' => $deletedCount,
            'message' => $this->trans('notification.batch_delete_success', [], 'Notifications successfully deleted.'),
        ]);
    }

    #[Route('/prune', name: 'prune', methods: ['POST'])]
    public function prune(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getAuthUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('notification.payload_too_large', [], 'Notification request payload too large.'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        $days = 30;
        if ($request->getContent() !== '') {
            try {
                $payload = $request->toArray();
                if (isset($payload['days'])) {
                    if (!is_numeric($payload['days'])) {
                        return $this->secureJson([
                            'error' => $this->trans('notification.invalid_days', [], 'Retention period in days must be an integer between 1 and 365.'),
                            'code' => 'INVALID_DAYS',
                        ], 422);
                    }
                    $days = (int) $payload['days'];
                }
            } catch (\Throwable) {
                return $this->secureJson([
                    'error' => $this->trans('notification.invalid_json', [], 'Invalid JSON payload.'),
                    'code' => 'INVALID_JSON',
                ], 400);
            }
        } elseif ($request->query->has('days')) {
            $days = $request->query->getInt('days', 30);
        }

        if ($days < 1 || $days > 365) {
            return $this->secureJson([
                'error' => $this->trans('notification.invalid_days', [], 'Retention period in days must be an integer between 1 and 365.'),
                'code' => 'INVALID_DAYS',
            ], 422);
        }

        $projectFilter = $this->resolveProjectFilter($request, $user);
        if ($projectFilter instanceof JsonResponse) {
            return $projectFilter;
        }

        $threshold = new \DateTimeImmutable(sprintf('-%d days', $days));
        $prunedCount = $this->notificationRepository->pruneReadOlderThanForUser($user, $threshold, $projectFilter);

        return $this->secureJson([
            'success' => true,
            'pruned' => $prunedCount,
            'days' => $days,
            'message' => $this->trans('notification.pruned_success', [], 'Old notifications successfully pruned.'),
        ]);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    public function unreadCount(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getAuthUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $projectFilter = $this->resolveProjectFilter($request, $user);
        if ($projectFilter instanceof JsonResponse) {
            return $projectFilter;
        }

        $count = $projectFilter !== null
            ? $this->notificationRepository->countUnreadByUserAndProject($user, $projectFilter)
            : $this->notificationRepository->countUnreadByUser($user);

        return $this->secureJson(['count' => $count]);
    }

    /**
     * Extrait et valide strictement une liste bornée d'identifiants de notifications.
     *
     * @return int[]|JsonResponse
     */
    private function extractAndValidateBatchIds(Request $request): array|JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('notification.payload_too_large', [], 'Notification request payload too large.'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('notification.invalid_json', [], 'Invalid JSON payload.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        $rawIds = $data['ids'] ?? null;
        if (!is_array($rawIds) || empty($rawIds)) {
            return $this->secureJson([
                'error' => $this->trans('notification.batch_empty', [], 'Notification batch identifiers list cannot be empty.'),
                'code' => 'BATCH_EMPTY',
            ], 422);
        }

        if (count($rawIds) > self::BATCH_MAX_IDS) {
            return $this->secureJson([
                'error' => $this->trans('notification.batch_too_large', ['%max%' => (string) self::BATCH_MAX_IDS], sprintf('Notification batch size exceeds maximum allowed limit (%d).', self::BATCH_MAX_IDS)),
                'code' => 'BATCH_TOO_LARGE',
            ], 422);
        }

        $validIds = [];
        foreach ($rawIds as $id) {
            if (!is_int($id) && (!is_string($id) || !ctype_digit($id))) {
                return $this->secureJson([
                    'error' => $this->trans('notification.invalid_id_list', [], 'Invalid notification identifiers provided.'),
                    'code' => 'INVALID_ID_LIST',
                ], 422);
            }
            $intId = (int) $id;
            if ($intId <= 0) {
                return $this->secureJson([
                    'error' => $this->trans('notification.invalid_id_list', [], 'Invalid notification identifiers provided.'),
                    'code' => 'INVALID_ID_LIST',
                ], 422);
            }
            $validIds[] = $intId;
        }

        return array_values(array_unique($validIds));
    }

    private function resolveProjectFilter(Request $request, User $user): Project|JsonResponse|null
    {
        $projectParam = $request->query->get('project') ?? $request->query->get('project_uuid');
        if (empty($projectParam) || !is_string($projectParam)) {
            return null;
        }

        $trimmedParam = trim($projectParam);
        if (!preg_match(self::UUID_REGEX, $trimmedParam)) {
            return $this->secureJson([
                'error' => $this->trans('notification.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if ($this->projectRepository === null) {
            return null;
        }

        $project = $this->projectRepository->findOneBy(['uuid' => $trimmedParam]);
        if (!$project) {
            return $this->secureJson([
                'error' => $this->trans('notification.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return $project;
        }

        if ($this->memberRepository !== null) {
            $member = $this->memberRepository->findActiveByUserAndProject($user, $project);
            if ($member === null) {
                return $this->secureJson([
                    'error' => $this->trans('notification.access_denied', [], 'Forbidden: You do not have access to this project.'),
                    'code' => 'ACCESS_DENIED',
                ], 403);
            }
        }

        return $project;
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->notificationLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            $limit = $this->notificationLimiter->create($identifier)->consume(1);
            if (!$limit->isAccepted()) {
                $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
                return $this->secureJson([
                    'error' => $this->trans('notification.rate_limit_exceeded', [], 'Too many notification requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                    'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                    'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
                ]);
            }
        }
        return null;
    }

    private function getAuthUser(): User|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson([
                'error' => $this->trans('notification.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }
        return $user;
    }

    private function sanitizeLink(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }
        $trimmed = trim($link);
        if (preg_match('/^(javascript|data|vbscript|file):/i', $trimmed)) {
            return null;
        }
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, 'http://')) {
            return $trimmed;
        }
        return null;
    }

    private function serializeNotification($n): array
    {
        $cleanTitle = strip_tags((string) $n->title);
        $cleanBody = $n->body !== null ? strip_tags((string) $n->body, ['b', 'strong', 'i', 'em', 'code', 'p', 'br', 'a']) : null;

        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $cleanTitle,
            'body' => $cleanBody,
            'link' => $this->sanitizeLink($n->link),
            'read_at' => $n->readAt?->format('c'),
            'created_at' => $n->createdAt->format('c'),
        ];
    }
}
