<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/collections', name: 'api_collection_')]
class CollectionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, EntityManagerInterface $em): JsonResponse
    {
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collections = $em->getRepository(Collection::class)->findBy(
            ['project' => $project],
            ['order' => 'ASC']
        );

        return $this->json([
            'data' => array_map(fn($c) => $this->serialize($c), $collections),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], 422);
        }

        $collection = new Collection();
        $collection->name = $data['name'];
        $collection->slug = $data['slug'] ?? strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $data['name']), '_'));
        $collection->description = $data['description'] ?? null;
        $collection->isSingleton = $data['is_singleton'] ?? false;
        $collection->order = $data['order'] ?? 0;
        $collection->project = $project;

        $em->persist($collection);
        $em->flush();

        return $this->json(['data' => $this->serialize($collection)], 201);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $projectUuid, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        foreach ($request->toArray()['collections'] ?? [] as $item) {
            $collection = $em->getRepository(Collection::class)->findOneBy([
                'id'      => (int) $item['id'],
                'project' => $project,
            ]);
            if ($collection) {
                $collection->order = (int) $item['order'];
            }
        }

        $em->flush();

        return $this->json(null, 204);
    }

    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $slug, EntityManagerInterface $em): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug, $em);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        return $this->json(['data' => $this->serialize($collection)]);
    }

    #[Route('/{slug}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $slug, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug, $em);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $collection->name = $data['name'];
        }
        if (isset($data['slug'])) {
            $collection->slug = $data['slug'];
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

        $em->flush();

        return $this->json(['data' => $this->serialize($collection)]);
    }

    #[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $slug, EntityManagerInterface $em): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug, $em);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $em->remove($collection);
        $em->flush();

        return $this->json(null, 204);
    }

    private function findCollection(string $projectUuid, string $slug, EntityManagerInterface $em): Collection|JsonResponse
    {
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collection = $em->getRepository(Collection::class)->findOneBy(['slug' => $slug, 'project' => $project]);
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
