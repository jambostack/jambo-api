<?php

namespace App\Service;

use App\Entity\Project;

class JamboClientGenerator
{
    private const TS_RESERVED_WORDS = [
        'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger', 'default',
        'delete', 'do', 'else', 'enum', 'export', 'extends', 'false', 'finally', 'for',
        'function', 'if', 'import', 'in', 'instanceof', 'new', 'null', 'return', 'super',
        'switch', 'this', 'throw', 'true', 'try', 'typeof', 'var', 'void', 'while', 'with',
        'any', 'boolean', 'constructor', 'declare', 'get', 'module', 'require', 'number',
        'set', 'string', 'symbol', 'type', 'from', 'of', 'interface', 'package', 'private',
        'protected', 'public', 'static', 'yield', 'let', 'await', 'unknown', 'never',
    ];

    public function generate(Project $project, string $apiUrl, string $projectUuid): string
    {
        // 1. Sanitize project name against CRLF / comment breaking injections
        $cleanProjectName = str_replace(["\r", "\n", "\0", '*/', '/*'], '', (string) $project->name);
        $cleanProjectName = mb_substr($cleanProjectName, 0, 100);

        // 2. Sanitize API URL & Project UUID safely via JSON encoding
        $cleanApiUrl = trim($apiUrl);
        $cleanProjectUuid = trim($projectUuid);

        $apiUrlLiteral = json_encode($cleanApiUrl, JSON_UNESCAPED_SLASHES);
        $projectUuidLiteral = json_encode($cleanProjectUuid);

        $code = "// Auto-generated Jambo API Client for project: {$cleanProjectName}\n";
        $code .= "const API_URL = {$apiUrlLiteral};\n";
        $code .= "const PROJECT_UUID = {$projectUuidLiteral};\n\n";

        if ($project->collections !== null) {
            foreach ($project->collections as $collection) {
                $singular = $this->toValidInterfaceName((string) $collection->name);
                $safeCollectionSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $collection->slug);
                if ($safeCollectionSlug === '') {
                    $safeCollectionSlug = 'item';
                }

                $code .= "export interface {$singular} {\n";
                if ($collection->fields !== null) {
                    foreach ($collection->fields as $field) {
                        $optional = $field->isRequired ? '' : '?';
                        $tsType = match (strtolower((string) $field->type)) {
                            'number', 'integer', 'float', 'decimal' => 'number',
                            'boolean', 'bool' => 'boolean',
                            'json', 'repeater', 'array' => 'any',
                            'date', 'datetime', 'time' => 'string',
                            default => 'string',
                        };

                        $propName = $this->formatProperty((string) $field->slug);
                        $code .= "    {$propName}{$optional}: {$tsType};\n";
                    }
                }
                $code .= "}\n\n";

                $funcName = $this->toValidFunctionName('get', $safeCollectionSlug);
                $escapedSlugLiteral = json_encode($safeCollectionSlug);

                $code .= "export async function {$funcName}(): Promise<{$singular}[]> {\n";
                $code .= "    const res = await fetch(`\${API_URL}/api/v1/projects/\${PROJECT_UUID}/collections/{$safeCollectionSlug}`);\n";
                $code .= "    if (!res.ok) throw new Error(`Failed to fetch \${{$escapedSlugLiteral}}`);\n";
                $code .= "    const data = await res.json();\n";
                $code .= "    return data.data;\n";
                $code .= "}\n\n";
            }
        }

        return $code;
    }

    /**
     * Converts a collection name into a valid, safe TypeScript PascalCase interface identifier.
     */
    public function toValidInterfaceName(string $name): string
    {
        $raw = trim($name);
        if ($raw === '') {
            return 'Item';
        }

        // Handle simple plural stripping (Articles -> Article)
        if (str_ends_with(strtolower($raw), 's') && strlen($raw) > 1 && !str_ends_with(strtolower($raw), 'ss')) {
            $raw = substr($raw, 0, -1);
        }

        // Split on non-alphanumeric characters
        $parts = preg_split('/[^a-zA-Z0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts)) {
            return 'Item';
        }

        $pascal = '';
        foreach ($parts as $part) {
            $pascal .= ucfirst(strtolower($part));
        }

        // If the original name had uppercase letters, preserve them if it was already single-word
        if (count($parts) === 1 && preg_match('/^[A-Z][a-zA-Z0-9]*$/', $raw)) {
            $pascal = $raw;
        }

        // Ensure does not start with a digit
        if (preg_match('/^[0-9]/', $pascal)) {
            $pascal = 'Item' . $pascal;
        }

        // Avoid TypeScript reserved keywords
        if (in_array(strtolower($pascal), self::TS_RESERVED_WORDS, true)) {
            $pascal = 'I' . $pascal;
        }

        return $pascal !== '' ? $pascal : 'Item';
    }

    /**
     * Formats a field slug into a valid TypeScript property name, safely quoted if necessary.
     */
    public function formatProperty(string $slug): string
    {
        $clean = trim($slug);
        if (preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $clean)) {
            return $clean;
        }

        // If it contains hyphens, spaces or special characters, quote safely
        return json_encode($clean);
    }

    /**
     * Converts a collection slug into a valid camelCase JavaScript function identifier.
     */
    public function toValidFunctionName(string $prefix, string $slug): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $slug, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts)) {
            return $prefix . 'Items';
        }

        $camel = $prefix;
        foreach ($parts as $part) {
            $camel .= ucfirst(strtolower($part));
        }

        // Ensure starts with valid identifier character
        if (!preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $camel)) {
            $camel = $prefix . 'Items';
        }

        return $camel;
    }
}
