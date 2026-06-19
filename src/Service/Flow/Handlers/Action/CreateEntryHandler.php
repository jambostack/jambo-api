<?php

namespace App\Service\Flow\Handlers\Action;

use App\Entity\ContentEntry;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Service\EavFieldHelperService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Doctrine\ORM\EntityManagerInterface;

class CreateEntryHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepo,
        private readonly CollectionRepository $collectionRepo,
        private readonly EavFieldHelperService $eavHelper,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $collectionSlug = $config['collection_slug'] ?? '';
        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);
        $collection = $this->collectionRepo->findOneBy(['slug' => $collectionSlug, 'project' => $project]);

        if (!$collection) {
            return new NodeOutput(data: ['created' => false, 'error' => "Collection '$collectionSlug' not found"]);
        }

        try {
            $entry = new ContentEntry();
            $entry->collection = $collection;
            $entry->project    = $project;
            $entry->locale     = $project->defaultLocale;
            $entry->status     = $collection->getDefaultStatus();

            $this->em->persist($entry);

            $fields = $config['fields'] ?? [];
            foreach ($fields as $slug => $value) {
                $field = $collection->getFieldBySlug($slug);
                if ($field) {
                    $this->eavHelper->saveFieldValue($entry, $field, $value);
                }
            }

            $this->em->flush();

            return new NodeOutput(data: ['created' => true, 'entry_uuid' => $entry->uuid?->toRfc4122()]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['created' => false, 'error' => $e->getMessage()]);
        }
    }

    public static function getCategory(): string { return 'action'; }
    public static function getType(): string { return 'create_entry'; }
    public static function getFullType(): string { return 'action.create_entry'; }
    public static function getLabel(): string { return 'Creer une entree'; }
    public static function getDescription(): string { return "Cree une nouvelle entree dans une collection"; }
    public static function getIcon(): string { return 'FilePlus'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['collection_slug'],
            'properties' => [
                'collection_slug' => ['type' => 'string', 'title' => 'Slug de la collection', 'template' => true],
                'fields' => ['type' => 'object', 'title' => 'Valeurs des champs', 'default' => []],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
