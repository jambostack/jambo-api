# JamboAPI CMS

A headless CMS built with **Symfony 8**, **PHP 8.4**, **React 19**, and **Webpack Encore**.  
Uses an EAV (Entity–Attribute–Value) data model for flexible content types.

## Requirements

- PHP 8.4+
- Composer
- Node.js 18+ / npm
- MySQL 8+ (configured via `DATABASE_URL` in `.env`)

## Setup

```bash
# 1. Install PHP dependencies
composer install

# 2. Install Node.js dependencies
npm install

# 3. Copy and configure environment
cp .env .env.local
# Edit .env.local: set DATABASE_URL, APP_SECRET if needed

# 4. Create the database and run migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 5. Build frontend assets
npm run build          # production
npm run dev            # development (single pass)
npm run watch          # development with watch
npm run dev-server     # hot-reload dev server
```

## Architecture

```
src/
├── Controller/
│   ├── DefaultController.php       # Inertia SPA catch-all
│   ├── SecurityController.php      # Login / Logout
│   ├── CollectionController.php    # CRUD – Content Types
│   ├── FieldController.php         # CRUD – Fields per Collection
│   ├── ContentController.php       # CRUD – Content Entries (EAV)
│   └── MediaController.php         # File uploads (vich/uploader-bundle)
├── Entity/
│   ├── User, Project, Collection, Field
│   ├── ContentEntry, ContentFieldValue
│   └── Media
└── Service/
    └── EavDataFormatterService.php  # Serializes EAV entries to flat JSON

assets/
├── app.js              # Webpack Encore entry point (Inertia + React)
├── styles/app.css      # Tailwind CSS
└── js/
    ├── pages/          # Inertia page components
    ├── components/     # Shared React components (shadcn/ui)
    ├── layouts/        # Layout wrappers
    ├── hooks/          # Custom React hooks
    └── types/          # TypeScript type definitions
```

## API Endpoints

All API routes are prefixed with `/api`. Authentication is required (ROLE_USER) except for `/api/*` public routes.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/projects/{uuid}/collections` | List collections |
| POST | `/api/projects/{uuid}/collections` | Create collection |
| GET/PUT/DELETE | `/api/projects/{uuid}/collections/{slug}` | Read/Update/Delete |
| GET | `/api/projects/{uuid}/collections/{slug}/fields` | List fields |
| POST | `/api/projects/{uuid}/collections/{slug}/fields` | Create field |
| GET/PUT/DELETE | `/api/projects/{uuid}/collections/{slug}/fields/{slug}` | Field CRUD |
| GET | `/api/projects/{uuid}/collections/{slug}/entries?page=1&per_page=15` | List entries (paginated) |
| POST | `/api/projects/{uuid}/collections/{slug}/entries` | Create entry |
| GET/PUT/DELETE | `/api/projects/{uuid}/collections/{slug}/entries/{uuid}` | Entry CRUD |
| GET | `/api/projects/{uuid}/media` | List media |
| POST | `/api/projects/{uuid}/media` | Upload file |
| GET/PUT/DELETE | `/api/projects/{uuid}/media/{uuid}` | Media CRUD |

## Running Tests

```bash
php vendor/bin/phpunit --no-coverage
```

## Deployment

1. Set `APP_ENV=prod` and configure all env vars in `.env.local`
2. Run `composer install --no-dev --optimize-autoloader`
3. Run `npm run build`
4. Run `php bin/console doctrine:migrations:migrate --no-interaction`
5. Run `php bin/console cache:warmup`
6. Ensure `public/uploads/` is writable by the web server
