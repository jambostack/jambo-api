<?php

namespace App\Controller;

use App\Entity\ColumnSetting;
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
    private const MAX_PAYLOAD_BYTES = 262144; // 256 KB
    public const MAX_COLUMNS_COUNT = 50;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private ColumnSettingRepository $columnSettingRepository,
        private EntityManagerInterface $em,
        private ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.project_limiter')]
        private ?RateLimiterFactory $projectLimiter = null,
        private ?AuditService $auditService = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug, ?Request $request = null): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($this->projectLimiter !== null && $request !== null) {
            $identifier = $user->id ? ('user_' . $user->id) : ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('column_setting.rate_limit_exceeded', [], 'Too many column settings requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
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
        if (!$user instanceof \App\Entity\User) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($this->projectLimiter !== null && $request !== null) {
            $identifier = $user->id ? ('user_' . $user->id) : ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('column_setting.rate_limit_exceeded', [], 'Too many column settings requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
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

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.payload_too_large', [], 'Column setting payload too large (max 256KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.invalid_json', [], 'Invalid JSON body.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }
        $rawColumns = $data['visible_columns'] ?? [];
        if (!is_array($rawColumns)) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.columns_must_be_array', [], 'Visible columns must be an array.'),
                'code' => 'COLUMNS_MUST_BE_ARRAY',
            ], 422);
        }
        $columns = [];
        foreach (array_slice($rawColumns, 0, self::MAX_COLUMNS_COUNT) as $col) {
            if (is_scalar($col)) {
                $trimmed = trim((string) $col);
                if ($trimmed !== '' && mb_strlen($trimmed) <= 100 && !preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
                    $columns[] = strip_tags($trimmed);
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
                'collection' => $collectionSlug,
                'columns_count' => count($columns),
            ],
            output: null,
            status: 'success',
            source: 'column_setting_controller',
        );

        return $this->secureJson(['visibleColumns' => $setting->visibleColumns]);
    }

    private function resolve(string $projectUuid, string $collectionSlug): array|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (strlen($collectionSlug) > 100 || !preg_match('/^[a-z0-9_-]+$/i', $collectionSlug)) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.collection_not_found', [], 'Collection not found'),
                'code' => 'COLLECTION_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if (!$project) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if (!$collection) {
            return $this->secureJson([
                'error' => $this->trans('column_setting.collection_not_found', [], 'Collection not found'),
                'code' => 'COLLECTION_NOT_FOUND',
            ], 404);
        }
        return [$project, $collection];
    }
}
