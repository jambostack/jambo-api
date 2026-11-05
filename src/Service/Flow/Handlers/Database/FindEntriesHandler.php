<?php

namespace App\Service\Flow\Handlers\Database;

use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Symfony\Contracts\Translation\TranslatorInterface;

class FindEntriesHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly ContentEntryRepository $entryRepo,
        private readonly CollectionRepository $collectionRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];

        if (empty($ctx->projectUuid)) {
            return new NodeOutput(data: [
                'entries' => [],
                'count' => 0,
                'error' => $this->trans('flow.db.project_uuid_required', 'Project context is required.'),
            ]);
        }

        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);
        if (!$project) {
            return new NodeOutput(data: [
                'entries' => [],
                'count' => 0,
                'error' => $this->trans('flow.db.project_not_found', 'Project not found.'),
            ]);
        }

        $collectionSlug = (string) ($config['collection_slug'] ?? '');
        $collection = $this->collectionRepo->findOneBy(['slug' => $collectionSlug, 'project' => $project]);
        if (!$collection) {
            return new NodeOutput(data: [
                'entries' => [],
                'count' => 0,
                'error' => $this->trans('flow.db.collection_not_found', sprintf('Collection "%s" not found in current project.', $collectionSlug), ['%slug%' => $collectionSlug]),
            ]);
        }

        $limit = min(100, max(1, (int) ($config['limit'] ?? 10)));
        $status = (string) ($config['status'] ?? 'published');
        $criteria = ['collection' => $collection];
        if ($status !== 'all') {
            $criteria['status'] = $status;
        }

        $entries = $this->entryRepo->findBy(
            $criteria,
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

    private function trans(string $id, string $default, array $parameters = []): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return empty($parameters) ? $default : strtr($default, $parameters);
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
