# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅ Active  |

Only the latest minor release of the current major version receives security fixes.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Report security issues by email to: **jprud67@gmail.com**

Include in your report:

- A description of the vulnerability and its potential impact
- Steps to reproduce (proof-of-concept code or reproduction steps)
- Affected versions
- Any suggested fix if you have one

You will receive an acknowledgement within **48 hours**.  
If the issue is confirmed, a fix will be released within **14 days** for critical vulnerabilities.

## Security Architecture

### Authentication

- **Admin users** — Symfony Security with bcrypt password hashing; login rate-limited to 5 attempts per 60 seconds
- **API tokens** — HMAC-SHA256 (`hash_hmac`) signed with `APP_SECRET`; token version upgrade on first use; expiry support
- **End-user JWT** — `lcobucci/jwt` 5.5; 15-minute access tokens, 30-day refresh tokens; cross-project rejection enforced

### Authorisation

- Every authenticated request verifies the token belongs to the correct project (prevents cross-project IDOR)
- `ProjectVoter` enforces project-level permissions for admin operations
- Relation and media fields validate UUIDs against the same project before storing (prevents cross-project data leakage)

### Input Validation

- All user-supplied data is validated at controller level before persistence
- Rich-text content is sanitised on render (Lexical / Twig `sanitize_html`)
- Email addresses validated with `filter_var(FILTER_VALIDATE_EMAIL)`
- Minimum password length enforced (8 characters)

### Infrastructure

- CSRF protection enabled on all state-changing forms
- `APP_SECRET` must be unique and kept private (used for token signing)
- Set `APP_ENV=prod` and `APP_DEBUG=false` in production
- Database credentials should use a least-privilege user
- Media uploads validated by MIME type and extension

## Security Best Practices for Deployment

1. **Change the default admin credentials** immediately after running `php bin/console app:setup`
2. **Generate a strong `APP_SECRET`**: `php -r "echo bin2hex(random_bytes(32));"`
3. **Use HTTPS** — never run Jambo API over plain HTTP in production
4. **Rotate API tokens** regularly; tokens support expiry dates
5. **Restrict database access** — the DB user should have only the necessary privileges
6. **Keep dependencies up to date** — run `composer update` and `npm update` regularly
