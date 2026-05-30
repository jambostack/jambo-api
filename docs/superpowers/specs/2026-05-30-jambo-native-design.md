# Jambo Native — Design Spec

> Date : 2026-05-30
> Statut : spec écrit
> Dépend de : Jambo Sites (implémenté)
> Suite à : [Jambo Platform Vision](../jambo-platform-vision.html)

## Objectif

Permettre à Jambo de rendre des templates Twig **côté serveur**, avec **accès direct à l'EAV** (zéro HTTP), rendu SSR/SEO, et exécution sandboxée du code généré par IA.

---

## 1. Architecture

```
┌──────────────────────────────────────────────────────────┐
│  Navigateur                                               │
│  GET monsite.com → HTML complet (SSR)                     │
│  GET monsite.com/about → page about rendue serveur         │
└──────────────────────────────────────────────────────────┘
                         │
                         ▼
┌──────────────────────────────────────────────────────────┐
│  SiteHostResolver (bi-mode)                               │
│                                                           │
│  IF WorkbenchProject.renderMode === 'twig'                │
│    → NativeRenderer::render(workbenchProject, path)        │
│    → Response(HTML, 200)                                   │
│  ELSE                                                     │
│    → PublishedSiteStorage::readFile() (statique)           │
└──────────────────────────────────────────────────────────┘
                         │
                         ▼
┌──────────────────────────────────────────────────────────┐
│  NativeRenderer                                           │
│                                                           │
│  1. Charger le template Twig depuis les fichiers du       │
│     WorkbenchProject (colonne files JSON)                  │
│  2. Compiler via Twig Sandbox (SecurityPolicy stricte)     │
│  3. Injecter les données EAV via extensions custom         │
│  4. Rendre → HTML                                         │
└──────────────────────────────────────────────────────────┘
                         │
                         ▼
┌──────────────────────────────────────────────────────────┐
│  Twig Sandbox + Extensions custom                         │
│                                                           │
│  jambo_collection('blog')     → liste les entrées         │
│  jambo_entry('blog', 'slug')  → une entrée                │
│  jambo_setting('site_title')  → variable globale           │
│  jambo_url('about')           → URL relative               │
│  jambo_asset('logo.png')      → URL d'un asset             │
│                                                           │
│  Accès direct EAV (EavDataFormatterService) — zéro HTTP   │
└──────────────────────────────────────────────────────────┘
```

---

## 2. Composants

### 2.1 NativeTemplate (nouveau template Workbench)

```php
class NativeTemplate extends BaseTemplate
{
    public function getId(): string { return 'native'; }
    public function getLabel(): string { return 'Jambo Native (Twig)'; }

    // Génère les fichiers Twig de base (pas de JS !)
    public function getStarterFiles(string $jamboApiUrl, string $projectUuid, array $collections): array
    {
        return [
            'templates/index.html.twig' => $this->generateIndexTemplate($collections),
            'templates/page.html.twig'  => $this->generatePageTemplate(),
            'templates/_layout.html.twig' => $this->generateLayout(),
        ];
    }

    public function getDevCommand(): string { return 'echo "Native — no dev server needed"'; }
    public function getDockerfile(): string { return ''; } // pas de Docker, rendu côté serveur
    public function getInstallCommand(): string { return 'echo "Native — no install"'; }
    public function getBuildCommand(): string { return 'echo "Native — no build"'; }
    public function getStaticOutputDir(): ?string { return null; } // pas d'export statique
}
```

### 2.2 Extension WorkbenchProject

Ajouter à `WorkbenchProject::$framework` la valeur `'native'` :

```php
public const FRAMEWORKS = ['nextjs', 'nuxt', 'astro', 'sveltekit', 'native'];
```

### 2.3 NativeRenderer (service de rendu)

```php
class NativeRenderer
{
    public function __construct(
        private TwigSecurityPolicy $policy, // sandbox policy
        private EavDataFormatterService $eavFormatter,
        private ContentEntryRepository $entryRepository,
        private CollectionRepository $collectionRepository,
        private Environment $twig, // environnement sandboxé pré-construit
    ) {}

    /**
     * Rend un template Twig pour un WorkbenchProject.
     */
    public function render(WorkbenchProject $workbench, string $path): string
    {
        // 1. Extraire le template depuis $workbench->files
        $templateName = $this->resolveTemplate($workbench, $path);
        $templateSource = $workbench->files[$templateName] ?? null;

        // 2. Créer un Twig Loader depuis la chaîne
        $loader = new ArrayLoader([$templateName => $templateSource]);
        $sandboxedTwig = $this->createSandboxedEnvironment($loader);

        // 3. Collecter les données EAV
        $context = $this->buildContext($workbench->project);

        // 4. Rendre
        return $sandboxedTwig->render($templateName, $context);
    }

    private function resolveTemplate(WorkbenchProject $workbench, string $path): string
    {
        // Mapping URL → template
        // / → templates/index.html.twig
        // /about → templates/page.html.twig (slug about)
        // /blog → templates/blog.html.twig
        // /blog/mon-article → templates/post.html.twig (slug mon-article)
    }

    private function buildContext(Project $project): array { /* ... */ }
    private function createSandboxedEnvironment(LoaderInterface $loader): Environment { /* ... */ }
}
```

### 2.4 Twig SecurityPolicy (sandbox)

```php
class NativeTwigSecurityPolicy implements SecurityPolicyInterface
{
    // Liste blanche stricte
    public function getAllowedTags(): array
    {
        return ['if', 'for', 'set', 'block', 'extends', 'include', 'embed'];
    }

    public function getAllowedFilters(): array
    {
        return ['escape', 'raw', 'upper', 'lower', 'title', 'date',
                'striptags', 'trim', 'nl2br', 'slice', 'first', 'last',
                'keys', 'length', 'sort', 'reverse', 'merge', 'json_encode'];
    }

    public function getAllowedFunctions(): array
    {
        return ['jambo_collection', 'jambo_entry', 'jambo_setting',
                'jambo_url', 'jambo_asset', 'jambo_locale',
                'url', 'path', 'asset', 'absolute_url',
                'block', 'parent', 'include']; // fonctions standard safe
    }

    public function getAllowedMethods(): array { return []; }
    public function getAllowedProperties(): array { return []; }
}
```

### 2.5 Extensions Twig custom

```php
class JamboNativeExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('jambo_collection', [$this, 'getCollection']),
            new TwigFunction('jambo_entry', [$this, 'getEntry']),
            new TwigFunction('jambo_setting', [$this, 'getSetting']),
            new TwigFunction('jambo_url', [$this, 'getUrl']),
            new TwigFunction('jambo_asset', [$this, 'getAsset']),
            new TwigFunction('jambo_locale', [$this, 'getLocale']),
        ];
    }

    public function getCollection(Project $project, string $slug, array $options = []): array
    {
        // Récupère les entrées d'une collection via EAV direct
    }

    public function getEntry(Project $project, string $collectionSlug, string $entrySlug): ?array
    {
        // Récupère une entrée spécifique
    }
}
```

### 2.6 SiteHostResolver — mode bi-mode

Modifier `onRequest()` :

```php
public function onRequest(RequestEvent $event): void
{
    // ... (vérifications hostname, domaine, etc.)

    $workbench = $siteDomain->workbenchProject;

    if ($workbench->framework === 'native') {
        // Mode Twig
        $html = $this->nativeRenderer->render($workbench, $path);
        $event->setResponse(new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]));
        return;
    }

    // Mode statique (existant)
    [$content, $resolvedPath] = $this->resolveContent($uuid, $path);
    // ...
}
```

---

## 3. Sécurité

### 3.1 Twig Sandbox (critique)

**Problème** : du code Twig généré par IA est exécuté dans le process PHP de Jambo.

**Solution** :
- `SecurityPolicy` stricte — **liste blanche** de tags/filtres/fonctions.
- **Pas** de `{% do %}`, `{{ dump() }}`, `source()`, `include(template_from_string(...))`.
- **Pas** d'accès aux objets Doctrine ou aux services Symfony.
- Les fonctions `jambo_*` sont les **seuls points d'entrée** vers les données.
- Le loader de template est un `ArrayLoader` (templates en mémoire, jamais depuis le filesystem).

### 3.2 Injection de données

- Seules les données EAV formatées (tableaux PHP) sont passées au template.
- Aucun objet Doctrine n'est exposé dans le contexte Twig.
- `SecurityPolicy::getAllowedMethods()` et `getAllowedProperties()` retournent `[]` (vide).

### 3.3 Limites de ressources

- Timeout de rendu : 5 secondes max (via `set_time_limit` ou `pcntl_alarm`).
- Pas de boucles infinies : `{% for %}` limité côté application (max 1000 entrées).
- Pas d'inclusion récursive : profondeur max d'`{% include %}` = 3.

---

## 4. Fichiers créés/modifiés

### Créés

| Fichier | Responsabilité |
|---------|---------------|
| `src/Workbench/Templates/NativeTemplate.php` | Template Jambo Native |
| `src/Service/NativeRenderer.php` | Service de rendu Twig sandboxé |
| `src/Twig/NativeTwigSecurityPolicy.php` | Politique de sandbox |
| `src/Twig/JamboNativeExtension.php` | Extensions Twig custom |
| `tests/Service/NativeRendererTest.php` | Tests |
| `tests/Twig/NativeTwigSecurityPolicyTest.php` | Tests sandbox |

### Modifiés

| Fichier | Modification |
|---------|-------------|
| `src/Workbench/Templates/BaseTemplate.php` | + getDefaultPath() optionnel |
| `src/Entity/WorkbenchProject.php` | + 'native' dans FRAMEWORKS |
| `src/EventSubscriber/SiteHostResolver.php` | + mode Twig (bi-mode) |
| `config/services.yaml` | + NativeRenderer, Twig extensions |

---

## 5. Non-périmètre (YAGNI)

- Routing dynamique (URLs custom par entrée) — v1 : mapping fixe
- Cache de template compilé (Twig le fait déjà nativement)
- Preview en direct dans le Workbench (le ChatPanel actuel fait du JS, pas du Twig)
- Multi-locale automatique (l'extension `jambo_locale()` expose la locale, le template gère)
- Assets uploadés (images, CSS) — v1 : URLs externes uniquement
