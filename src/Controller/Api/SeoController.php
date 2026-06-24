<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\Seo\SeoAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SeoController extends AbstractController
{
    public function __construct(
        private readonly SeoAnalyzer $analyzer,
        private readonly ContentEntryRepository $entryRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly CollectionRepository $collectionRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin-api/{projectUuid}/seo/scores', name: 'admin_seo_scores', methods: ['GET'])]
    public function scores(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $collectionSlug = $request->query->get('collection');
        $scoreFilter = $request->query->get('score_filter'); // 'poor' (< 50), 'ok' (50-79), 'good' (>= 80)
        $search = $request->query->get('search');

        $scores = [];
        $collections = $collectionSlug
            ? array_filter([$this->collectionRepo->findOneByProjectAndSlug($project, $collectionSlug)])
            : $project->collections->toArray();

        foreach ($collections as $collection) {
            if (!$collection || $collection->isDeleted()) {
                continue;
            }

            $entries = $this->entryRepo->findByCollectionPaginated($collection, 1, 500, null, 'published');
            foreach ($entries as $entry) {
                if ($search && stripos($entry->metaTitle ?? '', $search) === false) {
                    continue;
                }

                $seoScore = $this->analyzer->analyze($entry);

                if ($scoreFilter === 'poor' && $seoScore->score >= 50) {
                    continue;
                }
                if ($scoreFilter === 'ok' && ($seoScore->score < 50 || $seoScore->score >= 80)) {
                    continue;
                }
                if ($scoreFilter === 'good' && $seoScore->score < 80) {
                    continue;
                }

                $scores[] = [
                    'uuid' => $entry->uuid?->toRfc4122(),
                    'collection' => $collection->slug,
                    'metaTitle' => $entry->metaTitle,
                    'metaDescription' => $entry->metaDescription,
                    'slug' => $entry->slug,
                    'score' => $seoScore->score,
                    'seoScore' => $entry->seoScore,
                ];
            }
        }

        usort($scores, fn ($a, $b) => $a['score'] <=> $b['score']);

        $avgScore = count($scores) > 0
            ? (int) round(array_sum(array_column($scores, 'score')) / count($scores))
            : null;

        return $this->json([
            'data' => $scores,
            'meta' => ['total' => count($scores), 'avg_score' => $avgScore],
        ]);
    }

    #[Route('/admin-api/{projectUuid}/seo/bulk', name: 'admin_seo_bulk', methods: ['PUT'])]
    public function bulkUpdate(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $data = $request->toArray();
        $entries = $data['entries'] ?? [];
        $updated = 0;

        foreach ($entries as $entryData) {
            $entry = $this->entryRepo->findOneBy(['uuid' => $entryData['uuid'] ?? '']);
            if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid) {
                continue;
            }

            if (array_key_exists('metaTitle', $entryData)) {
                $entry->metaTitle = $entryData['metaTitle'];
            }
            if (array_key_exists('metaDescription', $entryData)) {
                $entry->metaDescription = $entryData['metaDescription'];
            }
            if (array_key_exists('slug', $entryData)) {
                $entry->slug = $entryData['slug'];
            }
            if (array_key_exists('canonicalUrl', $entryData)) {
                $entry->canonicalUrl = $entryData['canonicalUrl'];
            }
            $updated++;
        }

        $this->em->flush();

        return $this->json(['updated' => $updated]);
    }

    #[Route('/admin-api/{projectUuid}/seo/audit/{entryUuid}', name: 'admin_seo_audit', methods: ['GET'])]
    public function audit(string $projectUuid, string $entryUuid): JsonResponse
    {
        $entry = $this->entryRepo->findOneBy(['uuid' => $entryUuid]);
        if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Entry not found.'], 404);
        }

        $report = $this->analyzer->audit($entry);

        return $this->json([
            'score' => $report->score,
            'brokenLinks' => $report->brokenLinks,
            'warnings' => $report->warnings,
        ]);
    }
}
