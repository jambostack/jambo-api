<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Service\NamingConvention;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/collections', name: 'api_collection_')]
class CollectionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collections = $this->collectionRepository->findByProject($project);

        return $this->json([
            'data' => array_map(fn($c) => $this->serialize($c), $collections),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], 422);
        }

        $collection = new Collection();
        // Norme canonique Jambo : nom PascalCase, slug snake_case dérivé.
        $collection->name = NamingConvention::toPascalCase($data['name']);
        $collection->slug = NamingConvention::toSnakeCase($data['slug'] ?? $data['name']);
        $collection->description = $data['description'] ?? null;
        $collection->isSingleton = $data['is_singleton'] ?? false;
        $collection->order = $data['order'] ?? 0;
        $collection->project = $project;

        $this->em->persist($collection);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($collection)], 201);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        foreach ($request->toArray()['collections'] ?? [] as $item) {
            $collection = $this->collectionRepository->findOneBy([
                'id'      => (int) $item['id'],
                'project' => $project,
            ]);
            if ($collection) {
                $collection->order = (int) $item['order'];
            }
        }

        $this->em->flush();

        return $this->json(null, 204);
    }

    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $slug): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        return $this->json(['data' => $this->serialize($collection)]);
    }

    #[Route('/{slug}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $slug, Request $request): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            $collection->name = NamingConvention::toPascalCase($data['name']);
        }
        if (isset($data['slug'])) {
            $collection->slug = NamingConvention::toSnakeCase($data['slug']);
        }
        if (array_key_exists('description', $data)) {
            $collection->description = $data['description'];
        }
        if (isset($data['is_singleton'])) {
            $collection->isSingleton = (bool) $data['is_singleton'];
        }
        if (isset($data['order'])) {
            $collection->order = (int) $data['order'];
        }

        $this->em->flush();

        return $this->json(['data' => $this->serialize($collection)]);
    }

    #[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $slug): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $this->em->remove($collection);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function findCollection(string $projectUuid, string $slug): Collection|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $slug);
        if (!$collection) {
            return $this->json(['error' => 'Collection not found'], 404);
        }

        return $collection;
    }

    private function serialize(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'uuid' => $collection->uuid?->toRfc4122(),
            'name' => $collection->name,
            'slug' => $collection->slug,
            'description' => $collection->description,
            'is_singleton' => $collection->isSingleton,
            'order' => $collection->order,
            'fields_count' => $collection->fields->count(),
        ];
    }
}
