<?php
namespace App\Controller;

use App\Entity\Comment;
use App\Entity\ContentEntry;
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
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 524288; // 512 KB

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

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug, int $entryId, Request $request): JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $entryId, 'project.view');
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(50, max(1, $request->query->getInt('per_page', 15)));
        $comments = $this->commentRepository->findByEntryPaginated($entryId, $page, $perPage);
        $total = $this->commentRepository->countByEntry($entryId);
        return new JsonResponse([
            'data' => array_map(fn (Comment $c) => $this->serializeComment($c), $comments),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($total / $perPage)],
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, string $collectionSlug, int $entryId, Request $request): JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $entryId, 'content.update');
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([
                'error' => $this->trans('comment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($this->commentLimiter !== null) {
            $identifier = $user->id ? ('user_' . $user->id) : ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->commentLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('comment.too_many_requests', [], 'Too many comment requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('comment.payload_too_large', [], 'Comment payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        $rawBody = (string) ($data['body'] ?? '');
        $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawBody));
        if ($body === '') {
            return new JsonResponse([
                'error' => $this->trans('comment.body_empty', [], 'Comment body cannot be empty.'),
                'code' => 'BODY_EMPTY',
            ], 422);
        }
        if (mb_strlen($body) > 10000) {
            return new JsonResponse([
                'error' => $this->trans('comment.body_too_long', [], 'Comment body cannot exceed 10000 characters.'),
                'code' => 'BODY_TOO_LONG',
            ], 422);
        }

        $comment = new Comment();
        $comment->entry = $entry;
        $comment->author = $user;
        $comment->body = $body;
        if (!empty($data['parent_id']) && is_numeric($data['parent_id'])) {
            $parent = $this->commentRepository->find((int) $data['parent_id']);
            if ($parent && $parent->entry?->id === $entry->id) {
                // Flatten reply depth to prevent unbounded recursive nesting
                $comment->parent = $parent->parent !== null ? $parent->parent : $parent;
            }
        }
        $this->em->persist($comment);
        $this->em->flush();

        if ($this->notificationSubscriber !== null) {
            $this->notificationSubscriber->onCommentCreated($comment);
        }

        $this->auditService?->log(
            toolName: 'comment_create',
            project: $entry->project,
            input: ['entry_id' => $entryId, 'collection' => $collectionSlug],
            output: ['success' => true, 'comment_id' => $comment->id],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'comment_management'
        );
        $this->auditService?->flush();

        return new JsonResponse(['data' => $this->serializeComment($comment)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $projectUuid, string $collectionSlug, int $entryId, int $id, Request $request): JsonResponse
    {
        $comment = $this->resolveComment($projectUuid, $collectionSlug, $entryId, $id, 'content.update');
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([
                'error' => $this->trans('comment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($comment->author?->id !== $user->id) {
            return new JsonResponse([
                'error' => $this->trans('comment.access_denied', [], 'Access denied.'),
                'code' => 'ACCESS_DENIED',
            ], 403);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('comment.payload_too_large', [], 'Comment payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        if (isset($data['body'])) {
            $rawBody = (string) $data['body'];
            $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawBody));
            if ($body === '') {
                return new JsonResponse([
                    'error' => $this->trans('comment.body_empty', [], 'Comment body cannot be empty.'),
                    'code' => 'BODY_EMPTY',
                ], 422);
            }
            if (mb_strlen($body) > 10000) {
                return new JsonResponse([
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

        return new JsonResponse(['data' => $this->serializeComment($comment)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, int $entryId, int $id): JsonResponse
    {
        $comment = $this->resolveComment($projectUuid, $collectionSlug, $entryId, $id, 'project.view');
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([
                'error' => $this->trans('comment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        if ($comment->author?->id !== $user->id && !$isAdmin) {
            return new JsonResponse([
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

        return new JsonResponse(null, 204);
    }

    #[Route('/{id}/resolve', name: 'resolve', methods: ['POST'])]
    public function resolve(string $projectUuid, string $collectionSlug, int $entryId, int $id, Request $request): JsonResponse
    {
        $comment = $this->resolveComment($projectUuid, $collectionSlug, $entryId, $id, 'content.update');
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([
                'error' => $this->trans('comment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('comment.payload_too_large', [], 'Comment payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }
        $status = $data['status'] ?? '';
        if (!in_array($status, ['open', 'resolved'], true)) {
            return new JsonResponse([
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

        return new JsonResponse(['data' => $this->serializeComment($comment)]);
    }

    private function resolveEntry(string $projectUuid, string $collectionSlug, int $entryId, string $permission): ContentEntry|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return new JsonResponse([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        if (strlen($collectionSlug) > 100 || !preg_match('/^[a-z0-9_-]+$/i', $collectionSlug)) {
            return new JsonResponse([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        if ($entryId <= 0) {
            return new JsonResponse([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        try {
            $entry = $this->entryRepository->find($entryId);
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid || $entry->collection?->slug !== $collectionSlug) {
            return new JsonResponse([
                'error' => $this->trans('comment.entry_not_found', [], 'Entry not found.'),
                'code' => 'ENTRY_NOT_FOUND',
            ], 404);
        }

        $this->denyAccessUnlessGranted($permission, $entry->project);

        return $entry;
    }

    private function resolveComment(string $projectUuid, string $collectionSlug, int $entryId, int $id, string $permission): Comment|JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $entryId, $permission);
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        if ($id <= 0) {
            return new JsonResponse([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        try {
            $comment = $this->commentRepository->find($id);
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        if (!$comment || $comment->entry?->id !== $entryId) {
            return new JsonResponse([
                'error' => $this->trans('comment.not_found', [], 'Comment not found.'),
                'code' => 'COMMENT_NOT_FOUND',
            ], 404);
        }

        return $comment;
    }

    private function serializeComment(Comment $c): array
    {
        return [
            'id' => $c->id, 'uuid' => $c->uuid?->toRfc4122(), 'body' => $c->body, 'status' => $c->status,
            'author' => ['id' => $c->author?->id, 'name' => $c->author?->name],
            'parent_id' => $c->parent?->id,
            'children' => $c->children->map(fn (Comment $ch) => [
                'id' => $ch->id, 'uuid' => $ch->uuid?->toRfc4122(), 'body' => $ch->body, 'status' => $ch->status,
                'author' => ['id' => $ch->author?->id, 'name' => $ch->author?->name],
                'created_at' => $ch->createdAt->format('c'), 'updated_at' => $ch->updatedAt->format('c'),
            ])->toArray(),
            'created_at' => $c->createdAt->format('c'), 'updated_at' => $c->updatedAt->format('c'),
        ];
    }
}
