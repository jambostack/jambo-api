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
        $lines = ["const BASE = import.meta.env.JAMBO_API_URL ?? '{$apiUrl}';",
                  "const PROJECT = import.meta.env.JAMBO_PROJECT_UUID ?? '{$uuid}';", ''];
        foreach ($collections as $col) {
            $lines[] = "export async function get" . ucfirst($col['slug'] ?? 'items') . "() {";
            $lines[] = "  const r = await fetch(`\${BASE}/api/\${PROJECT}/collections/{$col['slug']}`);";
            $lines[] = "  return r.json();";
            $lines[] = '}';
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}
