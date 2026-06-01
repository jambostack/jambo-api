---
title: Configuration
description: Configure Jambo API environment variables.
---

Copy `.env.example` to `.env.local` and fill in the values:

```ini
APP_ENV=prod
APP_SECRET=           # 32-char random string: openssl rand -hex 32
APP_HOSTNAME=         # your domain, e.g. api.example.com

DATABASE_URL=         # mysql://user:pass@host/db?serverVersion=8.0.32&charset=utf8mb4

MAILER_DSN=           # smtp://user:pass@smtp.example.com:587
MEILISEARCH_HOST=     # http://localhost:7700
MEILISEARCH_KEY=      # your Meilisearch master key
```

## AI Providers

AI keys are configured in the **Admin → Settings → AI Studio** panel, not via environment variables. This allows updating keys without redeploying.
