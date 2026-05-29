<?php
// src/Workbench/Templates/NuxtTemplate.php
namespace App\Workbench\Templates;

class NuxtTemplate extends BaseTemplate
{
    public function getId(): string { return 'nuxt'; }
    public function getLabel(): string { return 'Nuxt 3'; }
    public function getDevCommand(): string { return 'npm run dev'; }

    public function getStarterFiles(string $jamboApiUrl, string $projectUuid, array $collections): array
    {
        return [
            'package.json' => json_encode([
                'name' => 'jambo-nuxt-app',
                'private' => true,
                'scripts' => ['dev' => 'nuxt dev', 'build' => 'nuxt build', 'generate' => 'nuxt generate'],
                'devDependencies' => ['nuxt' => '^3.9.0', '@nuxt/devtools' => 'latest'],
            ], JSON_PRETTY_PRINT),

            'nuxt.config.ts' => "export default defineNuxtConfig({ devtools: { enabled: true } });\n",

            '.env' => "JAMBO_API_URL={$jamboApiUrl}\nJAMBO_PROJECT_UUID={$projectUuid}\n",

            'composables/useJambo.ts' => $this->generateComposable($collections),

            'app.vue' => <<<'VUE'
<template>
  <div><NuxtPage /></div>
</template>
VUE,

            'pages/index.vue' => <<<'VUE'
<template>
  <main><h1>Welcome to your Jambo App</h1><p>Start chatting with the AI to generate your pages.</p></main>
</template>
VUE,
        ];
    }

    private function generateComposable(array $collections): string
    {
        $lines = [];

        // TypeScript interfaces first
        foreach ($collections as $col) {
            $typeName = ucfirst($col['slug'] ?? $col['name'] ?? 'Item');
            $lines[] = "export interface {$typeName} {";
            $lines[] = "  uuid: string;";
            $lines[] = "  locale: string;";
            $lines[] = "  status: 'draft' | 'published';";
            foreach ($col['fields'] ?? [] as $field) {
                $tsType = match($field['type']) {
                    'number', 'decimal' => 'number',
                    'boolean', 'checkbox' => 'boolean',
                    'json', 'array', 'repeater' => 'Record<string, unknown>',
                    'media', 'relation' => 'string | string[]',
                    default => 'string',
                };
                $optional = ($field['isRequired'] ?? false) ? '' : '?';
                $lines[] = "  {$field['slug']}{$optional}: {$tsType};";
            }
            $lines[] = "  created_at: string;";
            $lines[] = "  updated_at: string;";
            $lines[] = '}';
            $lines[] = '';
        }

        // Nuxt composables
        $lines[] = "const config = useRuntimeConfig();";
        $lines[] = "const BASE = config.public.jamboApiUrl;";
        $lines[] = "const PROJECT = config.public.jamboProjectUuid;";
        $lines[] = '';
        foreach ($collections as $col) {
            $typeName = ucfirst($col['slug'] ?? $col['name'] ?? 'Item');
            $fn = 'use' . ucfirst($col['slug'] ?? $col['name'] ?? 'items');
            $lines[] = "export function {$fn}() {";
            $lines[] = "  return useFetch<{$typeName}[]>(`\${BASE}/api/\${PROJECT}/collections/{$col['slug']}`);";
            $lines[] = '}';
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}
