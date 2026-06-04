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

[1.0.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.0.0
