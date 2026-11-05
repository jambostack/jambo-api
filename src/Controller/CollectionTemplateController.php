<?php

namespace App\Controller;

use App\Entity\CollectionTemplate;
use App\Entity\CollectionTemplateField;
use App\Repository\CollectionRepository;
use App\Repository\CollectionTemplateRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/collection-templates', name: 'api_collection_template_')]
class CollectionTemplateController extends AbstractController
{
    public const MAX_TEMPLATES_COUNT = 200;
    public const MAX_FIELDS_PER_TEMPLATE = 100;
    public const MAX_NAME_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 2000;
    public const MAX_OPTIONS_PAYLOAD_BYTES = 32768; // 32KB

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly CollectionTemplateRepository $templateRepository,
        private readonly CollectionRepository $collectionRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?AuditService $auditService = null,
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
    public function index(?Request $request = null): JsonResponse
    {
        $request ??= new Request();
        $limit = min(self::MAX_TEMPLATES_COUNT, max(1, $request->query->getInt('limit', self::MAX_TEMPLATES_COUNT)));
        $templates = $this->templateRepository->findBy([], ['createdAt' => 'DESC'], $limit);

        return new JsonResponse([
            'data' => array_map(fn ($t) => $this->serialize($t), $templates),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        return new JsonResponse($this->serialize($template, true), 200, self::SECURITY_HEADERS);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException($this->trans('template.admin_required', [], 'Admin privileges required.'));
        }

        $templateCount = $this->templateRepository->count([]);
        if ($templateCount >= self::MAX_TEMPLATES_COUNT) {
            return new JsonResponse([
                'error' => $this->trans('template.max_templates_exceeded', ['%max%' => self::MAX_TEMPLATES_COUNT], 'Maximum number of templates reached.'),
                'code' => 'MAX_TEMPLATES_EXCEEDED',
            ], 422, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400, self::SECURITY_HEADERS);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => $this->trans('template.name_required', [], 'name is required')], 422, self::SECURITY_HEADERS);
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return new JsonResponse(['error' => $this->trans('template.name_too_long', ['%max%' => self::MAX_NAME_LENGTH], 'Template name cannot exceed 255 characters.')], 422, self::SECURITY_HEADERS);
        }

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? trim((string) $data['description']) : null;
        if ($rawDesc !== null && mb_strlen($rawDesc) > self::MAX_DESCRIPTION_LENGTH) {
            return new JsonResponse(['error' => $this->trans('template.description_too_long', ['%max%' => self::MAX_DESCRIPTION_LENGTH], 'Template description cannot exceed 2000 characters.')], 422, self::SECURITY_HEADERS);
        }

        $rawFields = $data['fields'] ?? [];
        if (!is_array($rawFields)) {
            return new JsonResponse(['error' => $this->trans('template.fields_must_be_array', [], 'fields must be an array')], 422, self::SECURITY_HEADERS);
        }
        if (count($rawFields) > self::MAX_FIELDS_PER_TEMPLATE) {
            return new JsonResponse(['error' => $this->trans('template.max_fields_exceeded', [], 'A template cannot have more than 100 fields.')], 422, self::SECURITY_HEADERS);
        }

        $template = new CollectionTemplate();
        $template->name        = $name;
        $template->description = $rawDesc;
        $template->isSingleton = (bool) ($data['is_singleton'] ?? false);

        foreach (array_slice($rawFields, 0, self::MAX_FIELDS_PER_TEMPLATE) as $i => $f) {
            if (!is_array($f)) {
                return new JsonResponse([
                    'error' => $this->trans('template.fields_must_be_array', [], 'fields must be an array'),
                    'code' => 'INVALID_FIELD_STRUCTURE',
                ], 422, self::SECURITY_HEADERS);
            }
            $fieldName = trim((string) ($f['name'] ?? ''));
            $fieldSlug = trim((string) ($f['slug'] ?? ''));
            if ($fieldName === '') {
                return new JsonResponse([
                    'error' => $this->trans('template.field_name_required', [], 'Field name is required.'),
                    'code' => 'FIELD_NAME_REQUIRED',
                ], 422, self::SECURITY_HEADERS);
            }
            if ($fieldSlug === '') {
                return new JsonResponse([
                    'error' => $this->trans('template.field_slug_required', [], 'Field slug is required.'),
                    'code' => 'FIELD_SLUG_REQUIRED',
                ], 422, self::SECURITY_HEADERS);
            }
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $fieldSlug)) {
                return new JsonResponse([
                    'error' => $this->trans('template.field_slug_invalid', ['%slug%' => $fieldSlug], "Field slug contains invalid characters: {$fieldSlug}."),
                    'code' => 'INVALID_FIELD_SLUG',
                ], 422, self::SECURITY_HEADERS);
            }

            $rawType = is_string($f['type'] ?? null) ? trim($f['type']) : 'text';
            if (!in_array($rawType, FieldController::ALLOWED_TYPES, true)) {
                return new JsonResponse([
                    'error' => $this->trans('template.field_invalid_type', ['%name%' => $fieldName, '%type%' => $rawType], "Field {$fieldName} has an unsupported type: {$rawType}."),
                    'code' => 'INVALID_FIELD_TYPE',
                ], 422, self::SECURITY_HEADERS);
            }

            $options = null;
            if (isset($f['options'])) {
                if (!is_array($f['options'])) {
                    return new JsonResponse(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON'), 'code' => 'INVALID_FIELD_OPTIONS'], 422, self::SECURITY_HEADERS);
                }
                $jsonOptions = json_encode($f['options']);
                if (strlen((string) $jsonOptions) > self::MAX_OPTIONS_PAYLOAD_BYTES) {
                    return new JsonResponse([
                        'error' => $this->trans('template.field_options_too_large', [], 'Field options payload too large (max 32KB).'),
                        'code' => 'OPTIONS_TOO_LARGE',
                    ], 422, self::SECURITY_HEADERS);
                }
                $options = $f['options'];
            }

            $field = new CollectionTemplateField();
            $field->name        = mb_substr($fieldName, 0, self::MAX_NAME_LENGTH);
            $field->slug        = mb_substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $fieldSlug) ?: 'field_' . $i, 0, self::MAX_NAME_LENGTH);
            $field->type        = $rawType;
            $field->options     = $options;
            $field->order       = min(1000, max(0, (int) ($f['order'] ?? $i)));
            $field->isRequired  = (bool) ($f['is_required'] ?? false);
            $field->collectionTemplate = $template;
            $template->fields->add($field);
            $this->em->persist($field);
        }

        $this->em->persist($template);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'template_create',
            project: null,
            input: ['name' => $template->name, 'isSingleton' => $template->isSingleton, 'fields_count' => $template->fields->count()],
            output: ['id' => $template->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );

        return new JsonResponse($this->serialize($template, true), 201, self::SECURITY_HEADERS);
    }

    #[Route('/from-collection/{projectUuid}/{collectionSlug}', name: 'save_from_collection', methods: ['POST'])]
    public function saveFromCollection(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException($this->trans('template.admin_required', [], 'Admin privileges required.'));
        }

        $templateCount = $this->templateRepository->count([]);
        if ($templateCount >= self::MAX_TEMPLATES_COUNT) {
            return new JsonResponse([
                'error' => $this->trans('template.max_templates_exceeded', ['%max%' => self::MAX_TEMPLATES_COUNT], 'Maximum number of templates reached.'),
                'code' => 'MAX_TEMPLATES_EXCEEDED',
            ], 422, self::SECURITY_HEADERS);
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $projectUuid)) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($project === null) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null || $collection->isDeleted()) {
            return new JsonResponse(['error' => $this->trans('template.collection_not_found', [], 'Collection not found')], 404, self::SECURITY_HEADERS);
        }

        $activeFields = $collection->fields->filter(fn ($f) => !$f->isDeleted());
        if ($activeFields->count() > self::MAX_FIELDS_PER_TEMPLATE) {
            return new JsonResponse(['error' => $this->trans('template.max_fields_exceeded', [], 'A template cannot have more than 100 fields.')], 422, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400, self::SECURITY_HEADERS);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : '';
        $name = $rawName !== '' ? mb_substr($rawName, 0, self::MAX_NAME_LENGTH) : $collection->name;

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? trim((string) $data['description']) : null;
        $description = $rawDesc !== null ? mb_substr($rawDesc, 0, self::MAX_DESCRIPTION_LENGTH) : $collection->description;

        $template = new CollectionTemplate();
        $template->name        = $name;
        $template->description = $description;
        $template->isSingleton = $collection->isSingleton;

        foreach ($activeFields as $i => $f) {
            $fieldType = in_array($f->type, FieldController::ALLOWED_TYPES, true) ? $f->type : 'text';
            $options = is_array($f->options) ? $f->options : null;
            if ($options !== null && strlen((string) json_encode($options)) > self::MAX_OPTIONS_PAYLOAD_BYTES) {
                $options = null;
            }

            $field = new CollectionTemplateField();
            $field->name       = mb_substr($f->name, 0, self::MAX_NAME_LENGTH);
            $field->slug       = mb_substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $f->slug) ?: 'field_' . $i, 0, self::MAX_NAME_LENGTH);
            $field->type       = $fieldType;
            $field->options    = $options;
            $field->order      = min(1000, max(0, $f->order));
            $field->isRequired = (bool) $f->isRequired;
            $field->collectionTemplate = $template;
            $template->fields->add($field);
            $this->em->persist($field);
        }

        $this->em->persist($template);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'template_save_from_collection',
            project: $project,
            input: ['collection_slug' => $collectionSlug, 'name' => $template->name],
            output: ['template_id' => $template->id, 'fields_count' => $template->fields->count()],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );

        return new JsonResponse($this->serialize($template, true), 201, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $templateId = $template->id;
        $this->em->remove($template);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'template_delete',
            project: null,
            input: ['id' => $templateId],
            output: ['deleted' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );

        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
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
