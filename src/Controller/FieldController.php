<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use App\Service\NamingConvention;
use App\Repository\ProjectRepository;
use App\Service\FieldRelationOptionsNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/fields', name: 'api_field_')]
class FieldController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private FieldRepository $fieldRepository,
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private FieldRelationOptionsNormalizer $relationOptionsNormalizer,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $fields = $this->fieldRepository->findByCollection($collection);

        return $this->json([
            'data' => array_map(fn($f) => $this->serialize($f), $fields),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $data = $request->toArray();

        if (empty($data['name']) || empty($data['type'])) {
            return $this->json(['error' => 'name and type are required'], 422);
        }

        $field = new Field();
        // Norme canonique Jambo : nom camelCase, slug snake_case dérivé.
        $field->name = NamingConvention::toCamelCase($data['name']);
        $field->slug = NamingConvention::toSnakeCase($data['slug'] ?? $data['name']);
        $field->type = $data['type'];
        $field->options = $this->normalizeOptions($field->type, $data['options'] ?? null, $collection);
        if (isset($data['validationRules'])) {
            $field->validationRules = $data['validationRules'];
        }
        $field->order = $data['order'] ?? 0;
        $field->isRequired = $data['is_required'] ?? false;
        $field->collection = $collection;

        $this->em->persist($field);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($field)], 201);
    }

    #[Route('/{fieldSlug}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug, string $fieldSlug): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        return $this->json(['data' => $this->serialize($field)]);
    }

    #[Route('/{fieldSlug}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, string $fieldSlug, Request $request): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            $field->name = NamingConvention::toCamelCase($data['name']);
        }
        if (isset($data['slug'])) {
            $field->slug = NamingConvention::toSnakeCase($data['slug']);
        }
        if (isset($data['type'])) {
            $field->type = $data['type'];
        }
        if (array_key_exists('options', $data)) {
            $field->options = $this->normalizeOptions($field->type, $data['options'], $field->collection);
        }
        if (isset($data['validationRules'])) {
            $field->validationRules = $data['validationRules'];
        }
        if (isset($data['order'])) {
            $field->order = (int) $data['order'];
        }
        if (isset($data['is_required'])) {
            $field->isRequired = (bool) $data['is_required'];
        }

        $this->em->flush();

        return $this->json(['data' => $this->serialize($field)]);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        foreach ($request->toArray()['fields'] ?? [] as $item) {
            $field = $this->fieldRepository->findOneBy(['id' => (int) $item['id'], 'collection' => $collection]);
            if ($field) {
                $field->order = (int) $item['order'];
            }
        }
        $this->em->flush();

        return $this->json(null, 204);
    }

    #[Route('/{fieldSlug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, string $fieldSlug): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        $field->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function findCollection(string $projectUuid, string $collectionSlug): Collection|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if (!$collection) {
            return $this->json(['error' => 'Collection not found'], 404);
        }

        return $collection;
    }

    private function findField(string $projectUuid, string $collectionSlug, string $fieldSlug): Field|JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $field = $this->fieldRepository->findOneByCollectionAndSlug($collection, $fieldSlug);
        if (!$field) {
            return $this->json(['error' => 'Field not found'], 404);
        }

        return $field;
    }

    /** Normalise les options relation au format canonique avant persistance. */
    private function normalizeOptions(string $type, ?array $options, Collection $collection): ?array
    {
        if ($type !== 'relation' || $options === null) {
            return $options;
        }

        return $this->relationOptionsNormalizer->normalize($options, $collection->project, forStorage: true);
    }

    private function serialize(Field $field): array
    {
        $options = $field->options;
        // Lecture : enrichit les options relation (collection_slug dérivé)
        if ($field->type === 'relation' && $options !== null) {
            $options = $this->relationOptionsNormalizer->normalize($options, $field->collection->project);
        }

        return [
            'id' => $field->id,
            'name' => $field->name,
            'slug' => $field->slug,
            'type' => $field->type,
            'options' => $options,
            'order' => $field->order,
            'is_required' => $field->isRequired,
        ];
    }
}
