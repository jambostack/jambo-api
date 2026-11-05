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

use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class SeoController extends AbstractController
{
    public function __construct(
        private readonly SeoAnalyzer $analyzer,
        private readonly ContentEntryRepository $entryRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly CollectionRepository $collectionRepo,
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    public const MAX_BULK_ENTRIES = 100;
    public const MAX_SCORES_LIMIT = 500;
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB

    #[Route('/admin-api/{projectUuid}/seo/scores', name: 'admin_seo_scores', methods: ['GET'])]
    public function scores(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_seo.project_not_found', [], 'Project not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $project);

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
                if (count($scores) >= self::MAX_SCORES_LIMIT) {
                    break 2;
                }

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
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_seo.project_not_found', [], 'Project not found.')], 404);
        }
        $this->denyAccessUnlessGranted('content.update', $project);

        $content = $request->getContent();
        if (strlen($content) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->trans('admin_seo.payload_too_large', [], 'Payload too large (max 64KB).')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_seo.invalid_json', [], 'Invalid JSON')], 400);
        }

        $entries = $data['entries'] ?? [];
        if (!is_array($entries)) {
            return $this->json(['error' => $this->trans('admin_seo.invalid_json', [], 'Invalid JSON')], 400);
        }

        if (count($entries) > self::MAX_BULK_ENTRIES) {
            return $this->json(['error' => $this->trans('admin_seo.too_many_entries', ['%max%' => self::MAX_BULK_ENTRIES], 'Too many entries for bulk update (max ' . self::MAX_BULK_ENTRIES . ').')], 422);
        }

        $updated = 0;

        foreach ($entries as $entryData) {
            if (!is_array($entryData)) {
                continue;
            }

            $rawUuid = (string) ($entryData['uuid'] ?? '');
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rawUuid)) {
                continue;
            }

            try {
                $entry = $this->entryRepo->findOneBy(['uuid' => $rawUuid, 'project' => $project]);
            } catch (\Throwable) {
                $entry = null;
            }
            if (!$entry || (method_exists($entry, 'isDeleted') && $entry->isDeleted())) {
                continue;
            }

            if (array_key_exists('metaTitle', $entryData)) {
                $rawTitle = $entryData['metaTitle'];
                $entry->metaTitle = $rawTitle !== null ? mb_substr(strip_tags((string) $rawTitle), 0, 255) : null;
            }
            if (array_key_exists('metaDescription', $entryData)) {
                $rawDesc = $entryData['metaDescription'];
                $entry->metaDescription = $rawDesc !== null ? mb_substr(strip_tags((string) $rawDesc), 0, 1000) : null;
            }
            if (array_key_exists('slug', $entryData)) {
                $rawSlug = $entryData['slug'];
                if ($rawSlug !== null) {
                    $cleanSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $rawSlug);
                    if ($cleanSlug !== '') {
                        $entry->slug = mb_substr($cleanSlug, 0, 128);
                    }
                }
            }
            if (array_key_exists('canonicalUrl', $entryData)) {
                $rawCanonical = $entryData['canonicalUrl'];
                if ($rawCanonical !== null) {
                    $cleanCanonical = trim(str_replace(["\r", "\n", "\0"], '', (string) $rawCanonical));
                    if ($cleanCanonical !== '') {
                        if (!filter_var($cleanCanonical, FILTER_VALIDATE_URL) || (!str_starts_with($cleanCanonical, 'http://') && !str_starts_with($cleanCanonical, 'https://'))) {
                            return $this->json(['error' => $this->trans('admin_seo.invalid_canonical_url', [], 'Invalid canonical URL format.')], 422);
                        }
                        $entry->canonicalUrl = mb_substr($cleanCanonical, 0, 2048);
                    } else {
                        $entry->canonicalUrl = null;
                    }
                } else {
                    $entry->canonicalUrl = null;
                }
            }
            $updated++;
        }

        $this->em->flush();

        return $this->json(['updated' => $updated]);
    }

    #[Route('/admin-api/{projectUuid}/seo/audit/{entryUuid}', name: 'admin_seo_audit', methods: ['GET'])]
    public function audit(string $projectUuid, string $entryUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_seo.project_not_found', [], 'Project not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        try {
            $entry = $this->entryRepo->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        } catch (\Throwable) {
            $entry = null;
        }
        if (!$entry) {
            return $this->json(['error' => $this->trans('admin_seo.entry_not_found', [], 'Entry not found.')], 404);
        }

        $report = $this->analyzer->audit($entry);

        return $this->json([
            'score' => $report->score,
            'brokenLinks' => $report->brokenLinks,
            'warnings' => $report->warnings,
        ]);
    }

    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        try {
            return $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }
}
