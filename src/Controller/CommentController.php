<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\EventSubscriber\NotificationSubscriber;
use App\Repository\CommentRepository;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/content/{entryId}/comments', name: 'api_comments_')]
class CommentController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    public const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    public const MAX_COMMENT_LENGTH = 10000;
    public const MAX_NESTING_DEPTH = 3;

    public const PROJECT_TIER_COMMENT_LIMITS = [
        'free'       => 250,
        'starter'    => 1000,
        'pro'        => 5000,
        'enterprise' => 25000,
    ];
    public const DEFAULT_PROJECT_COMMENT_LIMIT = 500;

    public const ENTRY_TIER_COMMENT_LIMITS = [
        'free'       => 100,
        'starter'    => 250,
        'pro'        => 1000,
        'enterprise' => 5000,
    ];
    public const DEFAULT_ENTRY_COMMENT_LIMIT = 200;

    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                 => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private ContentEntryRepository $entryRepository,
        private CommentRepository $commentRepository,
        private EntityManagerInterface $em,
        private ?NotificationSubscriber $notificationSubscriber = null,
        #[Autowire(service: 'limiter.comment_limiter')]
        private ?RateLimiterFactory $commentLimiter = null,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function checkRateLimit(Request $request, ?\App\Entity\User $user = null): ?JsonResponse
    {
        if ($this->commentLimiter !== null) {
            $identifier = $user?->id ? ('user_' . $user->id) : ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->commentLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('comment.too_many_requests', [], 'Too many comment requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    public function getProjectCommentLimit(Project $project): int
    {
        if (isset($project->settings['max_comments']) && is_numeric($project->settings['max_comments'])) {
            return max(1, (int) $project->settings['max_comments']);
        }
        $tier = strtolower((string) ($project->settings['tier'] ?? $project->settings['plan'] ?? 'free'));
        return self::PROJECT_TIER_COMMENT_LIMITS[$tier] ?? self::DEFAULT_PROJECT_COMMENT_LIMIT;
    }

    public function getEntryCommentLimit(Project $project): int
    {
        if (isset($project->settings['max_comments_per_entry']) && is_numeric($project->settings['max_comments_per_entry'])) {
            return max(1, (int) $project->settings['max_comments_per_entry']);
        }
        $tier = strtolower((string) ($project->settings['tier'] ?? $project->settings['plan'] ?? 'free'));
        return self::ENTRY_TIER_COMMENT_LIMITS[$tier] ?? self::DEFAULT_ENTRY_COMMENT_LIMIT;
    }

    public function sanitizeCommentBody(string $rawBody): string
    {
        // 1. Supprimer les caractères de contrôle binaires (conserver sauts de ligne et tabulations)
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawBody) ?? '';

        // 2. Éliminer totalement les balises dangereuses et leur contenu textuel/inline
        $clean = preg_replace('/<\s*(script|style|iframe|object|embed|svg|math|form|applet|meta|link|base)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $clean) ?? '';
        $clean = preg_replace('/<\s*(script|style|iframe|object|embed|svg|math|form|applet|meta|link|base)[^>]*\/?\s*>/is', '', $clean) ?? '';

        // 3. Éliminer tous les gestionnaires d'événements inline (onload, onerror, onclick, etc.)
        $clean = preg_replace('/\s+\bon[a-zA-Z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/\bon[a-zA-Z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/<([a-zA-Z0-9]+)\s+>/', '<$1>', $clean) ?? '';

        // 4. Bloquer les schémas dangereux (javascript:, data:, vbscript:, file:) dans les attributs et le markdown
        $clean = preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|data|vbscript|file):[^\2]*?\2/i', '', $clean) ?? '';
        $clean = preg_replace('/(href|src)\s*=\s*(javascript|data|vbscript|file):[^\s>]+/i', '', $clean) ?? '';
        $clean = preg_replace('/\[([^\]]*)\]\(\s*(?:javascript|data|vbscript|file):(?:[^()]+|\([^()]*\))*\)/i', '[$1](#blocked)', $clean) ?? '';

        // 5. Restreindre aux seules balises HTML sûres de mise en forme
        $clean = strip_tags($clean, ['b', 'strong', 'i', 'em', 'code', 'pre', 'blockquote', 'p', 'br', 'ul', 'ol', 'li', 'a']);

        // 6. Nettoyer et valider strictement les attributs des balises <a>
        $clean = preg_replace_callback('/<a\s+([^>]*?)>/i', function ($matches) {
            $attrStr = $matches[1];
            if (preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $attrStr, $hrefMatch)) {
                $url = trim($hrefMatch[1]);
                if (preg_match('/^(https?:\/\/|mailto:|\/)/i', $url)) {
                    return sprintf('<a href="%s" target="_blank" rel="noopener noreferrer nofollow">', htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }
            }
            return '<a>';
        }, $clean) ?? '';

        // 7. Borner les répétitions abusives de retours à la ligne et de citations
        $clean = preg_replace("/(\r?\n){4,}/", "\n\n\n", $clean) ?? '';
        $clean = preg_replace('/(>{5,})/', '>>>>>', $clean) ?? '';

        return trim($clean);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug, int $entryId, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request, $this->getUser() instanceof \App\Entity\User ? $this->getUser() : null)) {
            return $rateLimitResponse;
        }

        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $entryId, 'project.view');
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(50, max(1, $request->query->getInt('per_page', 15)));
        $comments = $this->commentRepository->findByEntryPaginated($entryId, $page, $perPage);
        $total = $this->commentRepository->countByEntry($entryId);

        return $this->secureJson([
            'data' => array_map(fn (Comment $c) => $this->serializeComment($c), $comments),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
                'entry_comment_limit' => $this->getEntryCommentLimit($entry->project),
                'project_comment_limit' => $this->getProjectCommentLimit($entry->project),
            ],
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, string $collectionSlug, int $entryId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->secureJson([
                'error' => $this->trans('comment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($rateLimitResponse = $this->checkRateLimit($request, $user)) {
            return $rateLimitResponse;
        }

        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $entryId, 'content.update');
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('comment.payload_too_large', [], 'Comment payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        // 1. Quota vérification par entrée
        $entryLimit = $this->getEntryCommentLimit($entry->project);
        $entryCount = $this->commentRepository->countByEntry($entryId);
        if ($entryCount >= $entryLimit) {
            return $this->secureJson([
                'error' => $this->trans('comment.entry_quota_exceeded', ['%limit%' => (string) $entryLimit], 'Maximum comments quota reached for this content entry.'),
                'code' => 'ENTRY_COMMENT_LIMIT_EXCEEDED',
            ], 422);
        }

        // 2. Quota vérification par projet / forfait tenant
        $projectLimit = $this->getProjectCommentLimit($entry->project);
        $projectCount = $this->commentRepository->countByProject($entry->project);
        if ($projectCount >= $projectLimit) {
            return $this->secureJson([
                'error' => $this->trans('comment.project_quota_exceeded', ['%limit%' => (string) $projectLimit], 'Maximum comments quota reached for this project tier.'),
                'code' => 'PROJECT_COMMENT_LIMIT_EXCEEDED',
            ], 422);
        }

        $rawBody = (string) ($data['body'] ?? '');
        $body = $this->sanitizeCommentBody($rawBody);
        if ($body === '') {
            return $this->secureJson([
                'error' => $this->trans('comment.body_empty', [], 'Comment body cannot be empty.'),
                'code' => 'BODY_EMPTY',
            ], 422);
        }
        if (mb_strlen($body) > self::MAX_COMMENT_LENGTH) {
            return $this->secureJson([
                'error' => $this->trans('comment.body_too_long', [], 'Comment body cannot exceed 10000 characters.'),
                'code' => 'BODY_TOO_LONG',
            ], 422);
        }

        $comment = new Comment();
        $comment->entry = $entry;
        $comment->author = $user;
        $comment->body = $body;

        // 3. Contrôle de la hiérarchie et profondeur des réponses
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null && $data['parent_id'] !== '') {
            if (!is_numeric($data['parent_id']) || (int) $data['parent_id'] <= 0) {
                return $this->secureJson([
                    'error' => $this->trans('comment.invalid_parent', [], 'Invalid parent comment.'),
                    'code' => 'INVALID_PARENT',
                ], 422);
            }

            $parent = $this->commentRepository->find((int) $data['parent_id']);
            if (!$parent || $parent->entry?->id !== $entry->id) {
                return $this->secureJson([
                    'error' => $this->trans('comment.parent_entry_mismatch', [], 'Parent comment does not belong to this content entry.'),
                    'code' => 'PARENT_ENTRY_MISMATCH',
                ], 422);
            }

            // Calcul de profondeur et détection de cycle
            $depth = 1;
            $curr = $parent;
            $visited = [];
            while ($curr !== null) {
                if (isset($visited[$curr->id])) {
                    return $this->secureJson([
                        'error' => $this->trans('comment.invalid_parent_cycle', [], 'Circular comment parent reference detected.'),
                        'code' => 'CIRCULAR_PARENT',
                    ], 422);
                }
                $visited[$curr->id] = true;
                $depth++;
                if ($depth > self::MAX_NESTING_DEPTH) {
                    return $this->secureJson([
                        'error' => $this->trans('comment.max_depth_exceeded', ['%max%' => (string) self::MAX_NESTING_DEPTH], 'Maximum comment reply nesting depth reached.'),
                        'code' => 'MAX_DEPTH_EXCEEDED',
                    ], 422);
                }
                $curr = $curr->parent;
            }

            $comment->parent = $parent;
        }

        $this->em->persist($comment);
        $this->em->flush();

        if ($this->notificationSubscriber !== null) {
            $this->notificationSubscriber->onCommentCreated($comment);
        }

        $this->auditService?->log(
            toolName: 'comment_create',
            project: $entry->project,
            input: ['entry_id' => $entryId, 'collection' => $collectionSlug, 'parent_id' => $comment->parent?->id],
            output: ['success' => true, 'comment_id' => $comment->id],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'comment_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serializeComment($comment)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $projectUuid, string $collectionSlug, int $entryId, int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->secureJson([
                'error' => $this->trans('comment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($rateLimitResponse = $this->checkRateLimit($request, $user)) {
            return $rateLimitResponse;
        }

        $comment = $this->resolveComment($projectUuid, $collectionSlug, $entryId, $id, 'content.update');
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        if ($comment->author?->id !== $user->id) {
            return $this->secureJson([
                'error' => $this->trans('comment.access_denied', [], 'Access denied.'),
                'code' => 'ACCESS_DENIED',
            ], 403);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('comment.payload_too_large', [], 'Comment payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        if (isset($data['body'])) {
            $rawBody = (string) $data['body'];
            $body = $this->sanitizeCommentBody($rawBody);
            if ($body === '') {
                return $this->secureJson([
                    'error' => $this->trans('comment.body_empty', [], 'Comment body cannot be empty.'),
                    'code' => 'BODY_EMPTY',
                ], 422);
            }
            if (mb_strlen($body) > self::MAX_COMMENT_LENGTH) {
                return $this->secureJson([
                    'error' => $this->trans('comment.body_too_long', [], 'Comment body cannot exceed 10000 characters.'),
                    'code' => 'BODY_TOO_LONG',
                ], 422);
            }
            $comment->body = $body;
        }

        $comment->updatedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'comment_update',
            project: $comment->entry?->project,
            input: ['entry_id' => $entryId, 'comment_id' => $id],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'comment_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serializeComment($comment)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, int $entryId, int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->secureJson([
                'error' => $this->trans('comment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($rateLimitResponse = $this->checkRateLimit($request, $user)) {
            return $rateLimitResponse;
        }

        $comment = $this->resolveComment($projectUuid, $collectionSlug, $entryId, $id, 'project.view');
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        if ($comment->author?->id !== $user->id && !$isAdmin) {
            return $this->secureJson([
                'error' => $this->trans('comment.access_denied', [], 'Access denied.'),
                'code' => 'ACCESS_DENIED',
            ], 403);
        }

        $project = $comment->entry?->project;
        $this->em->remove($comment);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'comment_delete',
            project: $project,
            input: ['entry_id' => $entryId, 'comment_id' => $id],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'comment_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    #[Route('/{id}/resolve', name: 'resolve', methods: ['POST'])]
    public function resolve(string $projectUuid, string $collectionSlug, int $entryId, int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->secureJson([
                'error' => $this->trans('comment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($rateLimitResponse = $this->checkRateLimit($request, $user)) {
            return $rateLimitResponse;
        }

        $comment = $this->resolveComment($projectUuid, $collectionSlug, $entryId, $id, 'content.update');
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('comment.payload_too_large', [], 'Comment payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        $status = $data['status'] ?? '';
        if (!in_array($status, ['open', 'resolved'], true)) {
            return $this->secureJson([
                'error' => $this->trans('comment.invalid_status', [], 'Invalid comment status: must be open or resolved.'),
                'code' => 'INVALID_STATUS',
            ], 422);
        }

        $comment->status = $status;
        $comment->updatedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'comment_resolve',
            project: $comment->entry?->project,
            input: ['entry_id' => $entryId, 'comment_id' => $id, 'status' => $status],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'comment_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serializeComment($comment)]);
    }

    private function resolveEntry(string $projectUuid, string $collectionSlug, int $entryId, string $permission): ContentEntry|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        if (strlen($collectionSlug) > 100 || !preg_match('/^[a-z0-9_-]+$/i', $collectionSlug)) {
            return $this->secureJson([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        if ($entryId <= 0) {
            return $this->secureJson([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        try {
            $entry = $this->entryRepository->find($entryId);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid || $entry->collection?->slug !== $collectionSlug) {
            return $this->secureJson([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        $this->denyAccessUnlessGranted($permission, $entry->project);

        return $entry;
    }

    private function resolveComment(string $projectUuid, string $collectionSlug, int $entryId, int $id, string $permission): Comment|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        if (strlen($collectionSlug) > 100 || !preg_match('/^[a-z0-9_-]+$/i', $collectionSlug)) {
            return $this->secureJson([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        if ($id <= 0 || $entryId <= 0) {
            return $this->secureJson([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        try {
            $comment = $this->commentRepository->find($id);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        if (!$comment || $comment->entry?->id !== $entryId) {
            return $this->secureJson([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        $entry = $comment->entry;
        if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid || $entry->collection?->slug !== $collectionSlug) {
            return $this->secureJson([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        $this->denyAccessUnlessGranted($permission, $entry->project);

        return $comment;
    }

    private function serializeComment(Comment $c): array
    {
        return [
            'id' => $c->id,
            'uuid' => $c->uuid?->toRfc4122(),
            'body' => $c->body,
            'status' => $c->status,
            'author' => ['id' => $c->author?->id, 'name' => $c->author?->name],
            'parent_id' => $c->parent?->id,
            'children' => $c->children->map(fn (Comment $ch) => [
                'id' => $ch->id,
                'uuid' => $ch->uuid?->toRfc4122(),
                'body' => $ch->body,
                'status' => $ch->status,
                'author' => ['id' => $ch->author?->id, 'name' => $ch->author?->name],
                'created_at' => $ch->createdAt->format('c'),
                'updated_at' => $ch->updatedAt->format('c'),
            ])->toArray(),
            'created_at' => $c->createdAt->format('c'),
            'updated_at' => $c->updatedAt->format('c'),
        ];
    }
}
