<div align="center">

<br />

<img src="https://jambostack.site/logo.svg" alt="Jambo API" width="72" height="72" />

# Jambo API

**Open-source headless CMS — Symfony 8 · PHP 8.4 · React 19**

[![Version](https://img.shields.io/badge/version-1.26.0-blue.svg)](CHANGELOG.md)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-2fcf8f.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-8.0-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)](https://react.dev)
[![Database](https://img.shields.io/badge/Doctrine%20ORM-MySQL%20%7C%20PostgreSQL%20%7C%20SQLite-4479A1)](https://www.doctrine-project.org)
[![Tests](https://img.shields.io/badge/tests-500%2B%20passing-brightgreen.svg)](TEST_ROADMAP.md)

[Website](https://jambostack.site) · [Documentation](https://docs.jambostack.site) · [Changelog](CHANGELOG.md) · [Roadmap](ROADMAP.md) · [Test Roadmap](TEST_ROADMAP.md)

</div>

---

## Two capabilities your current CMS doesn't have

### 🔌 Native MCP Server — AI agents read and write your content directly

Connect Claude, Cursor, or any MCP-compatible agent to Jambo API. No custom API glue code. No integration layer. The agent talks to your CMS the same way a developer would — and acts autonomously.

```
# Your .cursor/mcp.json (or Claude Desktop config)
{
  "jambo": {
    "url": "https://your-jambo.com/mcp",
    "token": "your-api-token"
  }
}
```

**What your agent can do out of the box:**
- Browse collections, read and filter entries
- Create, update, delete content
- Manage schema (add fields, create collections)
- Upload and query media assets
- Translate entries into any configured locale
- Manage front-end users

> *"Connect your AI to update product sheets without writing a single line of API integration code."*

---

### 🤖 AI Studio — design your entire schema in a single conversation

Open the Studio, describe your project in plain language, and the AI scaffolds all your collections and fields — names, types, relations, required flags. Change your mind? Just say so.

Supported providers: **OpenAI · Claude · Gemini · Mistral · Groq · DeepSeek · xAI · Perplexity · Qwen · Ollama**

The AI also works **inside entries**: generate content, translate to 4 languages, suggest improvements — without leaving the admin.

---

## One install. Unlimited projects.

Most headless CMS tools give you one project per deployment. Jambo API gives you **unlimited projects on a single instance** — each with its own collections, API tokens, locales, and end users. No extra containers. No extra SSL certificates. No extra pipelines.

| | Jambo API | Strapi v5 | Directus | Payload v3 |
|---|---|---|---|---|
| **Multi-project** (single install) | ✅ native | ❌ | ❌ | ❌ |
| **AI Schema Studio** | ✅ native | ❌ | ❌ | ❌ |
| **MCP Server** (AI agents) | ✅ v2.0 native | ❌ | ✅ extension | ✅ plugin |
| **End Users** (front-end auth) | ✅ separate table + JWT | ✅ | ⚠️ | ✅ |
| **Content Versioning** | ✅ open source | ❌ Enterprise | ✅ | ✅ |
| **Multi-locale** | ✅ native | ✅ | ✅ | ✅ |
| **GraphQL** | ✅ native | ✅ | ✅ | ✅ |
| **Full-text Search** | ✅ Meilisearch | ❌ | ❌ | ❌ |
| **Audit logs** | ✅ open source | ❌ Enterprise | ✅ | ❌ |
| **PDF export** | ✅ native | ❌ | ❌ | ❌ |
| **License** | **AGPL v3** | MIT | Apache 2.0 | MIT |

---

## Everything else you need

### Content
- **17 field types** — text, longtext, richtext (Lexical editor), slug, email, number, decimal, boolean, date, datetime, color, json, enumeration, media, relation
- **Singleton collections** — for hero sections, site config, about pages
- **Content Versioning** — full history, diff & restore on every entry
- **Collection Templates** — reusable schema blueprints across projects
- **Full-text Search** — Meilisearch, real-time indexing

### API
- **Admin API (`/admin-api/`)** — full headless management of projects, collections, fields, and schemas
- **Public REST API** — paginated, filterable, locale-aware, direct slug retrieval, status-aware
- **GraphQL** — auto-generated dynamic schema, queries & mutations with query depth limiting
- **OpenAPI / Swagger UI** — complete interactive documentation for Public & Admin APIs (`/api/docs`)
- **Direct S3/R2 Uploads** — presigned single and multipart uploads directly to AWS S3, Cloudflare R2, or MinIO
- **Export / Import** — zip-based project snapshots & blueprints (structure + content + media)

### Users & Security
- **Admin users** — roles, project membership, invitations, audit logs
- **Personal Access Tokens (PAT)** — secure HMAC-SHA256 tokens (`jbo_pat_...`) for automated API access
- **End Users** — dedicated front-end auth table, OAuth2 PKCE, JWT & 2FA TOTP, cross-project IDOR protection
- **Security Policies** — native Twig sandbox (`NativeTwigSecurityPolicy`), anti-bruteforce lockout, rate limiter, CSRF protection

### Admin Panel & Workflows
- React 19 + Inertia.js + Tailwind CSS 4 + shadcn/ui
- Lexical rich text (bold, italic, tables, code, links)
- **Inline AI Assistant** — real-time SSE streaming for summarization, translation, and text expansion
- **Flow Automation Engine** — visual DAG workflows (Triggers, Conditions, Webhooks, Emails, AI actions)
- Dark mode · emerald design system

### Quality & DevOps
- **Test Suite** — 500+ PHPUnit 13 tests & Playwright E2E browser specs (see [TEST_ROADMAP.md](TEST_ROADMAP.md))
- **Webhooks** — per-collection event triggers and inbound webhook listeners
- **Audit logs** — every admin action tracked
- **Mailer** — per-project SMTP + email log
- **Messenger** — asynchronous worker queue (Doctrine / async transports)

---

## Tech Stack

| | |
|---|---|
| Backend | **PHP 8.4** + **Symfony 8** |
| ORM | **Doctrine ORM 3** + Migrations |
| Database | MySQL 8 · PostgreSQL 14 · SQLite |
| Search | **Meilisearch** |
| AI | **Symfony AI Bundle** — 10 providers |
| Auth | Symfony Security + **lcobucci/jwt 5.5** |
| Media | **VichUploader** + **Intervention Image 4** |
| Queue | **Symfony Messenger** |
| GraphQL | **webonyx/graphql-php 15** |
| Frontend | **React 19** + **Inertia.js 3** + **Webpack Encore** |
| Styles | **Tailwind CSS 4** + **shadcn/ui** + **Radix UI** |

---

## Getting Started

### Requirements

- **PHP 8.4+** · **Composer** · **Node.js 18+** + npm
- **MySQL 8+**, PostgreSQL 14+, or SQLite
- Optional: Meilisearch, Symfony CLI

### Installation

```bash
git clone https://github.com/jambostack/jambo-api.git
cd jambo-api
composer install
npm install && npm run build
cp .env .env.local
```

Edit `.env.local`:
```env
APP_SECRET=change-this-to-a-strong-random-value
DATABASE_URL="mysql://user:password@127.0.0.1:3306/jambo?serverVersion=8.0.32&charset=utf8mb4"
APP_HOSTNAME=yourdomain.com
```

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:setup   # creates your admin account
symfony serve
```

Open `http://localhost:8000`. See the full [installation guide](https://docs.jambostack.site/installation/).

> ⚠️ **Security:** Never use the demo fixture credentials (`admin@jambostack.site` / `admin1234`) in production. See [SECURITY.md](SECURITY.md).

---

## API Quick Reference

### Public Content API
```bash
# List entries in collection
GET /api/v1/projects/{projectId}/collections/{collection}/content?page=1&limit=20

# Single entry by UUID
GET /api/v1/projects/{projectId}/collections/{collection}/content/{entry-uuid}

# Direct lookup by unique Slug
GET /api/v1/projects/{projectId}/collections/{collection}/content/by-slug/{slug}

# Project Public Bearer Token Authorization
Authorization: Bearer YOUR_API_TOKEN

# GraphQL Endpoint
POST /api/v1/projects/{projectId}/graphql
```

### Admin Headless API & Personal Access Tokens (PAT)
```bash
# Generate a Personal Access Token via CLI
php bin/console jambo:pat:create admin@example.com --name="ci-token" --scopes=admin

# Authenticate against Admin API
GET /admin-api/_ping
Authorization: Bearer jbo_pat_YOUR_SECRET_TOKEN

# Manage projects and schema
GET /admin-api/projects
GET /admin-api/projects/{projectId}/collections

# OpenAPI Interactive Documentation
GET /api/docs
GET /api/settings/admin-api/openapi.json
```

---

## MCP Server

```
Endpoint : https://your-jambo.com/mcp
Version  : 2.0.0
```

Tool categories: **Exploration · Content · Schema · Media · End Users · AI Tools**

Full reference → [docs.jambostack.site/api/introduction](https://docs.jambostack.site/api/introduction/)

---

## Roadmap & Milestones

- [x] REST API + GraphQL + OpenAPI/Swagger
- [x] AI Schema Studio (10 providers)
- [x] MCP Server v2.0
- [x] End Users + JWT & 2FA TOTP
- [x] Content versioning · Webhooks · Audit logs
- [x] Meilisearch · Multi-locale · PDF export
- [x] Project & Collection templates · Export/Import
- [x] **v1.10 – v1.12** : Upload direct S3/R2 multipart, `ContentFieldGroup`, OAuth2 PKCE, Web Setup Wizard & Starters
- [x] **v1.20** : Moteur EAV durci (unicité slugs multi-locales avec soft-deletes, validation partielle PATCH)
- [x] **v1.21** : Admin API `/admin-api/` avec Personal Access Tokens HMAC-SHA256 & isolation IDOR multi-tenant
- [x] **v1.22** : Sécurité GraphQL (limiteur de profondeur de requête) & diffusion temps réel Mercure SSE
- [x] **v1.23** : Upload direct multipart S3/R2 résilient, cycle complet d'abandon & `PublishedSiteStorage`
- [x] **v1.24** : Moteur Flow DAG avec détection de boucles, streaming IA SSE inline & formulaires anti-spam
- [x] **v1.25** : Suite E2E Playwright automatisée & bac à sable Twig hermétique (`NativeTwigSecurityPolicy`)
- [x] **v1.26** : Consolidation qualité, pipeline CI/CD automatisé & vérification live 100% en production
- [ ] Jambo Cloud (managed hosting)

👉 **Spécifications fonctionnelles : [ROADMAP.md](ROADMAP.md)**  
🧪 **Feuille de route et cahier de recette des tests : [TEST_ROADMAP.md](TEST_ROADMAP.md)**

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues → jprud67@gmail.com ([SECURITY.md](SECURITY.md)).

---

## License

**[GNU AGPL v3](LICENSE)** — free to use, modify, and self-host.
