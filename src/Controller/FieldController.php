<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\SchemaProvisioner;
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
        private SchemaProvisioner $provisioner,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $collection->project);
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

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $collection->project);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        try {
            // Logique centralisée dans SchemaProvisioner (conventions, validation,
            // normalisation des options relation).
            $field = $this->provisioner->addField($collection, $data);
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode());
        }

        return $this->json(['data' => $this->serialize($field)], 201);
    }

    #[Route('/{fieldSlug}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug, string $fieldSlug): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $field->collection->project);
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

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $field->collection->project);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->provisioner->updateField($field, $data);
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode());
        }

        return $this->json(['data' => $this->serialize($field)]);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $collection->project);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        foreach ($data['fields'] ?? [] as $item) {
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

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $field->collection->project);
        }

        $field->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function findCollection(string $projectUuid, string $collectionSlug): Collection|JsonResponse
    {
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Project not found'], 404);
        }
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
