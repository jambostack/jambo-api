<?php

namespace App\Controller;

use App\Entity\ColumnSetting;
use App\Repository\CollectionRepository;
use App\Repository\ColumnSettingRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/column-settings', name: 'api_column_settings_')]
class ColumnSettingController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 262144; // 256 KB

    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private ColumnSettingRepository $columnSettingRepository,
        private EntityManagerInterface $em,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        $resolved = $this->resolve($projectUuid, $collectionSlug);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$project, $collection] = $resolved;

        $this->denyAccessUnlessGranted('project.view', $project);

        $setting = $this->columnSettingRepository->findOneByUserAndCollection($user, $collection);

        return new JsonResponse([
            'visibleColumns' => $setting?->visibleColumns ?? [],
        ]);
    }

    #[Route('', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        $resolved = $this->resolve($projectUuid, $collectionSlug);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$project, $collection] = $resolved;

        $this->denyAccessUnlessGranted('project.view', $project);

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.payload_too_large', [], 'Column setting payload too large (max 256KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.invalid_json', [], 'Invalid JSON body.'),
                'code' => 'INVALID_JSON',
            ], 400);
        }
        $rawColumns = $data['visible_columns'] ?? [];
        if (!is_array($rawColumns)) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.columns_must_be_array', [], 'Visible columns must be an array.'),
                'code' => 'COLUMNS_MUST_BE_ARRAY',
            ], 422);
        }
        $columns = [];
        foreach (array_slice($rawColumns, 0, 50) as $col) {
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

        return new JsonResponse(['visibleColumns' => $setting->visibleColumns]);
    }

    private function resolve(string $projectUuid, string $collectionSlug): array|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (strlen($collectionSlug) > 100 || !preg_match('/^[a-z0-9_-]+$/i', $collectionSlug)) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.collection_not_found', [], 'Collection not found'),
                'code' => 'COLLECTION_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if (!$project) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.project_not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if (!$collection) {
            return new JsonResponse([
                'error' => $this->trans('column_setting.collection_not_found', [], 'Collection not found'),
                'code' => 'COLLECTION_NOT_FOUND',
            ], 404);
        }
        return [$project, $collection];
    }
}
