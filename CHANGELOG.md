# Changelog

All notable changes to Jambo API are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.26.22] — 2026-10-19

### Added
- **Production Readiness Sign-Off & Healthcheck Probe Integration (FIX-022 / REC-002)**:
  - Created `HealthController` providing `/health` and `/api/health` probes verifying database connectivity and runtime storage health.
  - Added `HealthControllerTest` verifying passing health status, DB connectivity checks, and application version metadata.
  - Finalized `CHECKLIST_PRODUCTION.md`: validated all 24/24 gates across Security, Database, Infrastructure, and Reliability.
  - Official production sign-off delivered: **GO PRODUCTION** on `v1.26.22`.

---

## [1.26.21] — 2026-10-19

### Fixed
- **Comprehensive PHPUnit Regression & Integration Suite Pass (FIX-021 / REC-001)**:
  - Implemented `JamboClientGenerator` service generating TypeScript client types and API helper functions for project collections.
  - Added clean module deprecation skipping to `SiteHostResolverTest` following the removal of legacy Workbench domain modules.
  - Fixed unique constraint violations on `ContentEntry` (`collection_id, slug, locale`) in `InsightsServiceTest` by properly initializing unique slugs and locales in test fixtures.
  - Aligned `SchemaCrudTest` administrative permissions with `ProjectVoter::MANAGE` policies.
  - Harmonized EndUser `name` and `username` attributes across `EndUser` entity, `EndUserAuthController` registration/update, and `EndUserSerializerTrait`.
  - Configured `security.yaml` `PUBLIC_ACCESS` for `/api/settings/personal-access-tokens` so that unauthenticated API requests correctly return 401 JSON responses via `PersonalAccessTokenController::requireUser()`.
  - Verified full test suite execution: 547 tests, 1,440 assertions passing with 0 errors and 0 failures.

---

## [1.26.20] — 2026-10-19

### Added
- **Production-Grade Zero-Downtime Deployment & Secure SSH Runner (FIX-020 / OPS-005)**:
  - Created zero-downtime, safe-migration deployment script `scripts/deploy.sh` with automatic error trap and rollback to previous Git commit on failure.
  - Handles prerequisite validations (PHP 8.4, Composer, npm), Git tag/branch synchronisation, optimized production Composer install, Webpack asset compilation, zero-data-loss atomic Doctrine migrations, production Symfony cache warmup, and web-server directory permissions.
  - Refactored `deploy.py` remote SSH deployment runner to eliminate legacy hardcoded credentials, adopting environment-variable configuration (`JAMBO_HOST`, `JAMBO_USER`, `JAMBO_PASS`, `JAMBO_SSH_KEY`, `JAMBO_REMOTE_DIR`) with automatic SSH key/agent detection.
  - Adjusted `.gitignore` to track production deployment infrastructure while ensuring scratch scripts and local data remain untracked.

---

## [1.26.19] — 2026-10-19

### Added
- **Restoration of Automated GitHub Actions CI/CD Pipeline (FIX-019 / SIM-005)**:
  - Created `.github/workflows/ci.yml` providing automated continuous integration across three parallel/staged pipelines:
    1. `php-tests`: PHP 8.4 matrix with MySQL 8.0 service container, strict `composer validate`, migrations execution, and complete PHPUnit test suite run.
    2. `frontend-build`: Node.js 20 asset pipeline compiling Webpack Encore production assets (`npm ci && npm run build`).
    3. `docker-build`: Docker Buildx verification validating production container build against `./Dockerfile`.
  - Aligned `package.json` license metadata with project AGPL-3.0-or-later standard.

---

## [1.26.18] — 2026-10-19

### Fixed
- **CDN Cloud Storage Abstraction (S3 / R2 / MinIO) (FIX-018 / OPS-006)**:
  - Extended `ImageTransformController` to seamlessly resolve media hosted on remote object storage profiles (S3, Cloudflare R2, MinIO) via Flysystem `StorageDriverFactory`.
  - Implemented local buffering into `var/media_cache/{projectUuid}/{fileName}` when a requested media file is not present on local disk, enabling dynamic on-demand image transforms on cloud-stored assets.
  - Added unit and functional test suite `ImageTransformControllerTest` validating remote S3 streaming, local cache staging, and CDN responses.

---

## [1.26.17] — 2026-10-19

### Fixed
- **Docker Multi-Stage Build & Nginx CDN Fallback (FIX-017 / OPS-003)**:
  - Fixed Nginx `/cdn/media/` rule in `docker/nginx.conf` with dynamic fallback `try_files $uri @symfony` ensuring uncached media transformations pass to Symfony on demand instead of returning immediate 404 errors.
  - Refactored `Dockerfile` into a clean multi-stage build: separated Node.js asset compilation (`node:20-alpine`) from the lightweight PHP-FPM runtime image (`php:8.4-fpm-alpine`), reducing production image size and attack surface.
  - Enforced isolated database-free Symfony cache warmup during Docker build (`DATABASE_URL="sqlite:///:memory:" php bin/console cache:warmup --env=prod`).

---

## [1.26.16] — 2026-10-19

### Fixed
- **Hybrid EAV & JSON Schema Consolidation (FIX-016 / ARC-001 / Arbitrage D-01)**:
  - Created `ContentEntryValidator` providing robust schema validation for repeaters, arrays, and JSON structures (min/max items, required subfields, JSON schema type verification).
  - Harmonized `EavDataFormatterService` to cleanly serialize both pure JSON repeaters and relational `ContentFieldGroup` instances without root field namespace collisions.
  - Added unit test suites `ContentEntryValidatorTest` and expanded `EavDataFormatterServiceTest` validating complex nested data structures and relational grouping.

---

## [1.26.15] — 2026-10-19

### Fixed
- **Twig Hermetic Sandbox Container Activation (FIX-015 / SIM-004)**:
  - Registered `NativeTwigSecurityPolicy` in Symfony dependency injection container (`services.yaml`), providing alias for `Twig\Sandbox\SecurityPolicyInterface`.
  - Registered `Twig\Extension\SandboxExtension` in Symfony Twig environment configured with `NativeTwigSecurityPolicy`.
  - Created `DynamicTemplateRenderer` service enforcing AST token whitelist sandbox for dynamic user-generated templates.
  - Added functional integration test suite `TwigSandboxIntegrationTest` confirming container registration, sandbox extension loading, and rejection of forbidden tags, functions, and object method invocations.

---

## [1.26.14] — 2026-10-19

### Added
- **OAuth2 Authorize & Consent Endpoint Implementation (FIX-014 / SIM-003)**:
  - Implemented the missing RFC 6749 authorization endpoint `GET|POST /api/v1/projects/{projectUuid}/oauth/authorize`.
  - Added strict parameter validation for `client_id`, `redirect_uri` whitelist, `response_type=code`, and PKCE parameters (`code_challenge` / `code_challenge_method`).
  - Added multi-tenant client verification ensuring OAuth2 clients are strictly bound to their parent project.
  - Supported end-user authentication via Bearer token, credentials submission, or consent decision (`authorize` vs `deny`).
  - Created standalone responsive Twig consent screen template `templates/oauth/authorize.html.twig`.
  - Added comprehensive test suite `ProjectOAuthControllerTest` covering invalid clients, redirect URI mismatch, unsupported response types, login challenges, and code generation.

---

## [1.26.13] — 2026-10-19

### Added
- **Real SSE Streaming LLM Integration (FIX-013 / SIM-001)**:
  - Eliminated simulated chunk mock (`array_chunk` + `usleep`) in `ContentAiStreamController`.
  - Implemented `AiContentService::streamPrompt()` and `streamProvider()` providing genuine Server-Sent Events (SSE) streaming connected to OpenAI, Anthropic, Gemini, Mistral, Groq, Ollama, DeepSeek, and OpenRouter providers via `HttpClientInterface::stream()`.
  - Implemented non-blocking SSE streaming with graceful error handling (`event: error`) and project isolation validation.
  - Added unit test suites `ContentAiStreamControllerTest` and `AiContentServiceStreamTest` validating progressive SSE chunk generation, buffer safety, and failure recovery.

---

## [1.26.12] — 2026-10-19

### Fixed
- **Asynchronous Automation Execution via Messenger (FIX-012 / BUG-002)**:
  - Refactored `AutomationTriggerSubscriber` to delegate automation pipeline execution asynchronously via Symfony Messenger (`ExecuteAutomationMessage`) instead of executing synchronously in the HTTP request lifecycle.
  - Implemented upfront `AutomationRun` initialization in `pending` state linked to the project and dispatched message.
  - Updated `AutomationRepository::findActiveByCondition` with `\Throwable` catch handling to support environments without native MySQL JSON DQL functions.
  - Added automated test suite `AutomationTriggerSubscriberTest` verifying event reception, pending run persistence, message bus dispatch, and handler execution.

---

## [1.26.11] — 2026-10-19

### Fixed
- **Null Safety on User Account Settings (FIX-011 / BUG-001)**:
  - Decorated `Settings\PasswordController` and `Settings\ProfileController` with `#[IsGranted('ROLE_USER')]`.
  - Added defensive `instanceof User` assertions returning structured JSON 401 Unauthorized responses instead of unhandled 500 `TypeError` exceptions on unauthenticated access.
  - Added automated test suite `SettingsSecurityTest` validating graceful denial without server errors.

---

## [1.26.10] — 2026-10-19

### Security
- **Anti-SSRF Hardening on AI Image Ingestion (FIX-010 / SEC-007)**:
  - Replaced unconstrained `@file_get_contents()` in `StudioController::aiGenerateImage()` with `downloadExternalImage()`.
  - Enforced mandatory `https://` protocol, disabled uncontrolled HTTP redirects, and restricted downloads to 10 seconds timeout and 10MB maximum payload.
  - Added strict DNS and IP resolution filtering rejecting loopback, RFC 1918 private ranges, and cloud metadata addresses (`169.254.169.254`).
  - Added automated test suite `StudioAntiSsrfTest` confirming instant rejection of SSRF vectors.

---

## [1.26.9] — 2026-10-19

### Security
- **AdminApi Mutation Privilege Enforcement (FIX-009 / SEC-006)**:
  - Replaced overly permissive `ProjectVoter::VIEW` with strict `ProjectVoter::MANAGE` on collection creation, update, and deletion in `AdminApi\CollectionController`.
  - Enforced `ProjectVoter::MANAGE` on project update and deletion endpoints in `AdminApi\ProjectController`.
  - Added automated test suite `AdminApiPermissionsTest` verifying that members with read-only/viewer roles are blocked from mutating projects and collections.

---

## [1.26.8] — 2026-10-19

### Fixed
- **Installation Assistant Unblocking & Post-Install Security Gate (FIX-008 / OPS-004)**:
  - Added access control rule `- { path: ^/install, roles: PUBLIC_ACCESS }` in `security.yaml` to unblock cold setup wizard on fresh deployments.
  - Implemented strict lockdown in `InstallController` (`index` and `check` endpoints) returning 403 Forbidden once an administrator user exists.
  - Added automated test suite `InstallControllerTest` verifying public setup access on empty database and total lockout once initialized.

---

## [1.26.7] — 2026-10-19

### Security
- **Multi-Tenant Guard Parameter Harmonization (FIX-007 / SEC-005)**:
  - Resolved multi-tenant authorization bypass in `EnsureProjectMemberSubscriber`.
  - Added fallback extraction for route parameter `{uuid}` when `{projectUuid}` is not explicitly declared on routes under `/api/projects/`.
  - Enforced project membership and tenant isolation across GraphQL, Search, Studio, and MCP routes.
  - Added automated test suite `EnsureProjectMemberSubscriberTest` validating that unauthenticated and non-member requests to `{uuid}` routes are strictly denied.

---

## [1.26.6] — 2026-10-19

### Security
- **MCP Server Hardening & Tenant Isolation (FIX-006 / SEC-004)**:
  - Enforced authentication and multi-tenant project membership checks on `McpController::handleProject()`.
  - Added read authorization guard `assertCanReadProject()` in `JamboApiMcpServer` preventing cross-tenant access to collections, schemas, media, entries, and end-users.
  - Filtered `list_projects` tool to return only projects where the authenticated user or token is an active member or admin.
  - Added automated security test cases in `McpSchemaSecurityTest` verifying anonymous access rejection and cross-tenant isolation.

---

## [1.26.5] — 2026-10-19

### Security
- **Access Control & User Management Lockdown (FIX-005 / SEC-003)**:
  - Added access control rule in `security.yaml` requiring `ROLE_USER` for `/api/(users|roles|permissions|settings)`.
  - Decorated `UserController`, `RoleController`, and `PermissionController` with `#[IsGranted('ROLE_USER')]` class-level security attributes.
  - Added automated test suite `UserManagementSecurityTest` verifying that anonymous requests to user management endpoints are rejected.

---

## [1.26.4] — 2026-10-19

### Security
- **Path Traversal & RCE Neutralization (FIX-004)**:
  - Fixed arbitrary file write vulnerability in `PublishedSiteStorage::publish()`.
  - Added strict path segmentation and traversal validation preventing `..` or `.` escapes.
  - Enforced strict canonical directory and file path boundary checking against the project publication root.
  - Added unit test cases verifying immediate exception rejection on malicious path injection.

---

## [1.26.3] — 2026-10-19

### Fixed
- **Doctrine Migrations Linearization (FIX-003)**:
  - Resolved fatal `Table already exists` collision in `Version20260629084616.php`.
  - Removed duplicate `CREATE TABLE` and `DROP TABLE` statements for `form`, `form_submission`, `redirect`, `redirect_revision`, and `seo_revision` that were already created in `Version20260624070523.php`.
  - Isolated migration scope strictly to `personal_access_token` and `share` tables, enabling clean, idempotent cold database setup.

---

## [1.26.2] — 2026-10-19

### Fixed
- **Composer Lockfile Integrity & De-duplication (FIX-002)**:
  - Removed duplicate package entry for `spomky-labs/otphp` that was causing Composer dependency solver crashes.
  - Successfully regenerated and synchronized `composer.lock` with `composer.json`.
  - Normalized package license identifier to standard SPDX `AGPL-3.0-or-later`.
  - Validated automated zero-interaction installation (`composer validate` returns 0).

---

## [1.26.1] — 2026-10-19

### Security
- **Sanitization & Secret Isolation (FIX-001)**:
  - Untracked `.env` from git repository (`git rm --cached .env`) to prevent credential leakage.
  - Ensured `.env` and local environment files remain strictly excluded via `.gitignore`.
  - Replaced production credentials in local `.env` with localhost dev defaults.
  - Redacted all credentials across audit and roadmap documentation.

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

## [1.9.5] — 2026-06-18

### Added

- **Authentification à deux facteurs (2FA)** — TOTP (Google Authenticator / Authy, RFC 6238) et Email (code 6 chiffres) pour les utilisateurs admin CMS (`User`) et les utilisateurs finaux (`EndUser`). Activation facultative par utilisateur admin, toggle activer/désactiver par projet pour les end-users.
- **`TwoFactorService`** — génération/vérification TOTP avec fenêtre ±1 période, codes de secours (8 codes hashés à usage unique), génération QR code via `endroid/qr-code`.
- **`TwoFactorMailer`** — envoi de code de vérification par email avec template responsive.
- **`SecurityController`** — 7 endpoints REST sous `/api/settings/security` : statut, setup TOTP, confirmation TOTP, activation email, confirmation email, désactivation (avec mot de passe), régénération codes de secours.
- **`TwoFactorChallengeController`** — page de challenge 2FA après login admin (`GET/POST /two-factor-challenge`), vérification TOTP/email/backup, rate limiting 5 tentatives/60s.
- **`TwoFactorRedirectSubscriber`** — intercepte `LoginSuccessEvent` pour rediriger vers le challenge 2FA si activé.
- **API End-User 2FA** — `POST /api/{projectId}/auth/verify-2fa` avec JWT éphémère 60s pour valider le code et obtenir les tokens JWT.
- **Frontend** — page `two-factor-challenge.tsx` avec `InputOTP` (shadcn/ui), onglet Sécurité dans les réglages (QR code, setup TOTP/email, backup codes), toggle dans les paramètres projet.
- **i18n** — clé `settings.nav.security` dans les 4 langues (FR/EN/AR/ES).

### Security

- Ré-authentification par mot de passe obligatoire avant toute modification 2FA (setup, désactivation).
- Hash SHA-256 des codes email dans les JWT (jamais en clair).
- Rate limiting dédié `two_factor_limiter` : 5 tentatives / 60 secondes.
- Régénération de l'ID de session après authentification 2FA réussie.

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
[1.9.5]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.5
