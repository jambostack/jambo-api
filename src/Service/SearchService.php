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
            return ['hits' => [], 'query' => $query, 'processingTimeMs' => 0];
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
            $this->logger->error('Meilisearch search failed: {error}', ['error' => $e->getMessage()]);

            return ['hits' => [], 'query' => $query, 'processingTimeMs' => 0, 'error' => $e->getMessage()];
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
            $this->client->deleteIndex($indexName);
            $this->client->createIndex($indexName, ['primaryKey' => 'uuid']);
            $index = $this->client->index($indexName);
            $index->addDocuments($documents);
            $index->updateFilterableAttributes(['_collection', 'status', 'locale']);
            $index->updateSortableAttributes(['created_at', 'updated_at']);

            return count($documents);
        } catch (\Throwable $e) {
            $this->logger->error('Meilisearch rebuild failed: {error}', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    private function indexName(Project $project): string
    {
        return 'jamboapi_project_' . $project->uuid->toRfc4122();
    }
}
