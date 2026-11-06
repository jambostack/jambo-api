<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\CollectionTemplate;
use App\Entity\CollectionTemplateField;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\CollectionTemplateRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/collection-templates', name: 'api_collection_template_')]
class CollectionTemplateController extends AbstractController
{
    public const MAX_TEMPLATES_COUNT = 200;
    public const MAX_COLLECTIONS_PER_PROJECT = 100;
    public const MAX_FIELDS_PER_TEMPLATE = 100;
    public const MAX_NAME_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 2000;
    public const MAX_OPTIONS_PAYLOAD_BYTES = 32768; // 32KB
    public const MAX_PAYLOAD_BYTES = 524288; // 512KB
    public const MAX_OPTIONS_DEPTH = 6;

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
        #[Autowire(service: 'limiter.template_limiter')]
        private readonly ?RateLimiterFactory $templateLimiter = null,
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

    private function normalizeFieldType(string $rawType): ?string
    {
        $type = strtolower(trim($rawType));
        $aliasMap = [
            'textarea'    => 'longtext',
            'rich_text'   => 'richtext',
            'select'      => 'enumeration',
            'multiselect' => 'tags',
            'file'        => 'media',
            'int'         => 'number',
            'integer'     => 'number',
            'float'       => 'decimal',
            'bool'        => 'boolean',
            'string'      => 'text',
        ];
        if (isset($aliasMap[$type])) {
            $type = $aliasMap[$type];
        }
        return in_array($type, FieldController::ALLOWED_TYPES, true) ? $type : null;
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->templateLimiter === null) {
            return null;
        }

        $identifier = $request->getClientIp() ?: '127.0.0.1';
        try {
            $user = $this->getUser();
            if ($user !== null) {
                $identifier = $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        $limit = $this->templateLimiter->create($identifier)->consume(1);
        if (false === $limit->isAccepted()) {
            $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
            return new JsonResponse([
                'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                'code'  => 'RATE_LIMIT_EXCEEDED',
            ], 429, array_merge(self::SECURITY_HEADERS, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit->getLimit(),
                'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
            ]));
        }

        return null;
    }

    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->getArrayDepth($value) + 1;
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                }
            }
        }
        return $maxDepth;
    }

    private function sanitizeText(string $text): string
    {
        return trim(strip_tags(preg_replace('/[\x00-\x1F\x7F]/', '', $text)));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(?Request $request = null): JsonResponse
    {
        $request ??= new Request();
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $limit = min(self::MAX_TEMPLATES_COUNT, max(1, $request->query->getInt('limit', self::MAX_TEMPLATES_COUNT)));
        $templates = $this->templateRepository->findBy([], ['createdAt' => 'DESC'], $limit);

        return new JsonResponse([
            'data' => array_map(fn ($t) => $this->serialize($t), $templates),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimit = $this->checkRateLimit($request))) {
            return $rateLimit;
        }

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        return new JsonResponse($this->serialize($template, true), 200, self::SECURITY_HEADERS);
    }

    #[Route('/{id}/export', name: 'export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function export(int $id, ?Request $request = null): Response
    {
        if ($request !== null && ($rateLimit = $this->checkRateLimit($request))) {
            return $rateLimit;
        }

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $data = [
            'version'     => '1.0',
            'type'        => 'collection_template',
            'id'          => $template->id,
            'name'        => $template->name,
            'description' => $template->description,
            'isSingleton' => $template->isSingleton,
            'fields'      => $template->fields->map(fn ($f) => [
                'name'       => $f->name,
                'slug'       => $f->slug,
                'type'       => $f->type,
                'options'    => $f->options,
                'order'      => $f->order,
                'isRequired' => $f->isRequired,
            ])->toArray(),
            'exportedAt'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($template->name)) ?: 'template';
        $filename = sprintf('collection-template-%d-%s.json', $template->id, $safeSlug);

        $response = new Response($json, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $response->headers->set($k, $v);
        }

        return $response;
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        if (!$this->getUser()) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException($this->trans('template.admin_required', [], 'Admin privileges required.'));
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('template.payload_too_large', [], 'Payload too large (max 512KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
        }

        $templateCount = $this->templateRepository->count([]);
        if ($templateCount >= self::MAX_TEMPLATES_COUNT) {
            return new JsonResponse([
                'error' => $this->trans('template.max_templates_exceeded', ['%max%' => self::MAX_TEMPLATES_COUNT], 'Maximum number of templates reached.'),
                'code' => 'MAX_TEMPLATES_EXCEEDED',
            ], 422, self::SECURITY_HEADERS);
        }

        $uploadedFile = $request->files->get('file');
        $rawContent = '';
        $isYaml = false;

        if ($uploadedFile !== null) {
            if (!$uploadedFile->isValid()) {
                return new JsonResponse(['error' => $this->trans('template.invalid_blueprint_format')], 422, self::SECURITY_HEADERS);
            }
            if ($uploadedFile->getSize() > self::MAX_PAYLOAD_BYTES) {
                return new JsonResponse(['error' => $this->trans('template.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413, self::SECURITY_HEADERS);
            }
            $ext = strtolower($uploadedFile->getClientOriginalExtension());
            if (in_array($ext, ['yaml', 'yml'], true)) {
                $isYaml = true;
            } elseif ($ext !== 'json') {
                return new JsonResponse(['error' => $this->trans('template.invalid_blueprint_format')], 422, self::SECURITY_HEADERS);
            }
            $rawContent = (string) file_get_contents($uploadedFile->getPathname());
        } else {
            $rawContent = $request->getContent();
            $contentType = (string) $request->headers->get('Content-Type', '');
            if (str_contains($contentType, 'yaml') || str_contains($contentType, 'x-yaml')) {
                $isYaml = true;
            }
        }

        if (trim($rawContent) === '') {
            return new JsonResponse(['error' => $this->trans('template.invalid_blueprint_format')], 422, self::SECURITY_HEADERS);
        }

        $data = null;
        if ($isYaml) {
            try {
                $data = Yaml::parse($rawContent, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $this->trans('template.invalid_yaml', ['%error%' => $e->getMessage()])], 400, self::SECURITY_HEADERS);
            }
        } else {
            $data = json_decode($rawContent, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                try {
                    $data = Yaml::parse($rawContent, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
                } catch (\Throwable) {
                    return new JsonResponse(['error' => $this->trans('template.invalid_json')], 400, self::SECURITY_HEADERS);
                }
            }
        }

        if (!is_array($data)) {
            return new JsonResponse(['error' => $this->trans('template.invalid_blueprint_format')], 422, self::SECURITY_HEADERS);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? $this->sanitizeText((string) $data['name']) : '';
        if ($rawName === '') {
            return new JsonResponse(['error' => $this->trans('template.name_required')], 422, self::SECURITY_HEADERS);
        }
        $name = mb_substr($rawName, 0, self::MAX_NAME_LENGTH);

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? $this->sanitizeText((string) $data['description']) : null;
        $description = $rawDesc !== null && $rawDesc !== '' ? mb_substr($rawDesc, 0, self::MAX_DESCRIPTION_LENGTH) : null;

        $rawFields = $data['fields'] ?? [];
        if (!is_array($rawFields)) {
            return new JsonResponse(['error' => $this->trans('template.fields_must_be_array')], 422, self::SECURITY_HEADERS);
        }
        if (count($rawFields) > self::MAX_FIELDS_PER_TEMPLATE) {
            return new JsonResponse(['error' => $this->trans('template.max_fields_exceeded')], 422, self::SECURITY_HEADERS);
        }

        $template = new CollectionTemplate();
        $template->name = $name;
        $template->description = $description;
        $template->isSingleton = (bool) ($data['isSingleton'] ?? $data['is_singleton'] ?? false);

        $seenSlugs = [];
        foreach (array_slice($rawFields, 0, self::MAX_FIELDS_PER_TEMPLATE) as $i => $f) {
            if (!is_array($f)) {
                return new JsonResponse(['error' => $this->trans('template.fields_must_be_array')], 422, self::SECURITY_HEADERS);
            }
            $fieldName = isset($f['name']) && is_scalar($f['name']) ? $this->sanitizeText((string) $f['name']) : '';
            $fieldSlug = isset($f['slug']) && is_scalar($f['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $f['slug'])) : '';
            if ($fieldName === '') {
                $fieldName = 'Field ' . ($i + 1);
            }
            if ($fieldSlug === '') {
                $fieldSlug = 'field_' . ($i + 1);
            }
            if (isset($seenSlugs[$fieldSlug])) {
                $fieldSlug = mb_substr($fieldSlug, 0, 120) . '_' . ($i + 1);
            }
            $seenSlugs[$fieldSlug] = true;

            $rawType = is_string($f['type'] ?? null) ? trim($f['type']) : 'text';
            $fieldType = $this->normalizeFieldType($rawType);
            if ($fieldType === null) {
                return new JsonResponse([
                    'error' => $this->trans('template.field_invalid_type', ['%name%' => $fieldName, '%type%' => $rawType]),
                    'code'  => 'INVALID_FIELD_TYPE',
                ], 422, self::SECURITY_HEADERS);
            }

            $options = null;
            if (isset($f['options']) && is_array($f['options'])) {
                if ($this->getArrayDepth($f['options']) > self::MAX_OPTIONS_DEPTH) {
                    return new JsonResponse([
                        'error' => $this->trans('template.field_options_too_deep'),
                        'code'  => 'OPTIONS_TOO_DEEP',
                    ], 422, self::SECURITY_HEADERS);
                }
                if (strlen((string) json_encode($f['options'])) > self::MAX_OPTIONS_PAYLOAD_BYTES) {
                    return new JsonResponse([
                        'error' => $this->trans('template.field_options_too_large'),
                        'code'  => 'OPTIONS_TOO_LARGE',
                    ], 422, self::SECURITY_HEADERS);
                }
                $options = $f['options'];

                if ($fieldType === 'relation') {
                    $relation = is_array($options['relation'] ?? null) ? $options['relation'] : [];
                    $relType = (int) ($relation['type'] ?? $options['relationType'] ?? 1);
                    $target = $relation['collection_slug'] ?? $options['targetCollection'] ?? null;
                    if ($target === 'end_users') {
                        $options = ['targetCollection' => 'end_users', 'relation' => ['type' => $relType, 'collection_slug' => 'end_users']];
                    } elseif (is_string($target) && preg_match('/^[a-zA-Z0-9_\-]+$/', $target)) {
                        $options = ['relation' => ['type' => $relType, 'collection_slug' => $target]];
                    } else {
                        $options = ['relation' => ['type' => $relType, 'orphan' => true, 'collection_slug' => null]];
                    }
                }
            }

            $field = new CollectionTemplateField();
            $field->name = mb_substr($fieldName, 0, self::MAX_NAME_LENGTH);
            $field->slug = mb_substr($fieldSlug, 0, 128);
            $field->type = $fieldType;
            $field->options = $options;
            $field->order = min(1000, max(0, (int) ($f['order'] ?? $i)));
            $field->isRequired = (bool) ($f['isRequired'] ?? $f['is_required'] ?? false);
            $field->collectionTemplate = $template;
            $template->fields->add($field);
            $this->em->persist($field);
        }

        $this->em->persist($template);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'collection_template_import',
            project: null,
            input: ['name' => $template->name, 'fields_count' => $template->fields->count()],
            output: ['id' => $template->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return new JsonResponse($this->serialize($template, true), 201, self::SECURITY_HEADERS);
    }

    #[Route('/{id}/apply/{projectUuid}', name: 'apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(int $id, string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        if (!$this->getUser()) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $projectUuid)) {
            return new JsonResponse(['error' => $this->trans('template.target_project_not_found', [], 'Target project not found.')], 404, self::SECURITY_HEADERS);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.target_project_not_found', [], 'Target project not found.')], 404, self::SECURITY_HEADERS);
        }

        if ($project === null) {
            return new JsonResponse(['error' => $this->trans('template.target_project_not_found', [], 'Target project not found.')], 404, self::SECURITY_HEADERS);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('collection.create', $project);
        }

        // Quota check: max collections per project
        $activeCollectionsCount = 0;
        foreach ($project->collections as $col) {
            if (!method_exists($col, 'isDeleted') || !$col->isDeleted()) {
                $activeCollectionsCount++;
            }
        }
        if ($activeCollectionsCount >= self::MAX_COLLECTIONS_PER_PROJECT) {
            return new JsonResponse([
                'error' => $this->trans('template.project_collection_limit_exceeded', ['%max%' => self::MAX_COLLECTIONS_PER_PROJECT]),
                'code'  => 'PROJECT_COLLECTION_LIMIT_EXCEEDED',
            ], 422, self::SECURITY_HEADERS);
        }

        $data = [];
        if (trim($request->getContent()) !== '') {
            try {
                $data = $request->toArray();
            } catch (\Throwable) {
                return new JsonResponse(['error' => $this->trans('template.invalid_json')], 400, self::SECURITY_HEADERS);
            }
        }

        $customName = isset($data['name']) && is_scalar($data['name']) ? $this->sanitizeText((string) $data['name']) : '';
        $collectionName = $customName !== '' ? mb_substr($customName, 0, self::MAX_NAME_LENGTH) : $template->name;

        $customSlug = isset($data['slug']) && is_scalar($data['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $data['slug'])) : '';
        $baseSlug = $customSlug !== '' ? $customSlug : (preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($template->name)) ?: 'collection');
        $baseSlug = mb_substr($baseSlug, 0, 120);

        // Deduplication du slug de collection dans le projet
        $candidateSlug = $baseSlug;
        $suffix = 1;
        while ($this->collectionRepository->findOneBy(['project' => $project, 'slug' => $candidateSlug, 'deletedAt' => null]) !== null) {
            $candidateSlug = $baseSlug . '_' . $suffix++;
        }

        $collection = new Collection();
        $collection->project = $project;
        $collection->name = $collectionName;
        $collection->slug = $candidateSlug;
        $collection->description = $template->description;
        $collection->isSingleton = $template->isSingleton;
        $project->collections->add($collection);
        $this->em->persist($collection);

        foreach ($template->fields as $i => $tf) {
            $rawType = $tf->type;
            $fieldType = $this->normalizeFieldType($rawType) ?? 'text';

            $options = is_array($tf->options) ? $tf->options : null;
            if ($options !== null && (strlen((string) json_encode($options)) > self::MAX_OPTIONS_PAYLOAD_BYTES || $this->getArrayDepth($options) > self::MAX_OPTIONS_DEPTH)) {
                $options = null;
            }

            if ($fieldType === 'relation' && is_array($options)) {
                $relation = is_array($options['relation'] ?? null) ? $options['relation'] : [];
                $relType = (int) ($relation['type'] ?? $options['relationType'] ?? 1);
                $targetSlug = $relation['collection_slug'] ?? $options['targetCollection'] ?? null;

                if ($targetSlug === 'end_users') {
                    $options = [
                        'targetCollection' => 'end_users',
                        'relation' => ['type' => $relType, 'collection_slug' => 'end_users'],
                    ];
                } elseif (is_string($targetSlug) && $targetSlug !== '') {
                    $targetColl = $this->collectionRepository->findOneBy(['project' => $project, 'slug' => $targetSlug, 'deletedAt' => null]);
                    if ($targetColl !== null) {
                        $options = [
                            'relation' => [
                                'type' => $relType,
                                'collection' => $targetColl->id,
                                'collection_slug' => $targetColl->slug,
                            ],
                        ];
                    } else {
                        $options = [
                            'relation' => [
                                'type' => $relType,
                                'orphan' => true,
                                'collection_slug' => null,
                            ],
                        ];
                    }
                } else {
                    $options = [
                        'relation' => [
                            'type' => $relType,
                            'orphan' => true,
                            'collection_slug' => null,
                        ],
                    ];
                }
            }

            $field = new Field();
            $field->name = mb_substr($tf->name, 0, self::MAX_NAME_LENGTH);
            $field->slug = mb_substr($tf->slug, 0, 128);
            $field->type = $fieldType;
            $field->order = min(1000, max(0, $tf->order));
            $field->isRequired = $tf->isRequired;
            $field->options = $options;
            $field->collection = $collection;

            $collection->fields->add($field);
            $this->em->persist($field);
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'collection_template_apply',
            project: $project,
            input: ['template_id' => $id, 'collection_name' => $collectionName, 'collection_slug' => $candidateSlug],
            output: ['collection_id' => $collection->id, 'fields_count' => $collection->fields->count()],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return new JsonResponse([
            'id'          => $collection->id,
            'name'        => $collection->name,
            'slug'        => $collection->slug,
            'fieldsCount' => $collection->fields->count(),
        ], 201, self::SECURITY_HEADERS);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        if (!$this->getUser()) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException($this->trans('template.admin_required', [], 'Admin privileges required.'));
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('template.payload_too_large', [], 'Payload too large (max 512KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
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

        $rawName = isset($data['name']) && is_scalar($data['name']) ? $this->sanitizeText((string) $data['name']) : '';
        if ($rawName === '') {
            return new JsonResponse(['error' => $this->trans('template.name_required', [], 'name is required')], 422, self::SECURITY_HEADERS);
        }
        if (mb_strlen($rawName) > self::MAX_NAME_LENGTH) {
            return new JsonResponse(['error' => $this->trans('template.name_too_long', ['%max%' => self::MAX_NAME_LENGTH], 'Template name cannot exceed 255 characters.')], 422, self::SECURITY_HEADERS);
        }
        $name = mb_substr($rawName, 0, self::MAX_NAME_LENGTH);

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? $this->sanitizeText((string) $data['description']) : null;
        if ($rawDesc !== null && mb_strlen($rawDesc) > self::MAX_DESCRIPTION_LENGTH) {
            return new JsonResponse(['error' => $this->trans('template.description_too_long', ['%max%' => self::MAX_DESCRIPTION_LENGTH], 'Template description cannot exceed 2000 characters.')], 422, self::SECURITY_HEADERS);
        }
        $description = $rawDesc !== null && $rawDesc !== '' ? mb_substr($rawDesc, 0, self::MAX_DESCRIPTION_LENGTH) : null;

        $rawFields = $data['fields'] ?? [];
        if (!is_array($rawFields)) {
            return new JsonResponse(['error' => $this->trans('template.fields_must_be_array', [], 'fields must be an array')], 422, self::SECURITY_HEADERS);
        }
        if (count($rawFields) > self::MAX_FIELDS_PER_TEMPLATE) {
            return new JsonResponse(['error' => $this->trans('template.max_fields_exceeded', [], 'A template cannot have more than 100 fields.')], 422, self::SECURITY_HEADERS);
        }

        $template = new CollectionTemplate();
        $template->name        = $name;
        $template->description = $description;
        $template->isSingleton = (bool) ($data['is_singleton'] ?? false);

        $seenSlugs = [];
        foreach (array_slice($rawFields, 0, self::MAX_FIELDS_PER_TEMPLATE) as $i => $f) {
            if (!is_array($f)) {
                return new JsonResponse([
                    'error' => $this->trans('template.fields_must_be_array', [], 'fields must be an array'),
                    'code' => 'INVALID_FIELD_STRUCTURE',
                ], 422, self::SECURITY_HEADERS);
            }
            $fieldName = $this->sanitizeText((string) ($f['name'] ?? ''));
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
            if (isset($seenSlugs[$fieldSlug])) {
                return new JsonResponse([
                    'error' => $this->trans('template.duplicate_field_slug', ['%slug%' => $fieldSlug], "Duplicate field slug: {$fieldSlug}."),
                    'code' => 'DUPLICATE_FIELD_SLUG',
                ], 422, self::SECURITY_HEADERS);
            }
            $seenSlugs[$fieldSlug] = true;

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
                if ($this->getArrayDepth($f['options']) > self::MAX_OPTIONS_DEPTH) {
                    return new JsonResponse([
                        'error' => $this->trans('template.field_options_too_deep', [], 'Field options payload is nested too deeply (max 6 levels).'),
                        'code' => 'OPTIONS_TOO_DEEP',
                    ], 422, self::SECURITY_HEADERS);
                }
                $jsonOptions = json_encode($f['options']);
                if (strlen((string) $jsonOptions) > self::MAX_OPTIONS_PAYLOAD_BYTES) {
                    return new JsonResponse([
                        'error' => $this->trans('template.field_options_too_large', [], 'Field options payload too large (max 32KB).'),
                        'code' => 'OPTIONS_TOO_LARGE',
                    ], 422, self::SECURITY_HEADERS);
                }
                $options = $f['options'];

                // Si type relation : assainir pour éviter les IDs de BDD arbitraires orphelins
                if ($rawType === 'relation') {
                    $relation = is_array($options['relation'] ?? null) ? $options['relation'] : [];
                    $relType = (int) ($relation['type'] ?? $options['relationType'] ?? 1);
                    $target = $relation['collection_slug'] ?? $options['targetCollection'] ?? null;
                    if ($target === 'end_users') {
                        $options = ['targetCollection' => 'end_users', 'relation' => ['type' => $relType, 'collection_slug' => 'end_users']];
                    } elseif (is_string($target) && preg_match('/^[a-zA-Z0-9_\-]+$/', $target)) {
                        $options = ['relation' => ['type' => $relType, 'collection_slug' => $target]];
                    } else {
                        $options = ['relation' => ['type' => $relType, 'orphan' => true, 'collection_slug' => null]];
                    }
                }
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
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        if (!$this->getUser()) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException($this->trans('template.admin_required', [], 'Admin privileges required.'));
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('template.payload_too_large', [], 'Payload too large (max 512KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
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

        $rawName = isset($data['name']) && is_scalar($data['name']) ? $this->sanitizeText((string) $data['name']) : '';
        $name = $rawName !== '' ? mb_substr($rawName, 0, self::MAX_NAME_LENGTH) : $collection->name;

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? $this->sanitizeText((string) $data['description']) : null;
        $description = $rawDesc !== null && $rawDesc !== '' ? mb_substr($rawDesc, 0, self::MAX_DESCRIPTION_LENGTH) : $collection->description;

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

            if ($fieldType === 'relation' && $options !== null) {
                $relation = is_array($options['relation'] ?? null) ? $options['relation'] : [];
                $relType = (int) ($relation['type'] ?? $options['relationType'] ?? 1);
                $target = $relation['collection'] ?? $options['targetCollection'] ?? null;

                if ($target === 'end_users') {
                    $options = [
                        'targetCollection' => 'end_users',
                        'relation' => ['type' => $relType, 'collection_slug' => 'end_users'],
                    ];
                } else {
                    $targetColl = null;
                    if (is_int($target) || (is_string($target) && ctype_digit($target))) {
                        $targetColl = $this->collectionRepository->findOneBy(['id' => (int) $target, 'project' => $project, 'deletedAt' => null]);
                    } elseif (is_string($target) && $target !== '') {
                        $targetColl = $this->collectionRepository->findOneBy(['slug' => $target, 'project' => $project, 'deletedAt' => null]);
                    }

                    if ($targetColl !== null) {
                        $options = [
                            'relation' => [
                                'type' => $relType,
                                'collection_slug' => $targetColl->slug,
                            ],
                        ];
                    } else {
                        $options = [
                            'relation' => [
                                'type' => $relType,
                                'orphan' => true,
                                'collection_slug' => null,
                            ],
                        ];
                    }
                }
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
    public function delete(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimit = $this->checkRateLimit($request))) {
            return $rateLimit;
        }

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
