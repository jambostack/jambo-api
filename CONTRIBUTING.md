# Contributing to Jambo API

Thank you for your interest in contributing!

## Before You Start

- Check the [open issues](https://github.com/jambostack/jambo-api/issues) before opening a new one
- For significant changes, open an issue first to discuss the approach
- All contributions must be compatible with the **AGPL v3** licence

## Development Setup

```bash
git clone https://github.com/jambostack/jambo-api.git
cd jambo-api
composer install
npm install
cp .env .env.local          # set your DATABASE_URL and APP_SECRET
php bin/console doctrine:migrations:migrate
php bin/console app:setup   # creates the first admin user
npm run dev                 # build frontend assets
symfony serve               # or use your local web server
```

## Running Tests

```bash
./vendor/bin/phpunit
```

All pull requests must pass the full test suite. New features should include tests.

## Code Standards

- **PHP** — PSR-12 style; strict types (`declare(strict_types=1)`) on new files
- **TypeScript / React** — follow the existing component and hook patterns
- **Commits** — use [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `refactor:`, `test:`
- **No TODO/FIXME** — finish the implementation or open a tracked issue

## Pull Request Process

1. Fork the repository and create a branch from `main`
2. Make your changes with tests
3. Run `./vendor/bin/phpunit` — all tests must pass
4. Run `npx tsc --noEmit` — no TypeScript errors
5. Open a PR against `main` with a clear description of what and why

## Reporting Bugs

Please include:
- Jambo API version
- PHP version and OS
- Steps to reproduce
- Expected vs actual behaviour

For **security vulnerabilities**, see [SECURITY.md](SECURITY.md).
