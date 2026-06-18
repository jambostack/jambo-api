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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/content/{entryId}/comments', name: 'api_comments_')]
class CommentController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private ContentEntryRepository $entryRepository,
        private CommentRepository $commentRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug, int $entryId, Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(50, max(1, $request->query->getInt('per_page', 15)));
        $comments = $this->commentRepository->findByEntryPaginated($entryId, $page, $perPage);
        $total = $this->commentRepository->countByEntry($entryId);
        return $this->json([
            'data' => array_map(fn (Comment $c) => $this->serializeComment($c), $comments),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($total / $perPage)],
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, string $collectionSlug, int $entryId, Request $request): JsonResponse
    {
        $entry = $this->entryRepository->find($entryId);
        if (!$entry) return $this->json(['error' => 'Entry not found.'], 404);
        $data = $request->toArray();
        $comment = new Comment();
        $comment->entry = $entry;
        $comment->author = $this->getUser();
        $comment->body = $data['body'] ?? '';
        if (!empty($data['parent_id'])) {
            $parent = $this->commentRepository->find($data['parent_id']);
            if ($parent && $parent->entry->id === $entry->id) $comment->parent = $parent;
        }
        $this->em->persist($comment);
        $this->em->flush();
        $this->container->get(NotificationSubscriber::class)->onCommentCreated($comment);
        return $this->json(['data' => $this->serializeComment($comment)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $comment = $this->commentRepository->find($id);
        if (!$comment) return $this->json(['error' => 'Not found.'], 404);
        if ($comment->author->id !== $this->getUser()->id) return $this->json(['error' => 'Forbidden.'], 403);
        $data = $request->toArray();
        if (isset($data['body'])) $comment->body = $data['body'];
        $this->em->flush();
        return $this->json(['data' => $this->serializeComment($comment)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $comment = $this->commentRepository->find($id);
        if (!$comment) return $this->json(['error' => 'Not found.'], 404);
        if ($comment->author->id !== $this->getUser()->id && !in_array('ROLE_ADMIN', $this->getUser()->getRoles()))
            return $this->json(['error' => 'Forbidden.'], 403);
        $this->em->remove($comment);
        $this->em->flush();
        return $this->json(null, 204);
    }

    #[Route('/{id}/resolve', name: 'resolve', methods: ['POST'])]
    public function resolve(int $id, Request $request): JsonResponse
    {
        $comment = $this->commentRepository->find($id);
        if (!$comment) return $this->json(['error' => 'Not found.'], 404);
        $data = $request->toArray();
        $comment->status = in_array(($data['status'] ?? ''), ['open', 'resolved']) ? $data['status'] : $comment->status;
        $this->em->flush();
        return $this->json(['data' => $this->serializeComment($comment)]);
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
