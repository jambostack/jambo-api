<?php

declare(strict_types=1);

namespace App\Service\Mercure;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

class MercureTopicSecurityService
{
    public const MAX_TOPICS_PER_REQUEST = 20;
    public const MAX_TOPIC_LENGTH = 255;
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB

    private const ALLOWED_TOPIC_REGEX = '/^projects\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(\/[a-zA-Z0-9_\-\.]{1,64})*$/';

    public function __construct(
        private readonly ProjectMemberRepository $memberRepo,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    /**
     * Returns default topics for a project.
     *
     * @return string[]
     */
    public function getDefaultTopics(string $projectUuid): array
    {
        return [
            "projects/{$projectUuid}",
            "projects/{$projectUuid}/content",
            "projects/{$projectUuid}/media",
            "projects/{$projectUuid}/status",
        ];
    }

    /**
     * Validates and returns sanitized topics for a specific project.
     *
     * @param string[]|string|null $topics
     * @return array{topics: string[], error?: string}
     */
    public function validateAndFilterTopics(string $projectUuid, $topics): array
    {
        if ($topics === null || $topics === '' || $topics === []) {
            return ['topics' => $this->getDefaultTopics($projectUuid)];
        }

        if (is_string($topics)) {
            $topics = array_filter(array_map('trim', explode(',', $topics)));
        }

        if (!is_array($topics)) {
            return ['topics' => [], 'error' => $this->trans('realtime.invalid_topic_format', [], 'Topics must be an array or comma-separated list.')];
        }

        if (count($topics) > self::MAX_TOPICS_PER_REQUEST) {
            return ['topics' => [], 'error' => $this->trans('realtime.too_many_topics', ['%max%' => self::MAX_TOPICS_PER_REQUEST], 'Too many topics requested (max ' . self::MAX_TOPICS_PER_REQUEST . ').')];
        }

        $sanitized = [];
        $expectedPrefix = "projects/{$projectUuid}";

        foreach ($topics as $topic) {
            if (!is_string($topic)) {
                return ['topics' => [], 'error' => $this->trans('realtime.invalid_topic_format', [], 'Each topic must be a string.')];
            }

            $topic = trim($topic);
            if ($topic === '') {
                continue;
            }

            if (strlen($topic) > self::MAX_TOPIC_LENGTH) {
                return ['topics' => [], 'error' => $this->trans('realtime.topic_too_long', ['%max%' => self::MAX_TOPIC_LENGTH], 'Topic exceeds maximum length.')];
            }

            // Reject dangerous characters, wildcards, directory traversal, null bytes
            if (
                str_contains($topic, '..') ||
                str_contains($topic, "\0") ||
                str_contains($topic, '\\') ||
                str_contains($topic, '*') ||
                str_contains($topic, '?') ||
                str_contains($topic, '#') ||
                str_contains($topic, '+') ||
                str_contains($topic, '//')
            ) {
                return ['topics' => [], 'error' => $this->trans('realtime.invalid_topic', [], 'Topic contains illegal characters or wildcards.')];
            }

            // Enforce structure regex
            if (!preg_match(self::ALLOWED_TOPIC_REGEX, $topic)) {
                return ['topics' => [], 'error' => $this->trans('realtime.invalid_topic', [], 'Topic format is invalid.')];
            }

            // Strict multi-tenant isolation: topic must belong to this project
            if ($topic !== $expectedPrefix && !str_starts_with($topic, $expectedPrefix . '/')) {
                return ['topics' => [], 'error' => $this->trans('realtime.topic_access_denied', [], 'Cross-tenant topic subscription is forbidden.')];
            }

            $sanitized[] = $topic;
        }

        $unique = array_values(array_unique($sanitized));
        if (empty($unique)) {
            $unique = $this->getDefaultTopics($projectUuid);
        }

        return ['topics' => $unique];
    }

    /**
     * Determines whether the given user has publish permissions on the project.
     */
    public function canUserPublish(?User $user, Project $project): bool
    {
        if ($user === null) {
            return false;
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) || in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $member = $this->memberRepo->findActiveByUserAndProject($user, $project);
        if ($member === null) {
            return false;
        }

        $role = $member->role;
        if ($role === null) {
            return false;
        }

        // Project owners/managers/editors can publish
        $roleName = $role instanceof \App\Entity\Role ? $role->name : (is_string($role) ? $role : '');
        return in_array(strtolower($roleName), ['owner', 'admin', 'manager', 'editor'], true);
    }
}
