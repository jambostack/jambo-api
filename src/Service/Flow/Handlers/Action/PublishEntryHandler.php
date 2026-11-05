<?php

namespace App\Service\Flow\Handlers\Action;

use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PublishEntryHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentEntryRepository $entryRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $entryUuid = (string) ($config['entry_uuid'] ?? '');

        if (empty($ctx->projectUuid)) {
            return new NodeOutput(data: [
                'published' => false,
                'error' => $this->trans('flow.action.project_uuid_required', 'Project context is required.'),
            ]);
        }

        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);
        if (!$project) {
            return new NodeOutput(data: [
                'published' => false,
                'error' => $this->trans('flow.action.project_not_found', 'Project not found.'),
            ]);
        }

        $entry = $this->entryRepo->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        if (!$entry) {
            return new NodeOutput(data: [
                'published' => false,
                'error' => $this->trans('flow.action.entry_not_found', sprintf('Entry "%s" not found in current project.', $entryUuid), ['%uuid%' => $entryUuid]),
            ]);
        }

        try {
            $entry->status = 'published';
            $entry->updatedAt = new \DateTimeImmutable();
            $this->em->flush();

            return new NodeOutput(data: ['published' => true, 'entry_uuid' => $entryUuid]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['published' => false, 'error' => $e->getMessage()]);
        }
    }

    private function trans(string $id, string $default, array $parameters = []): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return empty($parameters) ? $default : strtr($default, $parameters);
    }

    public static function getCategory(): string { return 'action'; }
    public static function getType(): string { return 'publish_entry'; }
    public static function getFullType(): string { return 'action.publish_entry'; }
    public static function getLabel(): string { return 'Publier une entree'; }
    public static function getDescription(): string { return "Change le statut d'une entree a 'published'"; }
    public static function getIcon(): string { return 'CheckCircle'; }
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
