# Changelog

All notable changes to Jambo API are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
