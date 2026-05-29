<?php
// src/Workbench/Templates/AstroTemplate.php
namespace App\Workbench\Templates;

class AstroTemplate extends BaseTemplate
{
    public function getId(): string { return 'astro'; }
    public function getLabel(): string { return 'Astro 4'; }
    public function getDevCommand(): string { return 'npm run dev -- --host 0.0.0.0'; }

    public function getStarterFiles(string $jamboApiUrl, string $projectUuid, array $collections): array
    {
        return [
            'package.json' => json_encode([
                'name' => 'jambo-astro-app',
                'type' => 'module',
                'scripts' => ['dev' => 'astro dev', 'build' => 'astro build', 'preview' => 'astro preview'],
                'dependencies' => ['astro' => '^4.0.0'],
            ], JSON_PRETTY_PRINT),

            'astro.config.mjs' => "import { defineConfig } from 'astro/config';\nexport default defineConfig({});\n",

            '.env' => "JAMBO_API_URL={$jamboApiUrl}\nJAMBO_PROJECT_UUID={$projectUuid}\n",

            'src/lib/jambo.ts' => $this->generateClient($collections, $jamboApiUrl, $projectUuid),

            'src/pages/index.astro' => <<<'ASTRO'
---
const title = 'Jambo App';
---
<html lang="en">
  <head><meta charset="utf-8" /><title>{title}</title></head>
  <body><h1>{title}</h1><p>Start chatting with the AI to generate your pages.</p></body>
</html>
ASTRO,
        ];
    }

    private function generateClient(array $collections, string $apiUrl, string $uuid): string
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
        $lines[] = "const BASE = import.meta.env.JAMBO_API_URL ?? '{$apiUrl}';";
        $lines[] = "const PROJECT = import.meta.env.JAMBO_PROJECT_UUID ?? '{$uuid}';";
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

    public function getDockerfile(): string
    {
        return <<<'DOCKERFILE'
FROM node:20-alpine AS builder
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --legacy-peer-deps
COPY . .
RUN npm run build

FROM nginx:alpine AS runner
COPY --from=builder /app/dist /usr/share/nginx/html
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
DOCKERFILE;
    }

    /** Astro builds a static site served by nginx on port 80, not the Node default 3000. */
    public function getInternalPort(): int
    {
        return 80;
    }
}
