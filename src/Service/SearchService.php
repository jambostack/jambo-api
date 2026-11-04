<?php

namespace App\Service;

use App\Entity\ContentEntry;
use App\Entity\Project;
use Meilisearch\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SearchService
{
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
     * Index a single content entry in Meilisearch.
     */
    public function indexEntry(ContentEntry $entry): void
    {
        if (!$this->isEnabled() || $entry->isDeleted()) {
            return;
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
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $index = $this->client->index($this->indexName($entry->project));
            $index->deleteDocument($entry->uuid->toRfc4122());
        } catch (\Throwable $e) {
            $this->logger->error('Meilisearch delete failed: {error}', ['error' => $e->getMessage()]);
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

        $searchOptions = array_merge([
            'limit' => $options['limit'] ?? 20,
            'offset' => $options['offset'] ?? 0,
            'filter' => $options['filter'] ?? null,
            'sort' => $options['sort'] ?? ['updated_at:desc'],
            'attributesToHighlight' => ['*'],
            'highlightPreTag' => '<mark>',
            'highlightPostTag' => '</mark>',
        ], $options);

        try {
            $index = $this->client->index($this->indexName($project));

            return $index->search($query, array_filter($searchOptions))->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Meilisearch search failed, falling back to database: {error}', ['error' => $e->getMessage()]);

            return $this->fallbackDatabaseSearch($project, $query, $options);
        }
    }

    /**
     * Rebuild the entire search index for a project.
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

        $documents = [];
        foreach ($entries as $entry) {
            $data = $this->formatter->formatEntry($entry);
            $data['_collection'] = $entry->collection?->slug;
            $data['_project'] = $entry->project?->uuid?->toRfc4122();
            $documents[] = $data;
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
        if ($this->em === null) {
            return ['hits' => [], 'query' => $query, 'processingTimeMs' => 0];
        }

        $limit = min(max(1, (int) ($options['limit'] ?? 20)), 100);
        $offset = max(0, (int) ($options['offset'] ?? 0));

        $qb = $this->em->createQueryBuilder();
        $qb->select('DISTINCT e')
           ->from(ContentEntry::class, 'e')
           ->leftJoin('e.fieldValues', 'fv')
           ->leftJoin('e.collection', 'c')
           ->where('e.project = :project')
           ->andWhere('e.deletedAt IS NULL')
           ->andWhere('(fv.textValue LIKE :q OR e.slug LIKE :q)')
           ->setParameter('project', $project)
           ->setParameter('q', '%' . addcslashes($query, '%_\\') . '%')
           ->orderBy('e.updatedAt', 'DESC')
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        if (!empty($options['collection'])) {
            $qb->andWhere('c.slug = :collection')->setParameter('collection', $options['collection']);
        }
        if (!empty($options['locale'])) {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $options['locale']);
        }

        try {
            $entries = $qb->getQuery()->getResult();
            $hits = array_map(function (ContentEntry $entry) {
                $data = $this->formatter->formatEntry($entry);
                $data['_collection'] = $entry->collection?->slug;
                $data['_project'] = $entry->project?->uuid?->toRfc4122();
                return $data;
            }, $entries);
        } catch (\Throwable $e) {
            $this->logger->error('Fallback database search failed: {error}', ['error' => $e->getMessage()]);
            $hits = [];
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'hits' => $hits,
            'query' => $query,
            'estimatedTotalHits' => count($hits),
            'processingTimeMs' => $durationMs,
            'source' => 'database',
        ];
    }
}
