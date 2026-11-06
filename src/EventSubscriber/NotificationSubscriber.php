<?php

namespace App\EventSubscriber;

use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\User;
use App\Event\ContentEvent;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private ?NotificationService $notificationService = null,
        private ?\Symfony\Bundle\SecurityBundle\Security $security = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ContentEvent::UPDATED => 'onContentUpdated',
        ];
    }

    public function onContentUpdated(ContentEvent $event): void
    {
        $entry = $event->entry;
        $project = $event->project;

        if ($entry->assignedTo && $entry->status !== 'draft') {
            // Ne pas auto-notifier l'utilisateur s'il est l'auteur de la modification
            $author = $entry->updatedBy ?? $this->security?->getUser();
            $authorId = $author?->id ?? (is_object($author) && method_exists($author, 'getId') ? $author->getId() : null);
            if ($authorId !== null && $entry->assignedTo->id === $authorId) {
                return;
            }

            // Ne pas notifier si le statut antérieur est renseigné et inchangé
            if ($event->previousStatus !== '' && $event->previousStatus === $entry->status) {
                return;
            }

            if ($this->notificationService !== null) {
                $this->notificationService->notifyStatusChange(
                    recipient: $entry->assignedTo,
                    status: (string) $entry->status,
                    contentLocale: (string) ($entry->locale ?? 'default'),
                    project: $project,
                    collectionId: $entry->collection?->id ?? 0,
                    entryId: (int) $entry->id,
                );
            } else {
                $link = sprintf('/projects/%s/collections/%d/content/%d', $project->uuid?->toRfc4122() ?? (string)$project->id, $entry->collection?->id ?? 0, $entry->id);
                $this->createNotification(
                    'status_change',
                    "Status changed to « {$entry->status} »",
                    "Entry « {$entry->locale} » is now in {$entry->status}.",
                    $link,
                    $entry->assignedTo
                );
            }
        }
    }

    public function onCommentCreated(Comment $comment): void
    {
        $entry = $comment->entry;
        $project = $entry->project;
        $collectionId = $entry->collection?->id ?? 0;
        $entryId = (int) $entry->id;
        $author = $comment->author;

        if ($this->notificationService !== null) {
            // Notify assigned user
            if ($entry->assignedTo && $entry->assignedTo->id !== $author->id) {
                $this->notificationService->notifyComment(
                    recipient: $entry->assignedTo,
                    author: $author,
                    commentBody: $comment->body,
                    project: $project,
                    collectionId: $collectionId,
                    entryId: $entryId,
                    isAssigned: true,
                );
            }

            // Notify entry creator
            if ($entry->createdBy && $entry->createdBy->id !== $author->id && $entry->createdBy->id !== $entry->assignedTo?->id) {
                $this->notificationService->notifyComment(
                    recipient: $entry->createdBy,
                    author: $author,
                    commentBody: $comment->body,
                    project: $project,
                    collectionId: $collectionId,
                    entryId: $entryId,
                    isAssigned: false,
                );
            }

            // Mentions @username
            preg_match_all('/@(\w+)/', $comment->body, $matches);
            $notifiedUserIds = [$author->id];
            foreach ($matches[1] as $username) {
                $mentioned = $this->userRepository->findOneBy(['name' => $username]);
                if ($mentioned && !in_array($mentioned->id, $notifiedUserIds, true)) {
                    $notifiedUserIds[] = $mentioned->id;
                    $this->notificationService->notifyMention(
                        recipient: $mentioned,
                        author: $author,
                        commentBody: $comment->body,
                        project: $project,
                        collectionId: $collectionId,
                        entryId: $entryId,
                    );
                }
            }
        } else {
            $link = sprintf('/projects/%s/collections/%d/content/%d', $project->uuid?->toRfc4122() ?? (string)$project->id, $collectionId, $entryId);
            $authorName = $author->name ?? $author->email;

            if ($entry->assignedTo && $entry->assignedTo->id !== $author->id) {
                $this->createNotification('comment', "$authorName commented on an assigned entry", $this->excerpt($comment->body), $link, $entry->assignedTo);
            }
            if ($entry->createdBy && $entry->createdBy->id !== $author->id && $entry->createdBy->id !== $entry->assignedTo?->id) {
                $this->createNotification('comment', "$authorName commented on your entry", $this->excerpt($comment->body), $link, $entry->createdBy);
            }
            preg_match_all('/@(\w+)/', $comment->body, $matches);
            foreach ($matches[1] as $username) {
                $mentioned = $this->userRepository->findOneBy(['name' => $username]);
                if ($mentioned && $mentioned->id !== $author->id) {
                    $this->createNotification('mention', "$authorName mentioned you", $this->excerpt($comment->body), $link, $mentioned);
                }
            }
        }
    }

    public function createNotification(string $type, string $title, ?string $body, string $link, User $recipient): void
    {
        if ($this->notificationService !== null) {
            $this->notificationService->create($recipient, $type, $title, $body, $link);
            return;
        }

        $n = new Notification();
        $n->type = $type;
        $n->title = $title;
        $n->body = $body;
        $n->link = $link;
        $n->recipient = $recipient;
        $this->em->persist($n);
        $this->em->flush();
    }

    private function excerpt(string $text, int $max = 100): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}

