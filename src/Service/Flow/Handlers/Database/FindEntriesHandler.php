<?php

namespace App\Service\Flow\Handlers\Database;

use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class FindEntriesHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly ContentEntryRepository $entryRepo,
        private readonly CollectionRepository $collectionRepo,
        private readonly ProjectRepository $projectRepo,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);
        $collectionSlug = $config['collection_slug'] ?? '';
        $collection = $this->collectionRepo->findOneBy(['slug' => $collectionSlug, 'project' => $project]);
        $limit = min(100, $config['limit'] ?? 10);
        $status = $config['status'] ?? 'published';

        $entries = $this->entryRepo->findBy(
            ['collection' => $collection, 'status' => $status],
            ['createdAt' => 'DESC'],
            $limit
        );

        return new NodeOutput(data: [
            'entries' => array_map(fn($e) => [
                'id' => $e->id,
                'uuid' => $e->uuid?->toRfc4122(),
                'title' => $e->name,
                'status' => $e->status,
                'slug' => $e->slug,
            ], $entries),
            'count' => count($entries),
        ]);
    }

    public static function getCategory(): string { return 'db'; }
    public static function getType(): string { return 'find_entries'; }
    public static function getFullType(): string { return 'db.find_entries'; }
    public static function getLabel(): string { return 'Rechercher des entrees'; }
    public static function getDescription(): string { return 'Recherche des entrees dans une collection'; }
    public static function getIcon(): string { return 'Search'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['collection_slug'],
            'properties' => [
                'collection_slug' => ['type' => 'string', 'title' => 'Collection (slug)'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'all'], 'title' => 'Statut', 'default' => 'published'],
                'limit' => ['type' => 'number', 'title' => 'Limite', 'default' => 10],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
