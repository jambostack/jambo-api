<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ColumnSetting;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\ColumnSettingRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/column-settings', name: 'api_column_settings_')]
class ColumnSettingController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 32768; // 32 KB
    public const MAX_COLUMNS_COUNT = 50;
    public const MAX_JSON_DEPTH = 4;

    private const FORBIDDEN_KEYS = [
        '__proto__',
        'constructor',
        'prototype',
    ];

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                 => 'no-cache',
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly CollectionRepository $collectionRepository,
        private readonly ColumnSettingRepository $columnSettingRepository,
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.project_limiter')]
        private readonly ?RateLimiterFactory $projectLimiter = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function checkRateLimit(Request $request, User $user): ?JsonResponse
    {
        if ($this->projectLimiter !== null) {
            $identifier = $user->id ? ('user_' . $user->id) : ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('column_setting.rate_limit_exceeded', [], 'Too many column settings requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    private function getJsonDepth(mixed $data): int
    {
        if (!is_array($data)) {
            return 0;
        }
        $max = 0;
        foreach ($data as $val) {
            $depth = $this->getJsonDepth($val);
            if ($depth > $max) {
                $max = $depth;
            }
        }
        return $max + 1;
    }

    private function hasForbiddenKeys(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }
        foreach ($data as $key => $val) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                return true;
            }
            if (is_array($val) && $this->hasForbiddenKeys($val)) {
                return true;
            }
        }
        return false;
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug, ?Request $request = null): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.auth_required', [], 'Authentication required.'),
                'code'  => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($request !== null && ($error = $this->checkRateLimit($request, $user))) {
            return $error;
        }

        $resolved = $this->resolve($projectUuid, $collectionSlug);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$project, $collection] = $resolved;

        $this->denyAccessUnlessGranted('project.view', $project);

        $setting = $this->columnSettingRepository->findOneByUserAndCollection($user, $collection);

        return $this->secureJson([
            'visibleColumns' => $setting?->visibleColumns ?? [],
        ]);
    }

    #[Route('', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, ?Request $request = null): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.auth_required', [], 'Authentication required.'),
                'code'  => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($request !== null && ($error = $this->checkRateLimit($request, $user))) {
            return $error;
        }

        $resolved = $this->resolve($projectUuid, $collectionSlug);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$project, $collection] = $resolved;

        $this->denyAccessUnlessGranted('project.view', $project);

        if ($request === null) {
            return $this->secureJson(['visibleColumns' => []]);
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.payload_too_large', [], 'Column setting payload too large (max 32KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        if ($this->getJsonDepth($data) > self::MAX_JSON_DEPTH) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.json_too_deep', ['%max%' => (string) self::MAX_JSON_DEPTH], sprintf('Column settings JSON depth limit exceeded (max %d levels).', self::MAX_JSON_DEPTH)),
                'code'  => 'JSON_TOO_DEEP',
            ], 400);
        }

        if ($this->hasForbiddenKeys($data)) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.prototype_pollution', [], 'Forbidden property detected in column settings payload.'),
                'code'  => 'FORBIDDEN_PROPERTY',
            ], 400);
        }

        $rawColumns = $data['visible_columns'] ?? $data['columns'] ?? null;
        if (!is_array($rawColumns)) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.columns_must_be_array', [], 'Visible columns must be an array.'),
                'code'  => 'COLUMNS_MUST_BE_ARRAY',
            ], 422);
        }

        $columns = [];
        foreach (array_slice($rawColumns, 0, self::MAX_COLUMNS_COUNT) as $col) {
            if (is_scalar($col)) {
                $clean = strip_tags(trim((string) $col));
                if ($clean !== '' && mb_strlen($clean) <= 64 && !preg_match('/[\x00-\x1F\x7F]/', $clean) && preg_match('/^[a-zA-Z0-9_\-\.\(\)]+$/', $clean)) {
                    $columns[] = $clean;
                }
            } elseif (is_array($col)) {
                $id = isset($col['id']) && is_scalar($col['id']) ? strip_tags(trim((string) $col['id'])) : null;
                if ($id !== null && $id !== '' && mb_strlen($id) <= 64 && !preg_match('/[\x00-\x1F\x7F]/', $id) && preg_match('/^[a-zA-Z0-9_\-\.\(\)]+$/', $id)) {
                    $cleanCol = [
                        'id'      => $id,
                        'visible' => (bool) ($col['visible'] ?? true),
                    ];
                    if (isset($col['width']) && is_numeric($col['width'])) {
                        $cleanCol['width'] = max(20, min(2000, (int) $col['width']));
                    }
                    if (isset($col['order']) && is_numeric($col['order'])) {
                        $cleanCol['order'] = max(0, min(100, (int) $col['order']));
                    }

                    $columns[] = $cleanCol;
                }
            }
        }

        $setting = $this->columnSettingRepository->findOneByUserAndCollection($user, $collection);

        if ($setting === null) {
            $setting = new ColumnSetting();
            $setting->user       = $user;
            $setting->collection = $collection;
            $this->em->persist($setting);
        }

        $setting->visibleColumns = $columns;
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'column_setting_update',
            project: $project,
            input: [
                'collection'    => $collectionSlug,
                'columns_count' => count($columns),
            ],
            output: null,
            status: 'success',
            source: 'column_setting_controller',
        );

        return $this->secureJson(['visibleColumns' => $setting->visibleColumns]);
    }

    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function reset(string $projectUuid, string $collectionSlug, ?Request $request = null): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.auth_required', [], 'Authentication required.'),
                'code'  => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($request !== null && ($error = $this->checkRateLimit($request, $user))) {
            return $error;
        }

        $resolved = $this->resolve($projectUuid, $collectionSlug);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$project, $collection] = $resolved;

        $this->denyAccessUnlessGranted('project.view', $project);

        $setting = $this->columnSettingRepository->findOneByUserAndCollection($user, $collection);
        if ($setting !== null) {
            $this->em->remove($setting);
            $this->em->flush();
        }

        $this->auditService?->log(
            toolName: 'column_setting_reset',
            project: $project,
            input: ['collection' => $collectionSlug],
            output: ['status' => 'reset'],
            status: 'success',
            source: 'column_setting_controller',
        );

        return $this->secureJson([
            'status'         => 'reset',
            'message'        => $this->trans('column_setting.reset_success', [], 'Column settings successfully reset to default.'),
            'visibleColumns' => [],
        ]);
    }

    private function resolve(string $projectUuid, string $collectionSlug): array|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (strlen($collectionSlug) > 100 || !preg_match('/^[a-z0-9_-]+$/i', $collectionSlug)) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.collection_not_found', [], 'Collection not found'),
                'code'  => 'COLLECTION_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if (!$project) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if (!$collection) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.collection_not_found', [], 'Collection not found'),
                'code'  => 'COLLECTION_NOT_FOUND',
            ], 404);
        }
        return [$project, $collection];
    }
}
