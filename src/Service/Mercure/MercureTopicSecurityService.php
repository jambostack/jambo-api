<?php

declare(strict_types=1);

namespace App\Service\Mercure;

use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Contracts\Translation\TranslatorInterface;

class MercureTopicSecurityService
{
    public const MAX_TOPICS_PER_REQUEST = 20;
    public const MAX_TOPIC_LENGTH = 255;
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    public const MAX_TOPIC_DEPTH = 5;

    private const ALLOWED_TOPIC_REGEX = '/^projects\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(\/[a-zA-Z0-9_\-\.]{1,64}){0,5}$/i';

    public function __construct(
        private readonly ProjectMemberRepository $memberRepo,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?CollectionRepository $collectionRepo = null,
        private readonly ?ProjectRepository $projectRepo = null,
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
        $safeUuid = strtolower(trim($projectUuid));
        return [
            "projects/{$safeUuid}",
            "projects/{$safeUuid}/content",
            "projects/{$safeUuid}/media",
            "projects/{$safeUuid}/status",
        ];
    }

    /**
     * Validates and returns sanitized topics for a specific project.
     *
     * @param string[]|string|null $topics
     * @return array{topics: string[], error?: string, code?: string}
     */
    public function validateAndFilterTopics(string $projectUuid, $topics, User|ApiToken|null $actor = null): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectUuid)) {
            return [
                'topics' => [],
                'error'  => $this->trans('realtime.invalid_project_uuid', [], 'Invalid project UUID format.'),
                'code'   => 'INVALID_PROJECT_UUID',
            ];
        }

        $projectUuid = strtolower(trim($projectUuid));

        if ($topics === null || $topics === '' || $topics === []) {
            return ['topics' => $this->getDefaultTopics($projectUuid)];
        }

        if (is_string($topics)) {
            $topics = array_filter(array_map('trim', explode(',', $topics)));
        }

        if (!is_array($topics)) {
            return [
                'topics' => [],
                'error'  => $this->trans('realtime.invalid_topic_format', [], 'Topics must be an array or comma-separated list.'),
                'code'   => 'INVALID_TOPIC_FORMAT',
            ];
        }

        if (count($topics) > self::MAX_TOPICS_PER_REQUEST) {
            return [
                'topics' => [],
                'error'  => $this->trans('realtime.too_many_topics', ['%max%' => self::MAX_TOPICS_PER_REQUEST], 'Too many topics requested (max ' . self::MAX_TOPICS_PER_REQUEST . ').'),
                'code'   => 'TOO_MANY_TOPICS',
            ];
        }

        if ($actor instanceof ApiToken) {
            if (!$actor->can('*') && !$actor->can('read') && !$actor->can('realtime.subscribe')) {
                return [
                    'topics' => [],
                    'error'  => $this->trans('realtime.topic_access_denied', [], 'Cross-tenant topic subscription is forbidden.'),
                    'code'   => 'FORBIDDEN',
                ];
            }
        }

        $sanitized = [];
        $expectedPrefix = "projects/{$projectUuid}";

        foreach ($topics as $topic) {
            if (!is_string($topic)) {
                return [
                    'topics' => [],
                    'error'  => $this->trans('realtime.invalid_topic_format', [], 'Each topic must be a string.'),
                    'code'   => 'INVALID_TOPIC_FORMAT',
                ];
            }

            // Reject control characters or null bytes early
            if (preg_match('/[\x00-\x1F\x7F]/', $topic)) {
                return [
                    'topics' => [],
                    'error'  => $this->trans('realtime.null_byte_detected', [], 'Null bytes or control characters are strictly forbidden in realtime requests.'),
                    'code'   => 'NULL_BYTE_DETECTED',
                ];
            }

            $topic = trim($topic);
            if ($topic === '') {
                continue;
            }

            if (strlen($topic) > self::MAX_TOPIC_LENGTH) {
                return [
                    'topics' => [],
                    'error'  => $this->trans('realtime.topic_too_long', ['%max%' => self::MAX_TOPIC_LENGTH], 'Topic exceeds maximum length.'),
                    'code'   => 'TOPIC_TOO_LONG',
                ];
            }

            // Reject dangerous characters, wildcards, directory traversal, URL encoding, null bytes
            if (
                str_contains($topic, '..') ||
                str_contains($topic, "\0") ||
                str_contains($topic, '\\') ||
                str_contains($topic, '*') ||
                str_contains($topic, '?') ||
                str_contains($topic, '#') ||
                str_contains($topic, '+') ||
                str_contains($topic, '%') ||
                str_contains($topic, '//')
            ) {
                return [
                    'topics' => [],
                    'error'  => $this->trans('realtime.invalid_topic', [], 'Topic contains illegal characters or wildcards.'),
                    'code'   => 'ILLEGAL_CHARACTERS',
                ];
            }

            // Check topic segment nesting depth
            $slashCount = substr_count($topic, '/');
            if ($slashCount > (1 + self::MAX_TOPIC_DEPTH)) {
                return [
                    'topics' => [],
                    'error'  => $this->trans('realtime.topic_depth_exceeded', [], 'Topic hierarchy exceeds maximum allowed depth.'),
                    'code'   => 'TOPIC_DEPTH_EXCEEDED',
                ];
            }

            // Enforce structure regex
            if (!preg_match(self::ALLOWED_TOPIC_REGEX, $topic)) {
                return [
                    'topics' => [],
                    'error'  => $this->trans('realtime.invalid_topic', [], 'Topic format is invalid.'),
                    'code'   => 'INVALID_TOPIC_FORMAT',
                ];
            }

            // Normalize project UUID in topic to canonical lowercase
            $canonicalTopic = 'projects/' . strtolower(substr($topic, 9, 36)) . substr($topic, 45);

            // Strict multi-tenant isolation: topic must belong to this project
            if ($canonicalTopic !== $expectedPrefix && !str_starts_with($canonicalTopic, $expectedPrefix . '/')) {
                return [
                    'topics' => [],
                    'error'  => $this->trans('realtime.topic_access_denied', [], 'Cross-tenant topic subscription is forbidden.'),
                    'code'   => 'FORBIDDEN',
                ];
            }

            // Validate subpaths (sensitive administrative topics & collection containment)
            $subPath = substr($canonicalTopic, strlen($expectedPrefix));
            if ($subPath !== '') {
                $sensitivePrefixes = ['/audit', '/security', '/system', '/members', '/settings'];
                foreach ($sensitivePrefixes as $sp) {
                    if ($subPath === $sp || str_starts_with($subPath, $sp . '/')) {
                        if ($actor !== null && !$this->isActorAdmin($actor, $projectUuid)) {
                            return [
                                'topics' => [],
                                'error'  => $this->trans('realtime.sensitive_topic_denied', [], 'Sensitive topic subscription requires administrative privileges.'),
                                'code'   => 'FORBIDDEN',
                            ];
                        }
                        break;
                    }
                }

                if (preg_match('#^/collections/([a-zA-Z0-9_\-]+)$#i', $subPath, $cm)) {
                    $collectionSlug = $cm[1];
                    if ($this->collectionRepo !== null && $this->projectRepo !== null) {
                        $proj = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
                        if ($proj !== null) {
                            $coll = $this->collectionRepo->findOneByProjectAndSlug($proj, $collectionSlug);
                            if ($coll === null) {
                                return [
                                    'topics' => [],
                                    'error'  => $this->trans('realtime.collection_not_found', ['%slug%' => $collectionSlug], 'Collection "' . $collectionSlug . '" does not exist in this project.'),
                                    'code'   => 'COLLECTION_NOT_FOUND',
                                ];
                            }
                        }
                    }
                }
            }

            $sanitized[] = $canonicalTopic;
        }

        $unique = array_values(array_unique($sanitized));
        if (empty($unique)) {
            $unique = $this->getDefaultTopics($projectUuid);
        }

        return ['topics' => $unique];
    }

    /**
     * Determines whether the given actor (User or ApiToken) has publish permissions on the project.
     */
    public function canActorPublish(User|ApiToken|null $actor, Project $project): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($actor instanceof ApiToken) {
            if ($actor->project !== null && $actor->project->uuid?->toRfc4122() === $project->uuid?->toRfc4122()) {
                return $actor->can('write') || $actor->can('create') || $actor->can('realtime.publish') || $actor->can('*');
            }
            return false;
        }

        if ($actor instanceof User) {
            if (in_array('ROLE_SUPER_ADMIN', $actor->getRoles(), true) || in_array('ROLE_ADMIN', $actor->getRoles(), true)) {
                return true;
            }

            $member = $this->memberRepo->findActiveByUserAndProject($actor, $project);
            if ($member === null) {
                return false;
            }

            $role = $member->role;
            if ($role === null) {
                return false;
            }

            if ($role instanceof Role) {
                $name = strtolower($role->name);
                if (in_array($name, ['owner', 'admin', 'manager', 'editor'], true)) {
                    return true;
                }
                if ($role->hasPermission('realtime.publish') || $role->hasPermission('content.edit') || $role->hasPermission('project.manage')) {
                    return true;
                }
                return false;
            }

            if (is_string($role)) {
                return in_array(strtolower($role), ['owner', 'admin', 'manager', 'editor'], true);
            }
        }

        return false;
    }

    public function isActorAdmin(User|ApiToken|null $actor, Project|string $project): bool
    {
        if ($actor === null) {
            return false;
        }

        $projectEntity = null;
        if ($project instanceof Project) {
            $projectEntity = $project;
        } elseif ($this->projectRepo !== null) {
            $projectEntity = $this->projectRepo->findOneBy(['uuid' => $project]);
        }

        if ($actor instanceof ApiToken) {
            if ($projectEntity !== null && $actor->project !== null && $actor->project->uuid?->toRfc4122() === $projectEntity->uuid?->toRfc4122()) {
                return $actor->can('*') || $actor->can('manage') || $actor->can('admin') || $actor->can('realtime.admin');
            }
            return false;
        }

        if ($actor instanceof User) {
            if (in_array('ROLE_SUPER_ADMIN', $actor->getRoles(), true) || in_array('ROLE_ADMIN', $actor->getRoles(), true)) {
                return true;
            }

            if ($projectEntity !== null) {
                $member = $this->memberRepo->findActiveByUserAndProject($actor, $projectEntity);
                if ($member === null) {
                    return false;
                }

                $role = $member->role;
                if ($role instanceof Role) {
                    $name = strtolower($role->name);
                    return in_array($name, ['owner', 'admin'], true) || $role->hasPermission('project.manage');
                }

                if (is_string($role)) {
                    return in_array(strtolower($role), ['owner', 'admin'], true);
                }
            }
        }

        return false;
    }

    /**
     * Determines whether the given user has publish permissions on the project.
     */
    public function canUserPublish(?User $user, Project $project): bool
    {
        return $this->canActorPublish($user, $project);
    }

    /**
     * Verifies a Mercure JWT token signature, expiration and claims for a specific project.
     *
     * @return array{valid: bool, error?: string, code?: string, claims?: array<string, mixed>}
     */
    public function verifyToken(string $tokenString, string $secret, string $projectUuid): array
    {
        $tokenString = trim($tokenString);
        if ($tokenString === '') {
            return [
                'valid' => false,
                'error' => $this->trans('realtime.token_invalid', [], 'Token cannot be empty.'),
                'code'  => 'TOKEN_INVALID',
            ];
        }

        if ($secret === '' || strlen($secret) < 32) {
            return [
                'valid' => false,
                'error' => $this->trans('realtime.token_signature_invalid', [], 'Invalid secret configuration.'),
                'code'  => 'TOKEN_SIGNATURE_INVALID',
            ];
        }

        try {
            $jwtConfig = Configuration::forSymmetricSigner(
                new Sha256(),
                InMemory::plainText($secret),
            );

            $token = $jwtConfig->parser()->parse($tokenString);

            // Verify signature
            if (!$jwtConfig->validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($secret)))) {
                return [
                    'valid' => false,
                    'error' => $this->trans('realtime.token_signature_invalid', [], 'Invalid token signature.'),
                    'code'  => 'TOKEN_SIGNATURE_INVALID',
                ];
            }

            // Verify expiration
            $now = new \DateTimeImmutable();
            if ($token->isExpired($now)) {
                return [
                    'valid' => false,
                    'error' => $this->trans('realtime.token_expired', [], 'Token has expired.'),
                    'code'  => 'TOKEN_EXPIRED',
                ];
            }

            $claims = $token->claims()->all();
            $mercureClaim = $claims['mercure'] ?? null;
            if (!is_array($mercureClaim)) {
                return [
                    'valid' => false,
                    'error' => $this->trans('realtime.token_claims_mismatch', [], 'Token is missing Mercure authorization claim.'),
                    'code'  => 'TOKEN_CLAIMS_MISMATCH',
                ];
            }

            $subscribeTopics = (array) ($mercureClaim['subscribe'] ?? []);
            $publishTopics = (array) ($mercureClaim['publish'] ?? []);
            $expectedPrefix = 'projects/' . strtolower(trim($projectUuid));

            // Ensure subscribed topics belong to the project (unless global superadmin wildcard)
            foreach ($subscribeTopics as $st) {
                if ($st === '*') {
                    continue;
                }
                if (!is_string($st) || $st === '' || preg_match('/[\x00-\x1F\x7F]/', $st) || str_contains($st, '..') || str_contains($st, '\\') || str_contains($st, '//')) {
                    return [
                        'valid' => false,
                        'error' => $this->trans('realtime.invalid_topic', [], 'Topic contains illegal characters or wildcards.'),
                        'code'  => 'ILLEGAL_CHARACTERS',
                    ];
                }
                $normSt = 'projects/' . strtolower(substr($st, 9, 36)) . substr($st, 45);
                if ($normSt !== $expectedPrefix && !str_starts_with($normSt, $expectedPrefix . '/')) {
                    return [
                        'valid' => false,
                        'error' => $this->trans('realtime.topic_access_denied', [], 'Cross-tenant topic subscription is forbidden.'),
                        'code'  => 'FORBIDDEN',
                    ];
                }
            }

            // Ensure publish topics belong to the project (unless global superadmin wildcard)
            foreach ($publishTopics as $pt) {
                if ($pt === '*') {
                    continue;
                }
                if (!is_string($pt) || $pt === '' || preg_match('/[\x00-\x1F\x7F]/', $pt) || str_contains($pt, '..') || str_contains($pt, '\\') || str_contains($pt, '//')) {
                    return [
                        'valid' => false,
                        'error' => $this->trans('realtime.invalid_topic', [], 'Topic contains illegal characters or wildcards.'),
                        'code'  => 'ILLEGAL_CHARACTERS',
                    ];
                }
                $normPt = 'projects/' . strtolower(substr($pt, 9, 36)) . substr($pt, 45);
                if ($normPt !== $expectedPrefix && !str_starts_with($normPt, $expectedPrefix . '/')) {
                    return [
                        'valid' => false,
                        'error' => $this->trans('realtime.topic_access_denied', [], 'Cross-tenant topic subscription is forbidden.'),
                        'code'  => 'FORBIDDEN',
                    ];
                }
            }

            return [
                'valid'  => true,
                'claims' => [
                    'sub'       => $claims['sub'] ?? null,
                    'jti'       => $claims['jti'] ?? null,
                    'iss'       => $claims['iss'] ?? null,
                    'exp'       => isset($claims['exp']) && $claims['exp'] instanceof \DateTimeInterface ? $claims['exp']->format(\DateTimeInterface::ATOM) : null,
                    'subscribe' => $subscribeTopics,
                    'publish'   => $publishTopics,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'error' => $this->trans('realtime.token_invalid', [], 'Invalid token format.'),
                'code'  => 'TOKEN_INVALID',
            ];
        }
    }
}
