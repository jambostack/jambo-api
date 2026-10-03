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

[Website](https://jambostack.site) · [Documentation](https://docs.jambostack.site) · [Changelog](CHANGELOG.md) · [Roadmap](ROADMAP.md)

</div>

---

## Enterprise-Grade Superpowers Built-in

Jambo API goes far beyond traditional headless CMSs. It combines high-performance content delivery, visual automation, AI agent connectivity, and multi-tenant security into a single unified engine.

---

### 🔌 1. Native MCP Server v2.0 — AI Agents Work Autonomously

Connect Claude Desktop, Cursor, Copilot, or any Model Context Protocol (MCP) compatible agent directly to Jambo API. No custom glue code, no API wrappers. AI agents interact with your CMS natively:

```json
// Your .cursor/mcp.json or Claude Desktop config
{
  "mcpServers": {
    "jambo": {
      "url": "https://your-jambo.com/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}
```

- **Autonomous Content Operations**: Read, filter, create, update, publish, and delete entries across collections.
- **Dynamic Schema Scaffolding**: Create collections, configure 17 field types, define relations on the fly.
- **Media Asset Ingestion**: Upload, tag, transform, and attach digital assets directly.
- **Multi-locale Translation**: Automatically translate entire entries across configured locales.
- **End-User Administration**: Manage external user accounts, roles, and access rights.

---

### ⚡ 2. Visual Flow Automation Engine (DAG) — Built-in Zapier / n8n Alternative

Automate business processes, trigger reactive pipelines, and coordinate AI operations directly within Jambo API via an intuitive node-based Directed Acyclic Graph (DAG) engine with loop cycle detection:

- **8 Real-time Triggers**: `ContentCreated`, `ContentUpdated`, `ContentDeleted`, `ContentStatusChanged`, `ScheduleCron`, `WebhookInbound`, and form submissions.
- **Logic & Branching Nodes**: `Condition` (expression-based), `Switch`, `Loop`, `Delay`, `And`, `Or`.
- **Data Transforms**: `Filter`, `Flatten`, `Map`, `Omit`, `Pick`, `Pluck`, `Reduce`, `Template` (mustache syntax).
- **Embedded AI Nodes**: `Classify`, `GenerateText`, `LlmCall`, `Summarize`, `Translate` powered by 10 LLM providers.
- **Action Executors**: `SendEmail`, `SendNotification`, `CallWebhook`, `CreateEntry`, `UpdateEntry`, `DeleteEntry`, `PublishEntry`.

---

### 🚀 3. Direct-to-Storage Cloud Uploads (S3 / Cloudflare R2 / MinIO)

Bypass PHP memory constraints (`memory_limit`), worker timeouts, and server bandwidth saturation completely. Jambo API streams assets directly from client browsers to your cloud buckets:

- **Presigned Direct PUT & Multipart Chunking**: Browser-to-bucket direct transfers for files of any size (up to multi-gigabytes).
- **Complete Multipart Lifecycle**: Session initiation, chunk signature generation, server-side completion verification, and automated abort cleanup.
- **TUS Protocol Resumability**: Seamless resume capabilities for mobile or unstable client connections.
- **On-the-Fly Image Processing**: Dynamic WebP/AVIF transcoding, responsive srcset generation, and thumbnail cropping powered by Intervention Image 4.

---

### 🛡️ 4. Autonomous Per-Project OAuth2 Identity Provider (RFC 6749 + PKCE)

Each project functions as an independent, fully isolated OAuth2 Identity Provider (IdP) for your client SPAs, mobile apps, and external frontends:

- **Authorization Code Grant with PKCE (`S256`)**: Recommended for SPAs and mobile apps without exposing client secrets.
- **One-Click Social Logins**: Out-of-the-box OAuth2 federation for Google, GitHub, GitLab, and Microsoft Azure SSO.
- **Multi-Factor Authentication (2FA TOTP)**: Built-in RFC 6238 time-based one-time passwords with encrypted recovery codes.
- **Strict Tenant & IDOR Isolation**: Separate database tables per tenant, ensuring zero cross-project credential leakage.
- **Anti-Bruteforce Defense**: Automatic IP-based and account-based adaptive lockout.

---

### 🤖 5. AI Schema Studio & Inline Writing Companion (SSE Streaming)

- **AI Schema Studio**: Describe your data model in natural language; the AI scaffolds collections, fields, validations, and relational links instantly. Supported providers: **OpenAI · Claude · Gemini · Mistral · Groq · DeepSeek · xAI · Perplexity · Qwen · Ollama**.
- **Real-time SSE Inline Assistant**: Floating AI companion embedded in Lexical rich-text fields. Stream tone adjustments, content expansions, summarization, grammar corrections, and instant translations directly in the editor.

---

## One Install. Unlimited Projects.

Most headless CMS platforms lock you into a single project per instance or charge exorbitant enterprise tiers for multi-tenancy. Jambo API delivers **true multi-project multi-tenancy on a single installation** — complete with dedicated schemas, API tokens, locales, assets, and front-end users.

| Feature / Capability | Jambo API | Strapi v5 | Directus | Payload v3 |
|---|:---:|:---:|:---:|:---:|
| **Multi-project** (single instance) | ✅ Native | ❌ | ❌ | ❌ |
| **Visual Flow Engine (DAG)** | ✅ Native (Triggers, AI, Logic) | ❌ (Zapier / Webhooks) | ✅ Flows | ❌ |
| **Direct S3 / R2 Multipart Upload** | ✅ Client-to-Bucket | ❌ Server Proxy | ⚠️ Server Proxy | ❌ Server Proxy |
| **Project OAuth2 IdP (PKCE)** | ✅ RFC 6749 Native | ❌ | ⚠️ Auth | ❌ |
| **Relational Field Groups** | ✅ Real Indexed SQL | ⚠️ JSON Component | ✅ | ⚠️ JSON Block |
| **Native MCP Server v2.0** | ✅ Built-in | ❌ | ✅ Extension | ✅ Plugin |
| **AI Schema Studio** | ✅ 10 LLM Providers | ❌ | ❌ | ❌ |
| **Inline AI Editor (SSE Stream)** | ✅ Native Lexical SSE | ❌ | ❌ | ❌ |
| **End Users (Front-end Auth + 2FA)** | ✅ Isolated + TOTP | ✅ | ⚠️ | ✅ |
| **Content Versioning & Diff** | ✅ Open Source | ❌ Enterprise | ✅ | ✅ |
| **Dynamic Public Forms + Anti-Spam** | ✅ Native + A/B Test | ❌ | ❌ | ❌ |
| **SEO Intelligence & Loop Detection** | ✅ Native + PDF Audit | ⚠️ Plugin | ❌ | ⚠️ Plugin |
| **Full-text Search** | ✅ Meilisearch Real-time | ❌ | ❌ | ❌ |
| **Static Site Publishing** | ✅ Native + Twig Sandbox | ❌ | ❌ | ❌ |
| **License** | **AGPL v3** | MIT | Apache 2.0 | MIT |

---

## Architectural Modules & Core Capabilities

### 🧩 EAV Content Engine & Relational Schema
- **17 Native Field Types**: Text, longtext, richtext (Lexical editor), slug, email, number, decimal, boolean, date, datetime, color, json, enumeration, media, relation (1:1, 1:N, N:M).
- **Relational Field Groups & Repeaters (`ContentFieldGroup`)**: True relational nested groups with foreign key integrity and SQL indexing — unlike competitors that dump un-indexable JSON blobs.
- **Singletons**: Dedicated schema model for landing pages, hero sections, and site-wide configurations.
- **Granular Content Versioning**: Visual field-by-field diff comparison, instant rollback, and author audit history.
- **Blueprints & Collection Templates**: Export, import, and reuse collection schemas across multiple projects.
- **Meilisearch Real-Time Sync**: Instant search index updates with configurable facets and typo tolerance.

### 🌐 Headless APIs & Developer Tooling
- **Admin Headless REST API (`/admin-api/`)**: Full programmatic control over tenants, collections, fields, and permissions.
- **Personal Access Tokens (PAT)**: High-entropy tokens (`jbo_pat_...`) hashed with HMAC-SHA256 and granular RBAC scopes (`admin`, `schema:read`, `schema:write`, `content:read`, `content:write`).
- **Public Content REST API**: High-performance paginated, filterable, locale-aware endpoints with direct slug retrieval (`/by-slug/{slug}`).
- **Dynamic GraphQL Engine**: Auto-generated schema with queries, mutations, dynamic sub-types for field groups, and query depth limiting to eliminate DoS vulnerabilities.
- **Interactive OpenAPI 3.1 & Swagger UI**: Embedded explorer available directly at `/api/docs`.
- **Real-Time Mercure SSE**: Server-Sent Events hub for live reactive updates across web and mobile clients.

### 🗄️ Cloud Storage & High-Performance Media
- **Direct-to-Bucket Streaming**: AWS S3, Cloudflare R2, and MinIO presigned uploads bypassing PHP execution limits.
- **Multipart Upload Lifecycle**: Direct browser multipart chunk uploads with automatic abort cleanup.
- **Resumable TUS Protocol**: Fault-tolerant uploads for unstable connections.
- **On-The-Fly Media Transformations**: Automatic WebP/AVIF generation, responsive thumbnail variants, and aspect ratio cropping.

### 🔐 Identity, Multi-Factor Auth & Enterprise Security
- **Per-Project OAuth2 IdP**: RFC 6749 Authorization Code Grant with PKCE (`S256`) for client applications.
- **Social Logins**: Pre-configured federated authentication for Google, GitHub, GitLab, and Microsoft Azure SSO.
- **End-User Authentication**: Dedicated front-end user table, JWT issuance, and RFC 6238 2FA TOTP with recovery codes.
- **Hermetic Twig Sandbox (`NativeTwigSecurityPolicy`)**: Strict AST token parsing and allowlist for safe dynamic template execution without SSTI / RCE risks.
- **Multi-Tenant Protection**: Strict cross-project IDOR isolation and rate-limited brute-force lockouts.

### 📋 Public Forms Engine & Anti-Spam
- **Dynamic Form Endpoints (`/p/f/{slug}`)**: Collect leads and user feedback without writing custom backend controllers.
- **Multi-Layered Anti-Spam**: Invisible honeypots, time-traps, and Cloudflare Turnstile / hCaptcha / reCAPTCHA integration.
- **Built-in A/B Testing (`AbTestManager`)**: Compare form conversion rates across variants automatically.
- **Automated Routing**: Direct mapping from form submissions into collection entries, webhook broadcasts, and email notifications.

### 🔍 SEO Intelligence & Smart Redirect Manager
- **Real-Time SEO Content Scorer (`SeoAnalyzer`)**: Instant audit of meta titles, descriptions, heading structure, keyword density, and image alt tags.
- **Structured Data & Sitemaps**: Automatic JSON-LD Schema.org generation and multilingual XML sitemaps with `hreflang` alternates.
- **Exportable PDF Audit Reports**: Client-ready SEO audit reports generated with a single click.
- **Smart Redirect Manager (`RedirectChainDetector`)**: 301 / 302 redirect management with circular loop detection and chain flattening.

### 🚀 Static Site Publishing
- **Static Site Publishing (`PublishedSiteStorage`)**: Build, bundle, and distribute static websites directly from CMS data.
- **Sandboxed Execution**: Isolated template rendering with instant cache purging.

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

### Public Content REST API
```bash
# List entries in collection (paginated & filtered)
GET /api/v1/projects/{projectId}/collections/{collection}/content?page=1&limit=20&status=published

# Single entry by UUID
GET /api/v1/projects/{projectId}/collections/{collection}/content/{entry-uuid}

# Direct lookup by unique slug
GET /api/v1/projects/{projectId}/collections/{collection}/content/by-slug/{slug}

# Project Public Bearer Token Authorization
Authorization: Bearer YOUR_API_TOKEN
```

### GraphQL API
```graphql
# POST /api/v1/projects/{projectId}/graphql
query GetArticles {
  articles(limit: 10, sort: { publishedAt: DESC }) {
    id
    title
    slug
    author {
      name
      avatar { url }
    }
    fieldGroups {
      seoMetadata { metaTitle metaDescription }
    }
  }
}
```

### Admin Headless API & Personal Access Tokens (PAT)
```bash
# Generate a Personal Access Token via CLI
php bin/console jambo:pat:create admin@example.com --name="ci-token" --scopes=admin

# Authenticate against Admin API
GET /admin-api/projects
Authorization: Bearer jbo_pat_YOUR_SECRET_TOKEN

# Manage collections and schema programmatically
GET /admin-api/projects/{projectId}/collections
POST /admin-api/projects/{projectId}/collections/{collection}/fields
```

### Direct S3 / Cloudflare R2 Upload Flow
```bash
# 1. Request presigned direct upload URL from Jambo API
POST /api/v1/projects/{projectId}/media/direct-upload
{
  "filename": "hero-banner.jpg",
  "filesize": 4194304,
  "mimeType": "image/jpeg"
}

# Response contains direct presigned PUT URL and storage key:
# { "uploadUrl": "https://bucket.r2.cloudflarestorage.com/...", "key": "media/uuid.jpg" }

# 2. Upload file directly from browser to S3/R2 (Zero PHP server bandwidth)
PUT https://bucket.r2.cloudflarestorage.com/...
Content-Type: image/jpeg

# 3. Confirm completion to register asset in CMS
POST /api/v1/projects/{projectId}/media/direct-upload/complete
{ "key": "media/uuid.jpg" }
```

### Dynamic Public Forms API
```bash
# Submit to dynamic form endpoint (honeypot + anti-spam protected)
POST /p/f/{formSlug}
Content-Type: application/json

{
  "email": "visitor@example.com",
  "message": "Interested in enterprise plan",
  "_hp": "" # Honeypot trap must remain empty
}
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
- [x] AI Schema Studio (10 providers: OpenAI, Claude, Gemini, Mistral, Groq, DeepSeek, xAI, Perplexity, Qwen, Ollama)
- [x] Native MCP Server v2.0 for autonomous AI agents
- [x] End Users + JWT authentication & 2FA TOTP challenges
- [x] Content versioning with visual diff, webhooks & audit logs
- [x] Meilisearch real-time indexing, multi-locale & PDF exports
- [x] Project & Collection templates, blueprints & snapshots
- [x] **v1.10 – v1.12** : Upload direct S3/R2 multipart, `ContentFieldGroup` relationnels, OAuth2 PKCE, Web Setup Wizard & Starters
- [x] **v1.20** : Moteur EAV durci (unicité slugs multi-locales avec soft-deletes, validation partielle PATCH & relations N:M)
- [x] **v1.21** : Admin API headless `/admin-api/` avec Personal Access Tokens HMAC-SHA256 & isolation IDOR multi-tenant
- [x] **v1.22** : Sécurité GraphQL (limiteur de profondeur de requête) & diffusion temps réel Mercure SSE
- [x] **v1.23** : Upload direct multipart S3/R2 résilient avec cycle complet d'abandon & `PublishedSiteStorage`
- [x] **v1.24** : Moteur Flow DAG avec détection de boucles, streaming IA SSE inline & formulaires anti-spam avec A/B testing
- [x] **v1.25** : Bac à sable Twig hermétique (`NativeTwigSecurityPolicy`) & perfectionnement de l'AI Schema Studio
- [x] **v1.26** : Moteur d'hébergement statique sandboxé, résilience stockage multi-cloud & invalidation de cache instantanée
- [ ] Jambo Cloud (hébergement infogéré multi-régions)

👉 **Spécifications fonctionnelles complètes : [ROADMAP.md](ROADMAP.md)**

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues → jprud67@gmail.com ([SECURITY.md](SECURITY.md)).

---

## License

**[GNU AGPL v3](LICENSE)** — free to use, modify, and self-host.
