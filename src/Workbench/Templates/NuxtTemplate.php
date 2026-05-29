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
        $lines = ["const config = useRuntimeConfig();", "const BASE = config.public.jamboApiUrl;", "const PROJECT = config.public.jamboProjectUuid;", ''];
        foreach ($collections as $col) {
            $fn = 'use' . ucfirst($col['slug'] ?? $col['name'] ?? 'items');
            $lines[] = "export function {$fn}() {";
            $lines[] = "  return useFetch(`\${BASE}/api/\${PROJECT}/collections/{$col['slug']}`);";
            $lines[] = '}';
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}
