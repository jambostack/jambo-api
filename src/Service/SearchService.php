<?php

namespace App\Service;

use App\Entity\ContentEntry;
use App\Entity\Project;
use Meilisearch\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SearchService
{
    public const PROJECT_TIER_SEARCH_DOC_LIMITS = [
        'free'       => 1000,
        'starter'    => 5000,
        'pro'        => 25000,
        'enterprise' => 100000,
    ];
    public const DEFAULT_PROJECT_SEARCH_DOC_LIMIT = 1000;

    private ?Client $client = null;

    public function __construct(
        private string $meilisearchHost,
        private string $meilisearchKey,
        private EavDataFormatterService $formatter,
        private LoggerInterface $logger = new NullLogger(),
        private ?\Doctrine\ORM\EntityManagerInterface $em = null,
    ) {
        if (!empty($meilisearchHost) && !empty($meilisearchKey)) {
            $this->client = new Client($meilisearchHost, $meilisearchKey);
        }
    }

    public function isEnabled(): bool
    {
        return $this->client !== null;
    }

    /**
     * Calcule le quota de documents indexables pour le projet selon son forfait/tier.
     */
    public function getProjectSearchDocLimit(Project $project): int
    {
        if (isset($project->settings['max_search_documents']) && is_numeric($project->settings['max_search_documents'])) {
            return max(0, (int) $project->settings['max_search_documents']);
        }
        $tier = strtolower((string) ($project->settings['tier'] ?? $project->settings['plan'] ?? 'free'));
        return self::PROJECT_TIER_SEARCH_DOC_LIMITS[$tier] ?? self::DEFAULT_PROJECT_SEARCH_DOC_LIMIT;
    }

    /**
     * Retourne le nombre actuel de documents indexés pour ce projet.
     */
    public function getProjectIndexedCount(Project $project): int
    {
        if ($this->isEnabled()) {
            try {
                $index = $this->client->index($this->indexName($project));
                $stats = $index->stats();
                if (isset($stats['numberOfDocuments'])) {
                    return (int) $stats['numberOfDocuments'];
                }
            } catch (\Throwable) {
                // Si l'index n'existe pas encore ou erreur meilisearch, se replier sur les entités Doctrine
            }
        }

        if ($this->em !== null) {
            try {
                return (int) $this->em->getRepository(ContentEntry::class)->count([
                    'project' => $project,
                    'deletedAt' => null,
                ]);
            } catch (\Throwable) {
                // repli ci-dessous
            }
        }

        return $project->contentEntries->filter(fn(ContentEntry $e) => !$e->isDeleted())->count();
    }

    /**
     * Vérifie si le quota de documents indexés est atteint ou dépassé.
     */
    public function isSearchQuotaExceeded(Project $project): bool
    {
        return $this->getProjectIndexedCount($project) >= $this->getProjectSearchDocLimit($project);
    }

    /**
     * Index a single content entry in Meilisearch.
     */
    public function indexEntry(ContentEntry $entry): void
    {
        if (!$this->isEnabled() || $entry->isDeleted() || $entry->project === null) {
            return;
        }

        // Vérification de quota par tier (autorise la mise à jour si le document existe déjà)
        if ($this->isSearchQuotaExceeded($entry->project)) {
            $isUpdate = false;
            try {
                $index = $this->client->index($this->indexName($entry->project));
                $docUuid = $entry->uuid?->toRfc4122();
                if ($docUuid !== null) {
                    $index->getDocument($docUuid);
                    $isUpdate = true;
                }
            } catch (\Throwable) {}

            if (!$isUpdate) {
                $this->logger->warning('Meilisearch index skipped: document quota reached for project {uuid}', [
                    'uuid' => $entry->project->uuid?->toRfc4122(),
                    'limit' => $this->getProjectSearchDocLimit($entry->project),
                ]);
                return;
            }
        }

        $data = $this->formatter->formatEntry($entry);
        $data['_collection'] = $entry->collection?->slug;
        $data['_project'] = $entry->project?->uuid?->toRfc4122();

        try {
            $index = $this->client->index($this->indexName($entry->project));
            $index->addDocuments([$data], 'uuid');
        } catch (\Throwable $e) {
            $this->logger->error('Meilisearch index failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Remove an entry from the search index.
     */
    public function removeEntry(ContentEntry $entry): void
    {
        if (!$this->isEnabled() || $entry->project === null || $entry->uuid === null) {
            return;
        }

        try {
            $index = $this->client->index($this->indexName($entry->project));
            $index->deleteDocument($entry->uuid->toRfc4122());
        } catch (\Throwable) {
            $this->logger->error('Meilisearch delete failed: {error}', ['error' => $e->getMessage() ?? 'unknown']);
        }
    }

    /**
     * Search across all collections in a project.
     */
    public function search(Project $project, string $query, array $options = []): array
    {
        if (!$this->isEnabled()) {
            return $this->fallbackDatabaseSearch($project, $query, $options);
        }

        $limit = min(max(1, (int) ($options['limit'] ?? 20)), 100);
        $offset = max(0, (int) ($options['offset'] ?? 0));

        $searchOptions = array_merge([
            'limit' => $limit,
            'offset' => $offset,
            'filter' => $options['filter'] ?? null,
            'sort' => $options['sort'] ?? ['updated_at:desc'],
            'attributesToHighlight' => ['*'],
            'highlightPreTag' => '<mark>',
            'highlightPostTag' => '</mark>',
        ], $options);

        $searchOptions['limit'] = $limit;
        $searchOptions['offset'] = $offset;

        try {
            $index = $this->client->index($this->indexName($project));

            return $index->search($query, array_filter($searchOptions, fn($v) => $v !== null))->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Meilisearch search failed, falling back to database: {error}', ['error' => $e->getMessage()]);

            return $this->fallbackDatabaseSearch($project, $query, $options);
        }
    }

    /**
     * Rebuild the entire search index for a project (enforcing tier document limit).
     */
    public function rebuildIndex(Project $project): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $entries = $project->contentEntries->filter(
            fn(ContentEntry $e) => !$e->isDeleted()
        );

        if ($entries->isEmpty()) {
            return 0;
        }

        $docLimit = $this->getProjectSearchDocLimit($project);

        $documents = [];
        $count = 0;
        foreach ($entries as $entry) {
            if ($count >= $docLimit) {
                $this->logger->warning('Search rebuild truncated to tier limit of {limit} documents for project {uuid}', [
                    'limit' => $docLimit,
                    'uuid' => $project->uuid?->toRfc4122(),
                ]);
                break;
            }
            $data = $this->formatter->formatEntry($entry);
            $data['_collection'] = $entry->collection?->slug;
            $data['_project'] = $entry->project?->uuid?->toRfc4122();
            $documents[] = $data;
            $count++;
        }

        try {
            $indexName = $this->indexName($project);
            $tmpIndexName = $indexName . '_tmp_' . time();

            // Créer un index temporaire, y ajouter les documents, puis échanger
            $this->client->createIndex($tmpIndexName, ['primaryKey' => 'uuid']);
            $tmpIndex = $this->client->index($tmpIndexName);
            $tmpIndex->addDocuments($documents);
            $tmpIndex->updateFilterableAttributes(['_collection', 'status', 'locale']);
            $tmpIndex->updateSortableAttributes(['created_at', 'updated_at']);

            // Échange atomique des index
            $this->client->swapIndexes([[$indexName, $tmpIndexName]]);

            // Nettoyage : supprimer l'ancien index (maintenant sous le nom temporaire)
            try {
                $this->client->deleteIndex($tmpIndexName);
            } catch (\Throwable) {
                // L'index temporaire sera nettoyé par le prochain rebuild
            }

            return count($documents);
        } catch (\Throwable $e) {
            $this->logger->error('Meilisearch rebuild failed: {error}', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    private function indexName(Project $project): string
    {
        return 'jambo_p_' . substr(md5($project->uuid->toRfc4122()), 0, 8);
    }

    private function fallbackDatabaseSearch(Project $project, string $query, array $options): array
    {
        $startTime = microtime(true);
        $limit = min(max(1, (int) ($options['limit'] ?? 20)), 100);
        $offset = max(0, (int) ($options['offset'] ?? 0));

        if ($this->em === null) {
            return [
                'hits' => [],
                'query' => $query,
                'limit' => $limit,
                'offset' => $offset,
                'estimatedTotalHits' => 0,
                'processingTimeMs' => 0,
                'source' => 'database',
            ];
        }

        $escapedQuery = addcslashes($query, '%_\\');

        $qb = $this->em->createQueryBuilder();
        $qb->select('DISTINCT e')
           ->from(ContentEntry::class, 'e')
           ->leftJoin('e.fieldValues', 'fv')
           ->leftJoin('e.collection', 'c')
           ->where('e.project = :project')
           ->andWhere('e.deletedAt IS NULL')
           ->andWhere('(fv.textValue LIKE :q OR e.slug LIKE :q OR e.metaTitle LIKE :q)')
           ->setParameter('project', $project)
           ->setParameter('q', '%' . $escapedQuery . '%')
           ->orderBy('e.updatedAt', 'DESC')
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        if (!empty($options['collection'])) {
            $qb->andWhere('c.slug = :collection')->setParameter('collection', $options['collection']);
        }
        if (!empty($options['locale'])) {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $options['locale']);
        }
        if (!empty($options['status'])) {
            $qb->andWhere('e.status = :status')->setParameter('status', $options['status']);
        }

        $countQb = $this->em->createQueryBuilder();
        $countQb->select('COUNT(DISTINCT e.id)')
                ->from(ContentEntry::class, 'e')
                ->leftJoin('e.fieldValues', 'fv')
                ->leftJoin('e.collection', 'c')
                ->where('e.project = :project')
                ->andWhere('e.deletedAt IS NULL')
                ->andWhere('(fv.textValue LIKE :q OR e.slug LIKE :q OR e.metaTitle LIKE :q)')
                ->setParameter('project', $project)
                ->setParameter('q', '%' . $escapedQuery . '%');

        if (!empty($options['collection'])) {
            $countQb->andWhere('c.slug = :collection')->setParameter('collection', $options['collection']);
        }
        if (!empty($options['locale'])) {
            $countQb->andWhere('e.locale = :locale')->setParameter('locale', $options['locale']);
        }
        if (!empty($options['status'])) {
            $countQb->andWhere('e.status = :status')->setParameter('status', $options['status']);
        }

        try {
            $entries = $qb->getQuery()->getResult();
            $totalCount = (int) $countQb->getQuery()->getSingleScalarResult();
            $hits = array_map(function (ContentEntry $entry) {
                $data = $this->formatter->formatEntry($entry);
                $data['_collection'] = $entry->collection?->slug;
                $data['_project'] = $entry->project?->uuid?->toRfc4122();
                return $data;
            }, $entries);
        } catch (\Throwable $e) {
            $this->logger->error('Fallback database search failed: {error}', ['error' => $e->getMessage()]);
            $hits = [];
            $totalCount = 0;
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'hits' => $hits,
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset,
            'estimatedTotalHits' => $totalCount,
            'processingTimeMs' => $durationMs,
            'source' => 'database',
        ];
    }
}
