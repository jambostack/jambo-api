# Design — SEO / Redirects / Form Builder — v1.19

- **Date** : 2026-06-24
- **Version cible** : v1.19 (dernier sous-projet avant stabilisation v2.0)
- **Statut** : design validé, prêt pour le plan d'implémentation
- **Positionnement** : Battre la concurrence (Strapi, Directus) sur les 3 axes

## 1. Contexte & objectif

Jambo est un CMS headless API-first. Les sites publics qui consomment l'API (Next.js, Nuxt, etc.) ont besoin de :

1. **Données SEO structurées** dans l'API pour générer les balises meta, OpenGraph, schema.org côté frontend
2. **Sitemap et robots.txt** servis directement par le CMS
3. **Gestion des redirections** pour la migration et le référencement
4. **Formulaires publics** collectant des soumissions

**Actuellement :** rien n'existe. Le SEO repose sur des champs EAV définis manuellement par l'utilisateur. Pas de sitemap, pas de redirects, pas de form builder.

**Objectif :** un système SEO/Redirects/Forms de niveau entreprise, intégré nativement au CMS, qui surpasse les plugins Strapi et extensions Directus sur chaque fonctionnalité.

## 2. Sous-projet A — SEO Avancé

### 2.1 Architecture

```
┌─────────────────────────────────────────────────────────┐
│                   Studio Jambo (admin)                    │
│  ┌──────────────┐  ┌──────────────┐  ┌───────────────┐  │
│  │ Panneau SEO   │  │ SEO Dashboard│  │ Bulk SEO Edit │  │
│  │ Score + Aperçu│  │ Vue globale  │  │ Spreadsheet   │  │
│  └──────────────┘  └──────────────┘  └───────────────┘  │
└─────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────┐
│                    Backend Jambo                         │
│  ┌──────────────┐  ┌──────────────┐  ┌───────────────┐  │
│  │ SeoAnalyzer  │  │ SitemapGen   │  │ StructuredData│  │
│  │ Score + Audit│  │ sitemap.xml  │  │ JSON-LD       │  │
│  └──────────────┘  └──────────────┘  └───────────────┘  │
└─────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────┐
│              Frontend du client (Next.js, etc.)          │
│  Consomme /api/{project}/entries → _seo object          │
│  Consomme /{project}/sitemap.xml                        │
│  Consomme /{project}/robots.txt                         │
└─────────────────────────────────────────────────────────┘
```

### 2.2 Modèle de données

#### ContentEntry — colonnes SEO natives

| Colonne | Type | Description |
|---|---|---|
| `meta_title` | VARCHAR(255) nullable | Titre SEO (override)
| `meta_description` | TEXT nullable | Meta description (120-155 chars)
| `slug` | VARCHAR(255) NOT NULL | Slug natif (plus un champ EAV)
| `canonical_url` | VARCHAR(512) nullable | URL canonique manuelle
| `og_image` | VARCHAR(36) nullable | UUID vers media pour image OpenGraph
| `seo_score` | INT nullable (0-100) | Score SEO calculé (cache)
| `seo_scored_at` | DATETIME nullable | Dernière mise à jour du score

**Index :** UNIQUE `(collection_id, slug, locale)` — garantit l'unicité du slug par collection et locale.

#### Collection — settings SEO

Clé `seo` dans le JSON `Collection::$settings` :

```json
{
  "seo": {
    "indexable": true,
    "sitemapPriority": 0.5,
    "sitemapChangefreq": "weekly",
    "autoGenerateSlug": true,
    "slugSourceField": "title",
    "defaultOgImage": null,
    "structuredDataType": "Article"
  }
}
```

- `sitemapPriority` : 0.0 à 1.0
- `sitemapChangefreq` : `always`, `hourly`, `daily`, `weekly`, `monthly`, `yearly`, `never`
- `slugSourceField` : slug du champ EAV source pour auto-génération
- `structuredDataType` : `Article`, `Product`, `FAQ`, `Event`, `Recipe`, `Organization`, `WebPage`, `BreadcrumbList`, `none`

#### Project — settings SEO

Clé `seo` dans le JSON `Project::$settings` :

```json
{
  "seo": {
    "defaultTitleTemplate": "{title} | {siteName}",
    "siteName": "Mon Site",
    "defaultOgImage": null,
    "twitterHandle": "@monsite",
    "robotsDefault": "index, follow",
    "googleSiteVerification": null,
    "bingSiteVerification": null,
    "enableSitemap": true,
    "enableImageSitemap": true,
    "sitemapExcludeCollections": []
  }
}
```

### 2.3 Composants backend

#### SeoAnalyzer (`src/Service/Seo/SeoAnalyzer.php`)

Analyse et score le SEO d'une entrée. Retourne un `SeoScore` DTO.

**Critères de scoring :**

| Critère | Poids | Seuil optimal |
|---|---|---|
| Longueur metaTitle | 15% | 50-60 caractères
| Longueur metaDescription | 15% | 120-155 caractères
| Présence mot-clé dans le titre | 10% | Présent
| Présence mot-clé dans la description | 10% | Présent
| Présence image OG | 10% | Présente
| Slug optimisé (court, sans stop words) | 10% | ≤ 75 caractères
| Contenu > 300 mots | 10% | Oui
| Liens internes (≥ 2) | 5% | Présents
| Liens sortants (≥ 1) | 5% | Présents
| Alt-text sur toutes les images | 5% | Toutes
| Structured Data valide | 5% | Valide

Méthodes :
- `analyze(ContentEntry $entry, ?string $keyword = null): SeoScore`
- `batchAnalyze(array $entries): array` — pour le Bulk SEO Editor
- `audit(ContentEntry $entry): SeoAuditReport` — rapport complet pour l'audit one-click

#### StructuredDataGenerator (`src/Service/Seo/StructuredDataGenerator.php`)

Génère le JSON-LD selon le type configuré sur la collection.

Types supportés :
- `Article` → `@type: Article` avec `headline`, `description`, `image`, `datePublished`, `author`
- `Product` → `@type: Product` avec `name`, `description`, `image`, `offers`
- `FAQ` → `@type: FAQPage` avec `mainEntity: [Question]`
- `Event` → `@type: Event` avec `startDate`, `endDate`, `location`
- `Recipe` → `@type: Recipe` avec `ingredients`, `instructions`
- `Organization` → `@type: Organization` avec `name`, `logo`, `url`
- `WebPage` → `@type: WebPage` avec `name`, `description`
- `BreadcrumbList` → `@type: BreadcrumbList` avec `itemListElement`

**Validation :** chaque type est validé contre le schéma Google Rich Results avant émission. Si invalide, un avertissement est loggé (pas d'erreur utilisateur).

#### SitemapGenerator (`src/Service/Seo/SitemapGenerator.php`)

Méthodes :
- `generateSitemap(Project $project): string` — XML sitemap standard
- `generateImageSitemap(Project $project): string` — XML sitemap images

Comportement :
- Inclut toutes les entrées publiées des collections indexables
- Exclut les collections listées dans `sitemapExcludeCollections`
- Inclut les `<image:image>` pour chaque image référencée dans les entrées
- Respecte `sitemapPriority` et `sitemapChangefreq` de la collection
- Génération paginée si > 50 000 URLs (plusieurs fichiers sitemap + index)
- Cache 1h en production

#### HreflangGenerator (`src/Service/Seo/HreflangGenerator.php`)

- `generateHreflang(ContentEntry $entry): array` — retourne les balises hreflang
- Utilise la relation `collection` + `slug` pour trouver les autres locales
- Format : `['fr' => 'https://...', 'en' => 'https://...', 'x-default' => 'https://...']`

### 2.4 Routes

| Route | Méthode | Description |
|---|---|---|
| `/{projectUuid}/sitemap.xml` | GET | Sitemap XML
| `/{projectUuid}/sitemap-images.xml` | GET | Image sitemap
| `/{projectUuid}/robots.txt` | GET | Robots.txt dynamique
| `/api/{projectUuid}/seo/manifest` | GET | Données SEO globales du projet
| `/api/{projectUuid}/seo/scores` | GET | Scores SEO de toutes les entrées (dashboard)
| `/api/{projectUuid}/seo/audit/{entryUuid}` | GET | Audit SEO complet d'une entrée
| `/api/{projectUuid}/seo/bulk` | PUT | Mise à jour en masse des champs SEO

### 2.5 Hooks Doctrine

- `preUpdate` / `prePersist` sur ContentEntry :
  - Si `autoGenerateSlug` activé et slug vide → auto-génération depuis `slugSourceField`
  - Auto-détection de la locale et lien hreflang

### 2.6 Historique SEO

Nouvelle entité `SeoRevision` :

| Colonne | Type |
|---|---|
| `id` | INT PK |
| `entry` | ManyToOne ContentEntry |
| `metaTitle` | VARCHAR(255) nullable |
| `metaDescription` | TEXT nullable |
| `slug` | VARCHAR(255) |
| `canonicalUrl` | VARCHAR(512) nullable |
| `ogImage` | VARCHAR(36) nullable |
| `seoScore` | INT nullable |
| `changedBy` | ManyToOne User |
| `changedAt` | DATETIME |

Une révision est créée avant chaque `preUpdate` si au moins un champ SEO a changé.

### 2.7 SEO Endpoint dans l'API REST publique

Chaque entrée inclut un objet `_seo` dans la réponse :

```json
{
  "id": "...",
  "uuid": "...",
  "_seo": {
    "metaTitle": "Titre SEO",
    "metaDescription": "Description SEO...",
    "slug": "mon-article",
    "canonicalUrl": "https://...",
    "ogImage": "https://...",
    "score": 87,
    "openGraph": {
      "title": "Titre OG",
      "description": "Description OG",
      "image": "https://...",
      "type": "article",
      "siteName": "Mon Site"
    },
    "twitter": {
      "card": "summary_large_image",
      "title": "...",
      "description": "...",
      "image": "https://..."
    },
    "structuredData": { "@type": "Article", ... },
    "hreflang": {
      "fr": "https://...",
      "en": "https://...",
      "x-default": "https://..."
    }
  }
}
```

Le champ `_seo` est présent dans :
- `GET /api/{projectId}/{collectionSlug}` (liste)
- `GET /api/{projectId}/{collectionSlug}/{uuid}` (single)
- GraphQL (champ `_seo` sur chaque type d'entrée)
- Live Preview

### 2.8 Frontend admin — Studio Jambo

#### Panneau SEO dans l'éditeur d'entrée

- **Onglet "Score SEO"** :
  - Jauge circulaire du score (vert ≥ 80, orange 50-79, rouge < 50)
  - Liste des critères avec check ✅/❌
  - Conseils d'amélioration (ex. "Ajoutez 3 mots au titre pour être dans la zone optimale 50-60 caractères")

- **Onglet "Aperçu"** :
  - Google desktop snippet live
  - Google mobile snippet live
  - Facebook OpenGraph preview
  - Twitter Card preview
  - LinkedIn preview
  - Mise à jour en temps réel pendant la frappe

- **Onglet "Données structurées"** :
  - Prévisualisation Google Rich Result
  - Badge "✅ Compatible Rich Result" ou "❌ Erreur"
  - Code JSON-LD brut (read-only)

- **Onglet "Hreflang"** :
  - Liste des autres locales avec lien vers l'éditeur
  - Badge "✅ Hreflang OK" ou "⚠️ Locale manquante"

#### SEO Dashboard

- Score moyen global du projet
- Graphique d'évolution 30 jours
- Tableau des entrées triable par score
- Filtres : score < 50, sans meta description, titre trop long, slug dupliqué, sans image OG
- Clic sur une entrée → ouvre l'éditeur

#### Bulk SEO Editor

- Spreadsheet avec colonnes : Titre, Meta Title, Meta Description, Slug, Score
- Édition inline (double-clic pour éditer)
- Filtrage, tri, recherche plein texte
- Export CSV
- Bouton "Analyser tout" qui relance le scoring sur toutes les entrées visibles

#### Auto-internal-linking IA

- Bouton "Suggérer des liens" dans l'éditeur
- L'IA scanne le contenu et suggère 2-5 liens vers d'autres entrées
- L'admin clique pour accepter/rejeter chaque suggestion
- Les liens acceptés sont injectés dans le contenu

#### Audit SEO one-click

- Bouton "Auditer" dans le panneau SEO
- Rapport complet : score, critères détaillés, liens cassés, vitesse API, structured data
- Export PDF (template Twig → DomPDF)

### 2.9 i18n

Clés `seo.*` dans les 4 langues :
- `seo.score`, `seo.score_very_low`, `seo.score_low`, `seo.score_good`, `seo.score_excellent`
- `seo.criteria.*` (tous les critères de scoring)
- `seo.preview.google_desktop`, `seo.preview.google_mobile`, `seo.preview.facebook`, `seo.preview.twitter`, `seo.preview.linkedin`
- `seo.structured_data`, `seo.rich_result_valid`, `seo.rich_result_error`
- `seo.hreflang`, `seo.hreflang_ok`, `seo.hreflang_missing`
- `seo.dashboard`, `seo.bulk_editor`, `seo.audit`, `seo.export_pdf`
- `seo.internal_links`, `seo.suggest_links`, `seo.link_accepted`
- `seo.history`, `seo.revision_by`, `seo.revision_date`

---

## 3. Sous-projet B — Redirects Avancés

### 3.1 Architecture

```
┌──────────────────────────────────────────────────────┐
│                 Studio Jambo (admin)                  │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │ Redirect CRUD │  │ 404 Log      │  │ Bulk Editor│ │
│  └──────────────┘  └──────────────┘  └────────────┘ │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│              Middleware RedirectResolver             │
│  Cache in-memory → intercepte requêtes → redirect   │
│  Fallback: /redirects/resolve pour frontend          │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│   Frontend client → GET /{project}/redirects/resolve │
│   ?path=/blog/ancien → 301 → /blog/nouveau          │
└─────────────────────────────────────────────────────┘
```

### 3.2 Entité Redirect

Nouvelle entité `Redirect` :

| Colonne | Type | Description |
|---|---|---|
| `id` | INT PK |
| `uuid` | CHAR(36) UNIQUE |
| `project` | ManyToOne Project |
| `fromPath` | VARCHAR(512) | Chemin source (regex si `isPattern=true`)
| `toPath` | VARCHAR(512) | Chemin cible
| `httpCode` | INT | 301, 302, 307, 308
| `isPattern` | BOOL | true si fromPath est un pattern regex
| `isEnabled` | BOOL | Activé/désactivé
| `hits` | INT | Compteur de redirections
| `lastHitAt` | DATETIME nullable | Date du dernier hit
| `isAuto` | BOOL | true si auto-généré (changement de slug)
| `sourceEntry` | ManyToOne ContentEntry nullable | Entrée source pour l'auto-redirect
| `createdBy` | ManyToOne User |
| `updatedBy` | ManyToOne User nullable |
| `createdAt` | DATETIME |
| `updatedAt` | DATETIME |

**Index :** UNIQUE `(project_id, fromPath)` — pas deux redirections avec le même chemin source.

### 3.3 Entité RedirectRevision

| Colonne | Type |
|---|---|
| `id` | INT PK |
| `redirect` | ManyToOne Redirect |
| `fromPath` | VARCHAR(512) |
| `toPath` | VARCHAR(512) |
| `httpCode` | INT |
| `changedBy` | ManyToOne User |
| `changedAt` | DATETIME |

### 3.4 Entité NotFoundLog

| Colonne | Type |
|---|---|
| `id` | INT PK |
| `project` | ManyToOne Project |
| `path` | VARCHAR(512) |
| `referrer` | VARCHAR(512) nullable |
| `userAgent` | VARCHAR(512) nullable |
| `ip` | VARCHAR(45) nullable |
| `count` | INT | Occurrences de la même URL
| `firstSeenAt` | DATETIME |
| `lastSeenAt` | DATETIME |
| `isResolved` | BOOL | true si une redirection a été créée

### 3.5 Composants backend

#### RedirectResolver (`src/Service/Redirect/RedirectResolver.php`)

- `resolve(string $path, Project $project): ?Redirect` — trouve la redirection correspondante
- Gère les patterns regex avec `preg_match`
- Détecte les chaînes de redirection (max 10 hops)
- Détecte les boucles (A→B→A)
- Incrémente le compteur `hits` et met à jour `lastHitAt`

#### RedirectMiddleware (`src/Security/Redirect/RedirectMiddleware.php`)

- Intercepte les requêtes HTTP entrantes
- Charge toutes les redirections actives en mémoire au boot (cache)
- Pour chaque requête, vérifie si le chemin correspond à une redirection
- Si oui → retourne le redirect HTTP
- Invalidation du cache à chaque modification de redirect (via event listener)

#### RedirectChainDetector (`src/Service/Redirect/RedirectChainDetector.php`)

- `detectChains(array $redirects): array` — trouve toutes les chaînes A→B→C
- `suggestShortcuts(array $chains): array` — suggère des raccourcis A→C
- `detectLoop(Redirect $redirect): bool` — vérifie si l'ajout crée une boucle
- Exécuté automatiquement avant `persist`

#### LinkChecker (`src/Service/Redirect/LinkChecker.php`)

- `scanEntry(ContentEntry $entry): array` — extrait tous les liens du contenu
- `checkLinks(Project $project): array` — vérifie chaque lien → liste des liens cassés
- `suggestRedirect(NotFoundLink $link): ?Redirect` — suggère une redirection via matching flou du slug

### 3.6 Auto-redirect sur changement de slug

Dans le hook Doctrine `preUpdate` de ContentEntry :
1. Détecte si le slug a changé
2. Si oui, crée automatiquement une `Redirect` :
   - `fromPath` = ancien slug → `/collection-slug/ancien-slug`
   - `toPath` = `/collection-slug/nouveau-slug`
   - `httpCode` = 301
   - `isAuto` = true
   - `sourceEntry` = l'entrée modifiée
3. Détecte si une redirection auto existe déjà pour cette entrée → met à jour `toPath`

### 3.7 Routes

| Route | Méthode | Description |
|---|---|---|
| `/admin/redirects` | GET/POST | Liste et création
| `/admin/redirects/{uuid}` | PUT/DELETE | Édition et suppression
| `/admin/redirects/bulk` | PUT | Mise à jour en masse
| `/admin/redirects/preview` | POST | Preview : `{fromPath}` → trace de résolution
| `/admin/redirects/chains` | GET | Liste des chaînes détectées
| `/admin/redirects/chains/resolve` | POST | Résout une chaîne (raccourci A→C)
| `/admin/redirects/broken-links` | GET | Liste des liens cassés
| `/admin/redirects/not-found-logs` | GET | Liste des 404 loggées
| `/{projectUuid}/redirects/resolve` | GET | Résolution publique (frontend)
| `/{projectUuid}/redirects/export` | GET | Export CSV

### 3.8 Frontend admin

- **Liste des redirects** : tableau avec colonnes From, To, HTTP Code, Hits, Dernier Hit, Auto, Actions
- **Formulaire d'ajout/édition** : From, To, HTTP Code, toggle Pattern, toggle Enabled
- **Preview** : champ "Tester une URL" → trace de résolution complète
- **Bulk Editor** : spreadsheet avec édition inline, import CSV
- **Dashboard liens cassés** : liste des URLs cassées avec lien vers l'entrée source
- **Dashboard 404** : logs de 404, bouton "Créer une redirection" en un clic
- **Chaînes** : alerte visuelle, bouton "Raccourcir" one-click
- **Historique** : chaque redirect a un onglet "Historique" avec diff et rollback

### 3.9 Anti-collision

À la création/édition d'une redirection, vérification automatique :
1. Le `fromPath` ne correspond-il pas à une route existante ?
2. Le `fromPath` n'est-il pas un slug d'entrée publiée ?
3. Le `fromPath` ne crée-t-il pas une boucle avec une redirection existante ?

Si conflit → avertissement à l'admin (pas de blocage, override possible).

### 3.10 i18n

Clés `redirects.*` dans les 4 langues :
- `redirects.list`, `redirects.add`, `redirects.edit`, `redirects.delete`
- `redirects.from`, `redirects.to`, `redirects.http_code`
- `redirects.pattern`, `redirects.regex_help`
- `redirects.preview`, `redirects.preview_trace`
- `redirects.bulk`, `redirects.import_csv`, `redirects.export_csv`
- `redirects.chains`, `redirects.resolve_chain`, `redirects.loop_detected`
- `redirects.broken_links`, `redirects.suggest_redirect`
- `redirects.not_found`, `redirects.create_from_404`
- `redirects.history`, `redirects.rollback`
- `redirects.collision_route`, `redirects.collision_slug`
- `redirects.hits`, `redirects.last_hit`

---

## 4. Sous-projet C — Form Builder Avancé

### 4.1 Architecture

```
┌──────────────────────────────────────────────────────┐
│                 Studio Jambo (admin)                  │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │ Form Builder │  │ Submissions  │  │ A/B Testing │ │
│  │ Drag & Drop  │  │ Dashboard    │  │ Comparator  │ │
│  └──────────────┘  └──────────────┘  └────────────┘ │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│                  Backend Jambo                       │
│  ┌──────────────┐  ┌──────────────┐  ┌───────────┐ │
│  │ FormManager  │  │ SubmitHandler│  │ AntiSpam  │ │
│  │ Définition   │  │ Workflows    │  │ Honeypot  │ │
│  └──────────────┘  └──────────────┘  └───────────┘ │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│   Frontend client                                   │
│   GET /{project}/forms/{slug} → définition JSON     │
│   POST /{project}/forms/{slug}/submit → soumission  │
│   <script src="/forms/embed.js"> → widget JS 5 Ko  │
└─────────────────────────────────────────────────────┘
```

### 4.2 Modèle de données

#### Entité Form

| Colonne | Type | Description |
|---|---|---|
| `id` | INT PK |
| `uuid` | CHAR(36) UNIQUE |
| `project` | ManyToOne Project |
| `name` | VARCHAR(255) | Nom interne
| `slug` | VARCHAR(255) | Slug unique par projet
| `title` | VARCHAR(255) nullable | Titre affiché
| `description` | TEXT nullable | Texte introductif
| `fields` | JSON | Définition des champs (drag & drop)
| `steps` | JSON nullable | Configuration multi-step
| `settings` | JSON | Configuration globale
| `isPublished` | BOOL |
| `createdAt` | DATETIME |
| `updatedAt` | DATETIME |

**Structure JSON `fields` :**
```json
[
  {
    "id": "field-uuid",
    "type": "text",
    "label": "Nom complet",
    "placeholder": "Votre nom",
    "isRequired": true,
    "validation": {"minLength": 2, "maxLength": 100},
    "conditions": [
      {
        "action": "show",
        "operator": "equals",
        "fieldId": "field-uuid-2",
        "value": "Oui"
      }
    ],
    "order": 0,
    "width": "full"
  }
]
```

Types de champs supportés :
`text`, `email`, `textarea`, `number`, `select`, `checkbox`, `checkbox_group`, `radio`, `date`, `datetime`, `file`, `url`, `phone`, `rating`, `hidden`, `rich_text`, `color`, `toggle`

**Structure JSON `settings` :**
```json
{
  "submitButtonLabel": "Envoyer",
  "successMessage": "Merci, votre message a été envoyé.",
  "successRedirect": "https://...",
  "emails": {
    "admin": ["admin@acme.com"],
    "replyTo": "email_field_uuid",
    "confirmationToUser": {
      "enabled": true,
      "subject": "Merci pour votre message",
      "body": "<p>Bonjour {{name}}, ...</p>",
      "fromEmail": "noreply@acme.com"
    }
  },
  "webhook": {
    "enabled": false,
    "url": "https://...",
    "secret": "enc:..."
  },
  "createEntry": {
    "enabled": false,
    "collectionId": null,
    "fieldMapping": {}
  },
  "antiSpam": {
    "honeypot": true,
    "rateLimit": 5,
    "rateLimitWindow": 300,
    "captchaProvider": "turnstile",
    "captchaSiteKey": "...",
    "captchaSecret": "enc:...",
    "blocklistedDomains": ["mailinator.com", "tempmail.com"]
  },
  "abTest": {
    "enabled": false,
    "variants": []
  }
}
```

#### Entité FormSubmission

| Colonne | Type | Description |
|---|---|---|
| `id` | INT PK |
| `uuid` | CHAR(36) UNIQUE |
| `form` | ManyToOne Form |
| `data` | JSON | Toutes les valeurs des champs
| `metadata` | JSON | IP, User-Agent, Referrer, durée remplissage
| `step` | INT nullable | Étape de l'abandon (null si complété)
| `isComplete` | BOOL | true si toutes les étapes sont remplies
| `isSpam` | BOOL | Détecté comme spam
| `isRead` | BOOL | Lu par l'admin
| `abVariant` | VARCHAR(255) nullable | Variante A/B test
| `createdAt` | DATETIME |
| `readAt` | DATETIME nullable |

### 4.3 Composants backend

#### FormBuilder (`src/Service/Form/FormBuilder.php`)

- `validateDefinition(array $fields): array` — valide la structure JSON des champs
- `buildFormSchema(Form $form): array` — construit le schéma de validation Symfony
- `resolveConditions(array $fields, array $values): array` — résout les conditions d'affichage
- `renderForm(Form $form, ?array $prefillData = null): array` — retourne la définition JSON pour le frontend

#### SubmitHandler (`src/Service/Form/SubmitHandler.php`)

- `handle(Form $form, array $data, Request $request): FormSubmission` :
  1. Valide les champs (types, required, patterns)
  2. Vérifie l'anti-spam (honeypot, rate limit, captcha, blocklist)
  3. Résout les conditions
  4. Stocke la soumission
  5. Déclenche les workflows (email admin, confirmation user, webhook, création entrée)
  6. Retourne la réponse JSON

#### AntiSpamService (`src/Service/Form/AntiSpamService.php`)

- `checkHoneypot(array $data, Form $form): bool`
- `checkRateLimit(string $ip, Form $form): bool`
- `verifyCaptcha(string $token, Form $form): bool`
- `checkBlocklistedDomain(string $email): bool`
- `detectSpamPatterns(array $data): float` — score 0-1, > 0.7 = spam
- Utilise `symfony/rate-limiter` (déjà présent dans Symfony)

#### FormTemplateManager (`src/Service/Form/FormTemplateManager.php`)

Gère la bibliothèque de templates.

Templates built-in :
- **Contact** : nom, email, sujet, message
- **Sondage** : question, options radio, commentaire
- **Inscription événement** : nom, email, téléphone, nombre de places, régime alimentaire
- **Demande de devis** : entreprise, nom, email, téléphone, description du projet, budget
- **Feedback NPS** : score 0-10, commentaire
- **Candidature** : nom, email, téléphone, poste, CV (file), lettre de motivation

Méthodes :
- `getTemplates(): array` — liste des templates disponibles
- `applyTemplate(string $templateId, Form $form): Form` — applique un template
- `saveAsTemplate(Form $form, string $name): void` — sauvegarde en template perso

#### AbTestManager (`src/Service/Form/AbTestManager.php`)

- `assignVariant(Form $form): string` — assigne aléatoirement une variante
- `getStats(Form $form): AbTestStats` — stats comparatives des variantes
- `determineWinner(Form $form): ?AbVariant` — détermine le gagnant par significativité statistique
- `applyWinner(Form $form, string $variantId): void` — applique la variante gagnante

### 4.4 Routes

| Route | Méthode | Description |
|---|---|---|
| `/admin/forms` | GET/POST | Liste et création
| `/admin/forms/{uuid}` | GET/PUT/DELETE | Détail, édition, suppression
| `/admin/forms/{uuid}/submissions` | GET | Liste des soumissions
| `/admin/forms/{uuid}/submissions/{subUuid}` | GET/DELETE | Détail/Suppression soumission
| `/admin/forms/{uuid}/submissions/export` | GET | Export CSV/JSON/Excel
| `/admin/forms/{uuid}/stats` | GET | Statistiques (volume, conversion, abandon)
| `/admin/forms/{uuid}/ab-test` | GET/POST | Stats A/B test, appliquer gagnant
| `/admin/forms/templates` | GET | Liste des templates
| `/admin/forms/templates/{id}/apply` | POST | Appliquer un template
| `/{projectUuid}/forms/{slug}` | GET | Définition publique du formulaire
| `/{projectUuid}/forms/{slug}/submit` | POST | Soumettre une réponse
| `/forms/embed.js` | GET | Widget JS embeddable

### 4.5 Widget embeddable (`/forms/embed.js`)

Script JS vanilla de ~5 Ko (minifié+gzip) :
- Initialisé via `data-form-slug` et `data-project-uuid` sur une div cible
- Fetch la définition du formulaire → rend le HTML + CSS + JS
- Soumet en AJAX → affiche succès/erreur
- Validation côté client (en plus de la validation serveur)
- Mode iframe optionnel : ajouter `data-mode="iframe"`
- Styles overridables via CSS custom properties
- Accessible (a11y) : labels, ARIA, focus management, keyboard navigation

### 4.6 Frontend admin

#### Form Builder visuel

- **Colonne gauche** : palette de champs disponibles (drag source)
- **Centre** : canvas du formulaire (drop target) avec preview live
- **Colonne droite** : propriétés du champ sélectionné (label, placeholder, required, validation, conditions, width)
- **Barre d'outils** : undo/redo, preview desktop/tablet/mobile, save, publish
- **Conditions** : éditeur visuel "SI champ X [égale] valeur Y ALORS [afficher] champ Z"
- **Multi-step** : onglet "Étapes" → drag & drop des champs entre étapes → barre de progression

#### Dashboard soumissions

- Tableau avec colonnes : Date, Statut (complet/abandonné), Spam, Lu, Actions
- Filtres par formulaire, date, statut, spam
- Détail d'une soumission : données formatées, metadata (IP, UA, referrer)
- Graphique volume 30 jours
- Taux de conversion (visites page vs soumissions)
- Analyse d'abandon : heatmap des champs abandonnés
- Export CSV / JSON / Excel
- Boutons : Marquer lu/non-lu, Supprimer, Archiver

#### A/B Testing

- Créer une variante (clone du formulaire avec modifications)
- Dashboard comparatif : soumissions par variante, taux de complétion, temps moyen
- Détection automatique du gagnant (intervalle de confiance 95%)
- Bouton "Appliquer le gagnant"

### 4.7 i18n

Clés `forms.*` dans les 4 langues :
- `forms.builder`, `forms.builder.drag_hint`, `forms.builder.preview`
- `forms.fields.*` (tous les types de champs)
- `forms.conditions`, `forms.conditions.if`, `forms.conditions.then`
- `forms.multi_step`, `forms.multi_step.add_step`, `forms.multi_step.progress`
- `forms.submissions`, `forms.submissions.detail`, `forms.submissions.export`
- `forms.stats`, `forms.stats.volume`, `forms.stats.conversion`, `forms.stats.abandon`
- `forms.antispam`, `forms.antispam.honeypot`, `forms.antispam.rate_limit`, `forms.antispam.captcha`
- `forms.workflows`, `forms.workflows.email_admin`, `forms.workflows.email_user`, `forms.workflows.webhook`, `forms.workflows.create_entry`
- `forms.templates`, `forms.templates.apply`
- `forms.ab_test`, `forms.ab_test.create_variant`, `forms.ab_test.winner`
- `forms.embed`, `forms.embed.code`, `forms.embed.copied`

---

## 5. Implémentation par phases

Le scope de v1.19 est large (30 features majeures sur 3 sous-projets). Pour réduire le risque, je propose 3 phases de build au sein du même cycle :

### Phase 1 — Fondations (backend pur)
- Colonnes SEO sur ContentEntry + migration
- Entités Redirect + RedirectRevision + NotFoundLog + migrations
- Entités Form + FormSubmission + migrations
- Services : SeoAnalyzer, StructuredDataGenerator, SitemapGenerator, HreflangGenerator
- Services : RedirectResolver, RedirectChainDetector, LinkChecker
- Services : FormBuilder, SubmitHandler, AntiSpamService
- Routes publiques : sitemap.xml, robots.txt, `/seo/manifest`
- Routes publiques : `/redirects/resolve`
- Routes publiques : `/forms/{slug}`, `/forms/{slug}/submit`

### Phase 2 — Admin API + Frontend
- CRUD API pour Redirects (admin)
- CRUD API pour Forms (admin)
- SEO Dashboard API + Bulk SEO API
- Frontend : panneau SEO dans l'éditeur d'entrée (score + aperçu + structured data + hreflang)
- Frontend : SEO Dashboard
- Frontend : Bulk SEO Editor
- Frontend : Redirects admin (liste, formulaire, preview, chaînes, liens cassés, 404)
- Frontend : Form Builder (drag & drop, conditions, multi-step)
- Frontend : Submissions Dashboard
- i18n complète (seo.*, redirects.*, forms.*)

### Phase 3 — Features avancées + Polish
- Auto-internal-linking IA
- Historique SEO (SeoRevision)
- Image Sitemap
- Audit SEO one-click + export PDF
- A/B Testing de formulaires
- Widget embeddable (`/forms/embed.js`)
- Templates de formulaire
- FormTemplateManager
- AbTestManager
- Tests complets (unit + integration + WebTestCase)

---

## 6. Sécurité

- **Form submissions** :
  - Rate limiting par IP (configurable par formulaire)
  - Honeypot invisible
  - Turnstile / hCaptcha / reCAPTCHA intégré
  - Blocklist de domaines email jetables
  - Validation serveur obligatoire (même si le client valide)
  - Pas d'injection HTML dans les réponses (strip_tags sur toutes les entrées texte)
- **Redirects** :
  - Anti-collision avec les routes existantes
  - Détection de boucles
  - Pas de redirection vers des URLs externes non vérifiées (log warning)
- **Sitemap** :
  - Respecte `indexable = false` sur les collections
  - Pas d'exposition de données non publiées
- **Widget embeddable** :
  - Sandbox iframe optionnel
  - CORS restreint au domaine du projet

## 7. Tests

### Phase 1
- `SeoAnalyzerTest` : scoring, critères, audit
- `StructuredDataGeneratorTest` : chaque type → JSON-LD valide
- `SitemapGeneratorTest` : XML valide, entrées exclues
- `HreflangGeneratorTest` : détection locales
- `RedirectResolverTest` : patterns, chaînes, boucles
- `LinkCheckerTest` : extraction liens, détection liens cassés
- `FormBuilderTest` : validation définition, résolution conditions
- `SubmitHandlerTest` : soumission valide, anti-spam, workflows
- `AntiSpamServiceTest` : honeypot, rate limit, blocklist

### Phase 2
- `SeoControllerTest` : endpoints SEO admin
- `RedirectControllerTest` : CRUD, preview, bulk
- `FormControllerTest` : CRUD, submissions, stats
- `SeoBulkControllerTest` : bulk edit

### Phase 3
- `AbTestManagerTest` : assignation, stats, gagnant
- `FormTemplateManagerTest` : apply, save
- `EmbedControllerTest` : widget JS

## 8. Hors périmètre (YAGNI)

- Génération de pages HTML côté serveur (SSR/SSG) — Jambo reste headless
- SEO pour les médias individuels (alt-text, légende) — déjà géré par le champ media EAV
- Intégration Google Analytics / Search Console — le frontend du client gère
- CDN purging automatique sur changement de slug
- Multi-langue pour les formulaires (les champs sont définis dans une langue)
- Paiements intégrés aux formulaires (Stripe, PayPal)
- Signature électronique dans les formulaires
- Workflow d'approbation des soumissions
- Notifications SMS / Slack / Teams pour les formulaires
- Import de redirections depuis .htaccess / nginx.conf

## 9. Dépendances

- **Zéro nouvelle dépendance PHP** — tout est construit sur Symfony 8 + Doctrine (déjà présent)
- **Frontend** : `lucide-react` (déjà présent) pour les icônes du Form Builder
- **PDF export** : `dompdf/dompdf` (nouveau, léger, 0 dépendance transitive)
- **Widget JS** : vanilla JS, zéro dépendance

## 10. Livrables

- 3 nouvelles entités SEO (SeoRevision) + Redirect (Redirect, RedirectRevision, NotFoundLog) + Form (Form, FormSubmission)
- 12 nouveaux services backend
- 20+ nouvelles routes (publiques + admin)
- 3 nouveaux panneaux frontend (SEO, Redirects, Forms)
- Widget JS embeddable 5 Ko
- i18n complète 4 langues
- ~80 tests unitaires + integration + WebTestCase
- 1 nouvelle dépendance PHP (dompdf/dompdf)
