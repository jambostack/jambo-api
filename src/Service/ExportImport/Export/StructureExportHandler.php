<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Project;
use App\Service\ExportImport\ExportHandlerInterface;

class StructureExportHandler implements ExportHandlerInterface
{
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
                $fields[] = [
                    'name'        => $field->name,
                    'slug'        => $field->slug,
                    'type'        => $field->type,
                    'options'     => $field->options,
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

        $data = json_encode(['collections' => $collections], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($tempDir . '/structure.json', $data);

        return [
            'manifest' => ['file' => 'structure.json', 'entityCount' => count($collections)],
            'files'    => ['structure.json'],
        ];
    }
}
