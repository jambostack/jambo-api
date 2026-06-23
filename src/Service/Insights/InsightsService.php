<?php

namespace App\Service\Insights;

use App\Entity\Project;
use App\Enum\InsightsRange;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class InsightsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {}

    public function forProject(Project $project, InsightsRange $range): array
    {
        $key = sprintf('insights.project.%d.%s', $project->id, $range->value);

        return $this->cache->get($key, function (ItemInterface $item) use ($project, $range) {
            $item->expiresAfter(300);

            return [
                'range'    => $range->value,
                'content'  => $this->contentMetrics($project, $range),
                'media'    => $this->mediaMetrics($project),
                'activity' => $this->activityMetrics($project, $range),
                'flows'    => $this->flowMetrics($project, $range),
                'endusers' => $this->endUserMetrics($project, $range),
            ];
        });
    }

    private function contentMetrics(Project $project, InsightsRange $range): array
    {
        $em = $this->em;

        $total = (int) $em->createQuery(
            'SELECT COUNT(c.id) FROM App\Entity\ContentEntry c WHERE c.project = :p AND c.deletedAt IS NULL'
        )->setParameter('p', $project)->getSingleScalarResult();

        $byStatusRows = $em->createQuery(
            'SELECT c.status AS status, COUNT(c.id) AS cnt FROM App\Entity\ContentEntry c
             WHERE c.project = :p AND c.deletedAt IS NULL GROUP BY c.status'
        )->setParameter('p', $project)->getResult();
        $byStatus = [];
        foreach ($byStatusRows as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }

        $topRows = $em->createQuery(
            'SELECT col.name AS name, COUNT(c.id) AS cnt FROM App\Entity\ContentEntry c
             JOIN c.collection col
             WHERE c.project = :p AND c.deletedAt IS NULL
             GROUP BY col.id, col.name ORDER BY cnt DESC'
        )->setParameter('p', $project)->setMaxResults(5)->getResult();
        $topCollections = array_map(
            static fn ($r) => ['name' => $r['name'], 'count' => (int) $r['cnt']],
            $topRows
        );

        $dates = $em->createQuery(
            'SELECT c.createdAt AS createdAt FROM App\Entity\ContentEntry c
             WHERE c.project = :p AND c.deletedAt IS NULL AND c.createdAt >= :since'
        )->setParameter('p', $project)->setParameter('since', $range->since())->getResult();

        // total/by_status sont toutes périodes confondues (hors deletedAt) ; timeseries est borné à $range.
        return [
            'total'           => $total,
            'by_status'       => $byStatus,
            'top_collections' => $topCollections,
            'timeseries'      => $this->bucketByDay(array_column($dates, 'createdAt')),
        ];
    }

    private function mediaMetrics(Project $project): array
    {
        $em = $this->em;

        $row = $em->createQuery(
            'SELECT COUNT(m.id) AS cnt, COALESCE(SUM(m.fileSize), 0) AS bytes
             FROM App\Entity\Media m WHERE m.project = :p AND m.deletedAt IS NULL'
        )->setParameter('p', $project)->getSingleResult();

        $mimeRows = $em->createQuery(
            'SELECT m.mimeType AS mime, COUNT(m.id) AS cnt FROM App\Entity\Media m
             WHERE m.project = :p AND m.deletedAt IS NULL GROUP BY m.mimeType'
        )->setParameter('p', $project)->getResult();

        $byType = ['image' => 0, 'video' => 0, 'document' => 0, 'other' => 0];
        foreach ($mimeRows as $r) {
            $byType[$this->categorizeMime($r['mime'])] += (int) $r['cnt'];
        }

        return [
            'total'      => (int) $row['cnt'],
            'total_size' => (int) $row['bytes'],
            'by_type'    => $byType,
        ];
    }

    private function categorizeMime(?string $mime): string
    {
        if ($mime === null) {
            return 'other';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'application/') || str_starts_with($mime, 'text/')) {
            return 'document';
        }
        return 'other';
    }

    private function activityMetrics(Project $project, InsightsRange $range): array
    {
        $em = $this->em;

        $recentRows = $em->createQuery(
            'SELECT a.toolName AS tool, a.status AS status, a.source AS source,
                    a.createdBy AS createdBy, a.createdAt AS createdAt
             FROM App\Entity\AuditLog a WHERE a.project = :p ORDER BY a.createdAt DESC'
        )->setParameter('p', $project)->setMaxResults(10)->getResult();

        $recent = array_map(static fn ($r) => [
            'tool'   => $r['tool'],
            'status' => $r['status'],
            'source' => $r['source'],
            'by'     => $r['createdBy'],
            'at'     => $r['createdAt']->format('c'),
        ], $recentRows);

        $statusRows = $em->createQuery(
            'SELECT a.status AS status, COUNT(a.id) AS cnt FROM App\Entity\AuditLog a
             WHERE a.project = :p AND a.createdAt >= :since GROUP BY a.status'
        )->setParameter('p', $project)->setParameter('since', $range->since())->getResult();

        $totalInRange = 0;
        $successInRange = 0;
        foreach ($statusRows as $r) {
            $totalInRange += (int) $r['cnt'];
            if ($r['status'] === 'success') {
                $successInRange += (int) $r['cnt'];
            }
        }
        $successRate = $totalInRange > 0 ? $successInRange / $totalInRange : null;

        $dates = $em->createQuery(
            'SELECT a.createdAt AS createdAt FROM App\Entity\AuditLog a
             WHERE a.project = :p AND a.createdAt >= :since'
        )->setParameter('p', $project)->setParameter('since', $range->since())->getResult();

        return [
            'recent'       => $recent,
            'success_rate' => $successRate,
            'timeseries'   => $this->bucketByDay(array_column($dates, 'createdAt')),
        ];
    }

    private function flowMetrics(Project $project, InsightsRange $range): array
    {
        $em = $this->em;

        $statusRows = $em->createQuery(
            'SELECT r.status AS status, COUNT(r.id) AS cnt FROM App\Entity\AutomationRun r
             JOIN r.automation a WHERE a.project = :p AND r.startedAt >= :since GROUP BY r.status'
        )->setParameter('p', $project)->setParameter('since', $range->since())->getResult();

        $byStatus = [];
        $total = 0;
        foreach ($statusRows as $r) {
            $byStatus[$r['status']] = (int) $r['cnt'];
            $total += (int) $r['cnt'];
        }

        $avg = $em->createQuery(
            'SELECT AVG(r.durationMs) FROM App\Entity\AutomationRun r
             JOIN r.automation a WHERE a.project = :p AND r.startedAt >= :since AND r.durationMs IS NOT NULL'
        )->setParameter('p', $project)->setParameter('since', $range->since())->getSingleScalarResult();

        $dates = $em->createQuery(
            'SELECT r.startedAt AS startedAt FROM App\Entity\AutomationRun r
             JOIN r.automation a WHERE a.project = :p AND r.startedAt >= :since'
        )->setParameter('p', $project)->setParameter('since', $range->since())->getResult();

        return [
            'total'           => $total,
            'by_status'       => $byStatus,
            'avg_duration_ms' => $avg !== null ? (int) round((float) $avg) : null,
            'timeseries'      => $this->bucketByDay(array_column($dates, 'startedAt')),
        ];
    }

    private function endUserMetrics(Project $project, InsightsRange $range): array
    {
        $em = $this->em;

        $total = (int) $em->createQuery(
            'SELECT COUNT(e.id) FROM App\Entity\EndUser e WHERE e.project = :p'
        )->setParameter('p', $project)->getSingleScalarResult();

        $statusRows = $em->createQuery(
            'SELECT e.status AS status, COUNT(e.id) AS cnt FROM App\Entity\EndUser e
             WHERE e.project = :p GROUP BY e.status'
        )->setParameter('p', $project)->getResult();
        $byStatus = [];
        foreach ($statusRows as $r) {
            $byStatus[$r['status']] = (int) $r['cnt'];
        }

        $dates = $em->createQuery(
            'SELECT e.createdAt AS createdAt FROM App\Entity\EndUser e
             WHERE e.project = :p AND e.createdAt >= :since'
        )->setParameter('p', $project)->setParameter('since', $range->since())->getResult();

        return [
            'total'      => $total,
            'by_status'  => $byStatus,
            'timeseries' => $this->bucketByDay(array_column($dates, 'createdAt')),
        ];
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     * @return list<array{date:string,count:int}>
     */
    private function bucketByDay(array $dates): array
    {
        $buckets = [];
        foreach ($dates as $d) {
            $key = $d->format('Y-m-d');
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $date => $count) {
            $out[] = ['date' => $date, 'count' => $count];
        }
        return $out;
    }
}
