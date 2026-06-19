<?php

namespace App\Service\Flow\Handlers\Action;

use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Doctrine\ORM\EntityManagerInterface;

class DeleteEntryHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentEntryRepository $entryRepo,
        private readonly ProjectRepository $projectRepo,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $entryUuid = $config['entry_uuid'] ?? '';

        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);
        $entry = $this->entryRepo->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        if (!$entry) {
            return new NodeOutput(data: ['deleted' => false, 'error' => "Entry '$entryUuid' not found"]);
        }

        try {
            $this->em->remove($entry);
            $this->em->flush();

            return new NodeOutput(data: ['deleted' => true, 'entry_uuid' => $entryUuid]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['deleted' => false, 'error' => $e->getMessage()]);
        }
    }

    public static function getCategory(): string { return 'action'; }
    public static function getType(): string { return 'delete_entry'; }
    public static function getFullType(): string { return 'action.delete_entry'; }
    public static function getLabel(): string { return 'Supprimer une entree'; }
    public static function getDescription(): string { return "Supprime definitivement une entree de contenu"; }
    public static function getIcon(): string { return 'FileX'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['entry_uuid'],
            'properties' => [
                'entry_uuid' => ['type' => 'string', 'title' => 'UUID de l\'entree', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
