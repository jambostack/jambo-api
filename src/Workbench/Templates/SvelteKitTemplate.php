<?php
// src/Workbench/Templates/SvelteKitTemplate.php
namespace App\Workbench\Templates;

class SvelteKitTemplate extends BaseTemplate
{
    public function getId(): string { return 'sveltekit'; }
    public function getLabel(): string { return 'SvelteKit'; }
    public function getDevCommand(): string { return 'npm run dev -- --host 0.0.0.0'; }

    public function getStarterFiles(string $jamboApiUrl, string $projectUuid, array $collections): array
    {
        return [
            'package.json' => json_encode([
                'name' => 'jambo-svelte-app',
                'private' => true,
                'scripts' => ['dev' => 'vite dev', 'build' => 'vite build', 'preview' => 'vite preview'],
                'devDependencies' => [
                    '@sveltejs/adapter-auto' => '^3.0.0',
                    '@sveltejs/kit' => '^2.0.0',
                    'svelte' => '^5.0.0-next.0',
                    'vite' => '^5.0.0',
                ],
            ], JSON_PRETTY_PRINT),

            'svelte.config.js' => "import adapter from '@sveltejs/adapter-auto';\nexport default { kit: { adapter: adapter() } };\n",

            'vite.config.js' => "import { sveltekit } from '@sveltejs/kit/vite';\nimport { defineConfig } from 'vite';\nexport default defineConfig({ plugins: [sveltekit()] });\n",

            '.env' => "PUBLIC_JAMBO_API_URL={$jamboApiUrl}\nPUBLIC_JAMBO_PROJECT_UUID={$projectUuid}\n",

            'src/lib/jambo.ts' => $this->generateClient($collections),

            'src/routes/+page.svelte' => "<h1>Welcome to your Jambo App</h1>\n<p>Start chatting with the AI to generate your pages.</p>\n",
        ];
    }

    private function generateClient(array $collections): string
    {
        $lines = [];

        // TypeScript interfaces
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

        // Async getters
        $lines[] = "import { env } from '\$env/dynamic/public';";
        $lines[] = "const BASE = env.PUBLIC_JAMBO_API_URL;";
        $lines[] = "const PROJECT = env.PUBLIC_JAMBO_PROJECT_UUID;";
        $lines[] = '';
        foreach ($collections as $col) {
            $typeName = ucfirst($col['slug'] ?? $col['name'] ?? 'Item');
            $lines[] = "export async function get" . ucfirst($col['slug'] ?? 'items') . "(): Promise<{$typeName}[]> {";
            $lines[] = "  const r = await fetch(`\${BASE}/api/\${PROJECT}/collections/{$col['slug']}`);";
            $lines[] = "  return r.json();";
            $lines[] = '}';
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}
