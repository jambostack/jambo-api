<?php

namespace App\Controller;

use App\Entity\CollectionTemplate;
use App\Entity\CollectionTemplateField;
use App\Repository\CollectionRepository;
use App\Repository\CollectionTemplateRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/collection-templates', name: 'api_collection_template_')]
class CollectionTemplateController extends AbstractController
{
    public function __construct(
        private readonly CollectionTemplateRepository $templateRepository,
        private readonly CollectionRepository $collectionRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $templates = $this->templateRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->json([
            'data' => array_map(fn ($t) => $this->serialize($t), $templates),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        if ($id <= 0) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        return $this->json($this->serialize($template, true));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return $this->json(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => $this->trans('template.name_required', [], 'name is required')], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->json(['error' => $this->trans('template.name_too_long', ['%max%' => 255], 'Template name cannot exceed 255 characters.')], 422);
        }

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? trim((string) $data['description']) : null;
        if ($rawDesc !== null && mb_strlen($rawDesc) > 2000) {
            return $this->json(['error' => $this->trans('template.description_too_long', ['%max%' => 2000], 'Template description cannot exceed 2000 characters.')], 422);
        }

        $rawFields = $data['fields'] ?? [];
        if (!is_array($rawFields)) {
            return $this->json(['error' => $this->trans('template.fields_must_be_array', [], 'fields must be an array')], 422);
        }
        if (count($rawFields) > 100) {
            return $this->json(['error' => $this->trans('template.max_fields_exceeded', [], 'A template cannot have more than 100 fields.')], 422);
        }

        $template = new CollectionTemplate();
        $template->name        = $name;
        $template->description = $rawDesc;
        $template->isSingleton = (bool) ($data['is_singleton'] ?? false);

        foreach (array_slice($rawFields, 0, 100) as $i => $f) {
            if (!is_array($f)) {
                continue;
            }
            $fieldName = trim((string) ($f['name'] ?? ''));
            $fieldSlug = trim((string) ($f['slug'] ?? ''));
            if ($fieldName === '' || $fieldSlug === '') {
                continue;
            }

            $field = new CollectionTemplateField();
            $field->name        = mb_substr($fieldName, 0, 255);
            $field->slug        = mb_substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $fieldSlug) ?: 'field_' . $i, 0, 255);
            $field->type        = is_string($f['type'] ?? null) ? substr(trim($f['type']), 0, 50) : 'text';
            $field->options     = is_array($f['options'] ?? null) ? $f['options'] : null;
            $field->order       = (int) ($f['order'] ?? $i);
            $field->isRequired  = (bool) ($f['is_required'] ?? false);
            $field->collectionTemplate = $template;
            $template->fields->add($field);
            $this->em->persist($field);
        }

        $this->em->persist($template);
        $this->em->flush();

        return $this->json($this->serialize($template, true), 201);
    }

    #[Route('/from-collection/{projectUuid}/{collectionSlug}', name: 'save_from_collection', methods: ['POST'])]
    public function saveFromCollection(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        if (!Uuid::isValid($projectUuid)) {
            return $this->json(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404);
        }
        if ($project === null) {
            return $this->json(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => $this->trans('template.collection_not_found', [], 'Collection not found')], 404);
        }

        $activeFields = $collection->fields->filter(fn ($f) => !$f->isDeleted());
        if ($activeFields->count() > 100) {
            return $this->json(['error' => $this->trans('template.max_fields_exceeded', [], 'A template cannot have more than 100 fields.')], 422);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : '';
        $name = $rawName !== '' ? mb_substr($rawName, 0, 255) : $collection->name;

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? trim((string) $data['description']) : null;
        $description = $rawDesc !== null ? mb_substr($rawDesc, 0, 2000) : $collection->description;

        $template = new CollectionTemplate();
        $template->name        = $name;
        $template->description = $description;
        $template->isSingleton = $collection->isSingleton;

        foreach ($activeFields as $i => $f) {
            $field = new CollectionTemplateField();
            $field->name       = $f->name;
            $field->slug       = $f->slug;
            $field->type       = $f->type;
            $field->options    = $f->options;
            $field->order      = $f->order;
            $field->isRequired = $f->isRequired;
            $field->collectionTemplate = $template;
            $template->fields->add($field);
            $this->em->persist($field);
        }

        $this->em->persist($template);
        $this->em->flush();

        return $this->json($this->serialize($template, true), 201);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($id <= 0) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
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
