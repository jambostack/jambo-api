<?php

namespace App\EventSubscriber;

use App\Entity\Comment;
use App\Entity\Notification;
use App\Entity\User;
use App\Event\ContentEvent;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
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
        $link = sprintf('/projects/%d/collections/%d/content/%d', $project->getId(), $entry->collection->getId(), $entry->getId());

        if ($entry->assignedTo && $entry->status !== 'draft') {
            $this->createNotification('status_change', "Statut changé en « {$entry->status} »", "L'entrée « {$entry->locale} » est maintenant en {$entry->status}.", $link, $entry->assignedTo);
        }
    }

    public function onCommentCreated(Comment $comment): void
    {
        $entry = $comment->entry;
        $project = $entry->project;
        $link = sprintf('/projects/%d/collections/%d/content/%d', $project->getId(), $entry->collection->getId(), $entry->getId());
        $authorName = $comment->author->name ?? $comment->author->email;

        // Notify assigned user
        if ($entry->assignedTo && $entry->assignedTo->id !== $comment->author->id) {
            $this->createNotification('comment', "$authorName a commenté une entrée assignée", $this->excerpt($comment->body), $link, $entry->assignedTo);
        }
        // Notify entry creator
        if ($entry->createdBy && $entry->createdBy->id !== $comment->author->id && $entry->createdBy->id !== $entry->assignedTo?->id) {
            $this->createNotification('comment', "$authorName a commenté votre entrée", $this->excerpt($comment->body), $link, $entry->createdBy);
        }
        // Mentions @username
        preg_match_all('/@(\w+)/', $comment->body, $matches);
        foreach ($matches[1] as $username) {
            $mentioned = $this->userRepository->findOneBy(['name' => $username]);
            if ($mentioned && $mentioned->id !== $comment->author->id) {
                $this->createNotification('mention', "$authorName vous a mentionné", $this->excerpt($comment->body), $link, $mentioned);
            }
        }
    }

    private function createNotification(string $type, string $title, ?string $body, string $link, User $recipient): void
    {
        $n = new Notification();
        $n->type = $type; $n->title = $title; $n->body = $body; $n->link = $link; $n->recipient = $recipient;
        $this->em->persist($n);
        $this->em->flush();
    }

    private function excerpt(string $text, int $max = 100): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
