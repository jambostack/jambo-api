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
                'activity' => ['recent' => [], 'success_rate' => null, 'timeseries' => []],
                'flows'    => ['total' => 0, 'by_status' => [], 'avg_duration_ms' => null, 'timeseries' => []],
                'endusers' => ['total' => 0, 'by_status' => [], 'timeseries' => []],
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
