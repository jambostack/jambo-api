<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTemplateRepository;
use App\Service\AuditService;
use App\Service\ProjectTemplateBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/project-templates', name: 'api_project_template_')]
class ProjectTemplateController extends AbstractController
{
    public const MAX_TEMPLATES_COUNT = 100;
    public const MAX_COLLECTIONS_PER_TEMPLATE = 100;
    public const MAX_FIELDS_PER_COLLECTION = 100;
    public const MAX_OPTIONS_PAYLOAD_BYTES = 32768; // 32KB
    public const MAX_OPTIONS_DEPTH = 6;
    public const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    public const MAX_NAME_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 2000;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly ProjectTemplateRepository $templateRepository,
        private readonly ProjectTemplateBuilder $builder,
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
        return trim(strip_tags(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text)));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(?Request $request = null): JsonResponse
    {
        if ($this->templateLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        $templates = $this->templateRepository->findBy([], ['createdAt' => 'DESC']);

        return new JsonResponse([
            'data' => array_map(fn ($t) => [
                'id'          => $t->id,
                'name'        => $t->name,
                'description' => $t->description,
                'createdAt'   => $t->createdAt->format(\DateTimeInterface::ATOM),
            ], $templates),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, ?Request $request = null): JsonResponse
    {
        if ($this->templateLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        return new JsonResponse([
            'id'          => $template->id,
            'name'        => $template->name,
            'description' => $template->description,
            'structure'   => $template->structure,
            'createdAt'   => $template->createdAt->format(\DateTimeInterface::ATOM),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{id}/export', name: 'export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function export(int $id, ?Request $request = null): Response
    {
        if ($this->templateLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
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
            'type'        => 'project_template',
            'id'          => $template->id,
            'name'        => $template->name,
            'description' => $template->description,
            'structure'   => $template->structure,
            'createdAt'   => $template->createdAt->format(\DateTimeInterface::ATOM),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($template->name)) ?: 'template';
        $filename = sprintf('project-template-%d-%s.json', $template->id, $safeSlug);

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
        if ($this->templateLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
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
                'error' => $this->trans('template.max_templates_exceeded', ['%max%' => self::MAX_TEMPLATES_COUNT], sprintf('Maximum number of templates reached (%d).', self::MAX_TEMPLATES_COUNT)),
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
            $rawName = isset($data['project']['name']) && is_scalar($data['project']['name']) ? $this->sanitizeText((string) $data['project']['name']) : 'Imported Project Template';
        }
        $name = mb_substr($rawName, 0, self::MAX_NAME_LENGTH);

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? $this->sanitizeText((string) $data['description']) : null;
        $desc = $rawDesc !== null && $rawDesc !== '' ? mb_substr($rawDesc, 0, self::MAX_DESCRIPTION_LENGTH) : null;

        $rawStructure = $data['structure'] ?? $data;
        if (!is_array($rawStructure)) {
            return new JsonResponse(['error' => $this->trans('template.invalid_blueprint_format')], 422, self::SECURITY_HEADERS);
        }

        $rawCollections = $rawStructure['collections'] ?? $data['collections'] ?? [];
        if (!is_array($rawCollections) || empty($rawCollections)) {
            return new JsonResponse(['error' => $this->trans('template.collections_required')], 422, self::SECURITY_HEADERS);
        }
        if (count($rawCollections) > self::MAX_COLLECTIONS_PER_TEMPLATE) {
            return new JsonResponse(['error' => $this->trans('template.too_many_collections', ['%max%' => self::MAX_COLLECTIONS_PER_TEMPLATE])], 422, self::SECURITY_HEADERS);
        }

        $defaultLocale = (string) ($rawStructure['defaultLocale'] ?? $data['project']['default_locale'] ?? 'en');
        if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', $defaultLocale) || strlen($defaultLocale) > 10) {
            $defaultLocale = 'en';
        }
        $locales = is_array($rawStructure['locales'] ?? null) ? array_values(array_filter($rawStructure['locales'], 'is_string')) : [$defaultLocale];
        if (empty($locales)) {
            $locales = [$defaultLocale];
        }

        $sanitizedCollections = [];
        $availableCollectionSlugs = [];

        foreach ($rawCollections as $col) {
            if (is_array($col) && !empty($col['slug']) && is_scalar($col['slug'])) {
                $availableCollectionSlugs[] = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $col['slug']));
            }
        }
        $availableCollectionSlugs = array_values(array_filter(array_unique($availableCollectionSlugs)));

        $seenColSlugs = [];
        foreach ($rawCollections as $cIdx => $coll) {
            if (!is_array($coll)) {
                return new JsonResponse(['error' => $this->trans('template.invalid_blueprint_format')], 422, self::SECURITY_HEADERS);
            }
            $cName = isset($coll['name']) && is_scalar($coll['name']) ? $this->sanitizeText((string) $coll['name']) : '';
            if ($cName === '') {
                $cName = 'Collection ' . ($cIdx + 1);
            }
            $cName = mb_substr($cName, 0, self::MAX_NAME_LENGTH);

            $cSlug = isset($coll['slug']) && is_scalar($coll['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $coll['slug'])) : '';
            if ($cSlug === '') {
                $cSlug = 'collection_' . ($cIdx + 1);
            }
            $cSlug = mb_substr($cSlug, 0, 128);
            if (isset($seenColSlugs[$cSlug])) {
                $cSlug = mb_substr($cSlug, 0, 120) . '_' . ($cIdx + 1);
            }
            $seenColSlugs[$cSlug] = true;

            $cDesc = isset($coll['description']) && is_scalar($coll['description']) ? $this->sanitizeText((string) $coll['description']) : null;
            if ($cDesc !== null && $cDesc !== '') {
                $cDesc = mb_substr($cDesc, 0, self::MAX_DESCRIPTION_LENGTH);
            } else {
                $cDesc = null;
            }

            $rawFields = $coll['fields'] ?? [];
            if (!is_array($rawFields)) {
                return new JsonResponse(['error' => $this->trans('template.fields_must_be_array')], 422, self::SECURITY_HEADERS);
            }
            if (count($rawFields) > self::MAX_FIELDS_PER_COLLECTION) {
                return new JsonResponse(['error' => $this->trans('template.max_fields_exceeded')], 422, self::SECURITY_HEADERS);
            }

            $sanitizedFields = [];
            $seenFieldSlugs = [];

            foreach ($rawFields as $fIdx => $f) {
                if (!is_array($f)) {
                    return new JsonResponse(['error' => $this->trans('template.fields_must_be_array')], 422, self::SECURITY_HEADERS);
                }
                $fName = isset($f['name']) && is_scalar($f['name']) ? $this->sanitizeText((string) $f['name']) : '';
                if ($fName === '') {
                    $fName = 'Field ' . ($fIdx + 1);
                }
                $fName = mb_substr($fName, 0, self::MAX_NAME_LENGTH);

                $fSlug = isset($f['slug']) && is_scalar($f['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $f['slug'])) : '';
                if ($fSlug === '') {
                    $fSlug = 'field_' . ($fIdx + 1);
                }
                $fSlug = mb_substr($fSlug, 0, 128);
                if (isset($seenFieldSlugs[$fSlug])) {
                    $fSlug = mb_substr($fSlug, 0, 120) . '_' . ($fIdx + 1);
                }
                $seenFieldSlugs[$fSlug] = true;

                $rawType = isset($f['type']) && is_scalar($f['type']) ? (string) $f['type'] : 'text';
                $fType = $this->normalizeFieldType($rawType);
                if ($fType === null) {
                    return new JsonResponse([
                        'error' => $this->trans('template.field_invalid_type', ['%name%' => $fName, '%type%' => $rawType], "Field {$fName} has an unsupported type: {$rawType}."),
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

                    if ($fType === 'relation') {
                        $relation = is_array($options['relation'] ?? null) ? $options['relation'] : [];
                        $relType = (int) ($relation['type'] ?? $options['relationType'] ?? 1);
                        $target = $relation['collection_slug'] ?? $relation['collection'] ?? $options['targetCollection'] ?? null;
                        if ($target === 'end_users') {
                            $options = ['targetCollection' => 'end_users', 'relation' => ['type' => $relType, 'collection_slug' => 'end_users']];
                        } elseif (is_string($target) && in_array($target, $availableCollectionSlugs, true)) {
                            $options = ['relation' => ['type' => $relType, 'collection_slug' => $target]];
                        } else {
                            $options = ['relation' => ['type' => $relType, 'orphan' => true, 'collection_slug' => null]];
                        }
                    }
                }

                $sanitizedFields[] = [
                    'name'       => $fName,
                    'slug'       => $fSlug,
                    'type'       => $fType,
                    'options'    => $options,
                    'order'      => min(1000, max(0, (int) ($f['order'] ?? $fIdx))),
                    'isRequired' => (bool) ($f['isRequired'] ?? $f['is_required'] ?? false),
                ];
            }

            $sanitizedCollections[] = [
                'name'        => $cName,
                'slug'        => $cSlug,
                'description' => $cDesc,
                'isSingleton' => (bool) ($coll['isSingleton'] ?? $coll['is_singleton'] ?? false),
                'order'       => min(1000, max(0, (int) ($coll['order'] ?? $cIdx))),
                'fields'      => $sanitizedFields,
            ];
        }

        $structure = [
            'defaultLocale' => $defaultLocale,
            'locales'       => $locales,
            'collections'   => $sanitizedCollections,
        ];

        $template = new ProjectTemplate();
        $template->name        = $name;
        $template->description = $desc;
        $template->structure   = $structure;

        $this->em->persist($template);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'project_template_import',
            project: null,
            input: ['name' => $name, 'collections_count' => count($sanitizedCollections)],
            output: ['id' => $template->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
            source: 'project_template'
        );
        $this->auditService?->flush();

        return new JsonResponse([
            'id'        => $template->id,
            'name'      => $template->name,
            'createdAt' => $template->createdAt->format(\DateTimeInterface::ATOM),
        ], 201, self::SECURITY_HEADERS);
    }

    #[Route('/from-project/{projectUuid}', name: 'save_from_project', methods: ['POST'])]
    public function saveFromProject(string $projectUuid, Request $request): JsonResponse
    {
        if ($this->templateLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        try {
            $user = $this->getUser();
        } catch (\Throwable) {
            $user = null;
        }

        if (!$user && $request->attributes->get('_project') === null) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('template.payload_too_large', [], 'Payload too large (max 512KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $projectUuid)) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($project === null) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $templateCount = $this->templateRepository->count([]);
        if ($templateCount >= self::MAX_TEMPLATES_COUNT) {
            return new JsonResponse([
                'error' => $this->trans('template.max_templates_exceeded', ['%max%' => self::MAX_TEMPLATES_COUNT], sprintf('Maximum number of templates reached (%d).', self::MAX_TEMPLATES_COUNT)),
                'code' => 'MAX_TEMPLATES_EXCEEDED',
            ], 422, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400, self::SECURITY_HEADERS);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : null;
        $name = $rawName !== null && $rawName !== '' ? mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawName), 0, self::MAX_NAME_LENGTH) : null;

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? trim((string) $data['description']) : null;
        $desc = $rawDesc !== null && $rawDesc !== '' ? mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawDesc), 0, self::MAX_DESCRIPTION_LENGTH) : null;

        $template = $this->builder->exportFromProject($projectUuid, $name, $desc);

        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }

        $this->auditService?->log(
            toolName: 'project_template_export',
            project: $project,
            input: ['project_uuid' => $projectUuid, 'name' => $name],
            output: ['success' => true, 'template_id' => $template->id],
            status: 'success',
            createdBy: $user?->getUserIdentifier() ?? 'system',
            source: 'project_template'
        );
        $this->auditService?->flush();

        return new JsonResponse([
            'id'        => $template->id,
            'name'      => $template->name,
            'createdAt' => $template->createdAt->format(\DateTimeInterface::ATOM),
        ], 201, self::SECURITY_HEADERS);
    }

    #[Route('/{id}/apply', name: 'apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(int $id, Request $request): JsonResponse
    {
        if ($this->templateLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('template.payload_too_large', [], 'Payload too large (max 512KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
        }

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400, self::SECURITY_HEADERS);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : '';
        $projectName = $rawName !== '' ? mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawName), 0, self::MAX_NAME_LENGTH) : $template->name;

        $project = $this->builder->applyTemplate($template, $projectName, $user);

        $this->auditService?->log(
            toolName: 'project_template_apply',
            project: $project,
            input: ['template_id' => $id, 'project_name' => $projectName],
            output: ['success' => true, 'project_uuid' => $project->uuid?->toString()],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'project_template'
        );
        $this->auditService?->flush();

        return new JsonResponse([
            'uuid' => $project->uuid?->toString(),
            'name' => $project->name,
        ], 201, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, ?Request $request = null): JsonResponse
    {
        if ($this->templateLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        try {
            $user = $this->getUser();
        } catch (\Throwable) {
            $user = null;
        }

        $this->auditService?->log(
            toolName: 'project_template_delete',
            project: null,
            input: ['template_id' => $id, 'template_name' => $template->name],
            output: ['success' => true],
            status: 'success',
            createdBy: $user?->getUserIdentifier() ?? 'system',
            source: 'project_template'
        );
        $this->auditService?->flush();

        $this->em->remove($template);
        $this->em->flush();

        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
    }
}
