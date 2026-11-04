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

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/column-settings', name: 'api_column_settings_')]
class ColumnSettingController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private ColumnSettingRepository $columnSettingRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['error' => 'Authentication required'], 401);
        }

        $resolved = $this->resolve($projectUuid, $collectionSlug);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$project, $collection] = $resolved;

        $setting = $this->columnSettingRepository->findOneByUserAndCollection($user, $collection);

        return $this->json([
            'visibleColumns' => $setting?->visibleColumns ?? [],
        ]);
    }

    #[Route('', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['error' => 'Authentication required'], 401);
        }

        $resolved = $this->resolve($projectUuid, $collectionSlug);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$project, $collection] = $resolved;

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        $rawColumns = $data['visible_columns'] ?? [];
        if (!is_array($rawColumns)) {
            return $this->json(['error' => 'visible_columns must be an array'], 422);
        }
        $columns = [];
        foreach (array_slice($rawColumns, 0, 50) as $col) {
            if (is_scalar($col)) {
                $trimmed = trim((string) $col);
                if ($trimmed !== '' && mb_strlen($trimmed) <= 100 && !preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
                    $columns[] = $trimmed;
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

        return $this->json(['visibleColumns' => $setting->visibleColumns]);
    }

    private function resolve(string $projectUuid, string $collectionSlug): array|JsonResponse
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
        return [$project, $collection];
    }
}
