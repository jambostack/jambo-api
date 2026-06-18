<?php

namespace App\Controller;

use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notifications', name: 'api_notifications_')]
class NotificationController extends AbstractController
{
    public function __construct(private NotificationRepository $notificationRepository) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(50, max(1, $request->query->getInt('per_page', 20)));
        $unreadOnly = $request->query->getBoolean('unread_only');
        $user = $this->getUser();
        $notifications = $this->notificationRepository->findByRecipientPaginated($user, $page, $perPage, $unreadOnly);
        $unreadCount = $this->notificationRepository->countUnreadByUser($user);
        return $this->json([
            'data' => array_map(fn ($n) => $this->serializeNotification($n), $notifications),
            'meta' => ['unread_count' => $unreadCount],
        ]);
    }

    #[Route('/{id}/read', name: 'read', methods: ['POST'])]
    public function read(int $id): JsonResponse
    {
        $notification = $this->notificationRepository->find($id);
        if (!$notification || $notification->recipient->id !== $this->getUser()->id) {
            return $this->json(['error' => 'Not found.'], 404);
        }
        if ($notification->readAt === null) {
            $notification->readAt = new \DateTimeImmutable();
            $this->notificationRepository->getEntityManager()->flush();
        }
        return $this->json(['data' => $this->serializeNotification($notification)]);
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    public function readAll(): JsonResponse
    {
        $this->notificationRepository->markAllAsRead($this->getUser());
        return $this->json(['unread_count' => 0]);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        return $this->json(['count' => $this->notificationRepository->countUnreadByUser($this->getUser())]);
    }

    private function serializeNotification($n): array
    {
        return [
            'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body,
            'link' => $n->link, 'read_at' => $n->readAt?->format('c'),
            'created_at' => $n->createdAt->format('c'),
        ];
    }
}
