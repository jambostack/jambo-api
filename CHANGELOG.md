# Changelog

All notable changes to Jambo API are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.26.94] — 2026-11-05

### AiController, AuditController & SearchController Hardening, Bounded Payloads, Prompt Injection Defense & Strict I18N
- **AiController Hardening**:
  - Injected Symfony `TranslatorInterface` and removed all hardcoded English/French error strings.
  - Added strict prompt injection defense (`sanitizePromptInput` & `containsDangerousPromptBreakout`) filtering null bytes, bidirectional Trojan Source overrides, neutralizing special LLM delimiter tokens (`<|im_start|>`, `[INST]`, `<<SYS>>`), and rejecting jailbreak patterns with HTTP 400 (`ai.prompt_injection_detected`).
  - Implemented prompt size bounds: brief (min 3, max 10,000 characters), text (min 3, max 50,000 characters), max words (min 5, max 2,000).
  - Deep array structure validation for JSON content payloads (max depth 5, max 100 keys, key length max 255 chars, value length max 50,000 chars).
  - Multi-tenant tenant isolation with project token verification, abilities verification, and cross-project token rejection (HTTP 403).
  - Defensive error handling: handled transport timeouts (HTTP 504), provider rate limiting (HTTP 429), and missing provider errors (HTTP 503) without leaking provider API keys or internal stack traces.
- **AuditController Hardening**:
  - Injected Symfony `TranslatorInterface` with full localization of error responses.
  - Added strict UUID format validation for project identifiers before executing queries.
  - Bounded pagination parameters: `limit` capped at 100 (`MAX_PAGE_LIMIT`), `offset` capped at 10,000 (`MAX_OFFSET`) to prevent denial-of-service / memory exhaustion.
  - Enforced cross-project token isolation with HTTP 403 and read ability verification.
- **SearchController Hardening**:
  - Injected Symfony `TranslatorInterface` with localized messages.
  - Multi-tenant cross-project isolation: validated project tokens and abilities (`read` for search, `manage`/`write` for reindex) with HTTP 403.
  - Query parameter bounds: `q` query string sanitized of null bytes, required, and capped at 500 characters (`MAX_QUERY_LENGTH`).
  - Collection and locale format validation via regex (`/^[a-zA-Z0-9_\-]+$/` and `/^[a-zA-Z]{2,4}([-_][a-zA-Z0-9]{2,8})?$/`).
  - Bounded pagination parameters: `limit` capped at 100, `offset` capped at 10,000.
  - Rate limiting with localized 429 response for both search and reindex operations.
- **Strict I18N Parity**:
  - Added 45 new translation keys (`ai.*`, `audit.*`, `search.*`) across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
  - Strict 100% dictionary key parity maintained (2,433 keys across each of the 4 languages, 0 missing).
- **Quality Assurance**:
  - Added comprehensive test suite `tests/Controller/Cycle39HardeningTest.php` (9 tests, 228 assertions).
  - Full suite passing green (Cycle 38 & Cycle 39: 16 tests, 509 assertions).

## [1.26.93] — 2026-11-05

### TusController, CollectionTemplateController & ProjectTemplateController Hardening, Bounded Payloads & Strict I18N
- **TusController Hardening**:
  - Injected Symfony `TranslatorInterface` and eliminated all hardcoded error/status strings.
  - Enforced RFC 4122 UUID validation on `projectUuid` parameters before any database queries.
  - Added strict payload and header limits: `Upload-Length` capped at 100MB (`Tus-Max-Size`), returning HTTP 413 when exceeded.
  - Hardened `Upload-Metadata` header parser with max length (4KB), strict alphanumeric keys validation, and safe base64 decoding.
  - Sanitized target storage destination paths using canonical RFC 4122 project UUIDs to prevent any directory traversal.
  - Enhanced role permission checks with granular member role permissions (`content.create` and `project.view`).
  - Added standard `Tus-Resumable: 1.0.0` headers to all responses and error payloads per TUS 1.0.0 protocol specification.
- **CollectionTemplateController & ProjectTemplateController Hardening**:
  - Injected Symfony `TranslatorInterface` with fully localized error responses.
  - Enforced strict integer route requirements (`id => '\d+'`) and positive ID checks (`$id > 0`), returning HTTP 404 cleanly.
  - Added strict length limits: template name capped at 255 characters, description capped at 2,000 characters.
  - Enforced field limits: maximum 100 fields per template (HTTP 422).
  - Sanitized field names, slugs (alphanumeric/hyphens/underscores), and types (max 50 chars).
  - Fixed in-memory bidirectional ArrayCollection synchronization for template fields.
  - Hardened access control: allow both `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` on template creation.
  - Hardened `ProjectTemplateBuilder` defensive field extraction against missing or malformed keys.
- **Strict I18N Parity**:
  - Added 29 new translation keys (`tus.*`, `template.*`) across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
  - Strict 100% dictionary key parity maintained (2,388 keys across each of the 4 languages, 0 missing).
- **Quality Assurance**:
  - Added comprehensive test suite `tests/Controller/Cycle38HardeningTest.php` (7 tests, 281 assertions).
  - Full test suites passing green (100+ tests, 740+ assertions).

## [1.26.92] — 2026-11-05

### MediaController & AssetController Hardening, Payload Bounds & Strict I18N
- **MediaController Hardening**:
  - Injected Symfony `TranslatorInterface` and removed all hardcoded error/status strings.
  - Added strict payload size constraints: bulk delete (`<= 64KB`), media metadata update (`<= 16KB`), folder move (`<= 8KB`).
  - Added bulk deletion item count bounds: maximum 100 media IDs/UUIDs per request (HTTP 422).
  - Added text bounds validation for media metadata: `alt` text capped at 500 characters, `caption` text capped at 2,000 characters.
  - Added target folder integrity validation on file upload and move operations (numeric ID, existence, soft-delete check).
  - Protected image cropping endpoint by requiring valid image mime types and translated error messages.
  - Reinforced media lookup against soft-deleted records (`deletedAt !== null` returns 404).
- **AssetController (Public API) Hardening**:
  - Injected Symfony `TranslatorInterface` and replaced all hardcoded error strings.
  - Validated RFC 4122 UUID format for `projectId` on public files routes (returns HTTP 400 for malformed identifiers).
  - Reinforced public API access controls and token deletion ability validation with localized error messages.
- **Strict I18N Parity**:
  - Added 22 new translation keys (`media.*`, `asset.*`) across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
  - Strict 100% dictionary key parity maintained (2,359 keys across each of the 4 languages, 0 missing).
- **Quality Assurance**:
  - Added comprehensive test suite `tests/Controller/Cycle37HardeningTest.php` (20 tests, 218 assertions).
  - All test suites passing green.

## [1.26.91] — 2026-11-05

### MediaFolder & Realtime Hardening, Path Traversal Defense, Depth Bounds & Strict I18N
- **MediaFolderController Hardening**:
  - Enforced RFC 4122 UUID validation on `projectUuid` parameters before any database queries.
  - Added strict payload size bounding (max 16KB) on folder creation, update, and move endpoints (HTTP 413).
  - Protected against path traversal and separator injection in folder names (`..`, `/`, `\`), rejecting malicious sequences with HTTP 422.
  - Implemented hierarchy max depth limits (max 15 levels) and cyclic/descendant traversal loop protection.
  - Added parent folder existence and soft-delete verification (`isDeleted()`) on creation and move requests.
  - Injected Symfony `TranslatorInterface` with fallback defaults, replacing all hardcoded strings with 15 translation keys (`media_folder.*`).
- **RealtimeController Hardening**:
  - Enforced strict UUID regex validation and RateLimiter protection on poll and token endpoints.
  - Bounded polling query parameters (`since` capped between 0 and 1,000,000, `limit` clamped between 1 and 100).
  - Replaced all hardcoded error/status messages with 7 translation keys (`realtime.*`).
  - Safe event file handling with atomic writes and directory creation checks.
- **Strict I18N Parity**:
  - Added 22 new translation keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
  - Maintained exact 100% dictionary key parity (2337 keys each, 0 diffs).
- **Quality Assurance**:
  - Added dedicated test suite `tests/Controller/Cycle36HardeningTest.php` (14 tests, 232 assertions) covering traversal attacks, depth limits, payload limits, cycle move rejection, and translation parity.
  - 100% passing PHPUnit test suite (1184 tests, 6046 assertions).

## [1.26.90] — 2026-11-05

### AdminApi REST Hardening, UUID Bounds Enforcement, CSV Stream Protection & Strict I18N
- **AdminApi Hardening (`ProjectController`, `CollectionController`, `FieldController`, `ImportController`)**:
  - Enforced RFC 4122 UUID validation on all project and collection routing parameters (`validateUuid()`), returning explicit HTTP 422 for malformed tokens.
  - Implemented length boundaries and required checks for project names (max 255 chars), collection names and slugs (max 255 chars, regex `^[a-zA-Z0-9_\-]+$`), and field names (max 255 chars).
  - Protected CSV and JSON batch import against unbounded streams, memory inflation, excessive row counts (max 1000 rows), and excessive column mappings (max 100 columns).
  - Maintained safe nullable translator injection (`?TranslatorInterface $translator = null`) with seamless fallback across all endpoints.
- **Strict I18N Elimination of Hardcoded Strings**:
  - Replaced all hardcoded error/status responses across `AdminApi` controllers with 41 new translation keys under `admin_api.project.*`, `admin_api.collection.*`, `admin_api.field.*`, and `admin_api.import.*`.
  - Maintained exact 4-language parity (EN, FR, ES, AR) across `translations/messages.*.php` (2315 keys each, 0 diffs).
- **Tests & Quality Assurance**:
  - Added dedicated test suite `tests/Controller/AdminApi/AdminApiHardeningTest.php` (11 tests, 362 assertions) validating UUID format enforcement, input bounds, French/Spanish/Arabic localization, and full dictionary parity.
  - Verified 100% passing tests across the AdminApi suite.

## [1.26.89] — 2026-11-05

### Collection & Content Management Hardening, Anti-SSRF, Payload Constraints & Strict I18N
- **Collection Management Hardening (`CollectionController`)**:
  - Enforced project-level authentication defense-in-depth and granular RBAC checks for collection index, create, update, reorder, and delete operations.
  - Added strict input length bounds & regex validation: `name` (max 255 chars, non-empty), `slug` (max 255 chars, regex `^[a-zA-Z0-9_\-]+$`), and `description` (max 2000 chars).
  - Validated uniqueness of slugs on creation and modification with explicit HTTP 409 conflict handling.
  - Implemented safe nullable translator fallback (`trans()` helper) to preserve backwards compatibility across unit/integration test harnesses.
  - Integrated `AuditService` logging for collection lifecycle events (`collection_create`, `collection_update`, `collection_delete`).
- **Content Management Hardening (`ContentController`)**:
  - Enforced input sanitization, field-level size limits (max 65,535 chars for text fields), JSON depth limit (max 10 nesting levels), and JSON payload size checks (max 64KB).
  - Protected against SSRF and open redirect vulnerabilities on URL fields by verifying HTTP/HTTPS schemes and valid hostnames.
  - Enforced project locale activation check ensuring submitted entry locales are explicitly configured for the parent project.
  - Enforced singleton collection uniqueness constraint preventing duplicate entries.
  - Validated assignee project membership verifying assigned users are active project members.
  - Protected search query length (max 255 chars) and payload field count limits (max 200 fields per entry).
  - Integrated `AuditService` logging for content entry creation, updates, and deletion.
- **Strict I18N Parity**:
  - Replaced all hardcoded error/success responses in `CollectionController` and `ContentController` with 35 new translation keys under `collection.admin.*` and `content.admin.*`.
  - Maintained exact 4-language parity (EN, FR, ES, AR) across `translations/messages.*.php` (2274 keys each, 0 diffs).
- **Quality Assurance & Verification**:
  - Added comprehensive hardening test suite `tests/Controller/ContentAndCollectionHardeningTest.php` (17 tests, 306 assertions) verifying RBAC, payload bounds, SSRF prevention, singleton rules, and localized error messages.
  - Verified 100% passing test suite across all 1159 tests in the application.

## [1.26.88] — 2026-11-05

### Granular RBAC, Anti-Privilege Escalation, Audit Trail & Strict UserManagement I18N
- **UserManagement Hardening (UserController, RoleController, PermissionController)**:
  - **Granular RBAC & Anti-Privilege Escalation**:
    - Guarded user promotion and superadmin creation: non-superadmin users cannot create or assign `ROLE_SUPER_ADMIN` accounts.
    - Guarded user update: non-superadmin callers cannot modify or tamper with `ROLE_SUPER_ADMIN` user accounts (HTTP 403 Forbidden).
    - Guarded role assignment: delegated administrators cannot assign roles containing permissions beyond their own scope (CWE-269 prevention).
    - Guarded self-lockout & demotion: last super administrator cannot be demoted or deleted (HTTP 422 Unprocessable Entity).
    - Guarded role & permission management: protected system roles (`ROLE_SUPER_ADMIN`, `admin`, `super_admin`) and protected system permissions (`users.manage`, `roles.manage`, etc.) cannot be deleted or renamed.
    - Prevented deletion of roles assigned to active users and permissions assigned to active roles.
    - Implemented full CRUD and bulk deletion for `PermissionController` with format enforcement (`^[a-z0-9_]+(\.[a-z0-9_]+)*$`) and password verification.
  - **Audit Trail Integration**:
    - Integrated `AuditService` across `UserController`, `RoleController`, and `PermissionController`.
    - Automated secure audit logging on `user_create`, `user_update`, `user_delete`, `user_bulk_delete`, `role_create`, `role_update`, `role_delete`, `role_bulk_delete`, `permission_create`, `permission_update`, `permission_delete`, and `permission_bulk_delete` with automatic credential redaction.
  - **Strict I18N Elimination of Hardcoded Strings**:
    - Injected Symfony `TranslatorInterface` across all 3 controllers.
    - Replaced all raw strings with 48 new translation keys under `user.admin.*`, `role.admin.*`, and `permission.admin.*`.
    - Maintained exact 4-language parity (EN, FR, ES, AR) across `translations/messages.*.php` (2229 keys each, 0 diffs).
  - **Tests & Quality Assurance**:
    - Added dedicated test suite `UserManagementHardeningTest.php` (8 tests, 318 assertions) covering RBAC, anti-escalation, audit logging, system protections, and I18N parity.
    - Full PHPUnit test suite passed successfully (1116 tests, 4809 assertions, 0 failures).

## [1.26.87] — 2026-11-05

### Security Hardening, Trigger Defenses, Inbound Anti-SSRF & Full Automation/Flow I18N
- **AutomationController & FlowController Hardening**:
  - Injected `TranslatorInterface` with fallback across `AutomationController`, `FlowController`, and `WebhookInboundController`.
  - Replaced all hardcoded error and notification messages with strict translation keys under `automation.admin.*` and `flow.admin.*`.
  - Added strict payload validations:
    - Automation name bounds check (max 255 characters).
    - DryRun and test payloads validated against malformed data types and excessive sizes.
    - TestNode validated for required `node_type` and registry resolution.
  - Added dedicated flow validation endpoint `api_automation_validate_flow` (`POST /api/automations/validate-flow`) on `FlowController` for direct frontend canvas graph validation.
- **Trigger Hardening & Inbound Payload Defenses**:
  - `FlowValidator` structural integrity improvements:
    - Enforced that triggers cannot receive incoming edges (in-degree must be 0).
    - Limited trigger count per automation graph (max 10 triggers).
    - Enforced graph maximum depth traversal limit (`MAX_GRAPH_DEPTH = 50`) using Kahn's topological DAG depth calculation to prevent execution stack exhaustion.
    - Enforced node configuration size limits (max 64 KB per node config) against DoS memory attacks.
    - Enforced preventive static anti-SSRF validation on `action.call_webhook` and `http.request` URLs (blocking loopback, private subnets, cloud metadata hostnames, and invalid schemes).
  - `WebhookInboundController`:
    - Strict payload body size enforcement (max 2MB).
    - Recursion depth limit (`depth: 32`) on inbound JSON deserialization.
    - Elimination of all hardcoded error strings in favor of `automation.admin.webhook_*` translations.
- **Locale Handling & Internationalization**:
  - Enhanced `LocaleSubscriber` to dynamically update `TranslatorInterface` locale based on authenticated user preference or `Accept-Language` HTTP header (`en`, `fr`, `es`, `ar`).
  - Added 30 new translation keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`) maintaining strict 4-way parity (2181 keys, 0 discrepancy).
- **Test Suite**:
  - Added comprehensive test suite `AutomationAndFlowHardeningTest` covering catalog retrieval, flow validation, SSRF URL rejection, trigger in-degree enforcement, max depth enforcement, cyclic graph detection, payload validation, and multilingual error resolution (FR, ES, AR).

## [1.26.86] — 2026-11-05

### Security Hardening, Anti-XSS, Anti-SSRF, Redirect Limitation & Strict I18N
- **FormController Admin Hardening & Elimination of Hardcoded Error Messages**:
  - Injected `TranslatorInterface` with fallback mechanism across all administrative form endpoints.
  - Replaced over 30 hardcoded error and validation messages with localized translation keys under `form.admin.*`.
  - Added multi-layered Anti-XSS security defenses:
    - HTML and script injection detection across form names, field definitions (`label`, `placeholder`, `helpText`, options), steps, and settings.
    - Stricter field identifier syntax validation (`^[a-zA-Z0-9_\-\.]{1,64}$`).
  - Added Anti-SSRF and unsafe protocol defenses on form redirection settings (`settings.redirectUrl`), preventing redirect to private RFC 1918 addresses, loopbacks (`127.0.0.1`, `localhost`), cloud metadata (`169.254.169.254`), or dangerous URI schemes (`javascript:`, `data:`, `vbscript:`, protocol-relative `//`).
  - Added new dedicated route `admin_forms_submission_show` (GET `/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}`) with strict integer routing parameter constraints.
- **RedirectController Admin Hardening, SSRF Protection & Loop Limitation**:
  - Injected `TranslatorInterface` and `SsrfProtectionService`, eliminating all hardcoded messages under `redirect.admin.*`.
  - Enforced anti-SSRF filtering on all absolute redirection targets via `SsrfProtectionService`, blocking private/reserved subnets and cloud metadata endpoints.
  - Enforced target URI security: prohibited dangerous schemes, protocol-relative prefixes (`//`, `/\\`, `\\`), user credentials in URLs, and restricted network ports.
  - Prevented duplicate redirect rules on identical source paths (`fromPath`) within the same project.
  - Enhanced `RedirectChainDetector`:
    - Added `analyzeChain` method evaluating both forward and backward hops.
    - Implemented loop detection and maximum chain depth limitation (`MAX_CHAIN_HOPS = 5`), rejecting excessive redirect chains in accordance with SEO and browser security guidelines.
- **Strict 4-Language Translation Parity**:
  - Added 42 new translation keys (21 Form Admin + 21 Redirect Admin) across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
  - Maintained 100% dictionary parity (2,151 keys per dictionary, 0 difference).
- **Automated Test Suite**:
  - Added `FormControllerAdminHardeningTest` (8 tests, 33 assertions) covering anti-XSS, anti-SSRF, field syntax validation, and submission lifecycles.
  - Added `RedirectControllerAdminHardeningTest` (8 tests, 33 assertions) covering SSRF defense, scheme/userinfo/port restrictions, loop prevention, and chain depth bounds.
  - Total test suite expanded to 1,095 tests (+16 tests, 4,459 assertions) passing at 100%.

## [1.26.85] — 2026-11-05

### Security, Admin Project Configuration Hardening & Strict I18N
- **ProjectMailerSettingsController Complete I18N & Hardening**:
  - Injected `TranslatorInterface` with fallback mechanism across all mailer configuration and test endpoints.
  - Replaced all hardcoded error/status strings (previously mixed English/French) with localized keys under `project.mailer.*`.
  - Enforced strict SSRF validation with detailed safe-host diagnostics via `SsrfProtectionService`.
  - Localized SMTP test email subject and body across all 4 system languages.
- **ProjectStorageController SSRF Defense, Input Bounds & I18N**:
  - Injected `TranslatorInterface` and localized all storage configuration, profile, and rule responses under `project.storage.*`.
  - Added early SSRF validation on S3 custom endpoints (`s3_endpoint`) at profile creation and update time via `StorageDriverFactory::validateS3Endpoint`, rejecting cloud metadata (`169.254.169.254`), private IP ranges, and loopbacks.
  - Added input bounds and format constraints:
    - Profile name: 1-100 characters, no control characters.
    - S3 access key: max 255 characters, no control characters.
    - S3 region: max 64 alphanumeric characters and hyphens.
    - S3 bucket: 1-63 valid DNS label characters.
    - S3 secret: max 1024 characters before encryption.
    - Base URL: max 2048 characters, valid URL or relative path.
    - Root path: max 500 characters, directory traversal (`..`) and null bytes strictly rejected.
    - Rules: MIME pattern (max 100 chars), extension (max 20 alphanumeric chars), max file size (positive integer up to 10GB).
- **ProjectJwtTtlController Input Validation & I18N**:
  - Injected `TranslatorInterface` and aligned error responses with translatable keys under `project.jwt_*` and `project.jwt_ttl.*`.
  - Added strict numeric scalar validation on incoming TTL values, rejecting malformed types or negative numbers.
- **Strict 4-Language Translation Parity**:
  - Added 38 new translation keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`), maintaining 100% translation parity (2,109 keys per dictionary, 0 missing).
- **Automated Test Suite**:
  - Added 33 new automated controller tests in `tests/Controller/Admin/ProjectJwtTtlControllerTest.php`, `ProjectMailerSettingsControllerTest.php`, and `ProjectStorageControllerTest.php`.
  - Total test suite expanded to **1,079 tests / 4,393 assertions / 0 failures**.

---

## [1.26.84] — 2026-11-05

### Security, Two-Factor Authentication, Project Export/Import & Strict I18N Hardening
- **TwoFactorService & Constant-Time Verification**:
  - Implemented timing-safe `hash_equals` comparison for backup code hashes and email code hashes in `TwoFactorService::verifyAndConsumeBackupCode`, `verifyEmailCode`, and `verifyEmailHash`.
  - Added strict input length bounds (`MAX_CODE_LENGTH = 64`) and character set validation (`ctype_alnum`) to prevent CPU exhaustion on oversized inputs.
  - Added robust exception handling around `TOTP::create()` to catch malformed base32 secrets without unhandled 500 errors.
- **TwoFactorChallengeController Brute-Force & Flood Defense**:
  - Enforced brute-force attempt bounding per challenge session (`MAX_FAILED_ATTEMPTS = 5`): on 5th consecutive failure, the session is purged to neutralize OTP guessing attacks.
  - Rate-limited 2FA challenge requests per IP + user ID.
  - Bounded email resends to `MAX_EMAIL_RESENDS = 3` per challenge session to prevent email flooding.
  - Replaced hardcoded strings with I18N keys under `auth.2fa.*` (`max_attempts_exceeded`, `max_resends_exceeded`).
- **Settings/SecurityController Hardening & Localization**:
  - Secured 2FA endpoints (`totp/setup`, `totp/confirm`, `email/enable`, `email/confirm`, `disable`, `backup-codes`, `social/link`, `social/unlink`) with rate limiting and password length bounds.
  - Hardened email code confirmation with session attempt counters and constant-time matching.
  - Replaced all raw English strings with Symfony `TranslatorInterface` translations under `security.2fa.*` and `security.social.*`.
- **EndUserAuthController 2FA Hardening**:
  - Replaced non-constant-time hash comparison with `TwoFactorService::verifyEmailHash` using `hash_equals`.
  - Bounded input code length to `MAX_CODE_LENGTH = 64`.
- **OWASP ASVS Project Export / Import Hardening**:
  - Refactored `ProjectExportImportController` with localized responses under `project.export_import.*`.
  - Added length validation on new project names (`max 150 characters`).
  - Integrated `EndUserCustomFieldsSanitizer` in `EndUserImportHandler` to scrub imported `custom_fields` against prototype pollution and reserved system keys.
  - Enforced email format and length validation on imported user records.
- **Strict 4-Language Translation Parity**:
  - Added 32 new localized keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
- **Automated Test Suite**:
  - Expanded test suite to **1,046 tests / 4,314 assertions / 0 failures** (+18 new automated unit and functional tests).

---

## [1.26.83] — 2026-11-05

### Security, Webhook Anti-SSRF, Social Auth Rate Limiting & EndUser Schema Hardening
- **WebhookService & Outbound SSRF Protection**:
  - Implemented standalone `WebhookService` consolidating outbound webhook security, SSRF validation, DNS Pinning, 0-redirect enforcement, strict 5s/10s timeouts, and HMAC SHA256 signing.
  - Refactored `SendWebhookMessageHandler` and `WebhookController` to route validation and dispatch through `WebhookService`.
  - Added HTTP method validation (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`) and safe options binding in `CallWebhookHandler`.
  - Replaced all raw strings in `WebhookController` with translatable keys under `webhook.*`.
- **Targeted Rate Limiting on OAuth Social Endpoints**:
  - Defined dedicated `social_auth_limiter` (sliding window, 15 req/60s in production, 10,000 req/60s in test).
  - Applied targeted rate limiting to `/connect/{provider}` in `SocialLoginController` with localized 429 response.
  - Applied targeted rate limiting to `/api/{projectId}/auth/social/{provider}` GET (`social_url`) and POST (`social_login`) in `EndUserAuthController`.
- **EndUserSchemaController Hardening & Input Bounds**:
  - Whitelisted allowed field types (`ALLOWED_TYPES`: 24 supported types) with strict rejection of unsupported/malicious types.
  - Protected system slugs and attributes (`RESERVED_SLUGS`: 31 reserved identifiers) against creation or hijacking.
  - Added strict slug format validation (`/^[a-z][a-z0-9_]*$/`) and length limits (max 100 characters).
  - Bounded field names (max 100 characters, no ASCII control characters).
  - Enforced deep option validation: max 64KB JSON payload, enumeration choice bounds (max 500 choices, scalar values), number/rating min-max bounds, and target collection relation normalization.
  - Protected system fields (`isSystem=true`) against unauthorized modification and deletion.
  - Fully localized all error and status responses using Symfony `TranslatorInterface` with keys under `enduser.schema.*`.
- **Strict 4-Language Translation Parity**:
  - Added 40 new localized keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
- **Automated Test Suite**:
  - Expanded test suite to **1,028 tests / 4,230 assertions / 0 failures** (+20 new automated unit and functional tests).

---

## [1.26.82] — 2026-11-05

### Security, OAuth RFC 7636 PKCE & Custom Fields Hardening
- **SocialLoginService OAuth & PKCE RFC 7636 Hardening**:
  - Added native support for RFC 7636 PKCE (`code_challenge`, `code_challenge_method`, `code_verifier`) across Google, Microsoft, GitHub, GitLab.
  - Strict validation on authorization code length (max 2048 chars), rejection of control characters / null bytes.
  - Avatar URL sanitization to only permit `http://` and `https://` schemas, preventing `javascript:` and SSRF vector injection.
  - Username sanitization with control character removal and 100 character bounds.
  - Forwarded PKCE parameters through `EndUserAuthController::getSocialUrl` and `EndUserAuthController::socialLogin`.
- **PasswordPolicyValidator Dynamic I18N**:
  - Injected `TranslatorInterface` with fallback mechanism across `validate()`, `validateAdminPassword()`, and `validateRotation()`.
  - Added 12 localized error keys under `password.policy.*` with dynamic parameter interpolation (`%min%`, `%max%`).
- **EndUserCustomFieldsSanitizer Service & Mass-Assignment Defense**:
  - Created standalone `EndUserCustomFieldsSanitizer` defending against prototype pollution (`__proto__`, `constructor`, `prototype`), operator injection (`$`), and internal attribute modification (`roles`, `password`, `tokenVersion`, `twoFactorSecret`).
  - Strict limits: max depth 5, max 100 keys, max 64KB JSON payload, sanitization of null bytes and ASCII control characters.
  - Integrated into `EndUserAdminController` (`create`, `update`) and `EndUserAuthController` (`register`, `updateMe`).
- **EndUserAdminController I18N**:
  - Injected `TranslatorInterface` and replaced all hardcoded responses with translatable keys under `enduser.admin.*`.
- **Strict Translation Parity**:
  - Added 28 new translation keys across English, French, Spanish, and Arabic dictionaries, bringing total dictionary size to **2,000 keys** with 100% parity (0 missing keys).
- **Automated Test Suite**:
  - Expanded test suite to **1,008 tests / 4,158 assertions / 0 failures**.

---

## [1.26.81] — 2026-11-05

### Security & Strict I18N Hardening
- **PreviewController Hardening & Complete I18N**:
  - Injected `TranslatorInterface` with fallback mechanism across all preview endpoints (`token`, `validateUrl`, `content`).
  - Added strict payload size bounding (max 1MB) on `validateUrl`.
  - Replaced 23 hardcoded error and validation messages with parameterized translation keys.
- **FormPublicController Security Constraints & I18N**:
  - Autowired `formSubmitLimiter` (`limiter.form_submit`) with sliding window protection against flood submissions.
  - Added strict UUIDv4 format check on `projectUuid` and regex validation (`/^[a-zA-Z0-9_\-]{1,128}$/`) on `slug`.
  - Enforced 1MB request payload limit and migrated 6 error responses to translation dictionaries.
- **RedirectPublicController Open-Redirect SSRF Defense & I18N**:
  - Injected `SsrfProtectionService` to block external redirects pointing to private, loopback, or cloud-metadata destinations.
  - Added CRLF and control character rejection on path query parameter (400 Bad Request).
  - Wired `redirect_limiter` rate limiting and migrated all responses to translation keys.
- **SeoPublicController Topology Defense & I18N**:
  - Added Anti-SSRF inspection on `previewUrl` before generating `robots.txt` Sitemap header, falling back to `https://example.com` to prevent private topology disclosure.
  - Added UUID regex validation and translated `NotFoundHttpException` message.
- **Translation Dictionaries Parity**:
  - Added 35 new translation keys across `en`, `fr`, `es`, `ar`, bringing the dictionary size to **1,933 keys** (100% parity, 0 missing).
- **Automated Test Suite**:
  - Added `tests/Controller/Cycle34HardeningTest.php` with 10 tests and 310 assertions.
  - Total PHPUnit test suite: **992 tests**, all passing green.

## [1.26.80] — 2026-11-04

### Security & Strict I18N Hardening
- **DirectUploadController Hardening & I18N**:
  - Injected `TranslatorInterface` with fallback mechanism for legacy test compatibility.
  - Eliminated all hardcoded strings (rate limit, missing project, auth required, invalid JSON, intent not found).
  - Added strict input boundary validation on multipart uploads: part numbers constrained between 1 and 10,000; non-negative file sizes.
- **AdminApi\\TokenController Hardening & I18N**:
  - Injected `TranslatorInterface` across all token endpoints (`create`, `update`, `delete`, `consumeRateLimit`).
  - Migrated 15 hardcoded error strings to parameterized translation keys across all 4 locales.
- **Api\\ProjectEmailController I18N Migration**:
  - Injected `TranslatorInterface` and migrated all contact form & admin email error strings from hardcoded French to translation dictionaries.
  - Parameterized attachment security errors, captcha errors, recipient validations, and mailer state diagnostics.
- **Translation Dictionaries**:
  - Added 37 new keys across all 4 languages (`en`, `fr`, `es`, `ar`), total now **1,898 keys**, 0 missing keys.
- **Automated Test Suite**:
  - Added `tests/Controller/Cycle33HardeningTest.php` with 9 tests and 338 assertions.
  - Total PHPUnit test suite: **982 tests**, all passing green.

## [1.26.79] — 2026-11-04

### Security & I18N Hardening
- **Null-user guard on `ProjectController::index()`**:
  - Fixed a **500 TypeError** (`findByMember(null)`) triggered by unauthenticated GET requests to `/api/projects`. Now returns `401 Access denied` via translator.
- **Complete I18N Migration (`InstallController`, `ProjectController`)**:
  - Injected `TranslatorInterface` into both controllers, eliminating all hardcoded strings (French & English mixed).
  - Migrated **21 error/success keys** in `ProjectController` and **16 UI/diagnostic keys** in `InstallController`.
  - All 37 new keys synchronized across all 4 locales (`en`, `fr`, `es`, `ar`) — total now **1,865 keys**, 0 missing.
  - `InstallController` diagnostic labels now translated: PHP version check, extension checks, writable directory checks.
  - HTML install page now uses `htmlspecialchars` on translated strings for XSS safety.
- **Automated Test Suite & Version Sync**:
  - Synced `HealthController::VERSION` to `1.26.79` matching `composer.json`.
  - Added `tests/Controller/Cycle32HardeningTest.php` with 9 tests covering translation key parity (all 4 locales), zero hardcoded strings assertion, 401 guard on `/api/projects`, and install diagnostics.
  - Full PHPUnit suite passing green (973/973 tests).

## [1.26.78] — 2026-11-04

### Security & Hardening
- **Anti-SSRF Protection on Deployment URLs (`DeploymentController`)**:
  - Enforced anti-SSRF validation (`validateSafeUrl`) on deployment webhook preview and run URLs, rejecting private IP ranges (RFC 1918), loopback (`127.0.0.1`, `localhost`, `::1`), link-local/cloud metadata (`169.254.169.254`), and reserved addresses.
- **Comment System Hardening & Anti-Deep-Nesting (`CommentController`, `rate_limiter.yaml`)**:
  - Bound dedicated sliding-window rate limiter (`comment_limiter`) protecting against comment spam and denial-of-service.
  - Added strict control character sanitization on comment bodies.
  - Implemented reply tree flattening to cap recursion and prevent unbounded recursive comment structures.
  - Enforced strict validation on comment status transitions (`open`, `resolved`).
- **Column Settings Hardening & HTML Sanitization (`ColumnSettingController`)**:
  - Added strict RFC 4122 UUID format validation on project identifiers.
  - Enforced array structure check and sanitized column identifiers (`strip_tags`, whitespace trimming, max 50 columns).
- **Strict I18N Migration & Translation Coverage (`McpController`, `DeploymentController`, `CommentController`, `ColumnSettingController`, `messages.{en,fr,es,ar}.php`)**:
  - Eliminated all hardcoded strings in `McpController`, `DeploymentController`, `CommentController`, and `ColumnSettingController`.
  - Added 39 new translation keys synchronized across all 4 locales (`en`, `fr`, `es`, `ar`), maintaining total key parity (1,820 keys, 0 missing).
  - Decoupled responses using direct `JsonResponse` instances to guarantee hermetic controller operation.
- **Automated Test Suite**:
  - Added `tests/Controller/Cycle31HardeningTest.php` with 10 comprehensive tests covering deployment anti-SSRF, HMAC signatures, column settings sanitization, comment rate limiting and nesting flattening, and MCP metadata.
  - Full PHPUnit suite passing green.

## [1.26.77] — 2026-11-04

### Security & Hardening
- **Email & Automation Flow CRLF Header Injection Defense (`SendEmailHandler`, `ProjectMailerService`, `SendProjectEmailMessageHandler`)**:
  - Implemented strict CRLF and control character sanitization (`preg_replace('/[\r\n\x00-\x1F\x7F]/', ...)` ) on email recipients (`to`), subject lines, and `replyTo` across both synchronous and asynchronous mail dispatchers.
  - Added strict email format validation (`FILTER_VALIDATE_EMAIL`) blocking forged headers like `to: victim@example.com\r\nBcc: evil@attacker.com`.
  - Added safe contextual interpolation in `SendEmailHandler` supporting template tags (`{{entry.title}}`, etc.) via `interpolateSafe()`.
- **FlowContext Safe Variable Boundary Hardening (`FlowContext`)**:
  - Refined sensitive variable name regexes with word boundary checks (`/\b(?:auth|key|hash|salt)\b/` and `^(?:auth_|auth$|key_|key$)`) to avoid false-positive stripping of legitimate CMS attributes (such as `author`, `authority`).
- **Complete I18N Migration & Translation Coverage (`GraphQLController`, `ImageTransformController`, `messages.{en,fr,es,ar}.php`)**:
  - Eliminated hardcoded strings in `GraphQLController` and `ImageTransformController`, migrating all error responses to Symfony Translator with complete fallback catalogs across French, English, Spanish, and Arabic dictionaries.
- **Doctrine Automation Repository Warning Elimination (`AutomationRepository`)**:
  - Replaced unsupported native JSON DQL syntax with cross-database PHP filtering, eliminating 9 Doctrine ORM query parser warnings on PHP 8.5+.
- **Automated Test Suite**:
  - Added `tests/Controller/Cycle30HardeningTest.php` with 7 comprehensive hardening tests.
  - Added `#[AllowMockObjectsWithoutExpectations]` across command test suites suppressing PHPUnit mock notices.
  - Full PHPUnit suite: 954 tests passing (3,091 assertions).

## [1.26.76] — 2026-11-04

### CI & Testing Infrastructure
- **PHPUnit Environment Configuration (`phpunit.xml.dist`, `.env.test`)**:
  - Added version-controlled `phpunit.xml.dist` with `KERNEL_CLASS=App\Kernel` and `APP_ENV=test`.
  - Configured Mercure test environment in `.env.test` with 32+ character JWT secret.

## [1.26.75] — 2026-11-04

### Security & Hardening
- **Universal Rate Limiter Service Binding (`config/services.yaml`)**:
  - Bound all 20+ specialized `RateLimiterFactory` services globally under `_defaults.bind` (`$previewLimiter`, `$realtimeLimiter`, `$directUploadLimiter`, `$webhookInboundLimiter`, `$tokenLimiter`, `$shareLimiter`, `$aiStreamLimiter`, `$aiLimiter`, `$searchLimiter`, `$reindexLimiter`, `$exportLimiter`, `$importLimiter`, `$graphqlLimiter`, `$hookLimiter`, `$mailerTestLimiter`, `$storageTestLimiter`, `$invitationLimiter`, `$notificationLimiter`, `$openapiLimiter`, `$mcpLimiter`), resolving a silent autowiring gap where optional limiters defaulted to `null`.
- **Preview & Share Cache/SSRF Defense (`PreviewController`, `PublicShareController`)**:
  - Enhanced SSRF defense in `PreviewController::validatePreviewTargetUrl`: strictly filtered target URL ports against dangerous services (blocking ports 21, 22, 23, 25, 53, 110, 143, 3306, 5432, 6379, 11211, 27017, 9200, 9300).
  - Hardened Live Preview draft content and Public Shared entries endpoints: enforced `Cache-Control: no-store, private` across both JSON and HTML responses to prevent unauthorized proxy or browser caching of draft or revoked content.
- **GraphQL Anti-DoS & Response Isolation (`GraphQLController`)**:
  - Enforced `Cache-Control: no-store, private` on all GraphQL query/mutation responses and Mercure subscription tokens.
  - Active rate limiting enforcement via bound `@limiter.graphql` factory.
- **Automated Test Suite**:
  - Added `tests/Controller/Cycle28HardeningTest.php` covering preview SSRF port filtering, preview/share `no-store` cache headers, and GraphQL rate limiting and response headers.
  - Full PHPUnit suite: 947 tests passing (3,069 assertions).

## [1.26.74] — 2026-11-04

### Security & Hardening
- **OpenAPI & Introspection Hardening (`AdminApiOpenApiController`, `ProjectOpenApiController`)**:
  - Implemented rate limiting (`openapi_limiter` at 60 req/min) on `/api/settings/admin-api/openapi.json` and `/api/{projectId}/openapi.json` to prevent dynamic specification generation DoS.
  - Added strict sanitization of schema names in `ProjectOpenApiController::toSchemaName` stripping unsafe characters.
  - Sanitized generated OpenAPI schemas by excluding soft-deleted fields (`isDeleted()`), sensitive internal fields (`password`, `hash`, `salt`, `secret`, `token`, `credential`, `auth_token`, `api_key`), and internal prefixes (`_internal_`, `_`, `system_`).
  - Enforced `Cache-Control: private, max-age=60, must-revalidate` on all OpenAPI spec endpoints.
- **Clickjacking, Cross-Origin Isolation & CORS Defense (`SecurityHeadersSubscriber`, `CorsSubscriber`)**:
  - Hardened anti-clickjacking defense: enforced `X-Frame-Options: SAMEORIGIN` globally across all standard endpoints.
  - Added studio-specific Content Security Policy: `frame-ancestors 'self'` on studio routes (`/projects/{id}/settings/studio`).
  - Added iframe embed exemption on public widget and share endpoints (`/forms/embed.js`, `/share/*`, `/embed/*`) allowing legitimate framing while keeping backend interfaces secure.
  - Enforced `Cross-Origin-Opener-Policy: same-origin-allow-popups` globally to isolate top-level browsing contexts while permitting OAuth popup flows.
  - Added `Cross-Origin-Resource-Policy` partitioning: `cross-origin` for public APIs and assets, `same-origin` for internal administrative pages.
  - Enhanced CORS allowed origin matching: supported apex domains for wildcard subdomains (e.g. `*.jambostack.site` matches `jambostack.site` and subdomains) and guaranteed `Access-Control-Allow-Credentials` is never granted with wildcard `*`.
- **Webhook & Automation Strict Contextual Sandboxing (`FlowContext`, `FlowValidator`, `TemplateHandler`, `SetHandler`, `AutomationEngine`)**:
  - Implemented strict variable validation in `FlowValidator`: capped graph variables at 50, required valid identifiers, and rejected forbidden/system keys (`_ENV`, `_SERVER`, `GLOBALS`, `APP_SECRET`, etc.).
  - Added contextual variable isolation in `FlowContext`: sandboxed user variables with `setVariable`, preventing access or mutation of internal configurations (`_node_config`) or global server environments.
  - Hardened `TemplateHandler`: stripped all global environment variable interpolation attempts (`_ENV.*`, `env.*`, `_SERVER.*`, `GLOBALS`, `APP_SECRET`, `DATABASE_URL`) and limited final rendered template size to 1MB.
  - Hardened `AutomationEngine`: sanitized debug outputs (`getSanitizedStepLog()`, `getSafeVariables()`) by automatically redacting sensitive credentials (passwords, tokens, secrets, auth headers) before persistence.
- **Automated Test Suite**:
  - Added 18 unit tests in `tests/Controller/Cycle27HardeningTest.php` validating OpenAPI rate limits, schema masking, COOP/CORP/CSP anti-clickjacking headers, CORS subdomain matching, and Flow variable sandboxing.
  - Full PHPUnit suite: 942 tests passing (3,042 assertions).

## [1.26.73] — 2026-11-04

### Security & Hardening
- **Import/Export Memory & Schema Protection (`AdminApi/ImportController`, `ProjectExportImportController`, `StructureImportHandler`, `ProjectImporter`)**:
  - Enforced request payload size limits (max 10MB) returning 413 on oversized JSON or multipart CSV uploads.
  - Added strict column structure validation in `ImportController::importCsv` (mapped target fields must exist in collection, max 100 columns, max 64KB per cell).
  - Implemented stream chunking and entity memory management in `ImportController`: detaching persisted entities (`$em->detach()`) after flushes and triggering garbage collection to prevent memory exhaustion during bulk imports.
  - Added support for streamed CSV file uploads (`processCsvFileStream`) with row chunking and bounded limits.
  - Enforced 50MB archive size ceiling and ZIP format validation (`validateUploadedArchive`) across project export/import endpoints (`importNew`, `importPreview`, `importMerge`).
  - Added file size caps on manifest and payload files (manifest <= 5MB, structure <= 10MB, content <= 50MB, end_users <= 20MB).
  - Hardened `StructureImportHandler`: strictly validated collection and field slugs/names, and validated field types against `SchemaProvisioner::ALLOWED_TYPES`.
  - Capped content entries in `ContentImportHandler` (max 10,000 entries).
- **Strict Multi-Tenant Isolation & Bounded Pagination (`AuditController`, `NotificationController`)**:
  - Enforced cross-project API token isolation in `AuditController`: blocked requests where token project mismatches queried project with 403 Forbidden.
  - Required `read` capability for API tokens querying project audit logs.
  - Bounded audit log pagination: limit capped at 100, offset capped at 10,000.
  - Added tenant-aware filtering (`project` / `project_uuid`) in `NotificationController` with project membership verification (403 Forbidden for unauthorized projects).
  - Added project-scoped unread count and `readAll` support in `NotificationController`.
  - Bounded notification pagination: page capped at 1000, per_page capped at 50.
- **Direct Upload Orphan Expiration & Cleanup (`DirectUploadController`, `DirectUploadService`, `AssetUploadIntentRepository`, `PurgeExpiredTokensCommand`)**:
  - Added automatic expiration handling and cleanup for pending upload intents in `DirectUploadService::cleanupExpiredIntents`.
  - Added remote S3 multipart abort handling on expired intents to prevent orphan chunk accumulation and storage billing.
  - Added opportunistic background cleanup during intent creation in `DirectUploadController::createIntent`.
  - Added `POST /api/projects/{projectUuid}/media/direct-upload/cleanup-orphans` endpoint for manual project-scoped orphan cleanup.
  - Integrated upload intent cleanup and old intent deletion into `PurgeExpiredTokensCommand` with dry-run support.
- **Automated Test Suite**:
  - Added comprehensive security test suite `Cycle26HardeningTest.php` (11 tests, 52 assertions).
  - Full test suite passed with 924 tests and 2968 assertions.

## [1.26.72] — 2026-11-04

### Security & Hardening
- **Realtime Event Batching & Mercure Fallback Defense (`RealtimeController`, `MercureFallbackController`)**:
  - Enforced batch event capping (max 100 events per poll) with configurable `limit` parameter in `RealtimeController::poll` to prevent memory blowup and latency DoS during short-polling.
  - Added rate limiting (`realtime_limiter`) to `MercureFallbackController` to guard `/.well-known/mercure` from connection flooding and resource exhaustion while preserving CORS OPTIONS handling.
- **End-User Social Auth Hardening & 2FA Flow (`EndUserAuthController`)**:
  - Added rate limiting (`login_limiter`) and body payload size validation (max 1MB) on `socialLogin`.
  - Added strict account activity verification (`!$endUser->isActive()`) in `socialLogin` preventing banned or pending accounts from authenticating via OAuth providers.
  - Repaired 2FA workflow in `socialLogin`: generated signed JWT two-factor tokens (`createTwoFactorToken`) and dispatched verification email when method is email, matching the primary login workflow.
  - Added session recording (`createSession`) on successful social logins.
  - Added `GET /api/{projectId}/auth/social/{provider}` endpoint returning authorization URLs with CSRF states.
- **End-User Multi-Tenant Admin & Token Revocation (`EndUserAdminController`)**:
  - Implemented automatic and immediate revocation of all active sessions (`revokeAllForEndUser`) and refresh tokens upon banning or deleting end-users.
  - Added input validation & sanitization: username bounded to 100 characters with control character rejection, and custom fields payload capped at 64KB.
- **Personal Access Token Hardening (`PersonalAccessTokenController`)**:
  - Integrated `token_management_limiter` rate limiting on personal access token creation.
  - Enforced a 50-token active ceiling per user to prevent unbounded token accumulation and database bloat.
  - Added strict name sanitization (max 255 chars, control character rejection) and strict scope whitelist validation.
- **Automated Test Suite**:
  - Added comprehensive security suite `Cycle25HardeningTest.php` (8 tests, 45 assertions).
  - Maintained 100% pass rate across the full test suite (909 tests, 2916 assertions).

## [1.26.71] — 2026-11-04

### Security & Hardening
- **Strict API Token Scope Partitioning (`ApiTokenChecker`, `EnsureProjectMemberSubscriber`, `ProjectAwareControllerTrait`)**:
  - Implemented strict HTTP method and capability partitioning (`isAllowedForMethod`) in `ApiTokenChecker` (GET/HEAD requires `read`, POST requires `create`, PUT/PATCH requires `update`, DELETE requires `delete`).
  - Read-only tokens (`abilities = ['read']`) are strictly blocked from write/mutation actions across all project UUID routes.
  - Hardened unauthenticated fallback in `EnsureProjectMemberSubscriber` against missing or unauthenticated user sessions.
  - Added wildcard scope (`*`) support to `ApiToken::can()`.
- **Live Preview Target URL & TTL Defense (`PreviewController`, `PreviewTokenService`)**:
  - Enforced strict bounds on preview token TTL (60s <= TTL <= 86400s) during generation and validation.
  - Added strict anti-SSRF validation (`validatePreviewTargetUrl`) in `PreviewController`: verified valid HTTP/HTTPS schemes, banned credentials, neutralized CRLF characters, and validated hosts against private/reserved IPs via `SsrfProtectionService`.
  - Added `#[Route('/validate-url')]` endpoint for frontend live preview target verification.
  - Added preview settings validation (`preview_url`, `preview_mode`, `preview_enabled`) to `ProjectController::update`.
- **Share Expiration, Multi-Tenant Defense & Rate Limiting (`ShareController`, `PublicShareController`, `ShareRepository`)**:
  - Enforced rate limiting (`share_limiter`) across `ShareController::store`, `index`, and `destroy`.
  - Enforced strict multi-tenant cross-check between shared entry and share entity in `PublicShareController::show`.
  - Added `countExpired()` and `purgeExpired()` in `ShareRepository` and integrated public shares into `PurgeExpiredTokensCommand`.
- **Automated Test Suite**:
  - Added comprehensive unit and integration suite `Cycle24HardeningTest.php` (10 tests, 68 assertions).
  - Maintained 100% pass rate across the full test suite (901 tests, 2871 assertions).

## [1.26.70] — 2026-11-04

### Security & Hardening
- **Multi-Tenant Project & Content Authorization (`CommentController`, `ColumnSettingController`)**:
  - Enforced `denyAccessUnlessGranted('project.view')` on `CommentController::index` and `CommentController::delete`.
  - Enforced `denyAccessUnlessGranted('content.update')` on `CommentController::create`, `CommentController::update`, and `CommentController::resolve`, ensuring fine-grained role-based permission validation instead of relying solely on entry existence or generic project membership.
  - Enforced `denyAccessUnlessGranted('project.view')` on `ColumnSettingController::show` and `ColumnSettingController::update`, stopping cross-tenant collection enumeration and unauthorized column customization.
- **System Template Protection & Resource Capping (`CollectionTemplateController`)**:
  - Restricted system collection template creation (`POST /api/collection-templates`) to authenticated administrators (`ROLE_ADMIN`).
  - Added strict bound checks capping collection templates to a maximum of 100 fields on direct creation and save-from-collection (`POST /api/collection-templates/from-collection/...`), defending against resource exhaustion.
- **XML Injection & CRLF Defense in SEO Services (`SitemapGenerator`, `SeoPublicController`)**:
  - Hardened `SitemapGenerator`: enforced strict XML entity escaping (`ENT_XML1 | ENT_QUOTES`) across all XML output elements (`loc`, `lastmod`, `changefreq`, `priority`), neutralizing potential XML injection attacks via malicious collection/entry slugs.
  - Hardened `SeoPublicController::robots`: sanitized preview URL and project UUIDs against CRLF injection (`\r`, `\n`) and URL scheme tampering, neutralizing header injection, HTTP response splitting, and rogue crawler directive injection.
- **Automated Test Suite**:
  - Added comprehensive `Cycle23HardeningTest.php` covering all authorization checks, field caps, XML escaping, and anti-CRLF protections (11 tests, 30 assertions).
  - Maintained 100% pass rate across the full test suite (891 tests, 2803 assertions).

## [1.26.69] — 2026-11-04

### Security & Hardening
- **SSRF & SMTP Egress Protection (`SsrfProtectionService`, `ProjectMailerSettingsController`)**:
  - Added `assertSafeHost()` and `isSafeHost()` methods in `SsrfProtectionService` validating hostnames and IPs against private/reserved ranges (RFC 1918, CGNAT, 6to4, multicast), loopback, evasive IP representations, and Cloud metadata services (169.254.169.254, `metadata.google.internal`, `host.docker.internal`).
  - Hardened `ProjectMailerSettingsController`:
    - Enforced host SSRF validation via `SsrfProtectionService`.
    - Whitelisted legitimate SMTP ports (`25`, `465`, `587`, `2525`) and encryption protocols (`tls`, `ssl`, null).
    - Enforced strict length limits and rejected control characters/CRLF in hostname, username, and password.
    - Sanitized and validated `from_email` against email header injection (CRLF injection) and RFC email specification.
- **Open-Redirect & URL Scheme Defense (`RedirectController`, `RedirectPublicController`)**:
  - Added unified `validateRedirectPaths()` in `RedirectController`:
    - Rejected dangerous schemes (`javascript:`, `data:`, `vbscript:`, `file:`, `ftp:`, `blob:`).
    - Blocked protocol-relative and backslash bypass attempts (`//`, `/\\`, `\\\\`, `\\/`).
    - Enforced ReDoS bounds on pattern redirects (pattern regex capped at 256 characters) and path length limits (2048 characters).
    - Blocked CRLF response splitting in both target paths and public resolution.
  - Hardened `RedirectPublicController::resolve`: validated resolved destination safety prior to issuing redirect responses.
- **Deployment Webhook Hardening (`DeploymentController`)**:
  - Enforced a 1MB payload size cap returning 413 Payload Too Large against memory exhaustion DoS attacks.
  - Enforced strict hex formatting on `commit_sha` (`^[a-fA-F0-9]{4,40}$`).
  - Enforced control character and CRLF checks on branch names.
  - Restricted preview and run URLs to `http` and `https` schemes.
- **Form Builder Boundary Hardening (`FormController`)**:
  - Bounded form field definitions to 100 fields max and multi-step forms to 50 steps max.
  - Capped settings payload to 64KB.
  - Enforced strict alphanumeric slug formatting (max 128 characters) and form name limits (max 255 characters, no CRLF/control characters).
- **Defense-in-Depth Authentication (`CollectionController`, `FieldController`, `ShareController`, `VersioningController`)**:
  - Enforced explicit 401 unauthenticated challenge when neither an authenticated `User` nor an authenticated `_project` attribute exists on incoming requests.
- **Test Coverage**:
  - Added `tests/Controller/Cycle22HardeningTest.php` with 16 automated test suites covering all cycle 22 defenses.

## [1.26.68] — 2026-11-04

### Security & Hardening
- **Password Policies & Lifecycle for Administrators & Team Members (`PasswordPolicyValidator`, `User`, Controllers)**:
  - Extended `PasswordPolicyValidator` to support all `PasswordAuthenticatedUserInterface` accounts, covering both `User` (administrators, staff, team members) and `EndUser`.
  - Added `validateAdminPassword()` enforcing OWASP ASVS Level 2 baseline:
    - Minimum length bounded (default 8, configurable).
    - Maximum length bounded at 4096 bytes (anti-DoS against bcrypt / argon2 hash amplification attacks).
    - Blacklist rejection of common dictionary passwords (`COMMON_PASSWORDS`) on all administrative accounts.
    - Contextual checks rejecting passwords containing the user's email prefix (>= 4 chars) or full name.
    - Character diversity validation (uppercase, lowercase, digits).
  - Added anti-reuse rotation rule: `validateRotation()` ensures new passwords differ from the current existing password on update and reset.
  - Added `password_changed_at` tracking to `User` entity, migration `Version20261104170000.php`, and serialized in user management endpoints.
  - Added password lifecycle / age calculation helpers: `getPasswordAgeDays()` and `isPasswordExpired()`.
  - Hardened endpoints:
    - `Settings\PasswordController::update`: validates current password, verifies rotation / non-reuse, validates admin complexity, updates `passwordChangedAt`.
    - `Auth\RegisteredUserController::store`: validates admin password policy, sets `passwordChangedAt`.
    - `Auth\NewPasswordController::store`: validates admin password policy, prevents resetting to current password, sets `passwordChangedAt`.
    - `UserManagement\UserController::store` & `update`: validates email before password, checks length bounds, validates admin password policy, checks non-reuse on update, sets `passwordChangedAt`.
    - `InstallController::setupAdmin`: validates initial super admin password against common weak credentials and length boundaries.
- **Automation Export/Import & Flow Graph Integrity (`AutomationExportImportService`, `FlowValidator`, `AutomationController`)**:
  - Implemented `AutomationExportImportService` supporting structured JSON export with SHA-256 canonical integrity checksum:
    - Single automation export: `GET /api/projects/{projectUuid}/automations/{id}/export`.
    - Project automation bundle export: `GET /api/projects/{projectUuid}/automations/export-all`.
    - Safe import endpoint: `POST /api/projects/{projectUuid}/automations/import` with 2MB payload cap, anti-tampering checksum verification, project isolation, and reset of runtime state.
  - Hardened `FlowValidator`:
    - Enforced anti-DoS resource boundaries: `MAX_NODES = 100`, `MAX_EDGES = 250`, `MAX_ID_LENGTH = 64`.
    - Sanitized node ID format (`^[a-zA-Z0-9_\-]+$`) blocking injection, control characters, and null bytes.
    - Verified unique node IDs, valid node and edge structures, and verified type resolution against `NodeRegistry`.
    - Acyclic DAG check via Kahn's topological sort and orphan non-trigger node rejection.
  - Hardened `AutomationController::store` and `update` to strictly validate `flow_graph` with `FlowValidator` before database persistence.

---

## [1.26.67] — 2026-11-04

### Security & Hardening
- **Webhook Management & Secret Key Hardening (`WebhookController`)**:
  - Enforced strict project authorization on all endpoints (`index`, `show`, `create`, `update`, `delete`, `logs`, `test`, `rotate-secret`). Anonymous requests and non-members without permission are rejected with 401/403.
  - Implemented automatic generation of 256-bit CSPRNG secrets (`whsec_` + 48 hex characters) when secret is omitted or `auto_generate_secret` is enabled, returning `plain_secret` in response.
  - Enforced secret length validation: custom secrets must be between 16 and 256 characters long to prevent weak offline-brute-forceable HMAC keys.
  - Added new secret rotation endpoint: `POST /api/projects/{projectUuid}/webhooks/{uuid}/rotate-secret`.
  - Hardened URL validation: maximum 2048 characters, strict http/https schemes only, and thorough SSRF protection via `SsrfProtectionService`.
  - Added robust 32-byte key derivation in `WebhookSecretService` via SHA-256 fallback when passphrase is provided.
- **Inbound Webhook Verification & Integrations (`WebhookInboundController`)**:
  - Added native support for **GitLab** webhooks via `X-Gitlab-Token` header and timing-attack-safe `hash_equals`.
  - Added native support for **Slack** webhooks via `X-Slack-Signature` (`v0=` . HMAC-SHA256) and `X-Slack-Request-Timestamp` with 300s anti-replay enforcement.
  - Added native support for `Authorization: Bearer <secret>` webhook delivery.
  - Added transparent on-the-fly decryption of trigger secrets prefixed with `enc:` via `WebhookSecretService`.
- **OAuth Social Login CSRF Protection (`SocialLoginController`, `SocialLoginAuthenticator`, `SocialLoginService`)**:
  - Generated and bound CSRF `state` parameter in `SocialLoginService::getAuthorizationUrlAndState()`.
  - Persisted state in user session (`oauth2state_{provider}`) upon OAuth start redirect.
  - Enforced constant-time CSRF state verification in `SocialLoginAuthenticator::authenticate()` and immediately consumed single-use state from session to neutralize OAuth Login CSRF and account connection attacks.
- **Project OAuth 2.1 PKCE Hardening (`ProjectOAuthController`)**:
  - Enforced validation of `code_challenge_method` (`S256` or `plain`) rejecting unsupported transform algorithms with 400 `invalid_request`.
- **Scheduled Retention & Inactive Session Purge (`PurgeExpiredTokensCommand`, Repositories)**:
  - Added `--dry-run` simulation flag to `app:purge-expired-tokens` to audit eligible records without deleting.
  - Added counting queries (`countExpired()`, `countInactive()`) across `ProjectAuthSessionRepository`, `PasswordResetTokenRepository`, `PersonalAccessTokenRepository`, `ApiTokenRepository`, `ProjectAuthRefreshTokenRepository`, and `ProjectAuthAuthorizationCodeRepository`.
  - Added production crontab configuration guide and script (`scripts/setup-o2switch-cron.sh`) for o2switch.

---

## [1.26.66] — 2026-11-04

### Fixed & Hardened
- **AdminApi Token Anti-Privilege Escalation (`AdminApi\TokenController`)**:
  - Closed privilege escalation vulnerability where PAT holders could create or mutate tokens with scopes beyond those granted to the authorizer.
  - Enforced strict sub-scoping validation: child tokens cannot exceed authorizing token's scope boundaries, and administrative scopes (`*`, `tokens:manage`) require authorizing tokens to possess them.
  - Added strict name format validation: non-empty, capped at 255 chars, control characters rejected.
  - Added `token_management_limiter` rate limiter (30 req / 60 seconds) on token management endpoints.
- **AI Rate Limiting & Input Size Defense (`AiController`, `ContentAiStreamController`)**:
  - Implemented `ai_limiter` (30 req / 60s) across all synchronous AI endpoints (`generate`, `translate`, `summarize`, `seo`, `suggestSchema`).
  - Implemented `ai_stream_limiter` (15 req / 60s) on streaming LLM SSE endpoints (`/api/projects/{uuid}/ai/stream`, `/admin/api/content-ai/stream`).
  - Capped input payload lengths: 10k characters on briefs, 50k on text/content, and 30k on streaming prompts, neutralizing buffer exhaustion and LLM billing DoS.
- **Search Controller API Token Authorization & Rate Limiting (`SearchController`)**:
  - Aligned project authorization check to inspect `_project` request attribute, resolving 403 authorization failures when accessing search via Bearer API tokens.
  - Added dedicated rate limiters: `search_limiter` (60 req / 60s) and `search_reindex_limiter` (5 req / 300s) to protect table-scanning and Meilisearch sync operations.
  - Updated `EnsureProjectMemberSubscriber` to resolve project identifiers passed as `{project}` route parameters.
- **Notification Controller Link Sanitization & Deletion (`NotificationController`)**:
  - Implemented strict URI scheme filtering on notification links, dropping dangerous `javascript:` and `data:` schemes while preserving valid relative paths and HTTPS URLs.
  - Added `DELETE /api/notifications/{id}` endpoint with recipient ownership validation.
  - Added `notification_limiter` rate limiter (60 req / 60 seconds).
- **Password Length Defense Against Hashing DoS (`UserController`, `RegisteredUserController`, `NewPasswordController`)**:
  - Enforced maximum password length of 4096 bytes across user creation, user update, registration, and password reset routes to mitigate CPU-exhaustion DoS against bcrypt/Argon2id.
- **Automated Test Coverage**:
  - Added comprehensive `Cycle19HardeningTest` suite (10 tests, 40 assertions) covering all new security controls.

---

## [1.26.65] — 2026-11-04

### Fixed & Hardened
- **CSPRNG for A/B Test Variant Selection (`AbTestManager::resolveVariant`)**:
  - Replaced weak `mt_rand()` / `mt_getrandmax()` with cryptographically secure `random_int()` for A/B test variant selection, eliminating predictable variant distribution via PRNG state recovery.
- **Information Disclosure Prevention (`DeploymentController::systemInfo`)**:
  - Removed `'php'` (PHP version) and `'environment'` (runtime environment) fields from the public `/api/system/info` endpoint to reduce server fingerprinting and reconnaissance surface.
  - Added explanatory comment documenting the intentional omission.
- **Install Endpoint Brute-Force Protection (`InstallController::setupAdmin`)**:
  - Added `install_setup_limiter` rate limiter (5 req / 5 minutes) on the `/install/setup-admin` POST endpoint to prevent automated admin setup attempts on fresh instances.
  - Rate limiting applied before the user-count guard for maximum protection.
- **Trusted Proxies Configuration (`framework.yaml`)**:
  - Added `trusted_proxies` and `trusted_headers` configuration referencing `SYMFONY_TRUSTED_PROXIES` environment variable, preventing IP header spoofing via `X-Forwarded-For` in environments behind load balancers.
- **Automated Test Coverage**:
  - Added `Cycle18HardeningTest` covering all new defenses (8 assertions).

---

## [1.26.64] — 2026-11-04

### Fixed & Hardened
- **Media Crop Upload Defense & Image Validation (`MediaController::crop`)**:
  - Closed arbitrary upload vulnerability in `MediaController::crop`: enforced full `UploadSecurityValidator::validateUploadedFile` lifecycle (quota validation, polyglot PHP execution defense, MIME verification, filename sanitization).
  - Restricted cropped uploads strictly to validated image formats (`jpg`, `jpeg`, `png`, `webp`, `gif`, `avif`).
- **Realtime Fallback Polling Rate Limiting & Path Traversal Guard (`RealtimeController`, `MercurePublisher`)**:
  - Implemented `realtime_limiter` rate limiter (120 req / 60 seconds) on `/realtime` polling and `/realtime/token` endpoints to prevent disk I/O exhaustion and file-lock DoS.
  - Added strict UUID format verification on `RealtimeController` routes and sanitized file path generation in `MercurePublisher::appendToJsonl`.
- **Cross-Project & Cross-Entry Comment Resolution Guard (`CommentController::resolve`)**:
  - Hardened `CommentController::resolve` to verify matching entry and project isolation parameters, preventing resolving comments belonging to other collections or projects.
- **Media Folder & Column Settings Input Hardening (`MediaFolderController`, `ColumnSettingController`)**:
  - Enforced 150-char maximum length and control character rejection on folder creation and update operations.
  - Validated `visible_columns` array format and capped payload to 50 items with sanitization.
- **Automation Node Catalog Auth Protection & Versioning i18n (`FlowController`, `VersioningController`)**:
  - Added `#[IsGranted('ROLE_USER')]` to protect `/api/automations/node-catalog` from unauthenticated scanning.
  - Enforced version bounds on `VersioningController` and translated versioning messages across EN, FR, ES, and AR.
- **Automated Test Coverage**:
  - Added comprehensive automated test suite `Cycle17HardeningTest` covering all new defenses.

---

## [1.26.51] — 2026-11-04

### Fixed & Hardened
- **Data Export & Bulk Import Rate Limiting (`ProjectExportImportController`, `ImportController`)**:
  - Implemented dedicated rate limiters for resource-intensive export and import operations:
    - `export_limiter`: sliding window policy capped at 10 requests / minute.
    - `import_limiter`: sliding window policy capped at 5 requests / minute.
  - Applied throttling across `export()`, `importNew()`, `importPreview()`, `importMerge()`, and Admin API CSV batch import (`importCsv()`).
  - Graceful identification falling back to client IP for unauthenticated or public pre-flight callers.
- **Production File Permissions Hardening (`deploy.py`, o2switch runtime)**:
  - Audited and restricted permissions across the o2switch shared environment:
    - Restricted `.env`, `.env.local`, and backup configuration files to `600` (read/write by application user only).
    - Hardened `var`, `var/cache`, and `var/log` directories to `700`.
  - Added automated permission hardening step to remote OpenSSH deployment pipeline (`deploy.py`).
- **Automated Test Coverage**:
  - Added unit and functional test coverage for rate limiter rejection on bulk exports and imports (`ProjectExportImportRateLimiterTest`).

---

## [1.26.50] — 2026-11-04

### Fixed & Hardened
- **Token Retention & Session Purge Engine (`PurgeExpiredTokensCommand`)**:
  - Expanded `app:purge-expired-tokens` to automatically purge all categories of expired and revoked credentials:
    - Expired Password Reset Tokens (`PasswordResetTokenRepository::deleteExpired`)
    - Expired Personal Access Tokens (`PersonalAccessTokenRepository::deleteExpired`)
    - Expired Project API Tokens (`ApiTokenRepository::deleteExpired`)
    - Expired and revoked OAuth refresh tokens (`ProjectAuthRefreshTokenRepository::deleteExpired`)
    - Expired and used OAuth authorization codes (`ProjectAuthAuthorizationCodeRepository::deleteExpired`)
    - Stale / inactive end-user sessions (`ProjectAuthSessionRepository::deleteInactive`) with configurable retention window via `--session-days`.
- **End-User Authentication & Refresh Token Project Isolation (`EndUserAuthController`, `EndUserJwtAuthenticator`)**:
  - Fixed UUID string comparison in `EndUserAuthController::refresh()`, preventing legitimate refresh requests from being rejected by integer casts.
  - Hardened `EndUserJwtAuthenticator` to rigorously enforce tenant isolation: tokens presented on project routes (`/api/{projectId}/...`) must match the project UUID embedded in both the user entity and token claims (`pid`), preventing cross-tenant JWT reuse.
  - Added RFC 7519 `sub` standard claim alongside `euid` across `EndUserJwtService` and `ProjectOAuthController`.
- **OAuth 2.0 Dynamic Token TTL (`ProjectOAuthService`)**:
  - Configured `expires_in` to dynamically resolve custom project TTL settings (`jwtAccessTtl`) instead of falling back to static constants.
- **Automated Security Verification**:
  - Added full test coverage for token refresh rotation, cross-tenant refresh rejection, logout token version invalidation, cross-tenant `/me` protection, and purge console command.

---

## [1.26.49] — 2026-11-04

### Fixed & Hardened
- **SSRF Prevention & Outbound URL Safety (`WebhookController`, `SendWebhookMessageHandler`)**:
  - Hardened `WebhookController::validateWebhookUrl` to resolve and block DNS records pointing to private or reserved IP ranges (RFC 1918, link-local, loopback, multicast) in addition to hostname checks, thwarting DNS-rebinding SSRF attacks.
  - Implemented pre-flight IP validation in `SendWebhookMessageHandler` before dispatching HTTP POST requests, immediately halting and logging outbound transmissions aimed at internal infrastructure.
- **AI Image Pipeline Security Alignment (`StudioController`)**:
  - Replaced unconstrained `@file_get_contents` in `executeGenerateImages` with the anti-SSRF hardened `downloadExternalImage` helper, strictly enforcing HTTPS, DNS resolution filtering, and timeout policies on all batch AI asset ingestion paths.
- **Admin cURL Protocol Restriction (`AppSettingsController`)**:
  - Added URL format validation and restricted cURL to HTTP/HTTPS protocols (`CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS`), preventing protocol smuggling attacks against local or external AI endpoints.
- **Automated Test Coverage (`CoreApiHardeningTest`)**:
  - Added test cases covering malformed UUIDs on webhooks and validation for invalid URLs and non-HTTP/HTTPS webhook schemes.

---

## [1.26.48] — 2026-11-04

### Fixed & Hardened
- **End-User Authentication & Token Tenant Isolation (`EndUserAuthController`)**:
  - Scoped user lookup in `verifyTwoFactor` and `refresh` strictly to the target project (`'project' => $project`), blocking cross-tenant token or 2FA challenge exploitation.
  - Aligned the route parameter `string $projectId` in `refresh()` with the controller prefix, preventing parameter mapping issues.
  - Wrapped project lookups across all auth endpoints (`register`, `login`, `verify-2fa`, `refresh`, `forgot-password`, `reset-password`, `social`) in safe `findProject()` with `try / catch (\Throwable)` returning 404 on malformed UUIDs.
- **Studio Controller UUID & Fallback Robustness (`StudioController`)**:
  - Protected all 11 studio routes (`listCollections`, `aiSchema`, `aiChat`, `applyEntries`, `listChatMessages`, `clearChatMessages`, `schemaApply`, `aiCapabilities`, `aiExecute`, `aiGenerateImage`, `generateTypes`) with safe `findProject()` lookups catching invalid UUID strings.
  - Secured `aiChat` SSE non-AI command fallback to guarantee array structures when decoding fallback content.
- **GraphQL & AI Endpoints Payload & UUID Safety (`GraphQLController`, `AiController`)**:
  - Added malformed UUID exception handling on the GraphQL endpoint returning clean 404 instead of 500 DBAL crashes.
  - Enforced strict array verification on POST bodies in `GraphQLController::handle`, rejecting non-array JSON scalars with HTTP 400 Bad Request.
  - Protected `AiController::findProject` with `try / catch (\Throwable)`.
- **Media & Asset Management Hardening (`MediaController`)**:
  - Protected `index`, `upload`, `bulkDestroy`, folder reassignment, and `findMedia` with centralized `resolveProject()` catching malformed UUID exceptions.
- **Project Governance & Settings Hardening (`ProjectController`, `ProjectSettingsController`)**:
  - Guarded `resolveProject` in `ProjectController` with `try / catch (\Throwable)` for all project membership and CRUD routes.
  - Added exception safety to `resolveWithTokenFallback` in `ProjectSettingsController`.
- **Shares, SEO & OpenApi Parameter Safety (`ShareController`, `SeoController`, `ProjectOpenApiController`, `ProjectEmailController`, `ApiDocsController`)**:
  - Wrapped project and entry lookups in `try / catch (\Throwable)` across shares, admin SEO scores/audit/bulk-update, OpenAPI spec generation, contact email, and Swagger UI.
- **Personal Access Token Scopes Validation (`TokenController`)**:
  - Enforced array validation on token `scopes` in both `create` and `update` endpoints returning 422 Unprocessable Content.
- **Ops & Deployment Automation (`deploy.py`, `public/deploy.php`)**:
  - Updated production deployment configuration for active o2switch infrastructure (`gupi7723@folina.o2switch.net`).
  - Added native OpenSSH subprocess runner fallback to `deploy.py` allowing zero-dependency deployments.
  - Hardened `public/deploy.php` by removing hardcoded fallback tokens and using constant-time `hash_equals` against environment configuration.

---

## [1.26.47] — 2026-11-04

### Fixed & Hardened
- **Project OAuth Cross-Tenant Leak Prevention (`ProjectOAuthController::userinfo`)**:
  - Enforced strict project tenant isolation on the `/api/v1/projects/{projectUuid}/oauth/userinfo` endpoint. Prevented end-users authenticated on one project from retrieving userinfo against another project's UUID, and added `try / catch (\Throwable)` around project resolution.
- **Webhook Inbound Scalar JSON Payload Protection (`WebhookInboundController::receive`)**:
  - Fixed an unhandled fatal `TypeError` (HTTP 500) occurring when incoming webhooks receive JSON scalar literals (e.g. `"string"`, `123`, `true`) instead of JSON objects/arrays by ensuring the decoded payload is always an array.
- **Tus Resumable Upload UUID & Profile Safety (`TusController`)**:
  - Protected all TUS endpoints (`create`, `patch`, `head`, `delete`, `finalize`) with safe `findProject()` lookups catching invalid UUID strings and returning 404 instead of throwing unhandled DBAL `ConversionException`.
  - Added exception safety around storage profile resolution in upload finalization.
- **Realtime Mercure & Long-Polling Hardening (`RealtimeController`)**:
  - Wrapped project lookups in `try / catch (\Throwable)` across both `/realtime/token` and `/realtime` poll endpoints.
- **Comments, Invitations & Deployments Integrity**:
  - `CommentController`: Guarded `parent_id` parameter against non-numeric inputs.
  - `InvitationController`: Case-insensitive email comparison and explicit User type safety.
  - `DeploymentController`: Exception safety on project lookup and guaranteed array payload decoding.
  - `McpController`: Protected project UUID lookup against malformed string exceptions.

---

## [1.26.46] — 2026-11-04

### Fixed & Hardened
- **Public & System Endpoint UUID Safety**:
  - Protected `FormPublicController`, `RedirectPublicController`, `SeoPublicController`, `SearchController`, `InsightsController`, `ImageTransformController`, `AuditController`, and `EnsureProjectMemberSubscriber` against malformed UUID parameters in route paths, preventing unhandled DBAL `ConversionException` and returning clean HTTP 404 responses.
- **Authentication & Password Recovery Payload Hardening**:
  - `TwoFactorChallengeController`: Supported both JSON payloads via `$request->getPayload()` and form post data with safe fallback, avoiding unparsed verification codes.
  - `RegisteredUserController`: Wrapped payload parsing in `try / catch (\Throwable)` returning HTTP 400 Bad Request on invalid JSON bodies.
  - `ConfirmablePasswordController`: Added explicit authentication verification returning HTTP 401 instead of null `TypeError`, and wrapped payload parsing to return HTTP 400 on malformed JSON.
  - `PasswordResetLinkController` & `NewPasswordController`: Wrapped string extraction in `try / catch (\Throwable)` with strict email validation.

---

## [1.26.45] — 2026-11-04

### Fixed & Hardened
- **System-wide Malformed JSON Exception Safety (`src/Controller/`)**:
  - Replaced improper `catch (\JsonException)` with `catch (\Throwable)` across all controllers handling `$request->toArray()`. Symfony throws `Symfony\Component\HttpFoundation\Exception\JsonException` (which extends `\UnexpectedValueException`), which previously bypassed `\JsonException` catches and resulted in 500 crashes instead of clean HTTP 400 Bad Request responses.
- **UUID DBAL Conversion Exception Prevention (`src/Controller/Api/`, `src/Controller/AdminApi/`, `src/Controller/`)**:
  - Wrapped project, entry, asset, and automation UUID lookups in `try / catch (\Throwable)` across `ContentController`, `AssetController`, `ProjectInfoController`, `ProjectAwareControllerTrait`, `CollectionController`, `FieldController`, `WebhookController`, `AutomationController`, `MediaFolderController`, and `MediaController` to cleanly return 404 on malformed UUID strings instead of bubbling unhandled DBAL `ConversionException` (500).
- **User & Super Admin Deletion Protections (`UserController`)**:
  - Added protection preventing deletion or bulk deletion of the last super administrator (`ROLE_SUPER_ADMIN`).
  - Added strict email format validation (`FILTER_VALIDATE_EMAIL`) upon user creation in `UserController::create`.
- **Role Integrity Protections (`RoleController`)**:
  - Prevented deleting or bulk deleting roles assigned to one or more active users to avoid foreign key constraint violations and orphaned accounts.
  - Added payload array type check on `permissions` in role updates and creations returning 422.

---

## [1.26.44] — 2026-11-04

### Fixed & Hardened
- **Live Preview Resilience & UUID Safety (`PreviewController`)**:
  - Replaced firewall redirect with explicit in-method authentication check returning 401 JSON for unauthenticated preview token generation.
  - Wrapped project and entry UUID lookups in `try / catch` returning 404 cleanly on malformed UUID strings.
- **Open Redirect Protection (`LocaleController`)**:
  - Validated referer header against the current host to prevent open redirect vulnerabilities when switching user locales.
- **User Profile Email Validation (`ProfileController`)**:
  - Enforced strict email format validation (`FILTER_VALIDATE_EMAIL`) on user profile updates before persisting.
- **PAT Scopes Expansion (`PersonalAccessTokenController`)**:
  - Expanded `ALLOWED_SCOPES` to include `'content:write'`, `'content:read'`, and `'*'`, allowing creation of PATs for full Admin API access from the dashboard.
- **JSON Payload Safety across Settings & Install (`InstallController`, `PersonalAccessTokenController`, `PasswordController`, `ProfileController`, `LocaleController`)**:
  - Caught `\Throwable` on `$request->toArray()` preventing unhandled Symfony JSON exceptions on malformed payloads.

---

## [1.26.43] — 2026-11-04

### Fixed & Hardened
- **Personal Access Token Wildcard Scope (`PersonalAccessToken`)**:
  - Added wildcard scope matching (`*`) in `PersonalAccessToken::can()`, resolving issue where tokens configured with `*` failed specific scope checks.
- **OAuth JSON Payload Support & DBAL UUID Protection (`ProjectOAuthController`)**:
  - Added support for `application/json` payloads on `/token` and `/revoke` endpoints in addition to form-urlencoded bodies.
  - Wrapped project UUID lookups in `try / catch` to return HTTP 404 cleanly on malformed UUID strings instead of throwing DBAL conversion exceptions.
- **Export & Import Project Resilience (`ProjectExportImportController`)**:
  - Wrapped project UUID lookup in `try / catch` returning 404 for invalid UUID strings across export and merge endpoints.
  - Replaced firewall redirect with explicit in-method authentication check returning 401 JSON for unauthenticated import requests.
- **CSV Import Validation (`AdminApi/ImportController`)**:
  - Enforced array type checks for `data` and `field_mapping` payloads returning 422 Unprocessable Entity instead of triggering PHP `TypeError` during iteration.
  - Caught `\Throwable` on request body JSON decoding.
- **Direct Upload & Inbound Webhook Safety (`DirectUploadController` & `WebhookInboundController`)**:
  - Standardized JSON decoding with `\Throwable` safety returning 400 Bad Request.
  - Protected automation and intent UUID lookups against malformed UUID conversion errors, returning 404.
- **Email Body Parsing Resilience (`ProjectEmailController`)**:
  - Caught `\Throwable` on `$request->toArray()` preventing unhandled Symfony JSON exception bubbling.

---

## [1.26.42] — 2026-11-04

### Fixed & Hardened
- **Markdown & Single-Quote Link Extraction (`LinkChecker`)**:
  - Enhanced regex to extract links with single quotes (`<a href='...'>`) as well as Markdown syntax (`[text](url)`), which previously went unscanned in markdown/richtext fields.
- **Share Controller JSON Safety (`ShareController`)**:
  - Wrapped request body parsing with `try / catch (\Throwable)` to return clean 400 Bad Request on malformed JSON rather than unhandled exceptions.
- **Deployment Endpoint Authentication (`DeploymentController`)**:
  - Replaced method attribute `#[IsGranted('IS_AUTHENTICATED_FULLY')]` with in-method JSON authentication check, returning clean 401 instead of a 302 redirect to HTML login.

---

## [1.26.41] — 2026-11-04

### Fixed & Hardened
- **EAV Field Type Safety & Normalization (`EavFieldHelperService`)**:
  - Normalized boolean values (`FILTER_VALIDATE_BOOLEAN`) to prevent PHP 8.4 `TypeError` when assigning string representations (`'1'`, `'0'`, `'true'`, `'false'`) to typed property `?bool $booleanValue`.
  - Wrapped date and datetime parsing in safe handler to catch `DateMalformedStringException` on invalid date strings, returning `null` safely.
  - Hardened JSON and default string fallback against array-to-string notices and uncaught decode errors.
- **Field Value Hydration (`FieldValueHydrator`)**:
  - Aligned decimal/number property hydration to string type (`?string $numberValue`) preventing `TypeError` on float assignments.
  - Normalized boolean, datetime, and JSON array structures consistently with EAV rules.
- **Brute Force Lockout Increment Bug (`ProjectAuthLockoutService`)**:
  - Fixed critical bug where `recordFailedAttempt` deleted the cache key before incrementing, resetting attempt count to 1 on every failure and disabling lockout protection.
  - Proper incrementation now enforces lockout after 5 failed attempts.
- **Realtime SSE & Polling Resilience (`RealtimeController`)**:
  - Replaced `createAccessDeniedException` with structured JSON 401 (`Authentication required`) and 403 (`Access denied`) responses to prevent Symfony `main` firewall from issuing 302 redirects to `/login` HTML, which triggered browser `EventSource` MIME type errors.
  - Encapsulated JWT token generation in `try/catch` to return graceful polling fallback (`200 OK` with `enabled: false`) instead of 500/503 server errors if Mercure is not configured.
- **Social Login Resilience & Project EndUser Scoping (`SocialLoginService` & `EndUserAuthController`)**:
  - Exposed `getProjectCredentials` to pass project-scoped OAuth credentials for EndUser social login, preventing unintended fallback to Admin credentials.
  - Added strict email validation in `findOrCreateAdminUser` and `findOrCreateEndUser` to reject accounts without valid email addresses.
  - Caught external OAuth provider exceptions in `SocialLoginAuthenticator` to redirect smoothly to login with error query params instead of uncaught 500 crashes.

---

## [1.26.40] — 2026-11-04

### Fixed & Hardened
- **Studio AI Endpoints JSON Safety (`StudioController`)**:
  - Validated JSON payload structure (`!is_array($body)` checks returning HTTP 400 Bad Request) across all Studio endpoints: `aiSchema`, `aiChat`, `applyEntries`, `applySchema`, `aiExecute`, and `aiGenerateImage`, preventing PHP type errors on malformed payloads.
- **Search Query Sanitization & SQL Wildcard Escaping (`SearchController` & `SearchService`)**:
  - Enforced a 500-character upper bound on search query parameter `q` to prevent memory bloat.
  - Escaped SQL LIKE wildcards (`%`, `_`) in `SearchService::fallbackDatabaseSearch` to prevent performance degradation and unintended broad matches during fallback database searches.

---

## [1.26.39] — 2026-11-03

### Fixed & Hardened
- **Versioning Safety & Diff Status (`VersioningController`)**:
  - Protected `VersioningController::findEntry` against DBAL/UUID conversion crashes on malformed UUID strings, safely returning HTTP 404 instead of uncaught 500 errors.
  - Aligned `diff` endpoint with HTTP semantics: returns HTTP 404 with error payload when requested version numbers do not exist.
- **Collection & Project Template Validation (`CollectionTemplateController` & `ProjectTemplateController`)**:
  - Enforced strict authentication (`401`) on `CollectionTemplateController::create`.
  - Added field definition validation preventing undefined array key notices when template fields lack names or slugs.
  - Sanitized project name application in `ProjectTemplateController::apply` to ensure empty string inputs fallback to the template default.
- **Admin Form Management Hardening (`Admin/FormController`)**:
  - Enforced mandatory non-empty form names (HTTP 422).
  - Auto-generated slugs from form names when omitted and enforced slug uniqueness per project with automatic deduplication.
  - Prevented empty name/slug updates and rejected duplicate slugs on form modification (HTTP 422).
- **Settings App JSON Safety (`Admin/AppSettingsController`)**:
  - Wrapped request body parsing in `try / catch (\JsonException)` and reused decoded JSON body for both app name and AI provider configurations.

---

## [1.26.38] — 2026-11-03

### Fixed & Hardened
- **Multi-Tenant Media & Transformation Security (`ImageTransformController` & `ImageTransformService`)**:
  - Closed multi-tenant access vulnerability in `ImageTransformController::canAccessMedia` where any logged-in user could access private media of other projects; restricted access strictly to project members and super admins (`$project->hasMember($user)`).
  - Sanitized background color parameter (`$bg`) against invalid or malicious color strings, enforcing strict hex color validation.
  - Wrapped image processing in `try / catch (\Throwable)` returning HTTP 422 instead of uncaught 500 error when image decoders or drivers fail.
- **Tus Resumable Upload Path Traversal Hardening (`TusServer` & `TusController`)**:
  - Enforced strict UUID v4 format assertions on `uploadId` across `TusServer` and `TusController`, rejecting directory traversal attacks (`../`) with HTTP 404/400.
- **AI Service Error Resilience (`AiController`)**:
  - Wrapped AI content generation, translation, summarization, SEO, and schema suggestion operations in try/catch blocks returning HTTP 400/422 with clear error messages instead of propagating 500 exceptions when AI providers fail or are unconfigured.
- **Global JsonException Safety**:
  - Hardened remaining resource and authentication controllers against malformed JSON bodies (`JsonException` -> HTTP 400): `ProjectSettingsController`, `EndUserAuthController`, `EndUserSchemaController`, `MediaController`, `PageController`, `SeoController`, and `Api\ContentController`.
  - Added expiration date validation in `ProjectSettingsController::createToken`, rejecting past or malformed `expires_at` dates (HTTP 422).

---

## [1.26.37] — 2026-11-03

### Fixed & Hardened
- **NotificationSubscriber Entity Property Access Fix (`NotificationSubscriber`)**:
  - Fixed fatal PHP 500 error `Error: Call to undefined method App\Entity\Project::getId()` on comment creation and content status update events. Replaced `$project->getId()` and `$entry->getId()` with `$project->id`, `$entry->id`, and null-safe `$entry->collection?->id`.
- **Comment Validation & Security Hardening (`CommentController`)**:
  - Replaced unvalidated empty comment strings with strict empty/whitespace-only body checks returning HTTP 422 Unprocessable Entity.
  - Restricted comment resolution (`resolve` action) to users with project access or system administrators (HTTP 403 Forbidden).
  - Protected payload decoding against malformed JSON bodies (`JsonException` -> HTTP 400 Bad Request).
- **Core Resource JSON Safety (`ProjectController`, `CollectionController`, `FieldController`, `ContentController`, `WebhookController`, `ColumnSettingController`, `MediaFolderController`)**:
  - Wrapped request body parsing in try/catch `JsonException` returning clean HTTP 400 Bad Request errors instead of uncaught 500 server errors on malformed payloads.
- **Unauthenticated Insights Protection (`InsightsController::summary`)**:
  - Added explicit user authentication check on `/api/insights/summary`, returning HTTP 401 instead of a fatal PHP `TypeError`.

---

## [1.26.36] — 2026-11-03

### Fixed
- **Silent Realtime Fallback & DevTools Console Cleanliness (`RealtimeController::token`)**:
  - Replaced HTTP 503 error response on `/api/projects/{uuid}/realtime/token` with HTTP 200 `{ enabled: false, token: null, hub_url: null, topics: [] }` when Mercure is not active.
  - Eliminates the red `GET /api/projects/.../realtime/token 503 (Service Unavailable)` error in browser DevTools network tab and console, allowing both legacy and modern frontend clients to seamlessly and silently engage REST polling.

---

## [1.26.35] — 2026-11-03

### Fixed
- **SSE EventSource MIME Type & Mercure Hub Fallback (`MercureFallbackController`, `RealtimeController`, `useRealtime`)**:
  - Fixed browser error `EventSource's response has a MIME type ("text/html") that is not "text/event-stream"` caused when the dashboard opened an `EventSource` connection to `/.well-known/mercure` on hosts without an active Mercure daemon.
  - Implemented `MercureFallbackController` on `/.well-known/mercure` to cleanly deliver `Content-Type: text/event-stream` with an `event: close` stream, preventing HTML MIME type aborts and 302 login redirects.
  - Added `MERCURE_ENABLED` environment flag (defaulting to `false`). When Mercure is disabled, `/api/projects/{uuid}/realtime/token` returns a 503 `enabled: false` status, allowing the frontend (`useRealtime`) to switch cleanly to REST polling without attempting to open a failing `EventSource`.
  - Added `PUBLIC_ACCESS` to `/.well-known/mercure` in `security.yaml` so EventSource requests are never intercepted by session-redirect firewalls.

---

## [1.26.34] — 2026-11-03

### Fixed & Hardened
- **Project Import Protection & Safe Zip Extraction (`ProjectExportImportController` & `ProjectImporter`)**:
  - Enforced full authentication (`IS_AUTHENTICATED_FULLY`) on project archive import endpoints (`/api/projects/import`, `/api/projects/import/preview`) and `project.manage` on merging.
  - Hardened `ProjectImporter::extractZip` against Zip Slip (directory traversal in archive entry names) and Zip Bombs (>5000 files, >250MB uncompressed).
  - Sanitized media import filename targets (`MediaImportHandler`) with `basename()` to eliminate path traversal risk.
- **User & Role Integrity Protection (`UserController`, `RegisteredUserController`, `RoleController`)**:
  - Added duplicate email validation on user updates and user registration returning 409 / 422 Conflict instead of unhandled DBAL 500 exceptions.
  - Added uniqueness checks on role creation and updates returning 422 Unprocessable Entity.
- **Personal Access Token Hardening (`PersonalAccessTokenController` & `AdminApi\TokenController`)**:
  - Validated `expires_at` date format and rejected past expiration dates (422) across both PAT management controllers.
- **Public Route & Captcha Consistency (`CaptchaController` & `EnsureProjectMemberSubscriber`)**:
  - Added canonical route alias `GET /api/projects/{projectUuid}/captcha`.
  - Exempted public project endpoints (captcha, public form submissions, sitemaps, robots.txt, redirect resolutions) in `EnsureProjectMemberSubscriber`.
- **JSON Parsing & Rate Limiting Hardening (`AdminApi\*`, `Settings\*`, `TwoFactorChallengeController`)**:
  - Protected all Admin API controllers (`ProjectController`, `CollectionController`, `FieldController`, `ImportController`, `TokenController`) and Settings controllers (`ProfileController`, `PasswordController`, `LocaleController`, `SecurityController`, `EndUserAdminController`) against uncaught `JsonException` on malformed request bodies.
  - Added rate limiting to 2FA email challenge sending (`TwoFactorChallengeController::sendEmail`).

---

## [1.26.33] — 2026-11-02

### Fixed & Improved
- **CDN Media Access Control & Query Token Resolution (`ImageTransformController` & `ApiTokenChecker`)**:
  - Closed metadata information disclosure on `/cdn/media/{uuid}/info` for projects with private API by requiring authentication or valid project tokens.
  - Extended `ApiTokenChecker::resolve` to support `?token=` and `?preview_token=` query parameters when the `Authorization: Bearer` header is absent, enabling `<img src="/cdn/media/...?token=...">` embedding in frontend apps.
- **Direct Upload Authorization (`DirectUploadController`)**:
  - Enforced `content.create` permission checks on presigned upload intent creation (`/api/projects/{projectUuid}/media/direct-upload/intent`) to prevent unauthorized generation of presigned S3 upload URLs.
- **Project & Collection Template Protection (`ProjectTemplateController` & `CollectionTemplateController`)**:
  - Enforced project access checks (`project.view`) before exporting project schemas (`/from-project/{projectUuid}`) and collection definitions (`/from-collection/{projectUuid}/{collectionSlug}`) to global templates.
  - Restricted template deletion to `ROLE_SUPER_ADMIN` and wrapped request bodies in JSON parsing exception handling.
- **Public Shares & Live Preview ApiToken Access (`ShareController` & `PreviewController`)**:
  - Supported project `ApiToken` authentication in `PreviewController::token` and `ShareController` (`store`, `index`, `destroy`) via project attribute matching.
- **Project Email Route Alias & Attachment Hardening (`ProjectEmailController`)**:
  - Added standard route alias `POST /api/projects/{projectUuid}/email`.
  - Sanitized attachment filenames to strip control characters/path traversal and enforced a 10MB per-attachment size safeguard.
- **Automation Error Handling (`AutomationController`)**:
  - Protected `store`, `update`, `dryRun`, and `testNode` against uncaught `JsonException` returning clean 400 Bad Request responses.

---

## [1.26.32] — 2026-11-02

### Fixed & Improved
- **AI Streaming Endpoint Authorization & Security (`ContentAiStreamController` & `AiController`)**:
  - Closed unauthenticated access to expensive LLM text-generation streams (`/admin/api/content-ai/stream` and `/api/projects/{projectUuid}/ai/stream`).
  - Added project ownership checks with `content.create` permissions and support for project `ApiToken`.
  - Added `denyProjectAccess` helper in `AiController` to support project `ApiToken` across `generate`, `translate`, `summarize`, `seo`, `suggestSchema`, and `models`.
- **Redirect Loop Protection & Chain Detection (`RedirectController`)**:
  - Integrated `RedirectChainDetector::detectLoopWithContext` into redirect creation and updates to prevent circular redirect loops (`A -> B -> A`).
  - Prevented self-redirects (`fromPath === toPath`), enforced non-empty normalized paths, and validated redirect status codes (`301`, `302`, `307`, `308`).
  - Added `GET /admin-api/{projectUuid}/redirects/chains` to inspect redirect chains and recommend direct shortcut paths.
- **Form Submissions Memory Optimization & Single Deletion (`FormController` & `FormSubmissionRepository`)**:
  - Resolved potential memory exhaustion (OOM) on large forms by replacing in-memory entity filtering with SQL-level pagination (`findPaginatedByForm`) and count queries (`countByForm`).
  - Added endpoint `DELETE /admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}` for individual submission removal (GDPR/spam cleanup).
  - Streamlined form deletion with single-query bulk submission cleanup.
- **Storage Profile Connectivity Testing (`ProjectStorageController`)**:
  - Added `POST /api/admin/projects/{uuid}/storage/profiles/{id}/test` to safely verify storage credentials and read/write permissions (local and S3).
  - Sanitized driver validation returning 422 JSON errors instead of unhandled 500 exceptions.
- **Admin API Shared Session / PAT Context (`security.yaml`)**:
  - Configured `context: main` on firewall `admin_api` so both web UI session users and external PAT tokens can seamlessly interact with `/admin-api/` endpoints without false 401s.

---

## [1.26.31] — 2026-11-02

### Fixed & Improved
- **Audit Logs ApiToken Access & Pagination Hardening (`AuditController`)**:
  - Added support for project `ApiToken` authentication in audit logs retrieval.
  - Implemented strict boundary sanitization on `limit` (bounded between 1 and 500) and `offset` (minimum 0) to prevent denial of service and memory overflow.
- **Content Versioning ApiToken Authorization (`VersioningController`)**:
  - Enabled project `ApiToken` access for version listing, diff comparison, and content restoration endpoints.
  - Added project-scoped ownership validation to prevent cross-tenant version restoration.

---

## [1.26.30] — 2026-11-02

### Fixed & Improved
- **Invitation Acceptance JSON & Public Access (`InvitationController` & `security.yaml`)**:
  - Whitelisted `^/invitation` in `access_control` so invited users visiting links can view expiration or invalid notices without being prematurely redirected to login.
  - Added JSON response support when `Accept: application/json` is requested and added API endpoint `POST /api/invitations/{token}/accept`.
- **Notification Controller Authentication & Type Safety (`NotificationController`)**:
  - Replaced unhandled null `$this->getUser()` access with strict authentication checks (`getAuthUser()`) returning 401 instead of PHP TypeErrors/FatalErrors on unauthenticated requests.
- **Media Bulk Deletion & Mercure Real-Time Sync (`MediaController`)**:
  - Extended `bulkDestroy` to support both integer IDs and UUID strings, with robust JSON/form body parsing.
  - Emitted `mediaDeleted` real-time Mercure events on both single `delete` and `bulkDestroy` operations so frontend interfaces remove deleted media cards instantly.

---

## [1.26.29] — 2026-11-02

### Fixed & Improved
- **GraphQL API Token Authorization (`GraphQLController`)**:
  - Allowed `ApiToken` requests when validated by `EnsureProjectMemberSubscriber`, preventing false 403 Access Denied errors on `/api/projects/{uuid}/graphql`.
- **Column Setting Validation & Multi-Tenant Isolation (`ColumnSettingController`)**:
  - Enforced authentication and valid project/collection checks returning 401/404 JSON responses instead of crashing with unhandled null entity exceptions.
- **Form Public Controller Multi-Format Payload Support (`FormPublicController`)**:
  - Supported `application/x-www-form-urlencoded` and multipart form submissions alongside JSON payloads, preventing `JsonException` when native HTML forms post submissions.
  - Added alias routes under `/api/projects/{projectUuid}/forms/{slug}`.
- **Redirect Public Controller Accept Header Handling (`RedirectPublicController`)**:
  - Fixed client `Accept` negotiation so clients sending `Accept: */*` or `Accept: application/json` receive redirect metadata in JSON instead of an immediate HTTP 301 redirect.
  - Added alias route `/api/projects/{projectUuid}/redirects/resolve`.
- **Public SEO Sitemaps & Robots.txt Route Aliases (`SeoPublicController`)**:
  - Added route aliases under `/api/projects/{projectUuid}/sitemap.xml`, `/sitemap-images.xml`, and `/robots.txt`.

---

## [1.26.28] — 2026-11-02

### Fixed & Improved
- **TUS 1.0.0 Protocol Discovery & Resumable Upload CORS (`TusController` & `CorsSubscriber`)**:
  - Implemented standard `OPTIONS` route (`/files/tus` & `/{uploadId}`) returning `Tus-Resumable: 1.0.0`, `Tus-Version: 1.0.0`, `Tus-Extension: creation,termination`, and `Tus-Max-Size` required by Uppy and tus-js-client.
  - Configured `CorsSubscriber` to pass through `/files/tus` OPTIONS requests to the controller and expose TUS headers (`Upload-Offset`, `Location`, `Upload-Length`, `Tus-Version`, `Tus-Resumable`, `Tus-Max-Size`, `Tus-Extension`).
  - Added `ApiToken` authentication bypass check in `TusController::denyProjectAccess`.
- **Media Folder Circular Reference Protection (`MediaFolderController`)**:
  - Implemented ancestor tree traversal in `move` endpoint to prevent moving a folder into one of its descendants (preventing infinite recursion and tree corruption).
- **Two-Factor Challenge Manual Authentication (`TwoFactorChallengeController`)**:
  - Replaced broken `$this->container->get('security.authenticator.form_login.main')` with standard `UsernamePasswordToken` and constructor-injected `TokenStorageInterface`.
- **End-User Password Reset Email Delivery (`EndUserAuthController`)**:
  - Injected `ProjectMailerService` and `MailerInterface` to complete the missing email delivery upon end-user password reset requests.
- **Automation Controller API Token Authorization (`AutomationController`)**:
  - Added support for `ApiToken` authorization in `AutomationController::denyProjectAccess`.

---

## [1.26.27] — 2026-11-02

### Fixed & Improved
- **Public Share JSON Error Responses (`PublicShareController`)**:
  - Ensured `/share/{token}.json` and `Accept: application/json` requests return JSON error payloads (`{"error": "invalid"}` or `{"error": "expired"}`) instead of HTML error pages on 404/410 conditions.
- **Insights UUID & API Route Alignment (`InsightsController`)**:
  - Added support for project UUID lookups in addition to numeric IDs.
  - Added standard endpoint `GET /api/projects/{project}/insights`.
- **Realtime API Token Support (`RealtimeController`)**:
  - Fixed access denial when requesting Mercure subscription tokens or polling realtime events using valid `ApiToken` authentication.
- **Live Preview Query Param Token Integration (`PreviewController`)**:
  - Supported `?token=` and `?preview_token=` query parameters alongside the `Authorization: Bearer` header for live preview iframes and static site generators.

---

## [1.26.26] — 2026-11-02

### Fixed
- **CommentController Dependency & Multi-Tenant Isolation**:
  - Replaced broken `$this->container->get(NotificationSubscriber::class)` call with constructor dependency injection (`NotificationSubscriber`).
  - Added strict multi-tenant isolation verifying that comments and target contents belong to the requested project and collection UUID.
  - Added null check on `$this->getUser()` and strict role checks (`ROLE_ADMIN`, `ROLE_SUPER_ADMIN`) before updating or deleting comments.
- **CSV Import Reliability & EAV Property Fix (`AdminApi/ImportController`)**:
  - Fixed fatal `Undefined property: $numericValue` on `ContentFieldValue` by using `$fv->numberValue` via `EavFieldHelperService`.
  - Added auto-incrementing suffix slug deduplication (`ensureUniqueSlug`) to avoid duplicate key violations during bulk CSV row imports.
  - Added `EntityManager::clear()` and safe error recovery preventing subsequent row failures from poisoning the entity manager.
- **Search Controller Escaping & DB Fallback (`SearchController` & `SearchService`)**:
  - Fixed unquoted Meilisearch filter injection and syntax errors by enclosing filter string literals in quotes (`_collection = "..."`, `locale = "..."`).
  - Implemented database search fallback (`fallbackDatabaseSearch`) in `SearchService` so fulltext search remains functional when Meilisearch is unconfigured or unreachable.
- **Webhook Controller URL Validation, SSRF Protection & Test Ping (`WebhookController`)**:
  - Added `validateWebhookUrl()` with URL syntax validation, scheme restriction (HTTP/HTTPS only), and SSRF blocking of private and reserved IP addresses.
  - Added `POST /api/projects/{projectUuid}/webhooks/{uuid}/test` endpoint to trigger test pings via `SendWebhookMessage`.
- **System Version Reporting (`DeploymentController` & `HealthController`)**:
  - Added fallback to `composer.json` and `HealthController::VERSION` in `/api/system/info` when the static `VERSION` file is absent.

---

## [1.26.25] — 2026-11-02

### Added
- **MCP Feature Completion & Resource Expansion (FIX-025)**:
  - Added entry lifecycle tools: `unpublish_entry` (revert to draft status), `restore_entry` (restore soft-deleted entry), and `duplicate_entry` (clone entry with relations and unique slug generation).
  - Added schema mutation tools: `update_collection`, `delete_collection`, `update_field`, and `delete_field` wired to `SchemaProvisioner` with write authorization guards.
  - Added versioning tools: `restore_version` (rollback to snapshot) and `diff_versions` (compare version revisions) wired to `VersioningService`.
  - Added media management tools: `update_media` (alt and caption metadata) and `delete_media` (soft-delete media asset).
  - Added accessibility AI tool: `ai_generate_alt_text` powered by `AiContentService`.
  - Added `jamboapi://projects` resource listing accessible projects directly via MCP resource read.
  - Added `mcp_limiter` override in test environment to prevent rate-limit exhaustion during automated test runs.
  - Added regression test `testExtendedMcpToolsLifecycle` covering all new tools in `McpSchemaSecurityTest`.

---

## [1.26.24] — 2026-11-02

### Fixed
- **MCP Entry Slug Generation & Query Bounds Safety (FIX-024)**:
  - Implemented `ensureUniqueEntrySlug` in `JamboApiMcpServer` to auto-generate unique slugs on `create_entry` and `update_entry`, preventing `UNIQ_COLLECTION_SLUG_LOCALE` SQL duplicate entry collisions.
  - Added optional `slug` property in MCP tool input schemas for `create_entry` and `update_entry`.
  - Added bounded limit (`max(1, min(limit, 200))`) and offset (`max(0, offset)`) sanitization across `list_entries`, `list_media`, and `list_end_users` preventing division by zero and SQL offset errors.
  - Enhanced `list_entries` response to return accurate overall `total` via `countByCollection` alongside paginated `count`.
  - Added regression test `testEntrySlugCollisionAndPaginationSafety` in `McpSchemaSecurityTest`.

---

## [1.26.23] — 2026-11-02

### Fixed
- **MCP Server Hardening, Security Isolation & Protocol Compliance (FIX-023)**:
  - Added `PUBLIC_ACCESS` for `/mcp` in `security.yaml` preventing 302 login redirection on Bearer token requests.
  - Added rate limiting and case-insensitive Bearer token parsing in `McpController`.
  - Implemented `assertCanWriteProject` in `JamboApiMcpServer` enforcing tenant isolation and write ability checks for `create_entry`, `update_entry`, `delete_entry`, and `publish_entry`.
  - Enforced authentication and tenant guards on `search_content`, `search_content_semantic`, `get_media_info`, `transform_image`, `ai_generate_content`, `ai_suggest_schema`, `ai_translate_content`, `ai_summarize_text`, and `ai_generate_seo`.
  - Secured `apply_template` with authentication checks and active owner `ProjectMember` provisioning.
  - Added field value validation via `EavFieldHelperService::validateValue()` in entry creation and update.
  - Aligned MCP tool error reporting with Model Context Protocol specification (`isError: true`).
  - Added complete allowed field type enumeration (25 types) in `add_field` schema.
  - Added unit test regressions in `McpSchemaSecurityTest` covering public access, anonymous tool call denial, and cross-project entry mutation prevention.

---

## [1.26.22] — 2026-10-19

### Added
- **Production Readiness Sign-Off & Healthcheck Probe Integration (FIX-022 / REC-002)**:
  - Created `HealthController` providing `/health` and `/api/health` probes verifying database connectivity and runtime storage health.
  - Added `HealthControllerTest` verifying passing health status, DB connectivity checks, and application version metadata.
  - Finalized `CHECKLIST_PRODUCTION.md`: validated all 24/24 gates across Security, Database, Infrastructure, and Reliability.
  - Official production sign-off delivered: **GO PRODUCTION** on `v1.26.22`.

---

## [1.26.21] — 2026-10-19

### Fixed
- **Comprehensive PHPUnit Regression & Integration Suite Pass (FIX-021 / REC-001)**:
  - Implemented `JamboClientGenerator` service generating TypeScript client types and API helper functions for project collections.
  - Added clean module deprecation skipping to `SiteHostResolverTest` following the removal of legacy Workbench domain modules.
  - Fixed unique constraint violations on `ContentEntry` (`collection_id, slug, locale`) in `InsightsServiceTest` by properly initializing unique slugs and locales in test fixtures.
  - Aligned `SchemaCrudTest` administrative permissions with `ProjectVoter::MANAGE` policies.
  - Harmonized EndUser `name` and `username` attributes across `EndUser` entity, `EndUserAuthController` registration/update, and `EndUserSerializerTrait`.
  - Configured `security.yaml` `PUBLIC_ACCESS` for `/api/settings/personal-access-tokens` so that unauthenticated API requests correctly return 401 JSON responses via `PersonalAccessTokenController::requireUser()`.
  - Verified full test suite execution: 547 tests, 1,440 assertions passing with 0 errors and 0 failures.

---

## [1.26.20] — 2026-10-19

### Added
- **Production-Grade Zero-Downtime Deployment & Secure SSH Runner (FIX-020 / OPS-005)**:
  - Created zero-downtime, safe-migration deployment script `scripts/deploy.sh` with automatic error trap and rollback to previous Git commit on failure.
  - Handles prerequisite validations (PHP 8.4, Composer, npm), Git tag/branch synchronisation, optimized production Composer install, Webpack asset compilation, zero-data-loss atomic Doctrine migrations, production Symfony cache warmup, and web-server directory permissions.
  - Refactored `deploy.py` remote SSH deployment runner to eliminate legacy hardcoded credentials, adopting environment-variable configuration (`JAMBO_HOST`, `JAMBO_USER`, `JAMBO_PASS`, `JAMBO_SSH_KEY`, `JAMBO_REMOTE_DIR`) with automatic SSH key/agent detection.
  - Adjusted `.gitignore` to track production deployment infrastructure while ensuring scratch scripts and local data remain untracked.

---

## [1.26.19] — 2026-10-19

### Added
- **Restoration of Automated GitHub Actions CI/CD Pipeline (FIX-019 / SIM-005)**:
  - Created `.github/workflows/ci.yml` providing automated continuous integration across three parallel/staged pipelines:
    1. `php-tests`: PHP 8.4 matrix with MySQL 8.0 service container, strict `composer validate`, migrations execution, and complete PHPUnit test suite run.
    2. `frontend-build`: Node.js 20 asset pipeline compiling Webpack Encore production assets (`npm ci && npm run build`).
    3. `docker-build`: Docker Buildx verification validating production container build against `./Dockerfile`.
  - Aligned `package.json` license metadata with project AGPL-3.0-or-later standard.

---

## [1.26.18] — 2026-10-19

### Fixed
- **CDN Cloud Storage Abstraction (S3 / R2 / MinIO) (FIX-018 / OPS-006)**:
  - Extended `ImageTransformController` to seamlessly resolve media hosted on remote object storage profiles (S3, Cloudflare R2, MinIO) via Flysystem `StorageDriverFactory`.
  - Implemented local buffering into `var/media_cache/{projectUuid}/{fileName}` when a requested media file is not present on local disk, enabling dynamic on-demand image transforms on cloud-stored assets.
  - Added unit and functional test suite `ImageTransformControllerTest` validating remote S3 streaming, local cache staging, and CDN responses.

---

## [1.26.17] — 2026-10-19

### Fixed
- **Docker Multi-Stage Build & Nginx CDN Fallback (FIX-017 / OPS-003)**:
  - Fixed Nginx `/cdn/media/` rule in `docker/nginx.conf` with dynamic fallback `try_files $uri @symfony` ensuring uncached media transformations pass to Symfony on demand instead of returning immediate 404 errors.
  - Refactored `Dockerfile` into a clean multi-stage build: separated Node.js asset compilation (`node:20-alpine`) from the lightweight PHP-FPM runtime image (`php:8.4-fpm-alpine`), reducing production image size and attack surface.
  - Enforced isolated database-free Symfony cache warmup during Docker build (`DATABASE_URL="sqlite:///:memory:" php bin/console cache:warmup --env=prod`).

---

## [1.26.16] — 2026-10-19

### Fixed
- **Hybrid EAV & JSON Schema Consolidation (FIX-016 / ARC-001 / Arbitrage D-01)**:
  - Created `ContentEntryValidator` providing robust schema validation for repeaters, arrays, and JSON structures (min/max items, required subfields, JSON schema type verification).
  - Harmonized `EavDataFormatterService` to cleanly serialize both pure JSON repeaters and relational `ContentFieldGroup` instances without root field namespace collisions.
  - Added unit test suites `ContentEntryValidatorTest` and expanded `EavDataFormatterServiceTest` validating complex nested data structures and relational grouping.

---

## [1.26.15] — 2026-10-19

### Fixed
- **Twig Hermetic Sandbox Container Activation (FIX-015 / SIM-004)**:
  - Registered `NativeTwigSecurityPolicy` in Symfony dependency injection container (`services.yaml`), providing alias for `Twig\Sandbox\SecurityPolicyInterface`.
  - Registered `Twig\Extension\SandboxExtension` in Symfony Twig environment configured with `NativeTwigSecurityPolicy`.
  - Created `DynamicTemplateRenderer` service enforcing AST token whitelist sandbox for dynamic user-generated templates.
  - Added functional integration test suite `TwigSandboxIntegrationTest` confirming container registration, sandbox extension loading, and rejection of forbidden tags, functions, and object method invocations.

---

## [1.26.14] — 2026-10-19

### Added
- **OAuth2 Authorize & Consent Endpoint Implementation (FIX-014 / SIM-003)**:
  - Implemented the missing RFC 6749 authorization endpoint `GET|POST /api/v1/projects/{projectUuid}/oauth/authorize`.
  - Added strict parameter validation for `client_id`, `redirect_uri` whitelist, `response_type=code`, and PKCE parameters (`code_challenge` / `code_challenge_method`).
  - Added multi-tenant client verification ensuring OAuth2 clients are strictly bound to their parent project.
  - Supported end-user authentication via Bearer token, credentials submission, or consent decision (`authorize` vs `deny`).
  - Created standalone responsive Twig consent screen template `templates/oauth/authorize.html.twig`.
  - Added comprehensive test suite `ProjectOAuthControllerTest` covering invalid clients, redirect URI mismatch, unsupported response types, login challenges, and code generation.

---

## [1.26.13] — 2026-10-19

### Added
- **Real SSE Streaming LLM Integration (FIX-013 / SIM-001)**:
  - Eliminated simulated chunk mock (`array_chunk` + `usleep`) in `ContentAiStreamController`.
  - Implemented `AiContentService::streamPrompt()` and `streamProvider()` providing genuine Server-Sent Events (SSE) streaming connected to OpenAI, Anthropic, Gemini, Mistral, Groq, Ollama, DeepSeek, and OpenRouter providers via `HttpClientInterface::stream()`.
  - Implemented non-blocking SSE streaming with graceful error handling (`event: error`) and project isolation validation.
  - Added unit test suites `ContentAiStreamControllerTest` and `AiContentServiceStreamTest` validating progressive SSE chunk generation, buffer safety, and failure recovery.

---

## [1.26.12] — 2026-10-19

### Fixed
- **Asynchronous Automation Execution via Messenger (FIX-012 / BUG-002)**:
  - Refactored `AutomationTriggerSubscriber` to delegate automation pipeline execution asynchronously via Symfony Messenger (`ExecuteAutomationMessage`) instead of executing synchronously in the HTTP request lifecycle.
  - Implemented upfront `AutomationRun` initialization in `pending` state linked to the project and dispatched message.
  - Updated `AutomationRepository::findActiveByCondition` with `\Throwable` catch handling to support environments without native MySQL JSON DQL functions.
  - Added automated test suite `AutomationTriggerSubscriberTest` verifying event reception, pending run persistence, message bus dispatch, and handler execution.

---

## [1.26.11] — 2026-10-19

### Fixed
- **Null Safety on User Account Settings (FIX-011 / BUG-001)**:
  - Decorated `Settings\PasswordController` and `Settings\ProfileController` with `#[IsGranted('ROLE_USER')]`.
  - Added defensive `instanceof User` assertions returning structured JSON 401 Unauthorized responses instead of unhandled 500 `TypeError` exceptions on unauthenticated access.
  - Added automated test suite `SettingsSecurityTest` validating graceful denial without server errors.

---

## [1.26.10] — 2026-10-19

### Security
- **Anti-SSRF Hardening on AI Image Ingestion (FIX-010 / SEC-007)**:
  - Replaced unconstrained `@file_get_contents()` in `StudioController::aiGenerateImage()` with `downloadExternalImage()`.
  - Enforced mandatory `https://` protocol, disabled uncontrolled HTTP redirects, and restricted downloads to 10 seconds timeout and 10MB maximum payload.
  - Added strict DNS and IP resolution filtering rejecting loopback, RFC 1918 private ranges, and cloud metadata addresses (`169.254.169.254`).
  - Added automated test suite `StudioAntiSsrfTest` confirming instant rejection of SSRF vectors.

---

## [1.26.09] — 2026-10-19

### Security
- **AdminApi Mutation Privilege Enforcement (FIX-009 / SEC-006)**:
  - Replaced overly permissive `ProjectVoter::VIEW` with strict `ProjectVoter::MANAGE` on collection creation, update, and deletion in `AdminApi\CollectionController`.
  - Enforced `ProjectVoter::MANAGE` on project update and deletion endpoints in `AdminApi\ProjectController`.
  - Added automated test suite `AdminApiPermissionsTest` verifying that members with read-only/viewer roles are blocked from mutating projects and collections.

---

## [1.26.08] — 2026-10-19

### Fixed
- **Installation Assistant Unblocking & Post-Install Security Gate (FIX-008 / OPS-004)**:
  - Added access control rule `- { path: ^/install, roles: PUBLIC_ACCESS }` in `security.yaml` to unblock cold setup wizard on fresh deployments.
  - Implemented strict lockdown in `InstallController` (`index` and `check` endpoints) returning 403 Forbidden once an administrator user exists.
  - Added automated test suite `InstallControllerTest` verifying public setup access on empty database and total lockout once initialized.

---

## [1.26.07] — 2026-10-19

### Security
- **Multi-Tenant Guard Parameter Harmonization (FIX-007 / SEC-005)**:
  - Resolved multi-tenant authorization bypass in `EnsureProjectMemberSubscriber`.
  - Added fallback extraction for route parameter `{uuid}` when `{projectUuid}` is not explicitly declared on routes under `/api/projects/`.
  - Enforced project membership and tenant isolation across GraphQL, Search, Studio, and MCP routes.
  - Added automated test suite `EnsureProjectMemberSubscriberTest` validating that unauthenticated and non-member requests to `{uuid}` routes are strictly denied.

---

## [1.26.06] — 2026-10-19

### Security
- **MCP Server Hardening & Tenant Isolation (FIX-006 / SEC-004)**:
  - Enforced authentication and multi-tenant project membership checks on `McpController::handleProject()`.
  - Added read authorization guard `assertCanReadProject()` in `JamboApiMcpServer` preventing cross-tenant access to collections, schemas, media, entries, and end-users.
  - Filtered `list_projects` tool to return only projects where the authenticated user or token is an active member or admin.
  - Added automated security test cases in `McpSchemaSecurityTest` verifying anonymous access rejection and cross-tenant isolation.

---

## [1.26.05] — 2026-10-19

### Security
- **Access Control & User Management Lockdown (FIX-005 / SEC-003)**:
  - Added access control rule in `security.yaml` requiring `ROLE_USER` for `/api/(users|roles|permissions|settings)`.
  - Decorated `UserController`, `RoleController`, and `PermissionController` with `#[IsGranted('ROLE_USER')]` class-level security attributes.
  - Added automated test suite `UserManagementSecurityTest` verifying that anonymous requests to user management endpoints are rejected.

---

## [1.26.04] — 2026-10-19

### Security
- **Path Traversal & RCE Neutralization (FIX-004)**:
  - Fixed arbitrary file write vulnerability in `PublishedSiteStorage::publish()`.
  - Added strict path segmentation and traversal validation preventing `..` or `.` escapes.
  - Enforced strict canonical directory and file path boundary checking against the project publication root.
  - Added unit test cases verifying immediate exception rejection on malicious path injection.

---

## [1.26.03] — 2026-10-19

### Fixed
- **Doctrine Migrations Linearization (FIX-003)**:
  - Resolved fatal `Table already exists` collision in `Version20260629084616.php`.
  - Removed duplicate `CREATE TABLE` and `DROP TABLE` statements for `form`, `form_submission`, `redirect`, `redirect_revision`, and `seo_revision` that were already created in `Version20260624070523.php`.
  - Isolated migration scope strictly to `personal_access_token` and `share` tables, enabling clean, idempotent cold database setup.

---

## [1.26.02] — 2026-10-19

### Fixed
- **Composer Lockfile Integrity & De-duplication (FIX-002)**:
  - Removed duplicate package entry for `spomky-labs/otphp` that was causing Composer dependency solver crashes.
  - Successfully regenerated and synchronized `composer.lock` with `composer.json`.
  - Normalized package license identifier to standard SPDX `AGPL-3.0-or-later`.
  - Validated automated zero-interaction installation (`composer validate` returns 0).

---

## [1.26.01] — 2026-10-19

### Security
- **Sanitization & Secret Isolation (FIX-001)**:
  - Untracked `.env` from git repository (`git rm --cached .env`) to prevent credential leakage.
  - Ensured `.env` and local environment files remain strictly excluded via `.gitignore`.
  - Replaced production credentials in local `.env` with localhost dev defaults.
  - Redacted all credentials across audit and roadmap documentation.

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

---

## [1.9.5] — 2026-06-18

### Added

- **Authentification à deux facteurs (2FA)** — TOTP (Google Authenticator / Authy, RFC 6238) et Email (code 6 chiffres) pour les utilisateurs admin CMS (`User`) et les utilisateurs finaux (`EndUser`). Activation facultative par utilisateur admin, toggle activer/désactiver par projet pour les end-users.
- **`TwoFactorService`** — génération/vérification TOTP avec fenêtre ±1 période, codes de secours (8 codes hashés à usage unique), génération QR code via `endroid/qr-code`.
- **`TwoFactorMailer`** — envoi de code de vérification par email avec template responsive.
- **`SecurityController`** — 7 endpoints REST sous `/api/settings/security` : statut, setup TOTP, confirmation TOTP, activation email, confirmation email, désactivation (avec mot de passe), régénération codes de secours.
- **`TwoFactorChallengeController`** — page de challenge 2FA après login admin (`GET/POST /two-factor-challenge`), vérification TOTP/email/backup, rate limiting 5 tentatives/60s.
- **`TwoFactorRedirectSubscriber`** — intercepte `LoginSuccessEvent` pour rediriger vers le challenge 2FA si activé.
- **API End-User 2FA** — `POST /api/{projectId}/auth/verify-2fa` avec JWT éphémère 60s pour valider le code et obtenir les tokens JWT.
- **Frontend** — page `two-factor-challenge.tsx` avec `InputOTP` (shadcn/ui), onglet Sécurité dans les réglages (QR code, setup TOTP/email, backup codes), toggle dans les paramètres projet.
- **i18n** — clé `settings.nav.security` dans les 4 langues (FR/EN/AR/ES).

### Security

- Ré-authentification par mot de passe obligatoire avant toute modification 2FA (setup, désactivation).
- Hash SHA-256 des codes email dans les JWT (jamais en clair).
- Rate limiting dédié `two_factor_limiter` : 5 tentatives / 60 secondes.
- Régénération de l'ID de session après authentification 2FA réussie.

---

## [1.9.4] — 2026-06-18

### Added

- **Commentaires threadés** — les utilisateurs peuvent commenter sur les entrées (`Comment` entity), répondre à un commentaire (threading 1 niveau), marquer comme résolu (open/resolved), et mentionner des utilisateurs (`@username`). API REST complète : liste paginée, création, modification, suppression, toggle résolution.
- **Notifications** — système de notifications en temps réel (`Notification` entity) pour les assignations, commentaires, mentions et changements de statut. Cloche dans la navbar avec badge compteur, poll toutes les 30s, dropdown des 10 dernières notifications, marquage comme lu individuellement ou en masse.
- **`CommentThread`** dans la sidebar du ContentForm — liste paginée des commentaires avec threading, formulaire d'ajout, boutons répondre/résoudre/rouvrir.
- **`NotificationBell`** dans la navbar (`app-header`) — composant avec Popover, badge compteur (`unread-count`), lien de redirection vers l'entrée concernée.
- **i18n** — 5 clés de traduction : `comments.title`, `comments.write_placeholder`, `comments.reply`, `comments.resolve`, `comments.reopen` (FR/EN/AR/ES).

---

## [1.9.3] — 2026-06-18

### Added

- **Repeater à variantes (Dynamic Zones)** — chaque item d'un Repeater peut maintenant avoir un ensemble de sous-champs différent (variante). Définition de variantes nommées dans le SchemaBuilder, choix de la variante par item dans le ContentForm via un dropdown. Rétrocompatible avec l'ancien format `subFields`.
- SchemaBuilder : éditeur de variantes avec cards `<details>` collapsibles, sous-champs par variante, variante par défaut, migration automatique depuis l'ancien format `subFields`.

---

## [1.9.2] — 2026-06-17

### Added

- **Lexical structuré** — 5 nouveaux blocs de contenu dans l'éditeur Rich Text : **Code Block** (avec dropdown de langage), **Blockquote** (citation), **Horizontal Rule** (séparateur), **Tableau** (popover 3×3, `@lexical/table`), **Callout** (info/warning/success/danger avec icônes et couleurs).
- **Champ Code avancé** — remplacement du textarea brut par **CodeMirror 6** avec coloration syntaxique (13 langages supportés), thème One Dark, numéros de ligne. Composant `CodeMirrorEditor` réutilisable.
- SchemaBuilder : option `language` pour le type `code` (JavaScript, TypeScript, JSON, HTML, CSS, SQL, PHP, Python, Markdown, XML, YAML, Shell, Plain Text).

---

## [1.9.1] — 2026-06-17

### Fixed

- **Pagination sur la corbeille** (`/trash`) — `findTrashedPaginated()` remplace `findTrashed()` sans limite.
- **Statuts système** — `draft` et `scheduled` sont toujours valides, même sans workflow custom.
- **Service `FieldValueHydrator`** — centralise les 5 copies du `match($field->type)` en un seul service partagé.
- **i18n** — 13 clés de traduction ajoutées (FR/EN/AR/ES) : `content.assigned_to`, `content.unassigned`, `content.by`, `content.unknown`, `content.search_in`, `repeater.item_n`, `repeater.add_item`, `repeater.select`, etc.
- **Fuites d'informations dans les erreurs** — 3 `catch (\Throwable $e)` dans `StudioController` loggent l'exception côté serveur et renvoient un message générique.

### Added

- **Tests automatisés** — `CollectionTest` (7 tests workflow helpers), `ContentControllerTest` (5 tests statuts + assignation), `EavDataFormatterServiceTest` (2 tests `assigned_to`).

### Performance

- **Index composite** `content_field_value(content_entry_id, field_id)` — accélère les requêtes EAV.
- **Eager-loading** — `findByCollectionPaginated()` charge maintenant `fieldValues`, `createdBy`, `updatedBy`, `assignedTo` en une seule requête.
- **`findProjectMediaUuids()`** — remplacement de la boucle N+1 par une requête `IN()`.

---

## [1.9.0] — 2026-06-17

### Added

- **Workflows éditoriaux** — **statuts custom par collection** définis dans `Collection.settings.workflow` (slug, label, couleur, `published`). Boutons dynamiques dans le ContentForm, badges couleur dans la liste. Rétrocompatible : si aucun workflow n'est défini, `draft`/`published`/`scheduled` par défaut.
- **Assignation de contenu** — FK `assigned_to_id` sur `content_entry` → `User`. Selecteur d'assignation dans la sidebar du ContentForm, colonne « Assigné à » dans la liste, filtre « Mes contenus ». Validation : l'assigné doit être membre du projet (IDOR protégé).
- SchemaBuilder : nouveau panneau **Workflow** pour configurer les statuts (label, couleur, published, statut par défaut).
- API : `workflow` exposé dans `GET /public-api/collections/{slug}`, `assigned_to` dans les réponses d'entrées, filtre `status` acceptant tous les statuts custom.

---

## [1.8.1] — 2026-06-17

### Added

- **Drag-and-drop** dans le RepeaterField — remplacement des boutons ↑↓ par `@hello-pangea/dnd` avec handle `GripVertical`.
- **Support richtext** dans les sous-champs du Repeater — utilisation de l'éditeur `LexicalEditor` complet.
- **Support media** dans les sous-champs — bouton « Browse » + `MediaLibraryModal` natif pour choisir des fichiers.

---

## [1.8.0] — 2026-06-17

### Added

- **Repeater structuré (nested fields)** — nouveau type `repeater` avec **sous-champs configurables** dans le SchemaBuilder. L'utilisateur définit des sous-champs nommés (slug, label, type, required), et le ContentForm affiche un composant `RepeaterField` avec add/remove/reorder d'items contenant les sous-champs. Stockage via `jsonValue` existant — aucune migration DB.

---

## [1.7.0] — 2026-06-17

### Added

- **Publication planifiée** — nouveau statut `scheduled` avec champ `scheduledAt` (datetime) sur `content_entry`. Bouton « Planifier » + datepicker dans le ContentForm. Badge bleu « Planifié » dans la liste. Filtre « Planifié » dans le dropdown.
- **Commande `app:publish-scheduled`** — à exécuter via cron, publie automatiquement les entrées dont `scheduledAt <= now`. Transition `scheduled → published`, `scheduledAt → null`, `publishedAt` défini.
- **Permission `can.publish_content`** — gate sur les boutons Planifier/Publier/Replanifier.
- **i18n** — clés `schedule_btn`, `schedule_date`, `schedule_time`, `schedule_confirm`, `publish_now`, `reschedule` dans les 4 langues.

---

## [1.6.1] — 2026-06-17

### Fixed

- Correctifs validation pour `rating` (Integer limitations), `tags` (pas de Unique/Character count), `url`/`markdown` (text options).
- Icônes des nouveaux types dans le SchemaBuilder (`ICON_MAP`).

---

## [1.6.0] — 2026-06-17

### Added

- **7 nouveaux types de champs** : `url`, `markdown`, `code`, `icon` (Lucide icon name), `uuid`, `tags`, `rating` (star score).
- Composants React dédiés : `UrlField`, `MarkdownField`, `CodeField`, `IconField`, `UuidField`, `TagsField`, `RatingField`.

---

## [1.5.0] — 2026-06-07

### Added

- **JSONField** — éditeur JSON avec coloration syntaxique (Prism.js) et validation inline.
- **DateField amélioré** — support mode range (date de début → date de fin).
- **Options de champs étendues** — `helpText`, `hideInList`, `readOnly` dans le FieldOptionsEditor du SchemaBuilder.

---

## [1.4.0] — 2026-06-05

### Added

- **Opérations bulk dans la liste de contenu** — publier, dépublier, supprimer, restaurer plusieurs entrées en une action.
- **Colonnes personnalisables** — l'utilisateur peut masquer/afficher les colonnes dans la liste de contenu.
- **Améliorations MediaField** — prévisualisation des miniatures, chargement lazy, modal de sélection refondu.

---

[1.0.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.0.0
[1.0.1]: https://github.com/jambostack/jambo-api/releases/tag/v1.0.1
[1.4.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.4.0
[1.5.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.5.0
[1.6.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.6.0
[1.6.1]: https://github.com/jambostack/jambo-api/releases/tag/v1.6.1
[1.7.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.7.0
[1.8.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.8.0
[1.8.1]: https://github.com/jambostack/jambo-api/releases/tag/v1.8.1
[1.9.0]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.0
[1.9.1]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.1
[1.9.2]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.2
[1.9.3]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.3
[1.9.4]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.4
[1.9.5]: https://github.com/jambostack/jambo-api/releases/tag/v1.9.5
