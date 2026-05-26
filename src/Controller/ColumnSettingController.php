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
        [$project, $collection] = $this->resolve($projectUuid, $collectionSlug);

        $setting = $this->columnSettingRepository->findOneByUserAndCollection($user, $collection);

        return $this->json([
            'visibleColumns' => $setting?->visibleColumns ?? [],
        ]);
    }

    #[Route('', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $user = $this->getUser();
        [$project, $collection] = $this->resolve($projectUuid, $collectionSlug);

        $data    = $request->toArray();
        $columns = $data['visible_columns'] ?? [];

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

    private function resolve(string $projectUuid, string $collectionSlug): array
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        $collection = $project ? $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug) : null;
        return [$project, $collection];
    }
}
