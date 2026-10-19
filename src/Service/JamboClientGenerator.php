<?php

namespace App\Service;

use App\Entity\Project;

class JamboClientGenerator
{
    public function generate(Project $project, string $apiUrl, string $projectUuid): string
    {
        $code = "// Auto-generated Jambo API Client for project: {$project->name}\n";
        $code .= "const API_URL = '{$apiUrl}';\n";
        $code .= "const PROJECT_UUID = '{$projectUuid}';\n\n";

        if ($project->collections !== null) {
            foreach ($project->collections as $collection) {
                $singular = rtrim($collection->name, 's');
                if (empty($singular)) {
                    $singular = $collection->name;
                }

                $code .= "export interface {$singular} {\n";
                if ($collection->fields !== null) {
                    foreach ($collection->fields as $field) {
                        $optional = $field->isRequired ? '' : '?';
                        $tsType = match ($field->type) {
                            'number', 'integer', 'float' => 'number',
                            'boolean' => 'boolean',
                            'json', 'repeater' => 'any',
                            default => 'string',
                        };
                        $code .= "    {$field->slug}{$optional}: {$tsType};\n";
                    }
                }
                $code .= "}\n\n";

                $funcName = 'get' . ucfirst($collection->slug);
                $code .= "export async function {$funcName}(): Promise<{$singular}[]> {\n";
                $code .= "    const res = await fetch(`\${API_URL}/api/v1/projects/\${PROJECT_UUID}/collections/{$collection->slug}`);\n";
                $code .= "    if (!res.ok) throw new Error(`Failed to fetch {$collection->slug}`);\n";
                $code .= "    const data = await res.json();\n";
                $code .= "    return data.data;\n";
                $code .= "}\n\n";
            }
        }

        return $code;
    }
}
