<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\FieldRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/fields', name: 'api_field_')]
class FieldController extends AbstractController
{
    public function __construct(private FieldRepository $fieldRepository) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug, EntityManagerInterface $em): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug, $em);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $fields = $this->fieldRepository->findByCollection($collection);

        return $this->json([
            'data' => array_map(fn($f) => $this->serialize($f), $fields),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, string $collectionSlug, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug, $em);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $data = json_decode($request->getContent(), true);

        if (empty($data['name']) || empty($data['type'])) {
            return $this->json(['error' => 'name and type are required'], 422);
        }

        $field = new Field();
        $field->name = $data['name'];
        $field->slug = $data['slug'] ?? strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $data['name']), '_'));
        $field->type = $data['type'];
        $field->options = $data['options'] ?? null;
        $field->order = $data['order'] ?? 0;
        $field->isRequired = $data['is_required'] ?? false;
        $field->collection = $collection;

        $em->persist($field);
        $em->flush();

        return $this->json(['data' => $this->serialize($field)], 201);
    }

    #[Route('/{fieldSlug}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug, string $fieldSlug, EntityManagerInterface $em): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug, $em);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        return $this->json(['data' => $this->serialize($field)]);
    }

    #[Route('/{fieldSlug}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, string $fieldSlug, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug, $em);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $field->name = $data['name'];
        }
        if (isset($data['slug'])) {
            $field->slug = $data['slug'];
        }
        if (isset($data['type'])) {
            $field->type = $data['type'];
        }
        if (array_key_exists('options', $data)) {
            $field->options = $data['options'];
        }
        if (isset($data['order'])) {
            $field->order = (int) $data['order'];
        }
        if (isset($data['is_required'])) {
            $field->isRequired = (bool) $data['is_required'];
        }

        $em->flush();

        return $this->json(['data' => $this->serialize($field)]);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $projectUuid, string $collectionSlug, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug, $em);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        foreach ($request->toArray()['fields'] ?? [] as $item) {
            $field = $em->getRepository(Field::class)->findOneBy(['id' => (int) $item['id'], 'collection' => $collection]);
            if ($field) {
                $field->order = (int) $item['order'];
            }
        }
        $em->flush();

        return $this->json(null, 204);
    }

    #[Route('/{fieldSlug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, string $fieldSlug, EntityManagerInterface $em): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug, $em);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        $field->deletedAt = new \DateTimeImmutable();
        $em->flush();

        return $this->json(null, 204);
    }

    private function findCollection(string $projectUuid, string $collectionSlug, EntityManagerInterface $em): Collection|JsonResponse
    {
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collection = $em->getRepository(Collection::class)->findOneBy(['slug' => $collectionSlug, 'project' => $project]);
        if (!$collection) {
            return $this->json(['error' => 'Collection not found'], 404);
        }

        return $collection;
    }

    private function findField(string $projectUuid, string $collectionSlug, string $fieldSlug, EntityManagerInterface $em): Field|JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug, $em);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $field = $this->fieldRepository->findOneByCollectionAndSlug($collection, $fieldSlug);
        if (!$field) {
            return $this->json(['error' => 'Field not found'], 404);
        }

        return $field;
    }

    private function serialize(Field $field): array
    {
        return [
            'id' => $field->id,
            'name' => $field->name,
            'slug' => $field->slug,
            'type' => $field->type,
            'options' => $field->options,
            'order' => $field->order,
            'is_required' => $field->isRequired,
        ];
    }

}
