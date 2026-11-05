<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

class NotificationService
{
    public const TYPE_COMMENT = 'comment';
    public const TYPE_MENTION = 'mention';
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_AUTOMATION = 'automation';
    public const TYPE_SYSTEM = 'system';

    public const MAX_USER_NOTIFICATIONS = 1000;
    public const MAX_USER_UNREAD_NOTIFICATIONS = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?NotificationRepository $notificationRepository = null,
    ) {}

    /**
     * Crée et persiste une notification pour un utilisateur destinataire.
     */
    public function create(
        User $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?Project $project = null,
        bool $flush = true,
    ): Notification {
        // Enforce per-user notification volume quotas and prune oldest when exceeded
        if ($this->notificationRepository !== null) {
            $currentCount = $this->notificationRepository->countByRecipient($recipient);
            if ($currentCount >= self::MAX_USER_NOTIFICATIONS) {
                $this->notificationRepository->pruneOldestForUser($recipient, self::MAX_USER_NOTIFICATIONS - 1);
            }
        }

        $cleanTitle = $this->sanitizeTitle($title);
        $cleanBody = $body !== null ? $this->sanitizeBody($body) : null;

        $notification = new Notification();
        $notification->recipient = $recipient;
        $notification->type = $this->sanitizeType($type);
        $notification->title = $cleanTitle !== '' ? $cleanTitle : $this->trans('notification.default_title', [], $recipient->locale ?? 'en', 'Notification');
        $notification->body = $cleanBody;
        $notification->link = $this->sanitizeLink($link, $project);

        $this->em->persist($notification);

        if ($flush) {
            $this->em->flush();
        }

        return $notification;
    }

    /**
     * Notifie la personne assignée lors d'un changement de statut de contenu.
     */
    public function notifyStatusChange(
        User $recipient,
        string $status,
        string $contentLocale,
        ?Project $project,
        int $collectionId,
        int $entryId,
    ): ?Notification {
        $locale = $recipient->locale ?? 'en';

        $title = $this->trans(
            id: 'notification.status_change_title',
            parameters: ['%status%' => $status],
            locale: $locale,
            default: sprintf('Status changed to "%s"', $status)
        );

        $body = $this->trans(
            id: 'notification.status_change_body',
            parameters: [
                '%locale%' => $contentLocale,
                '%status%' => $status,
            ],
            locale: $locale,
            default: sprintf('Entry "%s" is now in status "%s".', $contentLocale, $status)
        );

        $link = $this->buildContentLink($project, $collectionId, $entryId);

        return $this->create(
            recipient: $recipient,
            type: self::TYPE_STATUS_CHANGE,
            title: $title,
            body: $body,
            link: $link,
            project: $project
        );
    }

    /**
     * Notifie un utilisateur lors de la publication d'un commentaire.
     */
    public function notifyComment(
        User $recipient,
        User $author,
        string $commentBody,
        ?Project $project,
        int $collectionId,
        int $entryId,
        bool $isAssigned = false,
    ): ?Notification {
        if ($recipient->id !== null && $recipient->id === $author->id) {
            return null; // Pas d'auto-notification
        }

        $locale = $recipient->locale ?? 'en';
        $authorName = $author->name ?: ($author->email ?: 'A user');
        $excerpt = $this->excerpt($commentBody, 120);

        if ($isAssigned) {
            $title = $this->trans(
                id: 'notification.comment_assigned_title',
                parameters: ['%author%' => $authorName],
                locale: $locale,
                default: sprintf('%s commented on an assigned entry', $authorName)
            );
            $body = $this->trans(
                id: 'notification.comment_assigned_body',
                parameters: ['%author%' => $authorName, '%excerpt%' => $excerpt],
                locale: $locale,
                default: sprintf('%s commented on your assigned entry: "%s"', $authorName, $excerpt)
            );
        } else {
            $title = $this->trans(
                id: 'notification.comment_created_title',
                parameters: ['%author%' => $authorName],
                locale: $locale,
                default: sprintf('%s commented on your entry', $authorName)
            );
            $body = $this->trans(
                id: 'notification.comment_created_body',
                parameters: ['%author%' => $authorName, '%excerpt%' => $excerpt],
                locale: $locale,
                default: sprintf('%s commented: "%s"', $authorName, $excerpt)
            );
        }

        $link = $this->buildContentLink($project, $collectionId, $entryId);

        return $this->create(
            recipient: $recipient,
            type: self::TYPE_COMMENT,
            title: $title,
            body: $body,
            link: $link,
            project: $project
        );
    }

    /**
     * Notifie un utilisateur lorsqu'il est mentionné dans un commentaire (@username).
     */
    public function notifyMention(
        User $recipient,
        User $author,
        string $commentBody,
        ?Project $project,
        int $collectionId,
        int $entryId,
    ): ?Notification {
        if ($recipient->id !== null && $recipient->id === $author->id) {
            return null; // Pas d'auto-notification
        }

        $locale = $recipient->locale ?? 'en';
        $authorName = $author->name ?: ($author->email ?: 'A user');
        $excerpt = $this->excerpt($commentBody, 120);

        $title = $this->trans(
            id: 'notification.mention_title',
            parameters: ['%author%' => $authorName],
            locale: $locale,
            default: sprintf('%s mentioned you', $authorName)
        );

        $body = $this->trans(
            id: 'notification.mention_body',
            parameters: ['%author%' => $authorName, '%excerpt%' => $excerpt],
            locale: $locale,
            default: sprintf('%s mentioned you in a comment: "%s"', $authorName, $excerpt)
        );

        $link = $this->buildContentLink($project, $collectionId, $entryId);

        return $this->create(
            recipient: $recipient,
            type: self::TYPE_MENTION,
            title: $title,
            body: $body,
            link: $link,
            project: $project
        );
    }

    /**
     * Notifie un utilisateur suite à une action de workflow d'automatisation.
     */
    public function notifyAutomation(
        User $recipient,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?Project $project = null,
    ): Notification {
        $locale = $recipient->locale ?? 'en';

        $resolvedTitle = !empty(trim($title))
            ? $title
            : $this->trans('notification.automation_title', [], $locale, 'Automation notification');

        $resolvedBody = $body !== null && !empty(trim($body))
            ? $body
            : $this->trans('notification.automation_body', [], $locale, 'Notification generated by an automation workflow.');

        $resolvedLink = $link ?? ($project !== null ? $this->buildProjectLink($project) : '');

        return $this->create(
            recipient: $recipient,
            type: self::TYPE_AUTOMATION,
            title: $resolvedTitle,
            body: $resolvedBody,
            link: $resolvedLink,
            project: $project
        );
    }

    public function trans(string $id, array $parameters = [], ?string $locale = null, string $default = ''): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages', $locale);
            if ($translated !== $id) {
                return $translated;
            }
        }

        if ($default !== '') {
            return strtr($default, $parameters);
        }

        return $id;
    }

    private function sanitizeType(string $type): string
    {
        $validTypes = [
            self::TYPE_COMMENT,
            self::TYPE_MENTION,
            self::TYPE_ASSIGNMENT,
            self::TYPE_STATUS_CHANGE,
            self::TYPE_AUTOMATION,
            self::TYPE_SYSTEM,
        ];

        return in_array($type, $validTypes, true) ? $type : self::TYPE_COMMENT;
    }

    private function sanitizeTitle(string $title): string
    {
        // Strip control characters
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $title) ?? '';
        // Strip dangerous tags with their contents first
        $clean = preg_replace('/<\s*(script|style|iframe|object|embed|svg|math|form)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $clean) ?? '';
        $clean = preg_replace('/<\s*(script|style|iframe|object|embed|svg|math|form)[^>]*\/?\s*>/is', '', $clean) ?? '';
        // Strip any remaining HTML tags from titles
        $clean = strip_tags($clean);
        return $this->truncate(trim($clean), 255);
    }

    private function sanitizeBody(string $body): string
    {
        // Strip control characters
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        // Strip dangerous tags and inline scripts
        $clean = preg_replace('/<\s*(script|style|iframe|object|embed|svg|math|form)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $clean) ?? '';
        $clean = preg_replace('/<\s*(script|style|iframe|object|embed|svg|math|form)[^>]*\/?\s*>/is', '', $clean) ?? '';
        // Strip event handlers
        $clean = preg_replace('/\s+\bon[a-zA-Z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/\bon[a-zA-Z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/<([a-zA-Z0-9]+)\s+>/', '<$1>', $clean) ?? '';
        // Strip dangerous protocols
        $clean = preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|data|vbscript|file):[^\2]*?\2/i', '', $clean) ?? '';
        // Allow only safe formatting tags or strip all tags
        $clean = strip_tags($clean, ['b', 'strong', 'i', 'em', 'code', 'p', 'br', 'a']);
        return $this->truncate(trim($clean), 2000);
    }

    private function sanitizeLink(?string $link, ?Project $project = null): string
    {
        if ($link === null || trim($link) === '') {
            return $project !== null ? $this->buildProjectLink($project) : '';
        }

        $trimmed = trim($link);

        // Bloquer les protocoles dangereux
        if (preg_match('/^(javascript|data|vbscript|file):/i', $trimmed)) {
            return $project !== null ? $this->buildProjectLink($project) : '';
        }

        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, 'http://')) {
            return $this->truncate($trimmed, 500);
        }

        return '/' . ltrim($this->truncate($trimmed, 499), '/');
    }

    private function buildContentLink(?Project $project, int $collectionId, int $entryId): string
    {
        $projectIdentifier = $project?->uuid?->toRfc4122() ?? ($project?->id ? (string) $project->id : 'default');
        return sprintf('/projects/%s/collections/%d/content/%d', $projectIdentifier, $collectionId, $entryId);
    }

    private function buildProjectLink(Project $project): string
    {
        $projectIdentifier = $project->uuid?->toRfc4122() ?? ($project->id ? (string) $project->id : 'default');
        return sprintf('/projects/%s', $projectIdentifier);
    }

    private function excerpt(string $text, int $max = 100): string
    {
        $clean = strip_tags(trim($text));
        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max) . '…' : $clean;
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
