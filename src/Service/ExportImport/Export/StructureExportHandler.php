<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Project;
use App\Repository\EndUserFieldRepository;
use App\Service\ExportImport\ExportHandlerInterface;

class StructureExportHandler implements ExportHandlerInterface
{
    public function __construct(private EndUserFieldRepository $endUserFieldRepository) {}

    public static function getOptionKey(): string
    {
        return 'structure';
    }

    public function export(Project $project, string $tempDir): array
    {
        $collections = [];
        foreach ($project->collections as $collection) {
            if (method_exists($collection, 'isDeleted') && $collection->isDeleted()) {
                continue;
            }

            $fields = [];
            foreach ($collection->fields as $field) {
                if (method_exists($field, 'isDeleted') && $field->isDeleted()) {
                    continue;
                }
                $options = $field->options;
                if ($field->type === 'relation' && is_array($options)) {
                    $options = $this->exportRelationOptions($options, $project);
                }
                $fields[] = [
                    'name'        => $field->name,
                    'slug'        => $field->slug,
                    'type'        => $field->type,
                    'options'     => $options,
                    'order'       => $field->order,
                    'is_required' => $field->isRequired,
                ];
            }

            $collections[] = [
                'name'         => $collection->name,
                'slug'         => $collection->slug,
                'description'  => $collection->description,
                'is_singleton' => $collection->isSingleton,
                'order'        => $collection->order,
                'fields'       => $fields,
            ];
        }

        $endUserFields = [];
        foreach ($this->endUserFieldRepository->findByProject($project) as $field) {
            if ($field->isSystem) {
                continue;
            }
            $endUserFields[] = [
                'name'        => $field->name,
                'slug'        => $field->slug,
                'type'        => $field->type,
                'options'     => $field->options,
                'order'       => $field->order,
                'is_required' => $field->isRequired,
            ];
        }

        $data = json_encode([
            'collections'     => $collections,
            'end_user_fields' => $endUserFields,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        file_put_contents($tempDir . '/structure.json', $data);

        return [
            'manifest' => [
                'file'              => 'structure.json',
                'entityCount'       => count($collections),
                'endUserFieldCount' => count($endUserFields),
            ],
            'files' => ['structure.json'],
        ];
    }

    private function exportRelationOptions(array $options, Project $project): array
    {
        $relation = is_array($options['relation'] ?? null) ? $options['relation'] : [];
        $relType = (int) ($relation['type'] ?? $options['relationType'] ?? 1);
        $target = $relation['collection'] ?? $options['targetCollection'] ?? null;

        if ($target === 'end_users') {
            return [
                'targetCollection' => 'end_users',
                'relation' => ['type' => $relType, 'collection_slug' => 'end_users'],
            ];
        }

        $targetColl = null;
        if (is_int($target) || (is_string($target) && ctype_digit($target))) {
            foreach ($project->collections as $c) {
                if ((!method_exists($c, 'isDeleted') || !$c->isDeleted()) && $c->id === (int) $target) {
                    $targetColl = $c;
                    break;
                }
            }
        } elseif (is_string($target) && $target !== '') {
            foreach ($project->collections as $c) {
                if ((!method_exists($c, 'isDeleted') || !$c->isDeleted()) && $c->slug === $target) {
                    $targetColl = $c;
                    break;
                }
            }
        }

        if ($targetColl !== null) {
            return [
                'relation' => [
                    'type' => $relType,
                    'collection_slug' => $targetColl->slug,
                ],
            ];
        }

        // Relation orpheline ou cible inexistante dans le projet
        return [
            'relation' => [
                'type' => $relType,
                'orphan' => true,
                'collection_slug' => null,
            ],
        ];
    }
}
