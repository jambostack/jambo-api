<?php
namespace App\Controller;

use App\Entity\Comment;
use App\EventSubscriber\NotificationSubscriber;
use App\Repository\CommentRepository;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
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
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug, int $entryId, Request $request): JsonResponse
    {
        $entry = $this->entryRepository->find($entryId);
        if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid || $entry->collection?->slug !== $collectionSlug) {
            return new JsonResponse(['error' => $this->trans('comment.entry_not_found', [], 'Entry not found.')], 404);
        }

        $this->denyAccessUnlessGranted('project.view', $entry->project);

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
        $entry = $this->entryRepository->find($entryId);
        if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid || $entry->collection?->slug !== $collectionSlug) {
            return new JsonResponse(['error' => $this->trans('comment.entry_not_found', [], 'Entry not found.')], 404);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse(['error' => $this->trans('comment.auth_required', [], 'Authentication required.')], 401);
        }

        $this->denyAccessUnlessGranted('content.update', $entry->project);

        if ($this->commentLimiter !== null) {
            $identifier = $user->id ? ('user_' . $user->id) : ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->commentLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('comment.too_many_requests', [], 'Too many comment requests. Please retry later.')], 429);
            }
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.')], 400);
        }

        $rawBody = (string) ($data['body'] ?? '');
        $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawBody));
        if ($body === '') {
            return new JsonResponse(['error' => $this->trans('comment.body_empty', [], 'Comment body cannot be empty.')], 422);
        }
        if (mb_strlen($body) > 10000) {
            return new JsonResponse(['error' => $this->trans('comment.body_too_long', [], 'Comment body cannot exceed 10000 characters.')], 422);
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

        return new JsonResponse(['data' => $this->serializeComment($comment)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $projectUuid, string $collectionSlug, int $entryId, int $id, Request $request): JsonResponse
    {
        $comment = $this->commentRepository->find($id);
        if (!$comment || $comment->entry?->id !== $entryId || $comment->entry?->project?->uuid?->toRfc4122() !== $projectUuid || $comment->entry?->collection?->slug !== $collectionSlug) {
            return new JsonResponse(['error' => $this->trans('comment.not_found', [], 'Comment not found.')], 404);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse(['error' => $this->trans('comment.auth_required', [], 'Authentication required.')], 401);
        }

        $this->denyAccessUnlessGranted('content.update', $comment->entry?->project);

        if ($comment->author?->id !== $user->id) {
            return new JsonResponse(['error' => $this->trans('comment.access_denied', [], 'Access denied.')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.')], 400);
        }

        if (isset($data['body'])) {
            $rawBody = (string) $data['body'];
            $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawBody));
            if ($body === '') {
                return new JsonResponse(['error' => $this->trans('comment.body_empty', [], 'Comment body cannot be empty.')], 422);
            }
            if (mb_strlen($body) > 10000) {
                return new JsonResponse(['error' => $this->trans('comment.body_too_long', [], 'Comment body cannot exceed 10000 characters.')], 422);
            }
            $comment->body = $body;
        }
        $comment->updatedAt = new \DateTimeImmutable();
        $this->em->flush();
        return new JsonResponse(['data' => $this->serializeComment($comment)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, int $entryId, int $id): JsonResponse
    {
        $comment = $this->commentRepository->find($id);
        if (!$comment || $comment->entry?->id !== $entryId || $comment->entry?->project?->uuid?->toRfc4122() !== $projectUuid || $comment->entry?->collection?->slug !== $collectionSlug) {
            return new JsonResponse(['error' => $this->trans('comment.not_found', [], 'Comment not found.')], 404);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse(['error' => $this->trans('comment.auth_required', [], 'Authentication required.')], 401);
        }

        $this->denyAccessUnlessGranted('project.view', $comment->entry?->project);

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        if ($comment->author?->id !== $user->id && !$isAdmin) {
            return new JsonResponse(['error' => $this->trans('comment.access_denied', [], 'Access denied.')], 403);
        }

        $this->em->remove($comment);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    #[Route('/{id}/resolve', name: 'resolve', methods: ['POST'])]
    public function resolve(string $projectUuid, string $collectionSlug, int $entryId, int $id, Request $request): JsonResponse
    {
        $comment = $this->commentRepository->find($id);
        if (!$comment || $comment->entry?->id !== $entryId || $comment->entry?->project?->uuid?->toRfc4122() !== $projectUuid || $comment->entry?->collection?->slug !== $collectionSlug) {
            return new JsonResponse(['error' => $this->trans('comment.not_found', [], 'Comment not found.')], 404);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse(['error' => $this->trans('comment.auth_required', [], 'Authentication required.')], 401);
        }

        $this->denyAccessUnlessGranted('content.update', $comment->entry?->project);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('comment.invalid_json', [], 'Invalid JSON body.')], 400);
        }
        $status = $data['status'] ?? '';
        if (!in_array($status, ['open', 'resolved'], true)) {
            return new JsonResponse(['error' => $this->trans('comment.invalid_status', [], 'Invalid comment status: must be open or resolved.')], 422);
        }
        $comment->status = $status;
        $comment->updatedAt = new \DateTimeImmutable();
        $this->em->flush();
        return new JsonResponse(['data' => $this->serializeComment($comment)]);
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
