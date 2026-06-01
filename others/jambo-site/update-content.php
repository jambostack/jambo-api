<?php
$BASE  = 'https://api.jambostack.site/api/f99cb038-6611-44d3-b1c7-46cf62c1e232';
$TOKEN = '8dfd0838343caa990cc82af467e606a09dd56c094e4e3679a1048c9c4a2da85f';

function api(string $base, string $token, string $method, string $path, array $body = []): array {
    $ch = curl_init("$base/$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => $method !== 'GET' ? json_encode($body) : null,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true) ?? []];
}

function patch(string $base, string $token, string $collection, string $uuid, array $fields): void {
    $r = api($base, $token, 'PATCH', "$collection/$uuid", $fields);
    $ok = $r['code'] >= 200 && $r['code'] < 300;
    echo ($ok ? '✅' : '❌') . " [$r[code]] PATCH $collection/$uuid\n";
    if (!$ok) print_r($r['data']);
}

function getAll(string $base, string $token, string $collection, string $locale): array {
    $r = api($base, $token, 'GET', "$collection?locale=$locale&limit=100");
    return $r['data']['data'] ?? [];
}

// ─── CONFIG ───────────────────────────────────────────────────────────────────
echo "\n── Config ──\n";
foreach (['en','fr','es','ar'] as $locale) {
    $entries = getAll($BASE, $TOKEN, 'config', $locale);
    if (empty($entries)) { echo "⚠️  config/$locale — aucune entrée\n"; continue; }
    foreach ($entries as $e) {
        $updates = match($locale) {
            'en' => ['site_name'=>'Jambostack', 'site_description'=>'The open-source platform for building data-driven applications. Jambo API, Jambo Workbench and more.'],
            'fr' => ['site_name'=>'Jambostack', 'site_description'=>'La plateforme open-source pour construire des applications data-driven. Jambo API, Jambo Workbench et plus encore.'],
            'es' => ['site_name'=>'Jambostack', 'site_description'=>'La plataforma open-source para construir aplicaciones orientadas a datos. Jambo API, Jambo Workbench y más.'],
            'ar' => ['site_name'=>'Jambostack', 'site_description'=>'المنصة مفتوحة المصدر لبناء تطبيقات قائمة على البيانات. Jambo API و Jambo Workbench والمزيد.'],
        };
        patch($BASE, $TOKEN, 'config', $e['uuid'], $updates);
    }
}

// ─── HERO ─────────────────────────────────────────────────────────────────────
echo "\n── Hero ──\n";
$heroUpdates = [
    'en' => [
        'headline'            => 'The open-source platform for builders',
        'tagline'             => 'Headless CMS, AI site builder, and more — a complete ecosystem for building and shipping data-driven applications.',
        'badge'               => 'Open-source · AGPL v3 · MIT',
        'cta_primary_label'   => 'Explore the ecosystem',
        'cta_primary_url'     => '/docs/guides/installation/',
        'cta_secondary_label' => '★ GitHub',
        'cta_secondary_url'   => 'https://github.com/jambostack',
        'snippet'             => 'composer create-project jambostack/jambo-api',
    ],
    'fr' => [
        'headline'            => 'La plateforme open-source pour les développeurs',
        'tagline'             => 'CMS headless, builder de sites IA, et plus — un écosystème complet pour construire et livrer des applications data-driven.',
        'badge'               => 'Open-source · AGPL v3 · MIT',
        'cta_primary_label'   => 'Explorer l\'écosystème',
        'cta_primary_url'     => '/docs/guides/installation/',
        'cta_secondary_label' => '★ GitHub',
        'cta_secondary_url'   => 'https://github.com/jambostack',
        'snippet'             => 'composer create-project jambostack/jambo-api',
    ],
    'es' => [
        'headline'            => 'La plataforma open-source para desarrolladores',
        'tagline'             => 'CMS headless, constructor de sitios con IA, y más — un ecosistema completo para construir aplicaciones orientadas a datos.',
        'badge'               => 'Open-source · AGPL v3 · MIT',
        'cta_primary_label'   => 'Explorar el ecosistema',
        'cta_primary_url'     => '/docs/guides/installation/',
        'cta_secondary_label' => '★ GitHub',
        'cta_secondary_url'   => 'https://github.com/jambostack',
        'snippet'             => 'composer create-project jambostack/jambo-api',
    ],
    'ar' => [
        'headline'            => 'المنصة مفتوحة المصدر للمطورين',
        'tagline'             => 'نظام إدارة المحتوى بدون رأس، منشئ المواقع بالذكاء الاصطناعي، والمزيد — منظومة متكاملة لبناء التطبيقات.',
        'badge'               => 'مفتوح المصدر · AGPL v3 · MIT',
        'cta_primary_label'   => 'استكشاف المنظومة',
        'cta_primary_url'     => '/docs/guides/installation/',
        'cta_secondary_label' => '★ GitHub',
        'cta_secondary_url'   => 'https://github.com/jambostack',
        'snippet'             => 'composer create-project jambostack/jambo-api',
    ],
];

foreach ($heroUpdates as $locale => $fields) {
    $entries = getAll($BASE, $TOKEN, 'hero', $locale);
    if (empty($entries)) { echo "⚠️  hero/$locale — aucune entrée\n"; continue; }
    foreach ($entries as $e) {
        patch($BASE, $TOKEN, 'hero', $e['uuid'], $fields);
    }
}

// ─── FEATURES : ajouter Jambostack comme plateforme ───────────────────────────
// Les features existantes parlent de Jambo API — on ajoute une entrée "ecosystem"
echo "\n── Features : ajout entrée écosystème ──\n";
$ecosystemFeatures = [
    'en' => ['title'=>'Open-source Ecosystem', 'icon'=>'🌐', 'category'=>'admin', 'order'=>0,
             'slug'=>'ecosystem-en',
             'description'=>'Jambo API (headless CMS), Jambo Workbench (AI site builder) — independent tools built to work together.'],
    'fr' => ['title'=>'Écosystème open-source', 'icon'=>'🌐', 'category'=>'admin', 'order'=>0,
             'slug'=>'ecosystem-fr',
             'description'=>'Jambo API (CMS headless), Jambo Workbench (builder IA) — des outils indépendants conçus pour fonctionner ensemble.'],
    'es' => ['title'=>'Ecosistema open-source', 'icon'=>'🌐', 'category'=>'admin', 'order'=>0,
             'slug'=>'ecosystem-es',
             'description'=>'Jambo API (CMS headless), Jambo Workbench (constructor IA) — herramientas independientes diseñadas para trabajar juntas.'],
    'ar' => ['title'=>'منظومة مفتوحة المصدر', 'icon'=>'🌐', 'category'=>'admin', 'order'=>0,
             'slug'=>'ecosystem-ar',
             'description'=>'Jambo API (نظام إدارة المحتوى)، Jambo Workbench (منشئ المواقع بالذكاء الاصطناعي) — أدوات مستقلة مصممة للعمل معًا.'],
];

foreach ($ecosystemFeatures as $locale => $fields) {
    $r = api($BASE, $TOKEN, 'POST', 'features', array_merge(['locale'=>$locale,'status'=>'published'], $fields));
    $ok = $r['code'] >= 200 && $r['code'] < 300;
    echo ($ok ? '✅' : '❌') . " [$r[code]] POST features/ecosystem ($locale)\n";
}

echo "\n✅ Terminé.\n";
