# Jambo Native — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** Permettre à Jambo de rendre des templates Twig côté serveur (SSR), avec accès direct EAV, exécutés dans un Twig Sandbox sécurisé (code généré par IA).

**Architecture:** (1) NativeTemplate (nouveau type de template Workbench). (2) Twig SecurityPolicy (sandbox strict). (3) JamboNativeExtension (fonctions Twig custom). (4) NativeRenderer (service de rendu). (5) SiteHostResolver bi-mode (statique OU Twig).

**Tech Stack:** PHP 8.4, Symfony 8, Twig 3, Doctrine ORM.

---

## File Map

### Créés
| Fichier | Responsabilité |
|---------|---------------|
| `src/Workbench/Templates/NativeTemplate.php` | Template Native (génère des fichiers .twig) |
| `src/Twig/NativeTwigSecurityPolicy.php` | Sandbox security policy |
| `src/Twig/JamboNativeExtension.php` | Extensions Twig custom |
| `src/Service/NativeRenderer.php` | Compile et rend un template Twig sandboxé |
| `tests/Twig/NativeTwigSecurityPolicyTest.php` | Tests sandbox |
| `tests/Service/NativeRendererTest.php` | Tests rendu |

### Modifiés
| Fichier | Modification |
|---------|-------------|
| `src/Entity/WorkbenchProject.php` | + 'native' dans FRAMEWORKS |
| `src/EventSubscriber/SiteHostResolver.php` | + branche Twig (bi-mode) |
| `config/services.yaml` | + NativeRenderer, NativeTemplate, Twig extensions |

---

## Task 1: NativeTemplate

- [ ] **Step 1: Créer NativeTemplate**
- Stub minimal : génère `templates/index.html.twig` + `templates/_layout.html.twig`
- `getStaticOutputDir()` retourne `null` (pas d'export statique)
- `getDevCommand()` / `getInstallCommand()` / `getBuildCommand()` retournent des no-ops

- [ ] **Step 2: Enregistrer dans services.yaml** (comme les autres templates)
- [ ] **Step 3: Ajouter 'native' à WorkbenchProject::FRAMEWORKS**
- [ ] **Step 4: Commit**

---

## Task 2: NativeTwigSecurityPolicy

- [ ] **Step 1: Écrire le test (red)**
- Vérifie que les tags autorisés passent, que les tags bloqués lèvent une exception
- Vérifie que `dump()` est bloqué, `do` est bloqué

- [ ] **Step 2: Implémenter NativeTwigSecurityPolicy**
- Liste blanche tags : `if`, `for`, `set`, `block`, `extends`, `include`, `embed`
- Liste blanche filtres : `escape`, `raw`, `upper`, `lower`, `date`, `striptags`, `trim`, `nl2br`, `slice`, `first`, `last`, `length`, `sort`, `reverse`, `merge`, `json_encode`
- Liste blanche fonctions : `jambo_collection`, `jambo_entry`, `jambo_setting`, `jambo_url`, `jambo_asset`, `jambo_locale`, `url`, `path`, `asset`, `absolute_url`, `block`, `parent`
- Méthodes/propriétés : `[]` (vide — zéro accès aux objets)

- [ ] **Step 3: Tests green**
- [ ] **Step 4: Commit**

---

## Task 3: JamboNativeExtension

- [ ] **Step 1: Implémenter les fonctions Twig**

```php
jambo_collection(project, slug, options = []) → array d'entrées formatées
jambo_entry(project, collectionSlug, entrySlug) → ?array
jambo_setting(project, key) → mixed
jambo_url(path) → string (URL relative)
jambo_asset(path) → string (chemin asset)
jambo_locale() → string (locale courante)
```

- Les fonctions reçoivent `$project` via le contexte d'exécution (passé automatiquement par NativeRenderer)
- La fonction `jambo_collection` utilise `CollectionRepository`, `ContentEntryRepository` et `EavDataFormatterService` (accès direct, zéro HTTP)

- [ ] **Step 2: Commit**

---

## Task 4: NativeRenderer

- [ ] **Step 1: Écrire le test (red)**
- Créer un WorkbenchProject avec un template simple
- Appeler `render()` et vérifier le HTML produit

- [ ] **Step 2: Implémenter NativeRenderer**

```php
class NativeRenderer
{
    public function render(WorkbenchProject $workbench, string $path): string
    {
        // 1. Résoudre le template depuis $workbench->files
        // 2. Créer un Twig Environment sandboxé
        // 3. Construire le contexte (données EAV)
        // 4. Rendre et retourner le HTML
    }
}
```

- Utilise `ArrayLoader` (templates chargés depuis `$workbench->files`, jamais depuis le filesystem)
- Applique la `SecurityPolicy` via `$twig->addExtension(new SandboxExtension($policy, true))`
- Mapping URL → template :
  - `/` → `templates/index.html.twig`
  - `/page/{slug}` → `templates/page.html.twig`
  - `/blog` → `templates/blog.html.twig`
  - `/blog/{slug}` → `templates/post.html.twig`

- [ ] **Step 3: Tests green**
- [ ] **Step 4: Register dans services.yaml**
- [ ] **Step 5: Commit**

---

## Task 5: SiteHostResolver bi-mode

- [ ] **Step 1: Ajouter la branche Twig dans onRequest()**

```php
if ($workbench->framework === 'native') {
    $html = $this->nativeRenderer->render($workbench, $path);
    $event->setResponse(new Response($html, 200, [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-cache',
    ]));
    return;
}
```

- [ ] **Step 2: Injecter NativeRenderer dans SiteHostResolver** (constructeur)
- [ ] **Step 3: Mettre à jour services.yaml**
- [ ] **Step 4: Commit**

---

## Task 6: Validation finale

- [ ] **Step 1: Tests complets** (`php vendor/bin/phpunit`)
- [ ] **Step 2: Container lint + routes**
- [ ] **Step 3: PHP syntax check**
- [ ] **Step 4: Commit final**
