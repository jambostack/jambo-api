<?php

namespace App\Controller;

use App\Entity\CollectionTemplate;
use App\Entity\CollectionTemplateField;
use App\Repository\CollectionRepository;
use App\Repository\CollectionTemplateRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/collection-templates', name: 'api_collection_template_')]
class CollectionTemplateController extends InertiaController
{
    public function __construct(
        private CollectionTemplateRepository $templateRepository,
        private CollectionRepository $collectionRepository,
        private ProjectRepository $projectRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $templates = $this->templateRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->json([
            'data' => array_map(fn ($t) => $this->serialize($t), $templates),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => 'Template not found'], 404);
        }

        return $this->json($this->serialize($template, true));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], 422);
        }

        $template = new CollectionTemplate();
        $template->name        = $data['name'];
        $template->description = $data['description'] ?? null;
        $template->isSingleton = $data['is_singleton'] ?? false;

        foreach ($data['fields'] ?? [] as $i => $f) {
            $field = new CollectionTemplateField();
            $field->name        = $f['name'];
            $field->slug        = $f['slug'];
            $field->type        = $f['type'] ?? 'text';
            $field->options     = $f['options'] ?? null;
            $field->order       = $f['order'] ?? $i;
            $field->isRequired  = $f['is_required'] ?? false;
            $field->collectionTemplate = $template;
            $this->em->persist($field);
        }

        $this->em->persist($template);
        $this->em->flush();

        return $this->json($this->serialize($template, true), 201);
    }

    #[Route('/from-collection/{projectUuid}/{collectionSlug}', name: 'save_from_collection', methods: ['POST'])]
    public function saveFromCollection(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found'], 404);
        }

        $data = $request->toArray();

        $template = new CollectionTemplate();
        $template->name        = $data['name'] ?? $collection->name;
        $template->description = $data['description'] ?? $collection->description;
        $template->isSingleton = $collection->isSingleton;

        foreach ($collection->fields->filter(fn ($f) => !$f->isDeleted()) as $i => $f) {
            $field = new CollectionTemplateField();
            $field->name       = $f->name;
            $field->slug       = $f->slug;
            $field->type       = $f->type;
            $field->options    = $f->options;
            $field->order      = $f->order;
            $field->isRequired = $f->isRequired;
            $field->collectionTemplate = $template;
            $this->em->persist($field);
        }

        $this->em->persist($template);
        $this->em->flush();

        return $this->json($this->serialize($template, true), 201);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => 'Template not found'], 404);
        }

        $this->em->remove($template);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function serialize(CollectionTemplate $t, bool $withFields = false): array
    {
        $data = [
            'id'          => $t->id,
            'name'        => $t->name,
            'description' => $t->description,
            'isSingleton' => $t->isSingleton,
            'fieldsCount' => $t->fields->count(),
            'createdAt'   => $t->createdAt->format(\DateTimeInterface::ATOM),
        ];

        if ($withFields) {
            $data['fields'] = $t->fields->map(fn ($f) => [
                'name'       => $f->name,
                'slug'       => $f->slug,
                'type'       => $f->type,
                'options'    => $f->options,
                'order'      => $f->order,
                'isRequired' => $f->isRequired,
            ])->toArray();
        }

        return $data;
    }
}
