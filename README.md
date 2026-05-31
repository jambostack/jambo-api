<div align="center">

<br />

<img src="https://jambostack.site/logo.svg" alt="Jambo API" width="72" height="72" />

# Jambo API

**Open-source headless CMS — Symfony 8 · PHP 8.4 · React 19**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-2fcf8f.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-8.0-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)](https://react.dev)
[![Database](https://img.shields.io/badge/Doctrine%20ORM-MySQL%20%7C%20PostgreSQL%20%7C%20SQLite-4479A1)](https://www.doctrine-project.org)
[![Website](https://img.shields.io/badge/jambostack.site-2fcf8f?logo=globe)](https://jambostack.site)

[Website](https://jambostack.site) · [Documentation](#) · [Demo](#) · [Roadmap](#)

</div>

---

## What is Jambo API?

**Jambo API** is a fully open-source, self-hosted headless CMS built on **Symfony 8** and **PHP 8.4**. It exposes your content via **REST** and **GraphQL** APIs, ships with an **AI-powered Studio** to design schemas by chat, native **End User authentication**, a built-in **MCP server** for AI agent integration, and a complete admin panel — all in a single deployable PHP application.

> Part of the **[Jambostack](https://jambostack.site)** ecosystem alongside [jambo-workbench](https://github.com/jambostack/jambo-workbench) (AI site builder, Node.js).

---

## Why Jambo API?

| | Jambo API | Strapi v5 | Directus | Payload v3 |
|---|---|---|---|---|
| **Backend stack** | **Symfony 8 / PHP 8.4** | Node.js | Node.js | Next.js / TS |
| **Multi-project** (single install) | ✅ native | ❌ one instance = one project | ❌ | ❌ |
| **AI Schema Studio** (chat-based) | ✅ native | ❌ | ❌ | ❌ |
| **MCP Server** (AI agents) | ✅ v2.0 native | ❌ | ✅ extension | ✅ plugin |
| **End Users** (dedicated front-end auth) | ✅ separate table + JWT | ✅ Users & Permissions | ⚠️ same user table | ✅ per-collection auth |
| **Content Versioning** | ✅ open source | ❌ Enterprise only | ✅ open source | ✅ open source |
| **Multi-locale** | ✅ native | ✅ native (v5) | ✅ native | ✅ native |
| **GraphQL** | ✅ native | ✅ free plugin | ✅ native | ✅ native |
| **Full-text Search** | ✅ Meilisearch | ❌ no native | ❌ no native | ❌ no native |
| **Webhooks** | ✅ native | ✅ | ✅ | ✅ |
| **Audit logs** | ✅ open source | ❌ Enterprise only | ✅ open source | ❌ Enterprise only |
| **PDF export** | ✅ native | ❌ | ❌ | ❌ |
| **License** | **AGPL v3** | MIT | Apache 2.0 | MIT |

---

## Feature Overview

### Content Management
- 📋 **Collections** — flexible EAV model, 15+ field types: `text`, `longtext`, `richtext` (Lexical editor), `slug`, `email`, `password`, `number`, `decimal`, `boolean`, `date`, `datetime`, `time`, `color`, `json`, `enumeration`, `media`, `relation`
- 🌍 **Multi-locale** — native i18n on every collection (en, fr, es, ar built-in)
- 📜 **Content Versioning** — full history of every entry, diff & restore
- 🗂 **Collection Templates** — reusable schema blueprints
- 📊 **Column Settings** — customizable list views per collection
- 🔍 **Full-text Search** — Meilisearch integration with real-time indexing

### API
- ⚡ **REST API** — paginated, filterable, locale-aware endpoints
- 🔗 **GraphQL** — full schema with query, cache invalidation
- 📖 **OpenAPI / Swagger** — auto-generated docs (Nelmio + Swagger UI)
- 🔑 **API Tokens** — per-project token management, role-based permissions

### AI & Automation
- 🤖 **AI Studio** — design and modify your content schema by chatting with an AI (OpenAI, Claude/Anthropic, DeepSeek, Ollama — via Symfony AI Bundle)
- 🔌 **MCP Server v2.0** — connect any AI agent (Claude, Cursor…) directly to your CMS via Model Context Protocol: exploration, content CRUD, schema management, media, end users, AI tools
- 🧠 **AI Content Service** — AI-assisted content generation within entries

### Users & Security
- 👤 **Admin Users** — roles, permissions, project members, invitations
- 👥 **End Users** — front-end user authentication with custom fields, JWT (`lcobucci/jwt`), registration, password reset
- 🛡 **Rate Limiter** — API protection against abuse
- 🔒 **Captcha** — bot protection on public forms

### Media & Assets
- 🖼 **Media Library** — file upload, metadata, VichUploader
- 🎨 **Image Transform** — resize, crop, format conversion (Intervention Image v4)
- 📄 **PDF Export** — generate PDFs from content (DomPDF)

### DevOps & Integrations
- 📬 **Webhooks** — event-driven triggers on content changes (per-collection)
- 📧 **Email / Mailer** — SMTP configuration per project, email logs
- 📦 **Messenger** — async jobs via Symfony Messenger (Doctrine transport)
- 🚀 **Deployments** — deployment tracking and management
- 📊 **Audit Logs** — complete history of every admin action
- 🏗 **Project Templates** — bootstrap new projects from templates

### Admin Panel
- 🎨 Built with **React 19** + **Inertia.js** + **Tailwind CSS 4** + **shadcn/ui**
- Rich text editor: **Lexical** (bold, italic, lists, links, code blocks, markdown)
- Drag & drop fields: **@hello-pangea/dnd**
- Charts: **Recharts**
- **Dark mode** — emerald-based design system

---

## Tech Stack

### Backend
| | |
|---|---|
| Language | **PHP 8.4** |
| Framework | **Symfony 8.0** |
| ORM | **Doctrine ORM 3** + Migrations |
| Database | **MySQL, PostgreSQL, SQLite** (via Doctrine ORM) |
| Search | **Meilisearch** |
| AI | **Symfony AI Bundle** (OpenAI, Anthropic, DeepSeek, Ollama) |
| Auth | Symfony Security + **lcobucci/jwt 5.5** |
| Media | **VichUploader** + **Intervention Image 4** |
| Queue | **Symfony Messenger** (Doctrine transport) |
| PDF | **DomPDF 3** |
| GraphQL | **webonyx/graphql-php 15** |
| API Docs | **Nelmio API Doc** + **Swagger PHP** |

### Frontend
| | |
|---|---|
| Framework | **React 19** + **Inertia.js 3** |
| Build | **Webpack Encore** + TypeScript 5.9 |
| Styles | **Tailwind CSS 4** + **shadcn/ui** + **Radix UI** |
| Rich text | **Lexical** |
| Charts | **Recharts** |
| UI extras | Stimulus 3, Hotwire Turbo, nanostores, sonner |

---

## Getting Started

### Requirements

- **PHP 8.4+** with extensions: `ctype`, `iconv`
- **Composer**
- **MySQL 8+**, PostgreSQL 14+, or SQLite (via Doctrine ORM)
- **Node.js 18+** + npm (for assets)
- Optional: **Meilisearch** (for full-text search), **Symfony CLI**

### Installation

```bash
# 1. Clone
git clone https://github.com/jambostack/jambo-api.git
cd jambo-api

# 2. PHP dependencies
composer install

# 3. JS dependencies & build
npm install
npm run build

# 4. Environment
cp .env .env.local
```

Edit `.env.local`:
```env
APP_ENV=prod
APP_SECRET=your-secret-here
DATABASE_URL="mysql://user:password@127.0.0.1:3306/jambo?serverVersion=8.0.32&charset=utf8mb4"
MAILER_DSN=smtp://user:pass@smtp.example.com:587
MEILISEARCH_HOST=http://localhost:7700
MEILISEARCH_KEY=your-meilisearch-key
APP_HOSTNAME=yourdomain.com
```

```bash
# 5. Database
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 6. Load fixtures (default admin + system permissions)
php bin/console doctrine:fixtures:load --append
# Default credentials: admin@jambostack.site / admin1234

# 7. Start
symfony serve
# or: php -S localhost:8000 -t public/
```

Open `http://localhost:8000` and log in with the default credentials above. **Change the password immediately.**

### Docker (coming soon)

```bash
docker compose up -d
```

---

## API Reference

```bash
# List published entries
GET /api/{project-uuid}/{collection}?locale=en&limit=20&offset=0

# Get single entry
GET /api/{project-uuid}/{collection}/{entry-uuid}

# Authentication
Authorization: Bearer YOUR_API_TOKEN
```

GraphQL endpoint: `GET/POST /api/{project-uuid}/graphql`

OpenAPI docs: `/api/docs`

Example response:
```json
{
  "data": [
    {
      "uuid": "a1b2c3d4-e5f6-...",
      "locale": "en",
      "status": "published",
      "title": "Hello World",
      "slug": "hello-world",
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-01-15T12:00:00+00:00"
    }
  ],
  "meta": { "total": 42, "page": 1, "limit": 20 }
}
```

---

## MCP Server (AI Agent Integration)

Connect any AI agent directly to your Jambo API via **Model Context Protocol**:

```
MCP endpoint: https://your-jambo.com/mcp
Version: 2.0.0 — JamboApi CMS
```

Available tool categories:
- **Exploration** — browse projects, collections, schema
- **Content** — list, create, update, delete entries
- **Schema** — manage collections and fields
- **Media** — upload, query assets
- **End Users** — manage front-end users
- **AI Tools** — content generation, search, versioning, image processing

---

## Project Structure

```
jambo-api/
├─ assets/js/            # React 19 frontend (Inertia.js)
│  ├─ components/        # UI components (shadcn/ui, Radix)
│  ├─ layouts/           # App shell
│  └─ pages/             # Inertia pages (Content, Media, Studio…)
├─ config/               # Symfony configuration
├─ migrations/           # 32 Doctrine migrations
├─ src/
│  ├─ Controller/        # 24 controllers (API, pages, MCP…)
│  ├─ Entity/            # 28 Doctrine entities
│  ├─ Mcp/               # MCP Server v2.0
│  ├─ Repository/        # Data access layer
│  ├─ Service/           # 16 business services
│  └─ EventSubscriber/   # 6 subscribers (webhooks, audit, search…)
├─ templates/            # Twig base templates
└─ translations/         # i18n — en, fr, es, ar
```

---

## Ecosystem

```
jambostack/
├─ jambo-api          ← You are here · Symfony 8 / PHP 8.4 · AGPL v3
├─ jambo-workbench    ← AI site builder · Node.js / Vite / React · MIT
└─ jambo-mobileapp    ← Mobile app (coming soon)
```

---

## Roadmap

- [x] REST API + GraphQL + OpenAPI/Swagger
- [x] AI Schema Studio (multi-provider via Symfony AI Bundle)
- [x] MCP Server v2.0
- [x] End Users + JWT authentication
- [x] Content versioning
- [x] Webhooks + Audit logs
- [x] Meilisearch full-text search
- [x] Multi-locale (en, fr, es, ar)
- [x] PDF export, Image transform
- [x] Project templates, Collection templates
- [ ] Docker one-click install
- [ ] Jambo Cloud (managed hosting)
- [ ] Plugin/extension system

---

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) first.

```bash
git clone https://github.com/jambostack/jambo-api.git
cd jambo-api
git checkout -b feat/your-feature
composer install && npm install && npm run dev
# Make your changes, write tests, then:
git commit -m "feat: description"
# Open a Pull Request on GitHub
```

---

## License

Jambo API is open source under the **[GNU AGPL v3 License](LICENSE)**.

You can use, modify and distribute Jambo API freely. If you run a modified version as a public SaaS, you must publish your source code under AGPL v3.

For a commercial license (closed-source, white-label): [contact@jambostack.site](mailto:contact@jambostack.site)

---

<div align="center">

Built with ❤️ · [jambostack.site](https://jambostack.site)

</div>
