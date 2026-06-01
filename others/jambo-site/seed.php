<?php
/**
 * Seed script — insère le contenu jambostack.site dans Jambo API (4 langues)
 * Usage: php seed.php
 */

$BASE  = 'https://api.jambostack.site/api/f99cb038-6611-44d3-b1c7-46cf62c1e232';
$TOKEN = '964122f433fff77c55e01b64bcf447eefa93f2f6ddc8a7a8c17432c39de43f7e';

function post(string $base, string $token, string $collection, array $body): array {
    $ch = curl_init("$base/$collection");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true) ?? ['raw' => $res];
    $ok   = $code >= 200 && $code < 300;
    $icon = $ok ? '✅' : '❌';
    $msg  = $ok ? ($data['uuid'] ?? 'ok') : (($data['error'] ?? $res));
    echo "$icon  [$code] $collection ({$body['locale']}) — $msg\n";
    return $data;
}

// ─── CONFIG ──────────────────────────────────────────────────────────────────
echo "\n── Config ──\n";
post($BASE, $TOKEN, 'config', [
    'locale' => 'en', 'status' => 'published',
    'site_name'        => 'Jambo API',
    'site_description' => 'Open-source headless CMS built on Symfony 8 and PHP 8.4.',
    'github_url'       => 'https://github.com/jambostack/jambo-api',
    'contact_email'    => 'contact@jambostack.site',
]);

// ─── HERO ─────────────────────────────────────────────────────────────────────
echo "\n── Hero (4 langues) ──\n";
$heroes = [
    'en' => [
        'headline'            => 'The open-source headless CMS for builders',
        'tagline'             => 'REST & GraphQL API, AI Schema Studio, MCP Server v2.0, Multi-locale — built on Symfony 8 and PHP 8.4.',
        'badge'               => 'Symfony 8 · PHP 8.4 · AGPL v3',
        'cta_primary_label'   => 'Get started',
        'cta_primary_url'     => '/docs/guides/installation/',
        'cta_secondary_label' => '★ GitHub',
        'cta_secondary_url'   => 'https://github.com/jambostack/jambo-api',
        'snippet'             => 'composer create-project jambostack/jambo-api',
    ],
    'fr' => [
        'headline'            => 'Le CMS headless open-source pour les développeurs',
        'tagline'             => 'API REST & GraphQL, Studio IA, Serveur MCP v2.0, Multi-locale — construit sur Symfony 8 et PHP 8.4.',
        'badge'               => 'Symfony 8 · PHP 8.4 · AGPL v3',
        'cta_primary_label'   => 'Commencer',
        'cta_primary_url'     => '/docs/guides/installation/',
        'cta_secondary_label' => '★ GitHub',
        'cta_secondary_url'   => 'https://github.com/jambostack/jambo-api',
        'snippet'             => 'composer create-project jambostack/jambo-api',
    ],
    'es' => [
        'headline'            => 'El CMS headless de código abierto para desarrolladores',
        'tagline'             => 'API REST & GraphQL, Studio IA, Servidor MCP v2.0, Multi-locale — construido sobre Symfony 8 y PHP 8.4.',
        'badge'               => 'Symfony 8 · PHP 8.4 · AGPL v3',
        'cta_primary_label'   => 'Comenzar',
        'cta_primary_url'     => '/docs/guides/installation/',
        'cta_secondary_label' => '★ GitHub',
        'cta_secondary_url'   => 'https://github.com/jambostack/jambo-api',
        'snippet'             => 'composer create-project jambostack/jambo-api',
    ],
    'ar' => [
        'headline'            => 'نظام إدارة محتوى مفتوح المصدر للمطورين',
        'tagline'             => 'واجهة REST و GraphQL، استوديو الذكاء الاصطناعي، خادم MCP v2.0، متعدد اللغات — مبني على Symfony 8 و PHP 8.4.',
        'badge'               => 'Symfony 8 · PHP 8.4 · AGPL v3',
        'cta_primary_label'   => 'ابدأ الآن',
        'cta_primary_url'     => '/docs/guides/installation/',
        'cta_secondary_label' => '★ GitHub',
        'cta_secondary_url'   => 'https://github.com/jambostack/jambo-api',
        'snippet'             => 'composer create-project jambostack/jambo-api',
    ],
];

foreach ($heroes as $locale => $fields) {
    post($BASE, $TOKEN, 'hero', array_merge(['locale' => $locale, 'status' => 'published'], $fields));
}

// ─── STATS ────────────────────────────────────────────────────────────────────
echo "\n── Stats (4 langues) ──\n";
$stats = [
    'en' => [
        ['value'=>'32',   'title'=>'Migrations',   'icon'=>'🗄',  'slug'=>'migrations',  'order'=>1],
        ['value'=>'25+',  'title'=>'Controllers',  'icon'=>'⚙️',  'slug'=>'controllers', 'order'=>2],
        ['value'=>'30+',  'title'=>'Entities',     'icon'=>'📦',  'slug'=>'entities',    'order'=>3],
        ['value'=>'15+',  'title'=>'Field types',  'icon'=>'🔤',  'slug'=>'field-types', 'order'=>4],
        ['value'=>'4',    'title'=>'Languages',    'icon'=>'🌍',  'slug'=>'languages',   'order'=>5],
        ['value'=>'100%', 'title'=>'Open source',  'icon'=>'🔓',  'slug'=>'open-source', 'order'=>6],
    ],
    'fr' => [
        ['value'=>'32',   'title'=>'Migrations',      'icon'=>'🗄',  'slug'=>'migrations-fr',  'order'=>1],
        ['value'=>'25+',  'title'=>'Contrôleurs',     'icon'=>'⚙️',  'slug'=>'controllers-fr', 'order'=>2],
        ['value'=>'30+',  'title'=>'Entités',         'icon'=>'📦',  'slug'=>'entities-fr',    'order'=>3],
        ['value'=>'15+',  'title'=>'Types de champs', 'icon'=>'🔤',  'slug'=>'field-types-fr', 'order'=>4],
        ['value'=>'4',    'title'=>'Langues',         'icon'=>'🌍',  'slug'=>'languages-fr',   'order'=>5],
        ['value'=>'100%', 'title'=>'Open source',     'icon'=>'🔓',  'slug'=>'open-source-fr', 'order'=>6],
    ],
    'es' => [
        ['value'=>'32',   'title'=>'Migraciones',     'icon'=>'🗄',  'slug'=>'migrations-es',  'order'=>1],
        ['value'=>'25+',  'title'=>'Controladores',   'icon'=>'⚙️',  'slug'=>'controllers-es', 'order'=>2],
        ['value'=>'30+',  'title'=>'Entidades',       'icon'=>'📦',  'slug'=>'entities-es',    'order'=>3],
        ['value'=>'15+',  'title'=>'Tipos de campos', 'icon'=>'🔤',  'slug'=>'field-types-es', 'order'=>4],
        ['value'=>'4',    'title'=>'Idiomas',         'icon'=>'🌍',  'slug'=>'languages-es',   'order'=>5],
        ['value'=>'100%', 'title'=>'Código abierto',  'icon'=>'🔓',  'slug'=>'open-source-es', 'order'=>6],
    ],
    'ar' => [
        ['value'=>'32',   'title'=>'الترحيلات',       'icon'=>'🗄',  'slug'=>'migrations-ar',  'order'=>1],
        ['value'=>'25+',  'title'=>'المتحكمات',        'icon'=>'⚙️',  'slug'=>'controllers-ar', 'order'=>2],
        ['value'=>'30+',  'title'=>'الكيانات',         'icon'=>'📦',  'slug'=>'entities-ar',    'order'=>3],
        ['value'=>'15+',  'title'=>'أنواع الحقول',     'icon'=>'🔤',  'slug'=>'field-types-ar', 'order'=>4],
        ['value'=>'4',    'title'=>'اللغات',           'icon'=>'🌍',  'slug'=>'languages-ar',   'order'=>5],
        ['value'=>'100%', 'title'=>'مفتوح المصدر',     'icon'=>'🔓',  'slug'=>'open-source-ar', 'order'=>6],
    ],
];

foreach ($stats as $locale => $rows) {
    foreach ($rows as $row) {
        post($BASE, $TOKEN, 'stats', array_merge(['locale'=>$locale,'status'=>'published'], $row));
    }
}

// ─── FEATURES ─────────────────────────────────────────────────────────────────
echo "\n── Features (4 langues) ──\n";
$features = [
    'en' => [
        ['slug'=>'rest-graphql',    'icon'=>'⚡','category'=>'api',     'order'=>1, 'title'=>'REST & GraphQL API',         'description'=>'Paginated, filterable, locale-aware endpoints with auto-generated OpenAPI & Swagger docs.'],
        ['slug'=>'ai-studio',       'icon'=>'🤖','category'=>'ai',      'order'=>2, 'title'=>'AI Schema Studio',           'description'=>'Design your content schema by chatting with an AI. Supports OpenAI, Claude, DeepSeek and Ollama.'],
        ['slug'=>'mcp-server',      'icon'=>'🔌','category'=>'ai',      'order'=>3, 'title'=>'MCP Server v2.0',            'description'=>'Connect any AI agent (Claude, Cursor…) directly to your CMS via Model Context Protocol.'],
        ['slug'=>'multi-locale',    'icon'=>'🌍','category'=>'api',     'order'=>4, 'title'=>'Multi-locale',               'description'=>'Native internationalization on every collection with RTL support for Arabic and Hebrew.'],
        ['slug'=>'end-users',       'icon'=>'👥','category'=>'security','order'=>5, 'title'=>'End Users',                  'description'=>'Built-in front-end user authentication with JWT, custom fields, registration and password reset.'],
        ['slug'=>'full-text-search','icon'=>'🔍','category'=>'api',     'order'=>6, 'title'=>'Full-text Search',           'description'=>'Meilisearch integration with real-time indexing and instant search results across all collections.'],
        ['slug'=>'webhooks',        'icon'=>'📬','category'=>'devops',  'order'=>7, 'title'=>'Webhooks',                   'description'=>'Event-driven triggers on content changes, per-collection with delivery logs and retry logic.'],
        ['slug'=>'multi-project',   'icon'=>'📦','category'=>'admin',   'order'=>8, 'title'=>'Multi-project',              'description'=>'Manage multiple independent projects from a single installation — unique among headless CMS platforms.'],
    ],
    'fr' => [
        ['slug'=>'rest-graphql-fr',    'icon'=>'⚡','category'=>'api',     'order'=>1, 'title'=>'API REST & GraphQL',            'description'=>'Endpoints paginés, filtrables et multilingues avec docs OpenAPI & Swagger auto-générées.'],
        ['slug'=>'ai-studio-fr',       'icon'=>'🤖','category'=>'ai',      'order'=>2, 'title'=>'Studio IA',                     'description'=>'Concevez votre schéma de contenu en discutant avec une IA. Supporte OpenAI, Claude, DeepSeek et Ollama.'],
        ['slug'=>'mcp-server-fr',      'icon'=>'🔌','category'=>'ai',      'order'=>3, 'title'=>'Serveur MCP v2.0',              'description'=>'Connectez n\'importe quel agent IA directement à votre CMS via le protocole MCP.'],
        ['slug'=>'multi-locale-fr',    'icon'=>'🌍','category'=>'api',     'order'=>4, 'title'=>'Multi-locale',                  'description'=>'Internationalisation native sur chaque collection avec support RTL pour l\'arabe.'],
        ['slug'=>'end-users-fr',       'icon'=>'👥','category'=>'security','order'=>5, 'title'=>'Utilisateurs finaux',           'description'=>'Authentification front-end native avec JWT, champs personnalisés, inscription et réinitialisation de mot de passe.'],
        ['slug'=>'full-text-search-fr','icon'=>'🔍','category'=>'api',     'order'=>6, 'title'=>'Recherche plein texte',         'description'=>'Intégration Meilisearch avec indexation en temps réel et résultats instantanés.'],
        ['slug'=>'webhooks-fr',        'icon'=>'📬','category'=>'devops',  'order'=>7, 'title'=>'Webhooks',                      'description'=>'Déclencheurs événementiels sur les changements de contenu, par collection, avec journaux de livraison.'],
        ['slug'=>'multi-project-fr',   'icon'=>'📦','category'=>'admin',   'order'=>8, 'title'=>'Multi-projets',                 'description'=>'Gérez plusieurs projets indépendants depuis une installation unique — une exclusivité parmi les CMS headless.'],
    ],
    'es' => [
        ['slug'=>'rest-graphql-es',    'icon'=>'⚡','category'=>'api',     'order'=>1, 'title'=>'API REST & GraphQL',            'description'=>'Endpoints paginados, filtrables y multilingües con documentación OpenAPI y Swagger auto-generada.'],
        ['slug'=>'ai-studio-es',       'icon'=>'🤖','category'=>'ai',      'order'=>2, 'title'=>'Studio IA',                     'description'=>'Diseñe su esquema de contenido conversando con una IA. Compatible con OpenAI, Claude, DeepSeek y Ollama.'],
        ['slug'=>'mcp-server-es',      'icon'=>'🔌','category'=>'ai',      'order'=>3, 'title'=>'Servidor MCP v2.0',             'description'=>'Conecte cualquier agente IA directamente a su CMS mediante el Protocolo de Contexto de Modelos.'],
        ['slug'=>'multi-locale-es',    'icon'=>'🌍','category'=>'api',     'order'=>4, 'title'=>'Multi-idioma',                  'description'=>'Internacionalización nativa en cada colección con soporte RTL para árabe y hebreo.'],
        ['slug'=>'end-users-es',       'icon'=>'👥','category'=>'security','order'=>5, 'title'=>'Usuarios finales',              'description'=>'Autenticación front-end nativa con JWT, campos personalizados, registro y restablecimiento de contraseña.'],
        ['slug'=>'full-text-search-es','icon'=>'🔍','category'=>'api',     'order'=>6, 'title'=>'Búsqueda de texto completo',    'description'=>'Integración con Meilisearch con indexación en tiempo real y resultados de búsqueda instantáneos.'],
        ['slug'=>'webhooks-es',        'icon'=>'📬','category'=>'devops',  'order'=>7, 'title'=>'Webhooks',                      'description'=>'Disparadores basados en eventos en cambios de contenido, por colección, con registros de entrega.'],
        ['slug'=>'multi-project-es',   'icon'=>'📦','category'=>'admin',   'order'=>8, 'title'=>'Multi-proyecto',                'description'=>'Administre múltiples proyectos independientes desde una sola instalación — exclusivo entre CMS headless.'],
    ],
    'ar' => [
        ['slug'=>'rest-graphql-ar',    'icon'=>'⚡','category'=>'api',     'order'=>1, 'title'=>'REST و GraphQL API',           'description'=>'نقاط نهاية مرقمة وقابلة للتصفية ومتعددة اللغات مع توثيق OpenAPI و Swagger مُنشأ تلقائياً.'],
        ['slug'=>'ai-studio-ar',       'icon'=>'🤖','category'=>'ai',      'order'=>2, 'title'=>'استوديو الذكاء الاصطناعي',    'description'=>'صمم مخطط المحتوى الخاص بك بالتحدث مع الذكاء الاصطناعي. يدعم OpenAI وClaude وDeepSeek وOllama.'],
        ['slug'=>'mcp-server-ar',      'icon'=>'🔌','category'=>'ai',      'order'=>3, 'title'=>'خادم MCP v2.0',               'description'=>'ربط أي وكيل ذكاء اصطناعي مباشرةً بنظام إدارة المحتوى عبر بروتوكول MCP.'],
        ['slug'=>'multi-locale-ar',    'icon'=>'🌍','category'=>'api',     'order'=>4, 'title'=>'متعدد اللغات',                'description'=>'دعم التدويل الأصلي على كل مجموعة مع دعم RTL للعربية والعبرية.'],
        ['slug'=>'end-users-ar',       'icon'=>'👥','category'=>'security','order'=>5, 'title'=>'المستخدمون النهائيون',        'description'=>'مصادقة المستخدمين الأمامية مع JWT وحقول مخصصة والتسجيل وإعادة تعيين كلمة المرور.'],
        ['slug'=>'full-text-search-ar','icon'=>'🔍','category'=>'api',     'order'=>6, 'title'=>'البحث النصي الكامل',          'description'=>'تكامل Meilisearch مع الفهرسة في الوقت الفعلي ونتائج البحث الفورية.'],
        ['slug'=>'webhooks-ar',        'icon'=>'📬','category'=>'devops',  'order'=>7, 'title'=>'Webhooks',                    'description'=>'مشغلات مبنية على الأحداث عند تغييرات المحتوى، لكل مجموعة، مع سجلات التسليم.'],
        ['slug'=>'multi-project-ar',   'icon'=>'📦','category'=>'admin',   'order'=>8, 'title'=>'متعدد المشاريع',              'description'=>'إدارة مشاريع مستقلة متعددة من تثبيت واحد — ميزة حصرية بين منصات CMS بدون رأس.'],
    ],
];

foreach ($features as $locale => $rows) {
    foreach ($rows as $row) {
        post($BASE, $TOKEN, 'features', array_merge(['locale'=>$locale,'status'=>'published'], $row));
    }
}

// ─── COMPARISON FEATURES ──────────────────────────────────────────────────────
echo "\n── ComparisonFeatures (4 langues) ──\n";
$comparisons = [
    'en' => [
        ['slug'=>'multi-project-cmp',    'order'=>1,  'title'=>'Multi-project (single install)', 'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'ai-studio-cmp',        'order'=>2,  'title'=>'AI Schema Studio (chat-based)',  'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'mcp-server-cmp',       'order'=>3,  'title'=>'MCP Server (AI agents)',         'jambo'=>'yes','strapi'=>'no',         'directus'=>'partial', 'payload'=>'partial'],
        ['slug'=>'end-users-cmp',        'order'=>4,  'title'=>'End Users (front-end auth)',     'jambo'=>'yes','strapi'=>'yes',        'directus'=>'partial', 'payload'=>'yes'],
        ['slug'=>'versioning-cmp',       'order'=>5,  'title'=>'Content Versioning',             'jambo'=>'yes','strapi'=>'enterprise', 'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'multi-locale-cmp',     'order'=>6,  'title'=>'Multi-locale',                  'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'graphql-cmp',          'order'=>7,  'title'=>'GraphQL',                       'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'search-cmp',           'order'=>8,  'title'=>'Full-text Search',               'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'webhooks-cmp',         'order'=>9,  'title'=>'Webhooks',                      'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'audit-logs-cmp',       'order'=>10, 'title'=>'Audit Logs (open source)',       'jambo'=>'yes','strapi'=>'enterprise', 'directus'=>'yes',     'payload'=>'enterprise'],
        ['slug'=>'pdf-export-cmp',       'order'=>11, 'title'=>'PDF Export',                    'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'backend-stack-cmp',    'order'=>12, 'title'=>'PHP / Symfony backend',         'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
    ],
    'fr' => [
        ['slug'=>'multi-project-cmp-fr', 'order'=>1,  'title'=>'Multi-projet (installation unique)',  'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'ai-studio-cmp-fr',     'order'=>2,  'title'=>'Studio IA (basé sur le chat)',        'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'mcp-server-cmp-fr',    'order'=>3,  'title'=>'Serveur MCP (agents IA)',             'jambo'=>'yes','strapi'=>'no',         'directus'=>'partial', 'payload'=>'partial'],
        ['slug'=>'end-users-cmp-fr',     'order'=>4,  'title'=>'Utilisateurs finaux (auth)',          'jambo'=>'yes','strapi'=>'yes',        'directus'=>'partial', 'payload'=>'yes'],
        ['slug'=>'versioning-cmp-fr',    'order'=>5,  'title'=>'Versionnage de contenu',             'jambo'=>'yes','strapi'=>'enterprise', 'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'multi-locale-cmp-fr',  'order'=>6,  'title'=>'Multi-locale',                       'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'graphql-cmp-fr',       'order'=>7,  'title'=>'GraphQL',                            'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'search-cmp-fr',        'order'=>8,  'title'=>'Recherche plein texte',              'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'webhooks-cmp-fr',      'order'=>9,  'title'=>'Webhooks',                           'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'audit-logs-cmp-fr',    'order'=>10, 'title'=>'Journaux d\'audit (open source)',    'jambo'=>'yes','strapi'=>'enterprise', 'directus'=>'yes',     'payload'=>'enterprise'],
        ['slug'=>'pdf-export-cmp-fr',    'order'=>11, 'title'=>'Export PDF',                         'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'backend-stack-cmp-fr', 'order'=>12, 'title'=>'Backend PHP / Symfony',              'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
    ],
    'es' => [
        ['slug'=>'multi-project-cmp-es', 'order'=>1,  'title'=>'Multi-proyecto (instalación única)', 'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'ai-studio-cmp-es',     'order'=>2,  'title'=>'Studio IA (basado en chat)',          'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'mcp-server-cmp-es',    'order'=>3,  'title'=>'Servidor MCP (agentes IA)',           'jambo'=>'yes','strapi'=>'no',         'directus'=>'partial', 'payload'=>'partial'],
        ['slug'=>'end-users-cmp-es',     'order'=>4,  'title'=>'Usuarios finales (autenticación)',    'jambo'=>'yes','strapi'=>'yes',        'directus'=>'partial', 'payload'=>'yes'],
        ['slug'=>'versioning-cmp-es',    'order'=>5,  'title'=>'Versionado de contenido',            'jambo'=>'yes','strapi'=>'enterprise', 'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'multi-locale-cmp-es',  'order'=>6,  'title'=>'Multi-idioma',                       'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'graphql-cmp-es',       'order'=>7,  'title'=>'GraphQL',                            'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'search-cmp-es',        'order'=>8,  'title'=>'Búsqueda de texto completo',         'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'webhooks-cmp-es',      'order'=>9,  'title'=>'Webhooks',                           'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'audit-logs-cmp-es',    'order'=>10, 'title'=>'Registros de auditoría (open source)','jambo'=>'yes','strapi'=>'enterprise','directus'=>'yes',     'payload'=>'enterprise'],
        ['slug'=>'pdf-export-cmp-es',    'order'=>11, 'title'=>'Exportación PDF',                    'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'backend-stack-cmp-es', 'order'=>12, 'title'=>'Backend PHP / Symfony',              'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
    ],
    'ar' => [
        ['slug'=>'multi-project-cmp-ar', 'order'=>1,  'title'=>'متعدد المشاريع (تثبيت واحد)',        'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'ai-studio-cmp-ar',     'order'=>2,  'title'=>'استوديو الذكاء الاصطناعي (دردشة)',   'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'mcp-server-cmp-ar',    'order'=>3,  'title'=>'خادم MCP (وكلاء الذكاء)',            'jambo'=>'yes','strapi'=>'no',         'directus'=>'partial', 'payload'=>'partial'],
        ['slug'=>'end-users-cmp-ar',     'order'=>4,  'title'=>'المستخدمون النهائيون (مصادقة)',      'jambo'=>'yes','strapi'=>'yes',        'directus'=>'partial', 'payload'=>'yes'],
        ['slug'=>'versioning-cmp-ar',    'order'=>5,  'title'=>'إصدار المحتوى',                      'jambo'=>'yes','strapi'=>'enterprise', 'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'multi-locale-cmp-ar',  'order'=>6,  'title'=>'متعدد اللغات',                       'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'graphql-cmp-ar',       'order'=>7,  'title'=>'GraphQL',                            'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'search-cmp-ar',        'order'=>8,  'title'=>'البحث النصي الكامل',                 'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'webhooks-cmp-ar',      'order'=>9,  'title'=>'Webhooks',                           'jambo'=>'yes','strapi'=>'yes',        'directus'=>'yes',     'payload'=>'yes'],
        ['slug'=>'audit-logs-cmp-ar',    'order'=>10, 'title'=>'سجلات التدقيق (مفتوح المصدر)',       'jambo'=>'yes','strapi'=>'enterprise', 'directus'=>'yes',     'payload'=>'enterprise'],
        ['slug'=>'pdf-export-cmp-ar',    'order'=>11, 'title'=>'تصدير PDF',                          'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
        ['slug'=>'backend-stack-cmp-ar', 'order'=>12, 'title'=>'خلفية PHP / Symfony',               'jambo'=>'yes','strapi'=>'no',         'directus'=>'no',      'payload'=>'no'],
    ],
];

foreach ($comparisons as $locale => $rows) {
    foreach ($rows as $row) {
        post($BASE, $TOKEN, 'comparison_features', array_merge(['locale'=>$locale,'status'=>'published'], $row));
    }
}

echo "\n✅ Terminé.\n";
