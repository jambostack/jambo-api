---
title: Installation
description: Install Jambo API on your server in minutes.
---

## Requirements

- **PHP 8.4+** with extensions: `ctype`, `iconv`, `sodium`
- **Composer**
- **MySQL 8+**, PostgreSQL 14+, or SQLite
- **Node.js 18+** + npm (for frontend assets)
- Optional: **Meilisearch** (full-text search), **Symfony CLI**

## Quick Install

```bash
git clone https://github.com/jambostack/jambo-api.git
cd jambo-api

composer install --no-dev --optimize-autoloader
npm install && npm run build

cp .env.example .env.local
```

Edit `.env.local` with your database and app settings, then:

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:setup
```

Open your domain and log in with the credentials shown by `app:setup`.

:::caution
Change the default admin password immediately after first login.
:::
