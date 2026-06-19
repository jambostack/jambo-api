<?php

namespace App\Service\Flow\Handlers\Database;

use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class CountEntriesHandler implements FlowNodeHandler
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
        $status = $config['status'] ?? 'published';

        $count = $this->entryRepo->count(
            ['collection' => $collection, 'status' => $status]
        );

        return new NodeOutput(data: [
            'count' => $count,
            'collection_slug' => $collectionSlug,
        ]);
    }

    public static function getCategory(): string { return 'db'; }
    public static function getType(): string { return 'count_entries'; }
    public static function getFullType(): string { return 'db.count_entries'; }
    public static function getLabel(): string { return 'Compter les entrees'; }
    public static function getDescription(): string { return 'Compte le nombre d entrees dans une collection'; }
    public static function getIcon(): string { return 'Hash'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['collection_slug'],
            'properties' => [
                'collection_slug' => ['type' => 'string', 'title' => 'Collection (slug)'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'all'], 'title' => 'Statut', 'default' => 'published'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
