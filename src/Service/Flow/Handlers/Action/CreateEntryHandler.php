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
use Symfony\Contracts\Translation\TranslatorInterface;

class CreateEntryHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepo,
        private readonly CollectionRepository $collectionRepo,
        private readonly EavFieldHelperService $eavHelper,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $collectionSlug = (string) ($config['collection_slug'] ?? '');

        if (empty($ctx->projectUuid)) {
            return new NodeOutput(data: [
                'created' => false,
                'error' => $this->trans('flow.action.project_uuid_required', 'Project context is required.'),
            ]);
        }

        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);
        if (!$project) {
            return new NodeOutput(data: [
                'created' => false,
                'error' => $this->trans('flow.action.project_not_found', 'Project not found.'),
            ]);
        }

        $collection = $this->collectionRepo->findOneBy(['slug' => $collectionSlug, 'project' => $project]);
        if (!$collection) {
            return new NodeOutput(data: [
                'created' => false,
                'error' => $this->trans('flow.action.collection_not_found', sprintf('Collection "%s" not found.', $collectionSlug), ['%slug%' => $collectionSlug]),
            ]);
        }

        try {
            $entry = new ContentEntry();
            $entry->collection = $collection;
            $entry->project    = $project;
            $entry->locale     = $project->defaultLocale ?? 'fr';
            $entry->status     = $collection->getDefaultStatus();

            $this->em->persist($entry);

            $fields = $config['fields'] ?? [];
            if (is_array($fields)) {
                foreach ($fields as $slug => $value) {
                    $field = $collection->getFieldBySlug((string) $slug);
                    if ($field) {
                        $this->eavHelper->saveFieldValue($entry, $field, $value);
                    }
                }
            }

            $this->em->flush();

            return new NodeOutput(data: ['created' => true, 'entry_uuid' => $entry->uuid?->toRfc4122()]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['created' => false, 'error' => $e->getMessage()]);
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
