<?php

namespace App\Controller;

use App\Entity\Project;
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
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

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

        return $this->json([
            'data' => array_map(fn ($n) => $this->serializeNotification($n), $notifications),
            'meta' => ['unread_count' => $unreadCount],
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
            return $this->json([
                'error' => $this->trans('notification.not_found', [], 'Notification not found.'),
                'code' => 'NOTIFICATION_NOT_FOUND',
            ], 404);
        }
        if ($notification->readAt === null) {
            $notification->readAt = new \DateTimeImmutable();
            $this->notificationRepository->getEntityManager()->flush();
        }
        return $this->json(['data' => $this->serializeNotification($notification)]);
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
            return $this->json([
                'error' => $this->trans('notification.not_found', [], 'Notification not found.'),
                'code' => 'NOTIFICATION_NOT_FOUND',
            ], 404);
        }

        $em = $this->notificationRepository->getEntityManager();
        $em->remove($notification);
        $em->flush();

        return $this->json(null, 204);
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

        return $this->json(['unread_count' => 0]);
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

        return $this->json(['count' => $count]);
    }

    private function resolveProjectFilter(Request $request, \App\Entity\User $user): Project|JsonResponse|null
    {
        $projectParam = $request->query->get('project') ?? $request->query->get('project_uuid');
        if (empty($projectParam) || !is_string($projectParam)) {
            return null;
        }

        $trimmedParam = trim($projectParam);
        if (!preg_match(self::UUID_REGEX, $trimmedParam)) {
            return $this->json([
                'error' => $this->trans('notification.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if ($this->projectRepository === null) {
            return null;
        }

        $project = $this->projectRepository->findOneBy(['uuid' => $trimmedParam]);
        if (!$project) {
            return $this->json([
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
                return $this->json([
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

            if (!$this->notificationLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->json([
                    'error' => $this->trans('notification.rate_limit_exceeded', [], 'Too many notification requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    private function getAuthUser(): \App\Entity\User|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json([
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
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, 'http://')) {
            return $trimmed;
        }
        return null;
    }

    private function serializeNotification($n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $this->sanitizeLink($n->link),
            'read_at' => $n->readAt?->format('c'),
            'created_at' => $n->createdAt->format('c'),
        ];
    }
}
