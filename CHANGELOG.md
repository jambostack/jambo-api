# Changelog

All notable changes to Jambo API are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.1] — 2026-06-04

### Fixed

- Activer la compression gzip (`mod_deflate`) sur les assets JS/CSS — résout `ERR_HTTP2_PROTOCOL_ERROR` sur les gros bundles (JS 933 KiB → ~250 KiB sur le réseau)
- Cache `immutable` 1 an sur les fichiers versionnés (hash dans le nom) pour éviter les re-téléchargements inutiles

---

## [1.0.0] — 2026-06-04

First stable release.

### Core CMS

- **Multi-project** — single Jambo installation manages unlimited projects, each with its own API tokens, collections, locales, and end users
- **Collections** — flexible EAV model; 17 field types: `text`, `longtext`, `richtext` (Lexical), `slug`, `email`, `password`, `number`, `decimal`, `boolean`, `date`, `datetime`, `time`, `color`, `json`, `enumeration`, `media`, `relation`
- **Singleton collections** — single-entry collections for site config, hero sections, etc.
- **Multi-locale** — native i18n on every collection; `en`, `fr`, `es`, `ar` built-in
- **Content Versioning** — full history of every entry with diff and restore
- **Collection Templates** — reusable schema blueprints across projects
- **Column Settings** — customisable list views per collection
- **Full-text Search** — Meilisearch integration with real-time indexing

### API

- **REST API** — paginated, filterable, locale-aware, status-filtered (`published` / `draft`)
- **GraphQL** — full auto-generated schema with queries and mutations
- **OpenAPI / Swagger UI** — auto-generated interactive docs (Nelmio + Swagger PHP)
- **API Tokens** — per-project tokens with granular abilities (`read`, `create`, `delete`); HMAC-SHA256 hashing with automatic upgrade from legacy SHA-256
- **Public API toggle** — per-project opt-in for unauthenticated read access
- **Export / Import** — zip-based project export with structure, content, media, and settings; merge or create new project on import

### AI Studio

- **AI Schema Chat** — design and modify your content schema by chatting with an AI; supports OpenAI, Claude/Anthropic, DeepSeek, Gemini, OpenRouter, Mistral, Groq, xAI, Perplexity, Qwen, Ollama (via Symfony AI Bundle)
- **AI Agent v3** — autonomous agent with tools: create/update entries, translate content, apply bulk operations, plan execution with step-by-step logging
- **File attachment in chat** — attach images (vision), CSV, documents, or media library assets in Studio AI chat
- **AI Content Service** — AI-assisted content generation within individual entries
- **AI Context** — per-project AI configuration (model, provider, prompt context, schema awareness)

### MCP Server v2.0

- Full Model Context Protocol server for AI agent integration (Claude, Cursor, etc.)
- Tools: project exploration, content CRUD, schema management, media, end-user management, AI inference

### End Users

- Separate end-user authentication table (not mixed with admin users)
- JWT access tokens (15 min) + refresh tokens (30 days) via `lcobucci/jwt 5.5`
- Custom end-user fields (EAV, same schema system as content)
- Endpoints: register, login, me, refresh, forgot/reset password
- Security: IDOR protection (cross-project token rejection), login rate limiter

### Media & Assets

- Media Library with file upload, metadata, VichUploader
- Image transforms: resize, crop, format conversion (Intervention Image v4)
- PDF export from content entries (DomPDF v3)
- IDOR protection on media relations (cross-project UUID validation)

### Admin Panel

- Built with React 19 + Inertia.js 3 + Tailwind CSS 4 + shadcn/ui + Radix UI
- Lexical rich text editor (bold, italic, lists, links, code blocks, tables)
- Drag-and-drop field ordering (@hello-pangea/dnd)
- Dark mode (emerald design system)
- Bulk actions: publish, draft, delete with confirmation dialogs
- Audit logs — complete history of every admin action
- Deployments — deployment tracking per project
- Webhooks — event-driven triggers on content changes (per-collection)
- Email / Mailer — SMTP per project, email log viewer
- Async jobs — Symfony Messenger with Doctrine transport

### Users & Permissions

- Admin users with roles and per-project membership
- Invitations by email
- Password reset flow
- Login throttling (5 attempts / 60 s)
- CSRF protection on all forms

### DevOps

- Symfony 8 / PHP 8.4 — latest stable stack
- Doctrine ORM 3 + Migrations — MySQL, PostgreSQL, SQLite
- Docker-ready (Dockerfile provided)
- Setup command: `app:setup` — creates admin user and configures permissions

### Bug Fixes (pre-release)

- `findProjectEntryUuids` — fixed UUID binary binding in Doctrine DBAL for relation validation (was silently dropping all relation values)
- `findProjectMediaUuids` — fixed Doctrine ORM 3.x UUID binary IN-clause (relations now saved correctly)
- Studio AI chat — fixed SSE streaming timeout on Apache (30 s); added `ob_implicit_flush`
- Media modal — fixed infinite React re-render loop (#185) in `useEffect` dependency
- Open redirect — validate host in referer before redirect
- AI execute tools — error message sanitisation (no raw exception exposure)

---

## [1.9.4] — 2026-06-18

### Added

- **Commentaires threadés** — les utilisateurs peuvent commenter sur les entrées (`Comment` entity), répondre à un commentaire (threading 1 niveau), marquer comme résolu (open/resolved), et mentionner des utilisateurs (`@username`). API REST complète : liste paginée, création, modification, suppression, toggle résolution.
- **Notifications** — système de notifications en temps réel (`Notification` entity) pour les assignations, commentaires, mentions et changements de statut. Cloche dans la navbar avec badge compteur, poll toutes les 30s, dropdown des 10 dernières notifications, marquage comme lu individuellement ou en masse.
- **`CommentThread`** dans la sidebar du ContentForm — liste paginée des commentaires avec threading, formulaire d'ajout, boutons répondre/résoudre/rouvrir.
- **`NotificationBell`** dans la navbar (`app-header`) — composant avec Popover, badge compteur (`unread-count`), lien de redirection vers l'entrée concernée.
- **i18n** — 5 clés de traduction : `comments.title`, `comments.write_placeholder`, `comments.reply`, `comments.resolve`, `comments.reopen` (FR/EN/AR/ES).

---

## [1.9.3] — 2026-06-18

### Added

- **Repeater à variantes (Dynamic Zones)** — chaque item d'un Repeater peut maintenant avoir un ensemble de sous-champs différent (variante). Définition de variantes nommées dans le SchemaBuilder, choix de la variante par item dans le ContentForm via un dropdown. Rétrocompatible avec l'ancien format `subFields`.
- SchemaBuilder : éditeur de variantes avec cards `<details>` collapsibles, sous-champs par variante, variante par défaut, migration automatique depuis l'ancien format `subFields`.

---

## [1.9.2] — 2026-06-17

### Added

- **Lexical structuré** — 5 nouveaux blocs de contenu dans l'éditeur Rich Text : **Code Block** (avec dropdown de langage), **Blockquote** (citation), **Horizontal Rule** (séparateur), **Tableau** (popover 3×3, `@lexical/table`), **Callout** (info/warning/success/danger avec icônes et couleurs).
- **Champ Code avancé** — remplacement du textarea brut par **CodeMirror 6** avec coloration syntaxique (13 langages supportés), thème One Dark, numéros de ligne. Composant `CodeMirrorEditor` réutilisable.
- SchemaBuilder : option `language` pour le type `code` (JavaScript, TypeScript, JSON, HTML, CSS, SQL, PHP, Python, Markdown, XML, YAML, Shell, Plain Text).

---

## [1.9.1] — 2026-06-17

### Fixed

- **Pagination sur la corbeille** (`/trash`) — `findTrashedPaginated()` remplace `findTrashed()` sans limite.
- **Statuts système** — `draft` et `scheduled` sont toujours valides, même sans workflow custom.
- **Service `FieldValueHydrator`** — centralise les 5 copies du `match($field->type)` en un seul service partagé.
- **i18n** — 13 clés de traduction ajoutées (FR/EN/AR/ES) : `content.assigned_to`, `content.unassigned`, `content.by`, `content.unknown`, `content.search_in`, `repeater.item_n`, `repeater.add_item`, `repeater.select`, etc.
- **Fuites d'informations dans les erreurs** — 3 `catch (\Throwable $e)` dans `StudioController` loggent l'exception côté serveur et renvoient un message générique.

### Added

- **Tests automatisés** — `CollectionTest` (7 tests workflow helpers), `ContentControllerTest` (5 tests statuts + assignation), `EavDataFormatterServiceTest` (2 tests `assigned_to`).

### Performance

- **Index composite** `content_field_value(content_entry_id, field_id)` — accélère les requêtes EAV.
- **Eager-loading** — `findByCollectionPaginated()` charge maintenant `fieldValues`, `createdBy`, `updatedBy`, `assignedTo` en une seule requête.
- **`findProjectMediaUuids()`** — remplacement de la boucle N+1 par une requête `IN()`.

---

## [1.9.0] — 2026-06-17

### Added

- **Workflows éditoriaux** — **statuts custom par collection** définis dans `Collection.settings.workflow` (slug, label, couleur, `published`). Boutons dynamiques dans le ContentForm, badges couleur dans la liste. Rétrocompatible : si aucun workflow n'est défini, `draft`/`published`/`scheduled` par défaut.
- **Assignation de contenu** — FK `assigned_to_id` sur `content_entry` → `User`. Selecteur d'assignation dans la sidebar du ContentForm, colonne « Assigné à » dans la liste, filtre « Mes contenus ». Validation : l'assigné doit être membre du projet (IDOR protégé).
- SchemaBuilder : nouveau panneau **Workflow** pour configurer les statuts (label, couleur, published, statut par défaut).
- API : `workflow` exposé dans `GET /public-api/collections/{slug}`, `assigned_to` dans les réponses d'entrées, filtre `status` acceptant tous les statuts custom.

---

## [1.8.1] — 2026-06-17

### Added

- **Drag-and-drop** dans le RepeaterField — remplacement des boutons ↑↓ par `@hello-pangea/dnd` avec handle `GripVertical`.
- **Support richtext** dans les sous-champs du Repeater — utilisation de l'éditeur `LexicalEditor` complet.
- **Support media** dans les sous-champs — bouton « Browse » + `MediaLibraryModal` natif pour choisir des fichiers.

---

## [1.8.0] — 2026-06-17

### Added

- **Repeater structuré (nested fields)** — nouveau type `repeater` avec **sous-champs configurables** dans le SchemaBuilder. L'utilisateur définit des sous-champs nommés (slug, label, type, required), et le ContentForm affiche un composant `RepeaterField` avec add/remove/reorder d'items contenant les sous-champs. Stockage via `jsonValue` existant — aucune migration DB.

---

## [1.7.0] — 2026-06-17

### Added

- **Publication planifiée** — nouveau statut `scheduled` avec champ `scheduledAt` (datetime) sur `content_entry`. Bouton « Planifier » + datepicker dans le ContentForm. Badge bleu « Planifié » dans la liste. Filtre « Planifié » dans le dropdown.
- **Commande `app:publish-scheduled`** — à exécuter via cron, publie automatiquement les entrées dont `scheduledAt <= now`. Transition `scheduled → published`, `scheduledAt → null`, `publishedAt` défini.
- **Permission `can.publish_content`** — gate sur les boutons Planifier/Publier/Replanifier.
- **i18n** — clés `schedule_btn`, `schedule_date`, `schedule_time`, `schedule_confirm`, `publish_now`, `reschedule` dans les 4 langues.

---

## [1.6.1] — 2026-06-17

### Fixed

- Correctifs validation pour `rating` (Integer limitations), `tags` (pas de Unique/Character count), `url`/`markdown` (text options).
- Icônes des nouveaux types dans le SchemaBuilder (`ICON_MAP`).

---

## [1.6.0] — 2026-06-17

### Added

- **7 nouveaux types de champs** : `url`, `markdown`, `code`, `icon` (Lucide icon name), `uuid`, `tags`, `rating` (star score).
- Composants React dédiés : `UrlField`, `MarkdownField`, `CodeField`, `IconField`, `UuidField`, `TagsField`, `RatingField`.

---

## [1.5.0] — 2026-06-07

### Added

- **JSONField** — éditeur JSON avec coloration syntaxique (Prism.js) et validation inline.
- **DateField amélioré** — support mode range (date de début → date de fin).
- **Options de champs étendues** — `helpText`, `hideInList`, `readOnly` dans le FieldOptionsEditor du SchemaBuilder.

---

## [1.4.0] — 2026-06-05

### Added

- **Opérations bulk dans la liste de contenu** — publier, dépublier, supprimer, restaurer plusieurs entrées en une action.
- **Colonnes personnalisables** — l'utilisateur peut masquer/afficher les colonnes dans la liste de contenu.
- **Améliorations MediaField** — prévisualisation des miniatures, chargement lazy, modal de sélection refondu.

---

[1.0.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.0.0
[1.0.1]: https://github.com/jambostack/jambo-api/releases/tag/v1.0.1
[1.4.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.4.0
[1.5.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.5.0
[1.6.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.6.0
[1.6.1]: https://github.com/jambostack/jambo-api/releases/tag/v1.6.1
[1.7.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.7.0
[1.8.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.8.0
[1.8.1]: https://github.com/jambostack/jambo-api/releases/tag/v1.8.1
[1.9.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.0
[1.9.1]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.1
[1.9.2]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.2
[1.9.3]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.3
[1.9.4]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.4
