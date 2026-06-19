<?php

namespace App\Service\Flow\Handlers\Action;

use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\EavFieldHelperService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Doctrine\ORM\EntityManagerInterface;

class UpdateEntryHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentEntryRepository $entryRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly EavFieldHelperService $eavHelper,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $entryUuid = $config['entry_uuid'] ?? '';

        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);
        $entry = $this->entryRepo->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        if (!$entry) {
            return new NodeOutput(data: ['updated' => false, 'error' => "Entry '$entryUuid' not found"]);
        }

        try {
            $fields = $config['fields'] ?? [];
            foreach ($fields as $slug => $value) {
                $field = $entry->collection?->getFieldBySlug($slug);
                if ($field) {
                    $this->eavHelper->saveFieldValue($entry, $field, $value);
                }
            }

            if (!empty($config['status'])) {
                $entry->status = $config['status'];
            }

            $entry->updatedAt = new \DateTimeImmutable();
            $this->em->flush();

            return new NodeOutput(data: ['updated' => true, 'entry_uuid' => $entryUuid]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['updated' => false, 'error' => $e->getMessage()]);
        }
    }

    public static function getCategory(): string { return 'action'; }
    public static function getType(): string { return 'update_entry'; }
    public static function getFullType(): string { return 'action.update_entry'; }
    public static function getLabel(): string { return 'Mettre a jour une entree'; }
    public static function getDescription(): string { return "Met a jour les champs et/ou le statut d'une entree existante"; }
    public static function getIcon(): string { return 'FileEdit'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['entry_uuid'],
            'properties' => [
                'entry_uuid' => ['type' => 'string', 'title' => 'UUID de l\'entree', 'template' => true],
                'fields' => ['type' => 'object', 'title' => 'Valeurs des champs', 'default' => []],
                'status' => ['type' => 'string', 'title' => 'Nouveau statut', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
