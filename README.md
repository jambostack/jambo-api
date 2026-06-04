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

[Website](https://jambostack.site) · [Documentation](https://docs.jambostack.site) · [Changelog](CHANGELOG.md)

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
- **REST** — paginated, filterable, locale-aware, status-aware
- **GraphQL** — auto-generated schema, queries & mutations
- **OpenAPI / Swagger UI** — auto-generated interactive docs
- **Export / Import** — zip-based project snapshots (structure + content + media)

### Users & Security
- **Admin users** — roles, project membership, invitations
- **End Users** — separate front-end auth table, JWT, custom fields, password reset, cross-project IDOR protection
- **Rate limiter**, CSRF protection, HMAC-signed API tokens

### Admin Panel
- React 19 + Inertia.js + Tailwind CSS 4 + shadcn/ui
- Lexical rich text (bold, italic, tables, code, links)
- Dark mode · emerald design system

### DevOps
- **Webhooks** — per-collection event triggers
- **Audit logs** — every admin action tracked
- **Mailer** — per-project SMTP + email log
- **Messenger** — async jobs (Doctrine transport)

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

```bash
# List published entries
GET /api/{project-uuid}/{collection}?locale=en&page=1&per_page=20

# Single entry
GET /api/{project-uuid}/{collection}/{entry-uuid}

# Authorization
Authorization: Bearer YOUR_API_TOKEN

# GraphQL
POST /api/{project-uuid}/graphql
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

## Roadmap

- [x] REST API + GraphQL + OpenAPI/Swagger
- [x] AI Schema Studio (10 providers)
- [x] MCP Server v2.0
- [x] End Users + JWT
- [x] Content versioning · Webhooks · Audit logs
- [x] Meilisearch · Multi-locale · PDF export
- [x] Project & Collection templates · Export/Import
- [ ] Docker one-click install
- [ ] Jambo Cloud (managed hosting)
- [ ] Plugin/extension system

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues → jprud67@gmail.com ([SECURITY.md](SECURITY.md)).

---

## License

**[GNU AGPL v3](LICENSE)** — free to use, modify, and self-host.
