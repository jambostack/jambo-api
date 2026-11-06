# Changelog

All notable changes to Jambo API are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.26.170] — 2026-11-06

### GraphQL AST Depth Hardening, UUID Enforcement, MCP & Search Audit Logging (Cycle #99 & #100)
- **GraphQL API Protection (`GraphQLController`)**:
  - Enforced `MAX_AST_DEPTH = 15` limit on GraphQL query abstract syntax tree depth, stopping nested recursive amplification attacks.
  - Added strict UUID v4 format validation (`UUID_REGEX`) prior to database lookups.
  - Injected `AuditService` to systematically log security events: rate limit violations (`graphql_rate_limit_exceeded`), batch query rejections, operation/fragment/selection/depth limit exceedances (`graphql_security_blocked`).
- **Model Context Protocol (MCP) Audit & Security (`McpController`)**:
  - Added comprehensive audit logging via `AuditService` for rate limit rejections (`mcp_rate_limit_exceeded`), payload size violations (`mcp_payload_too_large`), unauthorized tool requests (`mcp_tool_unauthorized`), and tool executions (`mcp_tool_call`).
- **Search Engine Hardening (`SearchController`)**:
  - Added audit logging for search reindex rate limit violations (`search_reindex_rate_limit_exceeded`), cross-tenant access attempts (`search_tenant_mismatch`), quota exceeded denials (`search_reindex_quota_exceeded`), and successful reindex operations (`search_reindex`) with duration metrics.
- **I18n Translation Completeness & Testing**:
  - Added translation key `graphql.ast_depth_exceeded` across French, English, Spanish, and Arabic dictionaries.
  - Created `Cycle99GraphQLMcpSearchHardeningTest` verifying AST depth protection, UUID validation, tenant isolation, and audit trail generation (7 tests, 88 assertions).

## [1.26.169] — 2026-11-06

### User, Role & Permission Validation Pipeline & Quota Optimization (Cycle #98 Finalization)
- **Check Ordering Hardening**:
  - Reordered request validation pipeline across `UserController`, `RoleController`, and `PermissionController`: HTTP 413 (Payload Too Large) and HTTP 400 (Malformed JSON) are enforced before business logic quotas and entity mutations.
  - Placed field constraint validations (name, format, label, email, password policy) before capacity quota limits to ensure meaningful validation error reporting.
- **Quota Bounds Tuning & Safe Context Resolution**:
  - Calibrated platform quota limits to production scale (`MAX_USERS_COUNT = 10000`, `MAX_ROLES_COUNT = 50000`, `MAX_PERMISSIONS_COUNT = 5000`) preventing false-positive denials under multi-tenant accumulations.
  - Implemented resilient `getUser()` fallback guarding against uninitialized container states during standalone test contexts or mocked requests.
- **Verification & Deployment**:
  - 100% PHPUnit passing rate across the entire test suite (2,017 tests, 14,839 assertions, 0 failures).

## [1.26.168] — 2026-11-06

### Admin User Management, JWT TTL & Ping Rate Limiting & Security Hardening (Cycle #98)
- **User Management Hardening (`UserManagement\UserController`, `RoleController`, `PermissionController`)**:
  - Enforced rate limiting via `limiter.token_management_limiter` across user, role, and permission management endpoints (`index`, `show`, `create`, `update`, `delete`, `bulkDelete`, `resetTwoFactor`).
  - Added strict platform capacity limits: `UserController::MAX_USERS_COUNT = 500`, `RoleController::MAX_ROLES_COUNT = 100`, and `PermissionController::MAX_PERMISSIONS_COUNT = 300` preventing entity exhaustion and DDoS attacks.
  - Safe error handling in rate limiting without reliance on uninitialized controller container.
- **Admin Project JWT TTL Hardening (`Admin\ProjectJwtTtlController`)**:
  - Injected `limiter.project_limiter` for rate limiting requests to `getJwtTtl` and `updateJwtTtl`.
  - Added audit logging via `AuditService` for security-sensitive token TTL updates (`admin_project_jwt_ttl_update`), recording both successes and invalid range rejections.
  - Retained strict range checks (`60s <= access <= refresh <= MAX_TTL`).
- **Admin API Ping & Trait Hardening (`AdminApi\PingController`, `AdminApiControllerTrait`)**:
  - Enforced rate limiting via `limiter.admin_api_limiter` on `GET /admin-api/_ping` with i18n error reporting (`admin_api.ping.rate_limit_exceeded`).
  - Added security audit logging in `AdminApiControllerTrait::requireScope` when personal access token scope violations are detected (`admin_api_scope_violation`).
- **Settings Locale Hardening (`Settings\LocaleController`)**:
  - Enforced rate limiting via `limiter.profile_limiter` on `/settings/locale` preventing database write flooding and audit log spam.
- **I18n Translation Completeness & Testing**:
  - Added 9 new translation keys across English, French, Spanish, and Arabic (`project.jwt_ttl.rate_limit_exceeded`, `admin_api.ping.rate_limit_exceeded`, `settings.locale.rate_limit_exceeded`, `user.admin.rate_limit_exceeded`, `user.admin.max_users_exceeded`, `role.admin.rate_limit_exceeded`, `role.admin.max_roles_exceeded`, `permission.admin.rate_limit_exceeded`, `permission.admin.max_permissions_exceeded`) — 100% symmetrical.
  - Added dedicated test suite `Cycle98AdminSecurityHardeningTest` (14 tests, 125 assertions, 100% pass).

## [1.26.167] — 2026-11-06

### Admin Form & Redirect Security Hardening (Cycle #97)
- **Admin Form Security Hardening (`Admin\FormController`)**:
  - Enforced rate limiting via `limiter.admin_api_limiter` across all form management endpoints (`index`, `create`, `show`, `update`, `delete`, `submissions`, `exportSubmissionsCsv`, `showSubmission`, `deleteSubmission`, `updateSubmission`).
  - Implemented project form quota limit (`MAX_FORMS_PER_PROJECT = 50`) preventing arbitrary form flooding and resource exhaustion.
  - Added comprehensive audit logging via `AuditService` for form creation (`admin_form_create`), form updates (`admin_form_update`), form deletions (`admin_form_delete`), submission exports (`admin_form_submissions_export`), submission updates (`admin_form_submission_update`), and submission deletions (`admin_form_submission_delete`).
  - Enforced strict security headers (`Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`).
- **Admin Redirect Security Hardening (`Admin\RedirectController`)**:
  - Enforced rate limiting via `limiter.admin_api_limiter` on all redirect endpoints (`index`, `chains`, `create`, `show`, `update`, `delete`, `toggle`).
  - Added project redirect quota bounds (`MAX_REDIRECTS_PER_PROJECT = 500`) preventing memory saturation and infinite loops during graph chain detection.
  - Added audit logging via `AuditService` for redirect creation (`admin_redirect_create`), updates (`admin_redirect_update`), toggling (`admin_redirect_toggle`), and deletion (`admin_redirect_delete`).
  - Enforced strict security headers (`Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`).
- **I18n Translation Completeness & Testing**:
  - Added 5 new translation keys across English, French, Spanish, and Arabic (`form.admin.rate_limit_exceeded`, `form.admin.project_quota_exceeded`, `form.admin.export_rate_limit_exceeded`, `redirect.admin.rate_limit_exceeded`, `redirect.admin.project_quota_exceeded`) — 3,525 keys per language, 100% symmetrical.
  - Added dedicated test suite `Cycle97AdminFormAndRedirectSecurityHardeningTest` (7 tests, 98 assertions, 100% pass). All 33 Form & Redirect tests pass without failures.

## [1.26.166] — 2026-11-06

### Content API & AdminApi CSV Import Security Hardening (Cycle #96)
- **Public & Token Content API Hardening (`ContentController`)**:
  - Enforced strict security headers (`Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Pragma: no-cache`) across all endpoints via `secureJson()`.
  - Added rate limiting protection via `limiter.project_limiter` for read, write, update, and delete actions.
  - Implemented strict payload size limits (`MAX_PAYLOAD_BYTES = 2MB`) returning `413 PAYLOAD_TOO_LARGE`.
  - Added request field count limits (`MAX_FIELDS_COUNT = 100`) returning `422 TOO_MANY_FIELDS`.
  - Enforced collection entry quotas (`MAX_ENTRIES_PER_COLLECTION = 10,000`) preventing storage exhaustion.
  - Added slug length bounds (`MAX_SLUG_LENGTH = 128`) returning `422 SLUG_TOO_LONG`.
  - Refactored all error messages and validation exceptions to strict i18n translation keys (`api_content.invalid_status`, `api_content.assignee_not_member`, `api_content.field_value_unique`, `api_content.collection_quota_exceeded`, `api_content.rate_limit_exceeded`, `api_content.payload_too_large`, `api_content.too_many_fields`, `api_content.slug_too_long`).
  - Added comprehensive audit logging for content creation (`api_content_create`), update (`api_content_update`), and soft-deletion (`api_content_delete`).
- **AdminApi CSV Import Hardening (`AdminApi\ImportController`)**:
  - Enforced `SECURITY_HEADERS` on JSON and stream-file import endpoints.
  - Implemented collection quota bounds: checked current active entries against `MAX_ENTRIES_PER_COLLECTION = 10,000` before and during streaming.
  - Added cell sanitization: stripped ASCII control characters (`[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]`) to prevent log poisoning and corrupted stored records.
  - Added comprehensive audit logging for CSV imports (`admin_api_import_csv`) tracking created entries, errors count, and executing actor.
- **I18n Translation Completeness & Testing**:
  - Added 11 new translation keys across English, French, Spanish, and Arabic (3,520 keys per language, 100% symmetry).
  - Created dedicated test suite `Cycle96ContentAndImportSecurityHardeningTest` (10 tests, 126 assertions, 100% pass).

## [1.26.165] — 2026-11-06

### Studio AI & Schema Hardening, Quotas & Type Sanitization (Cycle #96)
- **Studio Security Hardening (`StudioController`)**:
  - Added payload size validation on AI endpoints (2MB schema, 10MB chat).
  - Added quotas on batch operations (max 50 actions, max 10 image generation prompts, max 20 translation entries).
  - Enforced project collection limit (max 100 collections).
  - TypeScript interface and identifier sanitization preventing arbitrary symbol injection in generated types.
  - Added audit logging on automated actions and schema provisionings.

## [1.26.164] — 2026-11-06

### Media & Asset Security Hardening, Magic Bytes Inspection & Orphan Retention (Cycle #95)
- **Media & Upload Hardening (`UploadSecurityValidator`, `DirectUploadService`, `MediaController`)**:
  - Full magic bytes validation for all formats (images, audio, video, documents, archives).
  - Cross-validation between declared MIME and real bytes; active script detection in text/data formats.
  - Head inspection on S3 multipart complete uploads with automatic quarantine and malicious object eviction.
  - Partition disk space protection (< 50MB check) and automatic remote Flysystem/S3 purging.
  - New pruning CLI command `app:media:prune-orphans` (`PruneOrphanUploadsCommand`).

## [1.26.163] — 2026-11-06

### Blueprint Import & Collection Template Hardening (Cycle #94)
- Hardened collection template application, alias normalization, and collection quota limits.

### Schema & Template Hardening, Anti-Injection & Inter-Collection Relation Isolation (Cycle #94)
- **Collection Template Security Hardening (`CollectionTemplateController`)**:
  - Implemented rate limiting via `limiter.template_limiter` for create, save-from-collection, show, and delete operations.
  - Added strict payload size limits (`MAX_PAYLOAD_BYTES = 512KB`) preventing memory exhaustion attacks.
  - Sanitized template names and descriptions: stripped ASCII control characters (`[\x00-\x1F\x7F]`) and HTML/script tags.
  - Duplicate field slug rejection: prevents schema collisions with `422 DUPLICATE_FIELD_SLUG`.
  - Nested options protection: enforced maximum array depth (`MAX_OPTIONS_DEPTH = 6`) and payload size (`MAX_OPTIONS_PAYLOAD_BYTES = 32KB`).
  - Strict field type verification against `FieldController::ALLOWED_TYPES`.
  - Relation field option normalization: eliminated raw internal database ID leakage, enforcing portable `collection_slug` or isolated `orphan` tracking.
- **Project Template & Builder Hardening (`ProjectTemplateBuilder`)**:
  - Sanitized project names, collection descriptions, and field labels.
  - Portable relation serialization: converts inter-collection foreign keys to target collection slugs during export.
  - Defensive relation binding: remaps inter-collection relations to newly created collections post-flush in target projects.
  - Orphan relation detection & isolation: flags relations pointing to non-existent or foreign collections with `orphan: true`, preventing cross-tenant data leakage.
- **Project Structure Export/Import Hardening (`StructureExportHandler`, `StructureImportHandler`)**:
  - Export handler portable normalization: exports target collection slugs and strips primary key integers.
  - Project collection quota enforcement: validates active collections + incoming collections <= 100 (`import.structure.project_collection_quota_exceeded`).
  - Deep options protection (`depth > 6`) and duplicate field slug rejection within collections.
  - Orphan inter-collection relation isolation: sanitizes relations referencing collections absent from both target project and import payload.
- **Blueprint Import Hardening (`BlueprintImportCommand`)**:
  - Added secure multi-format support for JSON and YAML definitions using `Yaml::parse()` with `PARSE_EXCEPTION_ON_INVALID_TYPE`.
  - Enforced project collection limit (maximum 100 collections).
- **I18n Parity & Testing**:
  - Added 7 new translation keys across English, French, Spanish, and Arabic (3,461 keys each, 100% symmetry).
  - Created dedicated test suite `Cycle94TemplateExportHardeningTest` (12 tests, 106 assertions, 100% pass).

## [1.26.161] — 2026-11-05

### Search Security Hardening, Query Complexity Protection, Tier Quotas & Safe Pagination (Cycle #93)
- **Search Query Complexity & DoS Protection (`SearchController`)**:
  - Enforced query parameter validation (`q`): rejected non-string queries, empty or whitespace-only inputs.
  - Implemented Unicode searchable character requirement (`[\p{L}\p{N}]/u`): prevents DoS/CPU spikes on queries composed solely of wildcards, operators, or punctuation (`???`, `***`, `%%%`, `((()))`).
  - Added query token limitation: maximum 25 terms (`MAX_QUERY_TERMS`) to prevent token stream flooding.
  - Added parenthesis nesting depth protection: capped to 3 levels (`MAX_NESTING_DEPTH`) to prevent syntax tree exhaustion.
  - Collapsed excessive wildcard repetitions (`[%_*?~]{5,}`) to prevent ReDoS and SQL LIKE query degradation.
- **Filter Injections & Safe Pagination (`SearchController`, `SearchService`)**:
  - Filter sanitization: strict regex checks on `collection` (`/^[a-zA-Z0-9_\-]{1,64}$/`) and `locale` (`/^[a-zA-Z]{2,4}([-_][a-zA-Z0-9]{2,8})?$/`, max 16 chars).
  - Added `status` filter validation: whitelisted against `['published', 'draft', 'archived', 'scheduled']`.
  - Added `sort` parameter validation: whitelisted sort fields (`['created_at', 'updated_at', 'published_at', 'status', 'slug']`) and directions (`asc`, `desc`).
  - Safe pagination: validated numeric limit (`MAX_PAGE_LIMIT = 100`) and offset (`MAX_OFFSET = 10000`), with transparent `page` to `offset` conversion and out-of-bounds rejection.
- **Search Index Document Quotas by Tier (`SearchService`)**:
  - Introduced index document quotas by tenant tier: `free` (1,000), `starter` (5,000), `pro` (25,000), `enterprise` (100,000), with custom override support (`project.settings['max_search_documents']`).
  - Reindex quota enforcement: `/api/projects/{uuid}/search/reindex` returns HTTP 422 with `SEARCH_DOCUMENT_LIMIT_EXCEEDED` if project entries exceed the tier quota.
  - Reindex truncation safeguard: automatically truncates indexed batches in Meilisearch to the tier limit.
  - Real-time indexing protection: skips indexing new documents when the project document quota is reached while allowing in-place updates of already-indexed entries.
  - Enhanced database fallback search with `status`, `metaTitle`, and consistent pagination metadata (`estimatedTotalHits`).
- **Strict 100% I18n Compliance**:
  - Added 11 new translation keys across all 4 locales (`en`, `fr`, `es`, `ar`), maintaining exact parity (3,454 keys per locale, 0 diffs).
- **Test Suite Verification**:
  - Added dedicated test suite `Cycle93SearchHardeningTest` (16 tests, 152 assertions, 100% pass).

## [1.26.160] — 2026-11-05

### Versioning Quotas, Snapshot Sanitization, Auto-Retention Pruning & Project-Wide Prune Hardening (Cycle #92)
- **Content Versioning Quotas & Auto-Retention (`VersioningService`)**:
  - Implemented multi-tenant project version quotas based on tenant tier: `free` (500), `starter` (2,500), `pro` (10,000), `enterprise` (50,000) with custom override support (`max_versions`).
  - Added entry-level version retention limits: `free` (20), `starter` (50), `pro` (100), `enterprise` (250) with custom override support (`max_versions_per_entry`).
  - Automatic oldest-version pruning upon new snapshot creation when entry exceeds its retention quota.
  - Snapshot payload size validation: enforces strict 1 MB (`MAX_SNAPSHOT_BYTES`) cap preventing JSON memory-exhaustion DoS attacks.
  - Label sanitization: automatically strips HTML tags, filters control characters, and truncates to 150 characters (`MAX_LABEL_LENGTH`).
- **Project-Wide Version Pruning & Label Modification Endpoints (`VersioningController`)**:
  - Added `POST /api/projects/{projectUuid}/versions/prune`: enables administrators to purge versions older than `days` (bounded 1 to 365) and/or prune all entries in project to `keep` recent versions (bounded 1 to 100).
  - Added `PATCH /api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/{versionNumber}/label`: enables updating version labels with strict sanitization and audit logging.
  - Bounded request payload size (`MAX_PAYLOAD_BYTES = 16384`) on all mutation endpoints.
- **Optimized Content Version Repository Methods (`ContentVersionRepository`)**:
  - Added high-performance scalar counting: `countByEntry(ContentEntry $entry)` and `countByProject(Project $project)`.
  - Added `findOlderThanForProject` and batch `pruneOlderThanForProject`.
- **Test Suite Regression Fix (`AutomationAndFlowHardeningTest`)**:
  - Fixed bilingual assertions in `AutomationAndFlowHardeningTest` for flow graph depth limit and trigger incoming edges to match Symfony Translator output across both French and English environments.
- **Strict 100% I18n Compliance**:
  - Added 10 new translation keys across all 4 locales (`en`, `fr`, `es`, `ar`), maintaining exact parity (3,443 keys per locale, 0 diffs).
- **Test Suite Verification**:
  - Added `Cycle92VersioningHardeningTest` covering version quotas, auto-retention pruning, label sanitization, snapshot byte caps, project prune endpoints, label updates, and repository count queries (9 tests, 116 assertions, 100% pass).

## [1.26.159] — 2026-11-05

### Outbound HTTP Flow Node SSRF, Recursion & Complete Flow Validator I18n Hardening (Cycle #91 Finalization)
- **Flow Engine HTTP Request Node Hardening (`HttpRequestHandler`)**:
  - Added hop count tracking and recursion limit enforcement: requests exceeding 5 hops are blocked with translated error code.
  - Propagated platform-guaranteed header `X-JamboApi-Hop-Count` along with `X-JamboApi-Project`, `X-JamboApi-Automation-Id`, and `X-JamboApi-Run-Id`.
  - Added configurable timeout support: bounded between 1.0s and 15.0s (default 5.0s) and max total duration between 2.0s and 30.0s (default 10.0s).
  - Added configurable retry support: bounded between 0 and 3 retries with exponential backoff on HTTP 502/503/504 errors, and strict non-retry policy on SSRF blocks (`\InvalidArgumentException`).
  - Added explicit translated SSRF blocking message (`flow.http_request.ssrf_blocked`).
- **Complete Flow Validator I18n Localization (`FlowValidator`)**:
  - Replaced all 25+ hardcoded error messages with Symfony Translator calls (`$this->trans(...)`), supporting dynamic parameter placeholders with backward-compatible fallbacks.
  - Covers structural errors (at least one node, edges required, nodes/edges DoS limits), variable naming & sensitive patterns, node integrity (invalid ID format, duplicate ID, required/unknown type), edge integrity (source/target not found), DAG cycle detection, depth limits, trigger constraints, and orphan nodes.
- **Strict 100% I18n Compliance**:
  - Added 27 new translation keys across all 4 locales (`en`, `fr`, `es`, `ar`), maintaining exact parity (3,433 keys per locale, 0 diffs).
- **Test Suite Verification**:
  - Enhanced `Cycle91WebhookAndFlowHardeningTest` to 12 tests (40 assertions) with 0 failures, 0 notices, verifying `HttpRequestHandler` hop count, SSRF blocking, 503 exponential retries, and `FlowValidator` i18n translation coverage.

## [1.26.158] — 2026-11-05

### Webhook SSRF, Flow Recursion, Retries & Log Retention Hardening (Cycle #91)
- **Flow Engine SSRF Prevention & Graph Validation (`FlowValidator`, `CallWebhookHandler`)**:
  - Integrated `SsrfProtectionService` directly into `FlowValidator` to reject actions (`action.call_webhook`, `action.http_request`) targeting private RFC 1918 subnets (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), RFC 6598 carrier-grade NAT (`100.64.0.0/10`), IPv6 ULA/link-local, cloud metadata services (`169.254.169.254`, `fd00:ec2::254`, `metadata.google.internal`), and evasive IP formats (hexadecimal, decimal integer, octal) during flow compilation and pre-flight validation.
  - Enforced strict URL length (`MAX_URL_LENGTH = 2048`) and anti-CRLF control characters validation on action configurations.
- **Outbound Webhooks Reliability, Timeouts & Exponential Backoff (`CallWebhookHandler`, `WebhookService`)**:
  - Added configurable timeout support in `CallWebhookHandler`: bounded between 1.0s and 15.0s (default 5.0s) and max total duration between 2.0s and 30.0s (default 10.0s).
  - Implemented exponential backoff retries (up to 3 retries) on transient network errors or HTTP 502/503/504 status codes. Strict non-retry policy enforced on SSRF security blocks (`\InvalidArgumentException`) and 4xx client errors.
  - Added multi-tenant outbound webhook payload size restriction (`MAX_PAYLOAD_BYTES = 512KB`) in `WebhookService` with explicit i18n logging upon violation.
- **Automation Recursion & Infinite Loop Prevention (`CallWebhookHandler`, `WebhookInboundController`)**:
  - Implemented hop count tracking via platform-guaranteed header `X-JamboApi-Hop-Count` bounded to 5 hops across flow webhooks.
  - Added loop detection in `WebhookInboundController`: requests exceeding maximum hop count are rejected with 429 (`AUTOMATION_LOOP_DETECTED`).
  - Added direct self-trigger recursion detection in `WebhookInboundController`: inbound requests originating from the same project and same automation are rejected with 400 (`DIRECT_RECURSION_DETECTED`).
  - Sanitized inbound payloads to prevent injection or overwriting of internal engine variables (`_node_config`, `_hop_count`, `_trigger`).
- **Webhook Log Retention & Pruning Endpoint (`WebhookController`, `WebhookLogRepository`)**:
  - Added `POST /api/projects/{projectUuid}/webhooks/{uuid}/logs/prune` enabling administrators to prune webhook logs older than specified retention days (bounded between 1 and 365 days) with audit logging.
  - Fixed comment reply nesting flattening regression in `CommentController` ensuring nested comments are flattened to root parent and 404 response on project mismatch.
- **Strict 100% I18n Compliance**:
  - Added 11 new translation keys across all 4 locales (`en`, `fr`, `es`, `ar`), maintaining exact parity (3,406 keys per locale).
- **Test Suite Verification**:
  - Added `Cycle91WebhookAndFlowHardeningTest` covering SSRF static/semi-static rejection, metadata blocking, hop count recursion limits, self-recursion prevention, 503 exponential retries, payload size caps, and log pruning (8 tests, 28 assertions, 100% pass).

## [1.26.157] — 2026-11-05

### Comment Quotas, Nesting Security & Notification Volume Hardening (Cycle #90)
- **Comment Quotas & Anti-XSS Sanitization (`CommentController` & `CommentRepository`)**:
  - Implemented multi-tenant project comment quotas based on tenant tier: `free` (250), `starter` (1,000), `pro` (5,000), `enterprise` (25,000) with custom override support (`max_comments`).
  - Added entry-level comment quotas: `free` (100), `starter` (250), `pro` (1,000), `enterprise` (5,000) with custom override support (`max_comments_per_entry`).
  - Strict anti-XSS comment body sanitation: eradicated `<script>`, `<iframe>`, `<object>`, `<embed>`, `<svg>`, `<form>`, `<style>` blocks and their inner contents, removed inline event handlers (`onload`, `onerror`, `onclick`), neutralized malicious URI schemes (`javascript:`, `data:`, `vbscript:`, `file:`) in HTML and Markdown links, enforced safe `<a>` tags with `noopener noreferrer nofollow`, and collapsed newline/quote bombing attacks.
  - Nesting depth control & cycle prevention: bounded comment reply nesting to `MAX_NESTING_DEPTH = 3`, validated parent entry ownership, and detected circular parent hierarchies returning explicit 422 errors.
  - Added `countByProject(Project $project)` method to `CommentRepository`.
- **Notification Volume Control, Pruning & Bounded Batch Operations (`NotificationController`, `NotificationService`, `NotificationRepository`)**:
  - Enforced per-user storage quotas (`MAX_USER_NOTIFICATIONS = 1000`, `MAX_USER_UNREAD_NOTIFICATIONS = 500`) with automatic oldest-read pruning upon new notification creation.
  - Added secure bounded batch operations: `POST /api/notifications/batch-read` and `POST /api/notifications/batch-delete` bounded to `BATCH_MAX_IDS = 100` and `MAX_PAYLOAD_BYTES = 64KB`.
  - Added `POST /api/notifications/prune` endpoint enabling users to purge read notifications older than `days` (bounded between 1 and 365 days).
  - Implemented strict payload sanitization in `NotificationService`: titles stripped of all HTML and control characters, bodies sanitized and stripped of script blocks and event handlers, links strictly validated against forbidden schemes.
  - Added `countByRecipient`, `markBatchAsRead`, `deleteBatch`, `pruneReadOlderThanForUser`, and `pruneOldestForUser` in `NotificationRepository`.
- **Strict 100% I18n Compliance**:
  - Added 17 new translation keys across all 4 locales (`en`, `fr`, `es`, `ar`), maintaining exact parity (3,395 keys per locale).
- **Test Suite Verification**:
  - Added `Cycle90CommentAndNotificationHardeningTest` covering all comment quota verifications, XSS and Markdown sanitization, nesting depth enforcement, circular hierarchy detection, entry ownership checks, notification batch actions, pruning, quota auto-pruning, and I18n parity (17 tests, 147 assertions, 100% pass).

## [1.26.156] — 2026-11-05

### Plugin Quotas, Sandboxing Security & Table Column Settings Hardening (Cycle #89)
- **Plugin Sandboxing & Manifest Security (`PluginManager` & `PluginController`)**:
  - Enforced anti-execution sandboxing: blocked dangerous binaries and shell scripts (`.sh`, `.exe`, `.bin`, `.cgi`, `.bat`, `.cmd`, `.phar`, `.env`, `.htaccess`, etc.) and path traversal (`..`, `\0`, absolute paths, stream wrappers).
  - Reserved plugin IDs protection: blocked registration of reserved system IDs (`system`, `core`, `admin`, `api`, `config`, `root`, `null`, `undefined`, `jambo`).
  - Added strict recursive validation on manifest and plugin settings: enforced max depth (`MAX_SETTINGS_DEPTH = 5`), max keys (`MAX_SETTINGS_KEYS = 50`), anti-prototype pollution (`__proto__`, `constructor`, `prototype`), and stripped malicious script tags or dangerous protocols (`javascript:`, `vbscript:`, `data:text/html`).
  - Bounded permissions (`MAX_PERMISSIONS_COUNT = 20`) and hooks (`MAX_HOOKS_COUNT = 30`) to whitelist sets.
  - Implemented global plugin registration quota (`MAX_REGISTERED_PLUGINS = 100`) and global active plugins limit (`MAX_ACTIVE_PLUGINS_GLOBAL = 50`).
- **Multi-Tenant Project Plugin Management & Tier Quotas (`ProjectPluginController` & `PluginManager`)**:
  - Added `ProjectPluginController` with endpoints for listing project plugins, toggling activation, and managing project-specific plugin configurations.
  - Enforced project/tenant tier plugin quotas: `free` (3), `starter` (10), `pro` (25), `enterprise` (100) or custom override (`max_plugins`).
  - Blocked project activation of globally disabled plugins and prevented exceeding tier quotas with explicit 422 `QUOTA_EXCEEDED` response.
  - Audit logging for all project plugin toggle and settings update operations.
- **Table Column Settings Hardening (`ColumnSettingController`)**:
  - Reduced payload size limit to 32 KB (`MAX_PAYLOAD_BYTES = 32768`).
  - Enforced maximum JSON depth (`MAX_JSON_DEPTH = 4`) and anti-prototype pollution checks (`__proto__`, `constructor`, `prototype`).
  - Capped maximum visible columns to 50 (`MAX_COLUMNS_COUNT = 50`), sanitizing tags and stripping control characters while preserving valid field slugs and structured column definitions (`id`, `visible`, `width`, `order`).
  - Added `DELETE` route to reset column settings back to default with audit trail.
- **Strict 100% I18n Compliance**:
  - Added 19 new translation keys across all 4 locales (`en`, `fr`, `es`, `ar`), maintaining exact parity (3,378 keys per locale).
- **Test Suite Verification**:
  - Added `Cycle89PluginAndColumnSettingHardeningTest` covering all plugin sandboxing, quota enforcement, entrypoint security, settings sanitization, project tier limits, column settings depth, prototype pollution defense, column capping, and reset functionality (28 tests, 102 assertions, 100% pass).

## [1.26.155] — 2026-11-05

### Visual Blocks Quotas, Page Composition Depth & XSS/DOM Custom Script Security (Cycle #88)
- **Visual Block Quotas & Recursion Defense (`PageCompositionSecurityService` & `StudioController`)**:
  - Implemented visual block quotas: `MAX_COLLECTIONS_PER_SCHEMA = 50`, `MAX_FIELDS_PER_COLLECTION = 100`, `MAX_SUBFIELDS_PER_BLOCK = 50`, `MAX_TOTAL_BLOCKS = 500`, and `MAX_BATCH_ENTRIES = 100`.
  - Enforced strict page JSON composition depth limit (`MAX_JSON_DEPTH = 10`) protecting against deeply nested DoS and recursive stack overflow attacks.
  - Implemented prototype pollution prevention blocking dangerous keys (`__proto__`, `constructor`, `prototype`) in composition and schema structures.
- **XSS/DOM Attribute Sanitization & Custom Script Injection Hardening (`PageCompositionSecurityService`, `StudioController` & `PageController`)**:
  - Sanitized DOM attributes: stripped inline DOM event handlers (`on*`: `onclick`, `onload`, `onerror`, etc.), neutralized dangerous URI schemes (`javascript:`, `vbscript:`, `data:text/html`), and neutralized malicious CSS expression / behavior bindings.
  - Hardened collection/page settings against unauthorized custom script injection; restricted inline script tags to safe JSON-LD (`application/ld+json`) and enforced strict HTTPS protocols for external scripts.
  - Sanitized plain text fields (collection name, description, field names) stripping executable tags and control characters.
- **EndUsers Security in PageController (`PageController`)**:
  - Integrated `EndUserCustomFieldsSanitizer` into `settingsEndUsersStore` and `settingsEndUsersUpdate`, validating custom fields depth, size and attributes.
  - Sanitized `username` inputs and resolved hardcoded error string on duplicate emails using `page.email_already_registered`.
  - Added translated exceptions for collection and project lookup in `resolveProjectAndCollection`.
  - Re-aligned `InsightsController` 404 response handling to preserve both format validation and multi-tenant isolation.
- **Strict 100% I18n Compliance**:
  - Added 12 new translation keys across all 4 locales (`en`, `fr`, `es`, `ar`): `studio.quota.max_collections_exceeded`, `studio.quota.max_fields_exceeded`, `studio.quota.max_subfields_exceeded`, `studio.quota.max_total_blocks_exceeded`, `studio.quota.max_batch_entries_exceeded`, `studio.composition.max_depth_exceeded`, `studio.composition.forbidden_key`, `studio.security.script_injection_detected`, `studio.security.dangerous_attribute_detected`, `page.project_not_found`, `page.collection_not_found`, `page.composition.max_depth_exceeded`. Exactly 3,359 keys per locale.
- **Test Suite Verification**:
  - Added `Cycle88StudioAndPageHardeningTest` covering block quotas, batch entries limits, JSON depth limits, prototype pollution defense, DOM attribute sanitization, script injection rejection, EndUser custom field validation, username sanitization, and translated exceptions (22 tests, 58 assertions, 100% pass).

## [1.26.154] — 2026-11-05

### AI Payload Protection, Personal Access Token & Webhook Quotas, Multi-Tenant Resiliency (Cycle #87)
- **AI Controller DoS Defense, Catch Syntax Resiliency & Audit Logging (`AiController`)**:
  - Enforced strict payload limits (`MAX_PAYLOAD_BYTES = 1MB`) across AI endpoints (`/generate`, `/translate`, `/summarize`, `/seo`) before `json_decode`, preventing memory exhaustion attacks.
  - Fixed unassigned `catch (\Throwable $e)` syntax in `seo()` eliminating undefined variable exceptions.
  - Integrated `AuditService` logging across AI operations (`ai_generate`, `ai_translate`, `ai_summarize`, `ai_seo`, `ai_suggest_schema`) with token and user attribution.
- **Personal Access Token Quota & Lifecycle Audit Hardening (`TokenController` & `PersonalAccessTokenRepository`)**:
  - Implemented user token quota limit (`MAX_TOKENS_PER_USER = 50`) via `countByUser()` in `PersonalAccessTokenRepository`, preventing table exhaustion and token flooding attacks.
  - Integrated `AuditService` logging for personal access token lifecycle events (`personal_access_token_create`, `personal_access_token_update`, `personal_access_token_delete`).
- **Project Webhook Quotas & Early SSRF Rejection (`WebhookController`)**:
  - Enforced project webhook limit (`MAX_WEBHOOKS_PER_PROJECT = 50`) immediately upon project resolution, preventing webhook storming and eliminating unnecessary DNS/SSRF checks.
  - Returns localized HTTP 422 with `webhook.limit_exceeded` error code.
- **Insights Multi-Tenant API Resiliency (`InsightsController`)**:
  - Replaced thrown `createNotFoundException` with structured JSON 404 responses with anti-cache security headers (`no-store`) and translated error messages.
  - Enforced multi-tenant token isolation check against request attribute `_project` (`insights.token_project_mismatch`) returning structured 403 responses.
- **Strict 100% I18n Compliance**:
  - Added 4 new keys across all 4 locales (`en`, `fr`, `es`, `ar`): `ai.payload_too_large`, `token.max_tokens_reached`, `webhook.limit_exceeded`, `insights.token_project_mismatch`. Exactly 3,347 keys per locale.
- **Test Suite Verification**:
  - Added dedicated test suite `Cycle87SecurityAndQuotaHardeningTest` covering AI payload guards, SEO exception recovery, AI audit logging, PAT quota enforcement & lifecycle logs, webhook limits, Insights multi-tenant 404/403 responses, and 4-locale dictionary parity (10 tests, 69 assertions, 100% pass).

## [1.26.153] — 2026-11-05

### Export/Import Hardening, Tenant Quotas Enforcement & Backup Volume Controls (Cycle #86)
- **Tenant Storage Quota & Media Archive Hardening (`MediaImportHandler` & `ProjectExportImportController`)**:
  - Implemented cumulative media byte verification during archive extraction (`MAX_TOTAL_MEDIA_BYTES = 250MB`).
  - Added strict tenant storage quota checks against `MediaRepository::getTotalSizeByProject($project)` during import (`upload.error.quota_exceeded`), preventing project import from bypassing storage caps.
  - Enforced project creation limit on archive import (`MAX_PROJECTS_PER_USER = 100`) via `project.export_import.max_projects_exceeded`.
- **Export Permissions & Disk Space Hardening (`ProjectExportImportController` & `ProjectExporter`)**:
  - Restricted export of sensitive sections (`endUsers`, `settings`) to members with `project.manage` permission (or super admins).
  - Added filesystem disk free space guard (`MIN_FREE_DISK_BYTES = 500MB`) in `ProjectExporter` before staging export archives.
  - Wrapped export stream generation in resilient exception handling returning localized error responses.
- **Tenant & System Backup Volume Controls (`BackupManager` & `BackupController`)**:
  - Added backup retention policy and storage volume limits (`MAX_TOTAL_BACKUPS = 25`, `MAX_TOTAL_STORAGE_BYTES = 2GB`, `MIN_FREE_DISK_BYTES = 500MB`).
  - Implemented automatic pruning of oldest backups (`pruneOldBackups`) and partition disk space checks.
  - Added volume metadata (`total_size`, `count`, `max_count`, `max_bytes`, `available_disk_space`) in `GET /api/admin/backups` response.
  - Hardened `POST /api/admin/backups` with graceful 422 error handling on storage or disk space limits.
- **Strict I18n Expansion**:
  - Added 5 new localized keys across all 4 locales (`en`, `fr`, `es`, `ar`): `backup.insufficient_disk_space`, `backup.storage_limit_reached`, `export.insufficient_disk_space`, `import.media.cumulative_size_exceeded`, `project.export_import.max_projects_exceeded`. Total: 3,343 keys per locale.

## [1.26.152] — 2026-11-05

### 2FA & Backup Codes Hardening, Distributed Brute-Force Protection & Admin 2FA Reset (Cycle #86+)
- **Distributed Brute-Force & Account Lockout Defense (`TwoFactorChallengeController`)**:
  - Implemented persistent multi-tier cache-based lockout (`user_2fa_account:<id>` & `user_2fa_ip:<ip>`) via `ProjectAuthLockoutService`, preventing botnets and distributed IP proxies from bypassing per-IP sliding windows.
  - Added dedicated distributed brute-force protection for static recovery keys (`user_2fa_backup:<id>`) with strict lockout thresholds (3 attempts -> 300s, 5 -> 900s, 8 -> 3600s).
  - Integrated `AuditService` logging for 2FA verification attempts (`auth_2fa_verify` and `auth_2fa_failure`).
- **Backup Codes Validation & Re-Authentication Hardening (`TwoFactorService` & `SecurityController`)**:
  - Hardened `verifyAndConsumeBackupCode` with strict length and character checks (`8` alphanumeric characters `XXXX-XXXX`).
  - Added `getRemainingBackupCodesCount()`, `hasRemainingBackupCodes()`, and `isBackupCodeFormat()` helpers.
  - Enforced mandatory password re-authentication (`isPasswordValid`) on backup code regeneration (`POST /api/settings/security/backup-codes`) to prevent token/session hijacking extraction.
  - Added distributed tiered lockouts and audit logging on 2FA TOTP/email confirmations (`2fa_enable_totp`, `2fa_enable_email`, `2fa_disable`).
  - Updated frontend `assets/js/pages/settings/security.tsx` to prompt for password confirmation when regenerating backup codes.
- **Tenant & End-User 2FA Distributed Brute-Force Hardening (`EndUserAuthController`)**:
  - Added dedicated backup code lockout (`enduser_2fa_backup:<uuid>`) and automatic syntax detection (`isBackupCodeFormat`) on tenant 2FA verification.
  - Tracked backup code consumption and remaining count on verified challenges.
- **Administrative 2FA Reset Flows (`UserController` & `EndUserAdminController`)**:
  - Created Super Admin endpoint `POST /api/users/{id}/reset-2fa` with self-reset prevention, active lockout clearing, and security audit logging.
  - Created Project Admin endpoint `POST /api/projects/{uuid}/end-users/{endUserUuid}/reset-2fa` with project isolation, lockout clearing, and audit logging.
  - Serialized `two_factor_enabled` and `two_factor_method` flags across user and end-user management APIs.
- **Strict I18n Expansion**:
  - Added 9 complete translation keys across `en`, `fr`, `es`, and `ar` dictionaries (exactly 3,338 keys in each file).

## [1.26.151] — 2026-11-05

### OAuth & OIDC Callback Resiliency, Anti-Cache Hardening, Social Auth & Strict I18n (Cycle #86)
- **OAuth & OIDC Callback Exception & Cancellation Resiliency (`SocialLoginController` & `OidcController`)**:
  - Replaced unhandled 500 `LogicException` on provider errors and user cancellations (`error=access_denied`, etc.) with graceful error handling and redirection to login.
  - Automatically cleaned up temporary session state (`oauth2state_*`, `oauth2verifier_*`, `oidc_state`, `oidc_code_verifier`, `oidc_nonce`) on callback aborts.
  - Redirected with translated error keys (`auth.social.cancelled`, `auth.social.error_failed`, `oidc.cancelled`, `oidc.error_failed`).
- **OAuth & OIDC Authorization URL Anti-Cache Hardening**:
  - Added strict anti-cache headers (`Cache-Control: no-cache, no-store, must-revalidate`, `Pragma: no-cache`) to authorization redirect responses in both `SocialLoginController::connect` and `OidcController::start` to prevent browsers or intermediaries from caching single-use PKCE and CSRF states.
  - Ensured explicit session initialization on `/connect/{provider}` before storing state.
  - Added safe decryption error handling for encrypted OIDC client secrets (`enc:...`) in `OidcController::start`, preventing unhandled 500 error traces and returning translated 404 responses.
- **Login Error Propagation & Strict I18n (`SecurityController` & `login.tsx`)**:
  - Safely propagated allowed translated error query keys in `SecurityController::login`, mapping legacy codes (`oidc_failed` -> `oidc.error_failed`, `social_auth_failed` -> `auth.social.error_failed`) while maintaining strict allow-list sanitization against injection attacks.
  - Updated `assets/js/pages/auth/login.tsx` to dynamically translate error parameters with `t(error)` without hardcoded UI strings.
- **Enterprise SSO OIDC Identity Recognition (`SocialLoginService`)**:
  - Extended `SocialLoginService::canUnlinkProvider` to recognize enterprise OIDC identities (`$user->oidcSub`) as a valid persistent authentication mechanism, preventing lockouts while allowing social unlinking.
- **Registration Flow Password Policy Optimization (`RegisteredUserController`)**:
  - Reordered validation in `RegisteredUserController::store` to execute NIST/OWASP password policy verification prior to database email uniqueness checks, eliminating unnecessary DB queries for weak passwords.
- **Strict 100% I18n Compliance**:
  - Added 3 new translation keys (`auth.social.error_failed`, `auth.social.cancelled`, `oidc.cancelled`) with 100% parity across all 4 supported locales (3,329 keys each in English, French, Spanish, and Arabic).
- **Test Suite Verification**:
  - Created dedicated test suite `Cycle86OAuthAndOidcHardeningTest` covering cancellation handling, session purging, anti-cache headers, safe secret decryption, login error propagation, OIDC unlink recognition, registration order, and 4-locale parity (13 tests, 55 assertions, 100% pass).

## [1.26.150] — 2026-11-05

### OAuth Flow Hardening, End-User Password Reset Anti-Enumeration & Multi-Tenant Reset Purge (Cycle #85)
- **OAuth Authorization Server Security Hardening (`ProjectOAuthController`)**:
  - Added request payload size limit (max 64KB) on `/authorize`, `/token`, and `/revoke` endpoints (HTTP 413).
  - Integrated sliding window rate limiting on credential submission during `/authorize` (HTTP 429).
  - Implemented anti-enumeration timing-safe password evaluation for non-existent users during OAuth login (eliminating email enumeration timing oracles).
  - Unified account status responses (`project_oauth.error.account_inactive`) preventing internal status enumeration.
  - Enforced dual account and IP tiered lockout mechanisms (`enduser_login` and `enduser_login_ip`) shared with `EndUserAuthController::login`.
  - Hardened `/token` and `/revoke` endpoints with tenant-scoped IP lockout tracking (`oauth_token_ip`, `oauth_revoke_ip`) and tiered lockout progression.
- **End-User Password Reset Anti-Enumeration & Spray Protection (`EndUserAuthController`)**:
  - Added strict email validation (`FILTER_VALIDATE_EMAIL` and length limit 180 chars) with localized HTTP 422 response.
  - Implemented IP-level reset spray lockout defense (`pw_reset_spray_ip`) returning HTTP 429 with `Retry-After` headers.
  - Added passive expired token cleanup on both forgot-password and reset-password actions.
  - Enforced `password_confirmation` validation matching and optimized password policy verification order to reject weak passwords prior to expensive hash computations.
- **Multi-Tenant Password Reset Token Scoping (`PasswordResetTokenRepository` & `PurgeExpiredTokensCommand`)**:
  - Extended `PasswordResetTokenRepository` with optional tenant context (`?Project $project = null`) on `countExpired`, `deleteExpired`, and `findValidByToken`.
  - Updated `PurgeExpiredTokensCommand` (`app:purge-expired-tokens`) so project-scoped retention runs purge expired reset tokens for that specific project.
- **Strict 100% I18n Compliance**:
  - Added 11 new translation keys (`project_oauth.error.*` and `enduser.auth.*`) with 100% parity across all 4 locales (3,326 keys each in English, French, Spanish, and Arabic).
- **Test Suite Verification**:
  - Added dedicated test suite `Cycle85OAuthAndPasswordResetHardeningTest` covering payload limits, rate limiters, timing defense, lockout tiers, confirmation mismatch, and repository signatures (16 tests, 81 assertions, 100% pass).

## [1.26.149] — 2026-11-05

### Multi-Tenant Token & Session Retention Purge Hardening, PHP 8.5 Warning Elimination & Strict I18n (Cycle #84)
- **Multi-Tenant Token & Inactive Session Retention Purge Hardening**:
  - Extended `PurgeExpiredTokensCommand` (`app:purge-expired-tokens`) with `--project` (`-p`) option to safely scope retention purging to a specific project UUID, ID, or name.
  - Extended count and purge methods with optional tenant context (`?Project $project = null`) across `ApiTokenRepository` (`countExpired`, `deleteExpired`), `ShareRepository` (`countExpired`, `purgeExpired`), `ProjectAuthRefreshTokenRepository` (`countExpired`, `deleteExpired`), `ProjectAuthAuthorizationCodeRepository` (`countExpired`, `deleteExpired`), `ProjectAuthSessionRepository` (`countInactive`, `deleteInactive`), and `AssetUploadIntentRepository` (`countOldIntents`, `deleteOldIntents`).
  - Preserved global purge behavior when no project filter is supplied, purging global password reset tokens and personal access tokens (PAT).
- **PHP 8.5 & Runtime Warning Elimination**:
  - Fixed undefined variable `$project` in `WebhookController::test`, using `$webhook->project?->uuid?->toRfc4122() ?? $projectUuid`, completely resolving 2 PHP warnings.
- **Strict 100% I18n Compliance**:
  - Completely localized `PurgeExpiredTokensCommand` (command description, options, table headers, category labels, dry-run banners, success summaries) using `$this->trans()`.
  - Added 28 new translation keys (`cli.purge_tokens.*`) across all 4 locales with 100% parity (3,315 keys in `messages.en.php`, `messages.fr.php`, `messages.es.php`, `messages.ar.php`).
- **Test Suite Verification**:
  - Added dedicated test `Cycle84TokenAndSessionRetentionPurgeTest` covering simulation, project error handling, tenant purge isolation, and 4-locale parity (4 tests, 252 assertions, 100% success).

## [1.26.148] — 2026-11-05

### Symfony 8.1 Preparation, Burst Rate Limiting & Public Endpoints CSP/HSTS Hardening (Cycle #83 Part 2)
- **Symfony 8.1 / UX Turbo Forward-Compatibility**:
  - Implemented `RemoveDeprecatedTurboRendererPass` compiler pass migrating deprecated `TurboStreamListenRenderer` definitions to `MercureStreamSourceRenderer` with `turbo.renderer.stream_listen` tag, suppressing class-load deprecation notices in PHP 8.5 / Symfony 8.1.
  - Added dedicated unit test `RemoveDeprecatedTurboRendererPassTest` validating container compilation, service aliasing, and tag attributes.
  - Configured explicit `server_version: 'mariadb-10.6.18'` under `when@prod: doctrine: dbal` in `config/packages/doctrine.yaml` for o2switch MariaDB infrastructure, eliminating runtime introspection queries.
- **Automation Execution Burst Limiting & Queue / CLI Quota Enforcement**:
  - Extended `AutomationQuotaService` with per-minute rate limiting (`TIER_MINUTE_LIMITS`: Free 10/min, Starter 30/min, Pro 120/min, Enterprise 1,000/min) and custom override via `automation_runs_limit_per_minute` / `automation_minute_quota`.
  - Added helper methods `getMinuteLimit()`, `getMinuteUsage()`, `isBurstLimited()`, and `isDailyLimitReached()`.
  - Wired quota enforcement into `ExecuteAutomationMessageHandler` to reject queued executions with translated error and mark run status as `failed`.
  - Wired quota check into `AutomationTriggerSubscriber` to skip dispatching and prevent message queue exhaustion when quota is exceeded.
  - Wired quota check into CLI command `automation:run-scheduled` (`AutomationRunScheduledCommand`) to safely skip throttled projects with translated console feedback.
- **Extended Public & Preview Endpoints Security Hardening (CSP & HSTS)**:
  - Hardened `RedirectPublicController`, `FormPublicController`, `SeoPublicController`, `EmbedController`, and `ProjectExportImportController` with `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none';`, `X-Frame-Options: DENY`, and `Referrer-Policy: no-referrer`.
  - Maintained iframe embedding compatibility on `/embed/forms/{projectUuid}/{slug}` with `frame-ancestors *;` while adding strict HSTS.
- **Strict I18n Compliance**:
  - Added 2 new translation keys (`automation.quota.minute_limit_reached`, `cli.automation.quota_exceeded_skipped`) with 100% parity across all 4 locales (3,287 keys in `en`, `fr`, `es`, `ar`).
- **Test Suite Validation**:
  - Added unit test `RemoveDeprecatedTurboRendererPassTest` and expanded `Cycle83QuotaAndSecurityHardeningTest` covering burst rate limits, queue rejection, and security headers.

## [1.26.147] — 2026-11-05

### Security, Multi-tenant Isolation & I18n Hardening — Flow Quotas & Public Share/Preview Hardening (Cycle #83)
- **Multi-Tenant Automation Run Quotas & Tier Enforcement**:
  - Implemented `AutomationQuotaService` managing tiered daily execution limits for automation flows:
    - Free: 100 runs/day
    - Starter: 500 runs/day
    - Pro: 2,500 runs/day
    - Enterprise: 50,000 runs/day
    - Custom project overrides supported via `$project->settings['automation_runs_limit_daily']` or `$project->settings['automation_daily_quota']`.
  - Added `AutomationRunRepository::countRunsForProjectSince()` for tracking project executions in current UTC day.
  - Enforced quotas in `AutomationController::run` and `WebhookInboundController::receive` with HTTP 429 status code, `Retry-After` header, structured JSON error, and security audit log tracking (`automation_run_quota_exceeded`).
- **Public Share & Preview Security Hardening (CSP, HSTS & Anti-Framing)**:
  - Enforced strict `Content-Security-Policy` (`default-src 'none'; ... frame-ancestors 'none'`) across `/share/{token}`, `/share/{token}.json`, `/api/projects/{uuid}/shares`, and `/api/projects/{uuid}/preview/content/{col}/{eid}`.
  - Enforced `Strict-Transport-Security` (`max-age=31536000; includeSubDomains`) across all preview, public share, and form embed endpoints.
  - Enforced `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, and `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` to block clickjacking and search engine indexing.
  - Added public preview path exception in `EnsureProjectMemberSubscriber` allowing preview tokens to be verified by `PreviewController::content()`.
- **Strict I18n Compliance**:
  - Eliminated hardcoded French strings from Twig templates (`templates/share/show.html.twig`, `templates/share/error.html.twig`), substituting them with Symfony Translator filters (`|trans`) and `app.request.locale`.
  - Added 12 translation keys with complete translations in English, French, Spanish, and Arabic (`translations/messages.{en,fr,es,ar}.php`).
- **PHP 8.5 & DBAL Compatibility**:
  - Eliminated dynamic property deprecations across `Project::$owner`, `ContentEntry::$name`, and `FlowContext::$project`.
  - Removed deprecated `imagedestroy()` calls in test suites (PHP 8.5 deprecation).
  - Documented explicit `serverVersion` configurations for MariaDB on o2switch/cPanel in `config/packages/doctrine.yaml` and `.env`.
- **Validation**:
  - Created `Cycle83QuotaAndSecurityHardeningTest` covering CSP, HSTS, quota calculations, controller rate limit enforcement, inbound webhook protection, and 100% strict i18n completeness.

## [1.26.146] — 2026-11-05

### Security, Multi-tenant Isolation & I18n Hardening — Operational Log Retention & Tenant-Scoped Purge (Cycle #82)
- **Tenant-Scoped Operational Log Retention & Purge**:
  - Enhanced `AuditRetentionService::purgeLogs()` with optional tenant/project filtering (`?Project $project = null`).
  - Added project filtering and context tracking in logging: `project_id` and `project_uuid` recorded for all retention purge runs.
  - Hardened `AuditLogRepository`, `EmailLogRepository`, `WebhookLogRepository`, `AutomationRunRepository`, and `NotificationRepository` with optional tenant scoping in `deleteOlderThan` and `countOlderThan`.
  - Scoped webhook logs and automation runs via DQL subqueries matching parent entity project relations (`l.webhook IN (SELECT w.id FROM App\Entity\Webhook w WHERE w.project = :project)` and `r.automation IN (SELECT auto.id FROM App\Entity\Automation auto WHERE auto.project = :project)`).
  - Scoped read notifications via dual-link pattern matching (`link LIKE %projectUuid%` or `link LIKE %/projects/{projectId}/%`).
- **Command & CLI Hardening (`app:purge-audit-logs`)**:
  - Added `-p, --project` option to `PurgeAuditLogsCommand` allowing operators to safely execute log retention purge for an isolated tenant by UUID, ID, or name.
  - Added `--notification-days` option for read notification cleanup (defaults to 60 days).
  - Integrated `ProjectRepository::findByIdentifier()` with strict error handling if tenant is nonexistent (`cli.purge_logs.project_not_found`).
  - Full compliance with strict I18N rule: eliminated all hardcoded strings in command execution output, using Symfony Translator and localized dictionary entries.
- **Doctrine DBAL 5 & PHP 8.4/8.5 Compatibility**:
  - Replaced deprecated case-mismatched `\Doctrine\DBAL\Platforms\SqlitePlatform` with canonical `\Doctrine\DBAL\Platforms\SQLitePlatform` in `ContentPurgeCommand` and `FixCorruptedUtf8Command`.
  - Fixed implicit nullable parameter `$memberEmail` deprecation in `Cycle53RealtimeMercureSecurityTest`.
- **Localization (i18n)**:
  - Added 13 localized translation keys across all 4 system languages (`translations/messages.{en,fr,es,ar}.php`).
- **Validation**:
  - Added `Cycle82OperationalLogRetentionAndTenantPurgeTest` testing tenant-scoped purges, dry-run simulations, project routing, and strict multilingual completeness.

## [1.26.145] — 2026-11-05

### Security, Multi-tenant Isolation & I18n Hardening — Audit Logs & Notifications Integrity (Cycle #81)
- **AuditService — Recursive Input & Output Sanitization (CWE-532 protection)**:
  - Extended recursive sanitization to both `$input` AND `$output` fields before persistence.
  - Implemented token/secret auto-redaction: Bearer headers (`Bearer [REDACTED]`), JWT tokens (`[REDACTED_JWT]`), PEM private keys (`[REDACTED_PRIVATE_KEY]`), API key patterns (`[REDACTED_SECRET]`).
  - Added safe buffer lifecycle methods: `hasPending(): bool`, `clearPending(): void`, and error-tolerant `flush()`.
  - Added safe partial JSON encoding and large payload truncation guards (`_truncated`, `preview`).
- **NotificationService — Centralized & Localized Notification Management**:
  - Created `App\Service\NotificationService` with strict multi-tenant project UUID URL builder (`/projects/{uuid}/collections/{colId}/content/{entryId}`).
  - Built localized notification dispatching according to recipient preference (`$recipient->locale ?? 'en'`) with `TranslatorInterface`.
  - Implemented dangerous scheme blocking (`javascript:`, `data:`, `vbscript:`, `file:`).
  - Built specialized notification helpers: `notifyStatusChange`, `notifyComment`, `notifyMention`, `notifyAutomation`.
  - Enforced anti-self-notification guard (`$recipient->id !== $author->id`).
  - Added strict title (255 chars) and body (2000 chars) length truncation guards.
- **NotificationSubscriber & SendNotificationHandler**:
  - Refactored `NotificationSubscriber` to eliminate hardcoded French notification strings and use `NotificationService`.
  - Hardened `SendNotificationHandler`: added automated recipient resolution (config recipient, context user, project owner fallback), project UUID context injection, and i18n label/description metadata.
- **NotificationRepository & AuditRetentionService**:
  - Enhanced `NotificationRepository` project filtering with dual matching (RFC4122 UUID + legacy integer ID pattern).
  - Added `countReadOlderThan` and `deleteReadOlderThan` to purge stale read notifications.
  - Integrated notification retention into `AuditRetentionService` (`DEFAULT_NOTIFICATION_DAYS = 60`).
  - Updated `PurgeAuditLogsCommand` with full `CliSecurityTrait` localization.
- **i18n Dictionaries**:
  - Added 15 complete translation keys across `translations/messages.{en,fr,es,ar}.php`.
- **Test Suite**:
  - Added `Cycle81AuditAndNotificationIntegrityTest.php` with 10 tests and 62 assertions.

## [1.26.144] — 2026-11-05

### Security & Multi-tenant Isolation Hardening — Outbound Webhooks & HTTP Flows (Cycle #80)
- **CallWebhookHandler & HttpRequestHandler — Multi-tenant Isolation & Anti-Spoofing**:
  - Enforced mandatory `projectUuid` context verification.
  - Injected trusted tenant headers: `X-JamboApi-Project`, `X-JamboApi-Automation-Id`, `X-JamboApi-Run-Id`.
  - Added encrypted secret resolution support (`enc:...`) via `WebhookSecretService`.
  - Integrated SSRF protection and request payload size limiting (2MB).
- **SendWebhookMessage & Handler**:
  - Added strict tenant boundary verification between message project and target webhook project.
- **i18n Dictionaries & Test Suite**:
  - Added 23 translation keys in `translations/messages.{en,fr,es,ar}.php`.
  - Added `Cycle80WebhooksIsolationI18nTest.php` (15 tests, 264 assertions).

## [1.26.143] — 2026-11-05

### Security & Multi-tenant Isolation Hardening — Automation Async Flows, Handlers & Scheduler i18n (Cycle #79)
- **ExecuteAutomationMessageHandler — strict multi-tenant isolation**:
  - Enforced verification that `AutomationRun` belongs directly to targeted `Automation` (`automation.error.run_mismatch`).
  - Enforced requirement for an associated `Project` (`automation.error.missing_project`).
  - Added strict tenant boundary assertion comparing `message->projectUuid` to `$automation->project->uuid` (`automation.error.tenant_mismatch`).
- **AutomationExportImportService — multi-tenant graph sanitization & strict i18n**:
  - Injected `TranslatorInterface $translator = null` with fallback resolver `$this->trans()`.
  - Automatically re-binds any `data.config.project_uuid` in flow nodes to target destination project UUID on import.
  - Replaced all hardcoded exception messages and default names with translated error keys across schema validation, checksum verification, bundle limits, and flow validation.
- **Flow Database & Action Handlers — multi-tenant scoping & i18n**:
  - Hardened `CountEntriesHandler` & `FindEntriesHandler`: enforced non-empty `projectUuid`, project lookup, and project-scoped collection queries (preventing queries against null/unscoped collections). Handled status `'all'` properly.
  - Hardened `CreateEntryHandler`, `UpdateEntryHandler`, `DeleteEntryHandler`, `PublishEntryHandler`: added strict project context guards and translated error messages.
- **AutomationRunScheduledCommand & AutomationMigrateToFlowCommand**:
  - Added optional `--project` / `-p` option to `AutomationRunScheduledCommand` using `ProjectFinderTrait` and updated `AutomationRepository::findActiveScheduled(?Project $project = null)` for tenant-isolated cron jobs.
  - Hardened `AutomationMigrateToFlowCommand` with `CliSecurityTrait` and translated CLI message output.
- **ProjectFinderTrait**:
  - Guarded against `TypeError` on null results in PHP 8.5+ with safe `is_array` checks and query error recovery fallback.
- **i18n Dictionaries**:
  - Added 21 complete keys across `translations/messages.{en,fr,es,ar}.php`.
- **Test Suite**:
  - Added `Cycle79AutomationIsolationI18nTest.php` with 12 tests and 227 assertions.
  - Total full suite: 1,737 tests, 11,970 assertions (100% success).

## [1.26.142] — 2026-11-05

### Security & i18n Hardening — Project Export & Import strict i18n and Zip Security (Cycle #78)
- **ProjectExporter — strict i18n compliance**:
  - Replaced hardcoded temporary directory creation failure and ZIP archive creation failure exceptions with `$this->trans('export.temp_dir_create_failed')` and `$this->trans('export.zip_create_failed')`.
  - Maintained complete backward compatibility and safe fallback strings.
- **ProjectImporter — strict i18n compliance & translator injection**:
  - Injected `TranslatorInterface $translator = null` with fallback resolver `$this->trans()`.
  - Replaced 12 hardcoded exceptions with translated error keys:
    - ZIP archive open failures (`import.zip.open_failed`).
    - File count limit guards (`import.zip.too_many_files`).
    - Symbolic link rejection (`import.zip.symlinks_forbidden`).
    - Path traversal / Zip Slip guards (`import.zip.traversal_detected`).
    - Executable and dangerous extensions filter (`import.zip.dangerous_extension`).
    - Hidden files and directory guards (`import.zip.hidden_files_forbidden`).
    - Zip bomb decompression ratio guards (`import.zip.excessive_compression_ratio`).
    - Uncompressed ZIP size safety limit (`import.zip.total_size_exceeded`).
    - Manifest validation failures (`import.manifest.not_found`, `import.manifest.too_large`, `import.manifest.malformed`).
    - Data payload file size threshold assertions (`import.payload.size_exceeded`).
- **StructureImportHandler, ContentImportHandler, MediaImportHandler, EndUserImportHandler**:
  - Injected `TranslatorInterface $translator = null` across all import handler services.
  - Replaced all hardcoded `\RuntimeException` messages with localized translation keys:
    - `import.structure.collections_must_be_array`, `import.structure.too_many_collections`, `import.structure.collection_missing_slug`, `import.structure.invalid_collection_slug`, `import.structure.fields_must_be_array`, `import.structure.too_many_fields`, `import.structure.field_missing_slug`, `import.structure.invalid_field_slug`, `import.structure.invalid_field_type`, `import.structure.too_many_end_user_fields`.
    - `import.content.too_many_entries`.
    - `import.media.too_many_files`.
    - `import.end_users.too_many_users`.
- **Complete Quadrilingual Translation Catalogs**:
  - Added 27 new translation keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
- **Unit & Security Tests**:
  - Created `Cycle78ExportImportI18nTest` (5 tests, 229 assertions) validating multilingual error reporting across en, fr, es, ar and ensuring dictionary completeness.
  - Full PHPUnit suite: 1 725 tests, 11 740 assertions, 0 errors, 0 failures.

---

## [1.26.141] — 2026-11-05

### Security & i18n Hardening — UploadSecurityValidator & SsrfProtectionService strict i18n (Cycle #77)
- **UploadSecurityValidator — strict i18n compliance & translator injection**:
  - Injected `TranslatorInterface $translator = null` with backward-compatible fallback resolver.
  - Replaced 25 hardcoded exception error messages (null byte detection, invalid filename, required/forbidden extensions, MIME control characters, polyglot PHP executable code detection, corrupted JPEG/PNG/GIF/PDF/WebP headers, SVG/XML XXE & active script attacks, file size limits, project storage quotas) with `$this->trans()`.
  - Added 25 translation keys under `upload.error.*` across all 4 language dictionaries (`messages.{en,fr,es,ar}.php`).
- **SsrfProtectionService — strict i18n compliance & translator injection**:
  - Injected `TranslatorInterface $translator = null` with fallback resolver.
  - Replaced 15 hardcoded exception messages across host assertion, URL format validation, protocol enforcement, credentials restriction, port whitelisting, evasion detection, and DNS resolution with `$this->trans()`.
  - Added 15 translation keys under `ssrf.*` across all 4 language dictionaries (`messages.{en,fr,es,ar}.php`).
- **HealthController & System Info version synchronization**:
  - Synchronized `HealthController::VERSION` with `1.26.141`, ensuring consistency with `VERSION` and `composer.json`.
- **Unit & Security Tests**:
  - Created `Cycle77UploadAndSsrfI18nTest` covering localized error handling and dictionary completeness across en, fr, es, ar.
  - Full PHPUnit suite passes with 0 failures, 0 errors.

---

## [1.26.140] — 2026-11-05

### Security & i18n Hardening — SocialLoginService & StudioController strict i18n (Cycle #76)
- **SocialLoginService — strict i18n compliance & backward compatibility**:
  - Injected optional `TranslatorInterface $translator = null` with fallback resolver ensuring smooth DI autowiring and backward-compatible test instantiations.
  - Replaced hardcoded exception error strings across all OAuth provider workflows with `$this->trans()` using 8 translation keys: `social.error.unknown_provider`, `social.error.invalid_pkce_challenge`, `social.error.invalid_pkce_verifier`, `social.error.invalid_auth_code`, `social.error.provider_not_configured`, `social.error.email_not_verified`, `social.error.invalid_email`, `social.error.cannot_unlink_last`.
  - Added all 8 keys in complete 4-language dictionaries (`translations/messages.{en,fr,es,ar}.php`).
- **StudioController — strict i18n compliance**:
  - Replaced hardcoded French string `"Tool '$tool' inconnu"` in `ai-execute` fallback with `studio.error.unknown_tool` using `$this->trans(...)`.
  - Added `studio.error.unknown_tool` to all 4 language dictionaries (`messages.{en,fr,es,ar}.php`).
- **HealthController & System Info version synchronization**:
  - Synchronized `HealthController::VERSION` with `1.26.140`, ensuring `/health` and `/api/system/info` are 100% consistent with `VERSION` and `composer.json`.
- **Unit & Security Tests**:
  - Added `testTranslatorIsInvokedWhenProvided()` and `testI18nParityForSocialLoginErrors()` in `SocialLoginServiceTest`.
  - Full PHPUnit suite passes: 1,713 tests, 11,179 assertions, 0 errors, 0 failures.

---

## [1.26.139] — 2026-11-05

### Security & i18n Hardening — AiContentService strict i18n & translator injection (Cycle #76)
- **AiContentService — strict i18n compliance**:
  - Injected `TranslatorInterface` as 5th constructor argument (autowired).
  - Replaced all 6 hardcoded French error strings with i18n translation keys: `ai.error.parse_response`, `ai.error.translation`, `ai.error.seo`, `ai.error.analyze`, `ai.error.no_provider` (×2 occurrences in `streamPrompt` and `ask`).
  - Added 4 new translation keys to all 4 language dictionaries (`messages.en.php`, `messages.fr.php`, `messages.es.php`, `messages.ar.php`): `ai.error.parse_response`, `ai.error.translation`, `ai.error.seo`, `ai.error.analyze`.
- **Test suite — updated for new constructor signature**:
  - `AiContentServiceTest`: added `TranslatorInterface` mock injection, updated `expectExceptionMessage` to use i18n key.
  - `AiContentServiceStreamTest`: added `TranslatorInterface` mock injection, updated exception message assertion.
  - All 1711 tests pass (508 service tests, 0 failures, 0 errors).

---

## [1.26.136] — 2026-11-05

### Security Hardening on MCP (Model Context Protocol), Rate Limiting, Audit Trail & Strict i18n (Phase 88 — Cycle #73)
- **McpController Hardening**:
  - Protected `GET /mcp` (info/capabilities discovery endpoint) with `mcpLimiter` rate limiter (60 req/min) to prevent automated discovery scraping and DoS attacks.
  - Safe client IP resolution with fallback (`$request->getClientIp() ?: '127.0.0.1'`) across all MCP endpoints (`info`, `handle`, `handleProject`).
  - Integrated `AuditService` in `McpController` for security audit trail logging on authentication failure (`mcp_auth_failure`) and project tenant isolation denials (`mcp_access_denied`).
  - Wired `AuditService` and `TranslatorInterface` propagation directly to the underlying `JamboApiMcpServer`.
- **McpServer & JamboApiMcpServer Strict i18n & Error Handling**:
  - Enforced strict JSON-RPC root validation: rejecting numeric list payloads (`array_is_list`) via `-32600` `mcp.invalid_root`.
  - Replaced all hardcoded exception messages and errors with parameterized translation keys via Symfony `TranslatorInterface` (`mcp.unknown_method`, `mcp.unknown_tool`, `mcp.tool_not_available`, `mcp.unknown_resource`, `mcp.resource_not_available`, `mcp.parse_error`, `mcp.invalid_root`, `mcp.invalid_id`, `mcp.id_too_long`, `mcp.invalid_version`, `mcp.method_required`, `mcp.method_too_long`, `mcp.invalid_params`, `mcp.internal_error`, `mcp.collection_not_found`, `mcp.entry_not_found`, `mcp.singleton_entry_exists`, `mcp.field_error`, `mcp.field_exception`, `mcp.source_entry_not_found`, `mcp.singleton_duplicate_error`, `mcp.field_not_found`, `mcp.insufficient_scope`, `mcp.template_not_found`, `mcp.media_not_found`, `mcp.version_not_found`, `mcp.access_denied_project`).
  - Maintained 100% translation dictionary parity across all 4 languages (`messages.en.php`, `messages.fr.php`, `messages.es.php`, `messages.ar.php`).
- **Test Suite**:
  - Added comprehensive `McpHardeningAndSecurityTest` with 10 assertions verifying capabilities info headers, empty body rejection, malformed JSON handling, list payload rejection, unknown method/tool handling, project isolation, and unauthenticated/member access controls.

## [1.26.135] — 2026-11-05

### Security Hardening on Share, Preview, Column Settings, ApiDocs & Plugins (Phase 87 — Cycle #72)
- **ShareController & ShareRepository Hardening**:
  - Enforced an active shares quota cap (`MAX_ACTIVE_SHARES_PER_ENTRY = 50`) on `ShareController::store`, preventing resource exhaustion and database spam via 422 `MAX_ACTIVE_SHARES_EXCEEDED` (`share.max_shares_exceeded`).
  - Added full pagination support to `ShareController::index` with `limit` (max 100, default 25), `page`, and `offset` (capped at `MAX_OFFSET = 10000`), returning structured metadata (`total`, `page`, `limit`).
  - Extended `ShareRepository` with `findByEntry($entry, $limit, $offset)`, `countByEntry($entry)`, and `countActiveByEntry($entry)`.
  - Added null-safe project tenant isolation check in `ShareController::destroy`: `$share->project?->id !== $project->id`.
  - Integrated `AuditService` audit trail logging for both `share_create` and `share_revoke`.
- **PreviewController Hardening**:
  - Injected `AuditService` to log preview token generation (`preview_token_create`).
  - Added security audit event logging (`preview_url_validation`) upon target preview URL validation failure (catching anti-SSRF violations and invalid URL probes).
- **ColumnSettingController Hardening**:
  - Injected rate limiting (`#[Autowire(service: 'limiter.project_limiter')]`, 30 req/min) across `show` and `update` endpoints with user/IP identification.
  - Enforced `MAX_COLUMNS_COUNT = 50` constant and HTML stripping / control character sanitization on column identifiers.
  - Added audit logging (`column_setting_update`) tracking modified collections and column counts.
- **ApiDocsController Hardening**:
  - Injected `#[Autowire(service: 'limiter.openapi_limiter')]` (60 req/min) on Swagger UI endpoint (`/api/docs/{projectId}`) with 429 early response (`api_docs.rate_limit_exceeded`).
  - Enforced strict Content Security Policy (CSP) headers restricting script, style, img, and connect origins (`https://unpkg.com`, `https://validator.swagger.io`, `'self'`), plus clickjacking prevention (`frame-ancestors 'self'`, `X-Frame-Options: SAMEORIGIN`).
- **PluginController Hardening**:
  - Autowired `#[Autowire(service: 'limiter.admin_api_limiter')]` and extended rate limiting protection across all mutation and validation endpoints (`validate`, `register`, `toggle`, `delete`).
- **Strict I18N Parity**:
  - Added translations for `share.max_shares_exceeded`, `column_setting.rate_limit_exceeded`, and `api_docs.rate_limit_exceeded` across all 4 language dictionaries (`messages.en.php`, `messages.fr.php`, `messages.es.php`, `messages.ar.php`).

## [1.26.134] — 2026-11-05

### Security Hardening on Automations, Flows & Outbound Webhooks (Phase 86 — Cycle #71)
- **AutomationController Hardening**:
  - Configured and injected granular rate limiting (`limiter.automation_limiter`, 30 req/min) covering write, import, and execution endpoints (`store`, `update`, `delete`, `import`, `run`, `test`, `dryRun`, `testNode`).
  - Added strict payload size bounds (`MAX_PAYLOAD_BYTES = 512 KB`) across `store`, `update`, `dryRun`, and `testNode` with early 413 rejection (`automation.admin.payload_too_large`).
  - Enforced node count (`MAX_NODES_COUNT = 100`) and edge count (`MAX_EDGES_COUNT = 200`) limits upon storing and updating automation flow graphs.
  - Hardened isolated node execution (`testNode`): validated `node_id` (alphanumeric/dash/underscore, max 64 chars), validated `node_type` format (max 64 chars), constrained config size (<= 64 KB), and bounded input payload (<= 512 KB).
  - Sanitized runs pagination: bounded `$page` (1 - 10,000) and `$perPage` (1 - 100) to mitigate memory consumption and pagination denial of service.
  - Integrated audit trail logging (`auditService`) for automation creation, updates, deletions, and execution runs (`automation_create`, `automation_update`, `automation_delete`, `automation_run`, `automation_import_single`, `automation_import_bundle`).
- **Flow Handlers Security & Outbound Anti-SSRF (CallWebhookHandler & HttpRequestHandler)**:
  - Enforced strict HTTP method allowlist (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`) across both `CallWebhookHandler` and `HttpRequestHandler`.
  - Added rigorous scheme validation (strictly allowing only `http` and `https`), rejecting dangerous protocols (`file://`, `gopher://`, `phar://`, `ldap://`, etc.).
  - Enforced maximum URL length (`MAX_URL_LENGTH = 2048`) and request body limits (`MAX_BODY_BYTES = 2 MB`).
  - Implemented header sanitization preventing CRLF injection (`\r`, `\n`, `\0`) and stripping dangerous/smuggling headers (`Host`, `Transfer-Encoding`, `Connection`, `Upgrade`, `Keep-Alive`, `Proxy-Connection`).
  - Extended safe context interpolation with secure dotted path navigation (e.g. `{{ entry.slug }}`) while strictly neutralizing attempts to leak environment variables, tokens, credentials, AWS keys, or secrets.
  - Leveraged `SsrfProtectionService::buildSafeHttpClientOptions` with DNS pinning (preventing DNS rebinding) and zero redirects (`max_redirects = 0`).
- **AutomationExportImportService Hardening**:
  - Implemented `MAX_BUNDLE_ITEMS = 50` in `importProjectAutomations` to prevent bundle parsing DoS attacks.
- **BackupController Compatibility**:
  - Made `$request` parameter nullable in `list`, `download`, and `delete` to ensure robust fallback when called directly in tests or internal pipelines.
- **Strict I18N Parity**:
  - Maintained complete 4-language parity (`en`, `fr`, `es`, `ar`) across `translations/messages.*.php` for all automation, flow, and webhook messages.

## [1.26.133] — 2026-11-05

### Security Hardening on Content, Field & Collection Controllers (Phase 85 — Cycle #70)
- **ContentController**:
  - Enforced authentication verification on all endpoints (`index`, `trash`, `create`, `show`, `update`, `duplicate`, `delete`, `restore`, `forceDelete`) rejecting unauthenticated requests with 401 `AUTH_REQUIRED` (`content.admin.auth_required`).
  - Implemented granular RBAC permission checks: `project.view` on index/trash/show, `content.create` on create/duplicate, `content.update` on update, `content.delete`/`content.trash` on soft-delete, `content.restore` on restore, and `content.delete` on permanent forceDelete.
  - Multi-tenant nested relation validation: strictly verified that target collection belongs to current project, and referenced entry IDs exist, belong to target collection, and are not soft-deleted.
  - Multi-tenant media reference validation: verified referenced media UUIDs/IDs belong to the current project.
  - Mitigated pagination DoS: capped `$page` to 10,000 and `$perPage` to 100 on both `index()` and `trash()`.
  - Date range validation: rejected inverted date ranges (`date_from > date_to`) with 422 `INVALID_DATE_RANGE`.
  - Workflow status enforcement: strictly required valid ISO-8601 `scheduledAt` when status is `scheduled` (`SCHEDULED_AT_REQUIRED`, `INVALID_SCHEDULED_AT`).
  - Assignment validation: verified assignee user existence (`ASSIGNEE_NOT_FOUND`) and active project membership (`ASSIGNEE_NOT_MEMBER`).
- **HtmlContentSanitizer Service & WYSIWYG / Richtext Sanitization**:
  - Created `App\Service\Security\HtmlContentSanitizer` defending against XSS in WYSIWYG and Richtext content.
  - Stripped high-risk tags (`<script>`, `<iframe>`, `<object>`, `<embed>`, `<form>`, etc.), stripped inline event handlers (`on*`), and neutralized dangerous URI schemes (`javascript:`, `vbscript:`, `data:text/html`).
  - Added strict JSON-LD parser and re-encoder with anti-breakout safeguards (`JSON_HEX_TAG`, `JSON_HEX_AMP`, `JSON_HEX_APOS`, `JSON_HEX_QUOT`).
  - Integrated into `FieldValueHydrator` and `EavFieldHelperService`.
- **FieldController & CollectionController Hardening**:
  - Validated field `options` across both `create()` and `update()` endpoints (rejecting non-array payloads with 422 `INVALID_OPTIONS`).
  - Validated field `validationRules` with bounds on size, nesting depth (<= 5), and regex length (<= 255 chars).
  - Enforced duplicate ID deduplication in `reorder()` across both `FieldController` and `CollectionController`.
- **Repository Hardening (`ContentEntryRepository`)**:
  - Escaped SQL LIKE wildcards (`%_\\`) in full-text search queries.
  - Employed Doctrine `Paginator` with `fetchJoinCollection: true` on `findTrashedPaginated` to eliminate pagination and join hydration anomalies.
- **Strict I18N Parity**:
  - Maintained complete 4-language parity (`en`, `fr`, `es`, `ar`) in `translations/messages.*.php` for all content, field, and collection error/status messages.

## [1.26.132] — 2026-11-05

### Security Hardening on Backup, Audit, Notifications, Search & Multi-Tenant Enforcement (Cycle #69)
- **Backup Controller & Manager Hardening (Phase 84)**:
  - `BackupController`:
    - Enforced full HTTP security response headers (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, no-cache, must-revalidate, private`, `Pragma: no-cache`) across all backup endpoints including binary download streams.
    - Added route parameter requirements on `/{filename}/download` and `/{filename}` to reject invalid names and path traversal attempts at the routing layer.
    - Extended rate limiting coverage to `list()`, `download()`, and `delete()` in addition to `create()` and `restore()`.
    - Sanitized backup labels: rejected control characters/null bytes (`backup.label_control_chars`), invalid characters (`backup.label_invalid_characters`), and length overflow (> 50 chars).
    - Hardened payload parsing with strict JSON validation (`backup.invalid_json`).
    - Added immediate audit logging and flush for backup creation, download, restore, deletion, and unauthorized access attempts (`backup_access_denied`, `backup_delete_rejected`).
  - `BackupManager`:
    - Expanded `FILENAME_REGEX` to `{8,128}` to accommodate timestamped, labeled, and hashed backup filenames safely without truncation issues.
    - Sanitized and bounded labels defensively.
- **Audit, Notifications & Search Security Response Headers**:
  - `AuditController`: Added `X-Frame-Options: DENY` across all audit log responses.
  - `NotificationController`: Hardened anti-cache and security headers (`X-Frame-Options: DENY`, `Pragma: no-cache`, `Cache-Control: no-store, no-cache, must-revalidate, private`).
  - `SearchController`: Added `Pragma: no-cache`, `Cache-Control: no-store, no-cache, must-revalidate, private` to prevent sensitive project search query leaks in browser/proxy caches.
  - `EnsureProjectMemberSubscriber`: Applied hardened security headers (`X-Frame-Options: DENY`, `no-store`, `nosniff`) across all early termination responses (401, 403, 404).
- **Multilingual Parity (Strict i18n Rule)**:
  - Added new translation keys (`backup.label_control_chars`, `backup.label_invalid_characters`) with 100% parity across English (`messages.en.php`), French (`messages.fr.php`), Spanish (`messages.es.php`), and Arabic (`messages.ar.php`).
- **Automated Testing**:
  - Added `tests/Service/BackupManagerTest.php` (7 tests, 32 assertions).
  - Added `tests/Controller/Admin/BackupControllerSecurityTest.php` (5 tests, 24 assertions).
  - Added `tests/Controller/NotificationAndAuditHardeningTest.php` (3 tests, 27 assertions).
  - 100% passing rate across all targeted and security suites.

## [1.26.131] — 2026-11-05

### Security Hardening on User Management (Users, Roles, Permissions) & Lock DSN Fallback (Cycle #68)
- **Lock DSN & Server Environment Fallback**:
  - `config/packages/lock.yaml`: Added parameter fallback default `env(LOCK_DSN): 'flock'` to prevent missing environment variable runtime failures (`EnvNotFoundException`) during container compilation or deployment.
  - `.env.example`: Documented `LOCK_DSN=flock`.
- **User Management Security Hardening (Phase 83)**:
  - `UserController`:
    - Enforced strict HTTP security response headers (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cache-Control: no-store, no-cache, must-revalidate`, `Pragma: no-cache`) across all endpoints via `secureJson`.
    - Sanitized query parameter inputs on search (`$search`), filter name (`$filterName`), and filter email (`$filterEmail`), capping search strings to 100 characters max.
    - Hardened user update: enforced empty name rejection (`user.admin.name_cannot_be_empty`), length limits (max 255 chars), and control character / null byte rejection (`[\x00-\x1F\x7F]`).
    - Enforced control character / null byte rejection on user creation and email update.
    - Added failed authentication audit logging (`user_bulk_delete_failed_auth`) during bulk delete password confirmation.
  - `RoleController`:
    - Enforced strict HTTP security response headers across all role endpoints.
    - Sanitized search query parameters to 100 characters max.
    - Enforced control character / null byte rejection across role names and labels (`[\x00-\x1F\x7F]`).
    - Bounded assigned permissions array to a maximum limit of 200 permissions per role (`MAX_PERMISSIONS_PER_ROLE = 200`) to prevent memory exhaustion and DoS.
    - Added failed authentication audit logging (`role_bulk_delete_failed_auth`) during bulk delete.
  - `PermissionController`:
    - Enforced strict HTTP security response headers across all permission endpoints.
    - Sanitized search query parameters to 100 characters max.
    - Enforced control character / null byte rejection across permission names, labels, and groups (`[\x00-\x1F\x7F]`).
    - Added failed authentication audit logging (`permission_bulk_delete_failed_auth`) during bulk delete.
- **Multilingual Parity (Strict i18n Rule)**:
  - Added 9 new translation keys across English (`messages.en.php`), French (`messages.fr.php`), Spanish (`messages.es.php`), and Arabic (`messages.ar.php`).
- **Unit Testing**:
  - Added `tests/Controller/UserManagement/Cycle68UserManagementHardeningTest.php` (6 tests, 15 assertions).
  - 100% passing rate across full User Management test suites (29 tests, 589 assertions).

## [1.26.130] — 2026-11-05

### Security Hardening on Realtime, SEO, Redirects, Export/Import & Strict i18n (Cycle #67)
- **Realtime, SEO & Redirects Hardening (Phase 81)**:
  - `MercurePublisher`: Localized fallback titles (`realtime.untitled`, `realtime.file`) across all 4 locales (`en`, `fr`, `es`, `ar`). Enforced fallback file size rotation & truncation (`MAX_REALTIME_FILE_BYTES = 5 MB`, retaining 250 most recent lines) to prevent disk exhaustion when Mercure Hub is unavailable.
  - `SeoAnalyzer`: Full internationalization of all SEO evaluation criteria labels, advice suggestions, and warnings via `TranslatorInterface`. Enforced input size bounds (`MAX_SCANNED_TEXT_BYTES = 500 KB`) on scanned EAV content to protect against ReDoS and high memory allocations in word count, links, and alt tag evaluations.
  - `SeoPdfExporter`: Multilingual PDF generation with dynamic locale resolution, complete UI localization, and bidirectional layout support (RTL layout for Arabic). Configured strict Dompdf security options (`isRemoteEnabled = false`).
  - `SeoController`: Hardened `bulkUpdate` route by enforcing `content.update` permission check, payload size limit (64 KB), maximum batch size (100 entries), and rigorous sanitization on metadata fields (`canonicalUrl` scheme validation, CRLF/null byte rejection, slug sanitization, and string length capping). Added scanning ceiling in `scores` (`MAX_SCORES_LIMIT = 500`).
  - `HreflangGenerator`: Neutralized CRLF injection, null bytes, and XSS breakout vectors across preview base URLs, collection slugs, entry slugs, and locale tokens.
  - `LinkChecker` & `RedirectResolver`: Filtered out soft-deleted content entries in link scanning, bounded scanned text to 250 KB per field, and protected redirect pattern regexes against oversized patterns (> 500 chars) and ReDoS.
- **Export & Import Security Hardening (Phase 82)**:
  - `MediaExportHandler`: Hardened media binary exports with path traversal and null-byte defenses, symlink resolution rejection, canonical upload root boundary verification (`realpath`), maximum entity ceiling (`MAX_MEDIA_ENTITIES = 1000`), and total cumulative export size budget (`MAX_TOTAL_MEDIA_BYTES = 250 MB`).
  - `ProjectExporter`: Replaced predictable identifiers with cryptographically secure tokens (`bin2hex(random_bytes(16))`), enforced restrictive directory permissions (`0700`), integrated automatic stale temporary directory cleanup (`cleanStaleTempDirs`), and bounded archive generation to 5,000 files and 250 MB.
  - `SettingsExportHandler` & `SettingsImportHandler`: Removed internal database integer identifiers (`role?->id`, `user?->id`) from exported settings to prevent schema leakage. Enforced strict validation and limits on `default_locale` format and `locales` array bounds.
- **Multilingual Parity (Strict i18n Rule)**:
  - Added 46 new translation keys across English (`messages.en.php`), French (`messages.fr.php`), Spanish (`messages.es.php`), and Arabic (`messages.ar.php`).
- **Unit Testing**:
  - Added comprehensive test suites: `tests/Service/Cycle67HardeningTest.php` (12 tests) and `tests/Controller/Api/SeoControllerHardeningTest.php` (4 tests).
  - 100% passing rate across full test suite (1,645 tests, 10,102+ assertions).

## [1.26.129] — 2026-11-05

### Security Hardening on Async Messenger Flows, Workers & HTTP Error Headers (Cycle #66)
- **Async Messenger Flows & TTL Expiration Protection (Phase 79)**:
  - Enforced message payload validation and TTL expiration checks across all Messenger messages (`ExecuteAutomationMessage`, `ExecuteSubFlowMessage`, `SendWebhookMessage`, `SendProjectEmailMessage`).
  - Added timestamp tracking (`$dispatchedAt`) and configurable TTL (`$ttlSeconds = 86400` / 24h) to prevent zombie/stale background tasks from executing delayed side effects after server outages.
  - Implemented idempotent state checking in `ExecuteAutomationMessageHandler`: execution automatically skips runs that are already `success`, `failed`, or `cancelled`, preventing duplicate runs during queue replays.
  - Hardened `Attachment`: decoupled into PSR-4 compliant standalone class with individual file size limit (10 MB), filename sanitization (`basename`, CRLF/control char stripping), MIME format whitelist validation (`^[a-zA-Z0-9_\-\.\+]+/[a-zA-Z0-9_\-\.\+]+$`), and `JsonSerializable` support.
  - Enforced total cumulative attachment size cap (25 MB) in `SendProjectEmailMessage`.
  - Added recursion depth limiter (`depth <= 5`) and TTL check in `ExecuteSubFlowMessage` and `ExecuteSubFlowMessageHandler`.
  - Bounded `DelayHandler` delay duration to a safe ceiling (max 30 seconds / `MAX_DELAY_MS = 30000`), preventing worker starvation from malicious or runaway workflows.
  - Implemented global execution timeout guard in `FlowInterpreter` (`MAX_EXECUTION_TIME_SECONDS = 60.0`) and node count ceiling (`MAX_FLOW_NODES = 100`).
  - Internationalized all worker, flow, and CLI command messages across all 4 locales (`en`, `fr`, `es`, `ar`).
- **HTTP Security Headers & Error Response Hardening (Phase 80)**:
  - Created centralized `JsonErrorHandler` service for generating standardized, hardened JSON error responses.
  - Configured automatic internal error masking in `prod` environment for 500 status codes, preventing database credential, file path, and stack trace leaks.
  - Integrated `JsonErrorHandler` into `AdminApiExceptionSubscriber` and `PublicApiExceptionSubscriber`.
  - Hardened `SecurityHeadersSubscriber`: systematically enforces anti-caching headers (`Cache-Control: no-store, no-cache, must-revalidate, private`, `Pragma: no-cache`, `Expires: 0`) and MIME sniffing protection (`X-Content-Type-Options: nosniff`) on all HTTP 4xx and 5xx error responses.
  - Added sensitive cookie removal (`remove('Set-Cookie')`) on 500 internal server error responses to prevent state poisoning during fatal server faults.
  - Created customized, brand-aligned Twig error templates (`templates/bundles/TwigBundle/Exception/error.html.twig`, `error404.html.twig`, `error403.html.twig`, `error500.html.twig`) with `robots: noindex, nofollow`, RTL support for Arabic, and full i18n localization.
- **Unit Testing & Verification**:
  - Added `tests/MessageHandler/MessengerHardeningTest.php` with 11 tests covering validation, TTL expiration, idempotency, attachment size limits, delay capping, and error response headers.
  - Expanded `tests/EventSubscriber/SecurityHeadersSubscriberTest.php` to verify no-cache headers and cookie stripping on error responses.

## [1.26.128] — 2026-11-05

### Security Hardening on Image Transformation, Route Manifest & Client Generator (Cycle #65)
- **Image Transformation Anti-DoS & Memory Bomb Defense (`ImageTransformService`)**:
  - Implemented strict source file boundaries: rejection of null bytes, non-regular or unreadable files, source file size cap (25 MB / `MAX_SOURCE_BYTES`).
  - Added image bomb & decompression bomb protection via `@getimagesize()` pre-validation: strictly capped source dimensions (`MAX_SOURCE_DIMENSION = 10000` px) and total megapixels (`MAX_SOURCE_PIXELS = 30_000_000` / 30 MP).
  - Enforced strict raster-only source MIME type whitelist (`image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif`), preventing SVG XML/XXE/SSRF injection and polyglot parser confusion.
  - Hardened Intervention Image execution: bounded output pixel budget (`MAX_OUTPUT_PIXELS = 12_000_000` / 12 MP), output dimensions bounded to 4000 px, quality bounded (1–100), and hex background color validation.
  - Imagick resource quotas configured at runtime (`RESOURCETYPE_MEMORY`, `RESOURCETYPE_MAP`, `RESOURCETYPE_WIDTH`, `RESOURCETYPE_HEIGHT`, `RESOURCETYPE_DISK`).
  - Cache key sanitization: prevented arbitrary extension leakage (e.g. `.php`) into cache storage; strictly constrained cache extension to allowed raster formats.
  - Fully internationalized exception messages via `TranslatorInterface` adhering to i18n standards.
- **Frontend Route Manifest & Host Header Injection Hardening (`FrontendRouteManifestService`)**:
  - Protected Inertia / Ziggy shared route manifest against HTTP Host Header Injection & Poisoning (`resolveSafeBaseUrl`).
  - Added RFC 1123 hostname and IPv4/IPv6 validation with strict character rejection (neutralizing CRLF, null bytes, HTML/XSS characters, backslashes, and slashes in Host headers).
  - Implemented graceful fallback to configured `DEFAULT_URI` (`http://localhost` / production base URI) when invalid or malicious Host headers are detected or when `SuspiciousOperationException` is thrown.
  - Sanitized route parameter token extraction: stripped inline regex requirements and defaults (e.g. `{id<\d+>}` -> `{id}`) ensuring reliable Ziggy frontend parameter bindings.
- **TypeScript Client Generator Code Injection Hardening (`JamboClientGenerator`)**:
  - Neutralized comment-breaking sequences, CRLF and control characters in project names (`cleanProjectName`), preventing JavaScript code breakout from header comments.
  - Safely encoded `API_URL` and `PROJECT_UUID` literals using `json_encode` to prevent string breakout syntax injection.
  - Sanitized interface identifiers (`toValidInterfaceName`): PascalCase normalization, alphanumeric sanitization, leading digit stripping/prefixing, and TypeScript reserved keyword escaping (e.g. `IInterface`).
  - Safe property formatting (`formatProperty`): automatically JSON-quotes properties with hyphens or non-identifier characters (e.g. `"first-name": string;`), preventing syntax errors and injection.
  - Safe function name generation (`toValidFunctionName`): camelCase sanitization ensuring valid JavaScript/TypeScript function identifiers.
- **Unit Testing & Verification**:
  - Added comprehensive test suites: `tests/Service/ImageTransformServiceTest.php` (9 tests) and `tests/Service/FrontendRouteManifestServiceTest.php` (7 tests).
  - Expanded `tests/Service/JamboClientGeneratorTest.php` with 5 new security and injection test cases (8 tests).
  - Full suite verification: 1,675+ tests executed with 100% pass rate.

## [1.26.127] — 2026-11-05

### Security Hardening on Static Site Publishing, Storage Manager & Twig Sandbox (Cycle #64)
- **Static Site Publishing Hardening (`PublishedSiteStorage`)**:
  - Implemented strict project UUID/identifier validation (`validateProjectUuid`), preventing directory escapes and traversal outside target project repositories.
  - Added comprehensive sensitive and executable file blocking (`validateRelativePathSecurity`): rejects server-side scripts (`.php`, `.phtml`, `.phar`, `.sh`, `.exe`, `.cgi`, `.py`, `.asp`, `.jsp`, etc.) and sensitive configuration files (`.htaccess`, `.htpasswd`, `web.config`, `.env`, `.git`), preventing RCE and configuration overrides.
  - Added capacity and resource boundaries: enforced 2,000 maximum files ceiling (`MAX_FILES_PER_PUBLICATION`), 25 MB single file limit (`MAX_SINGLE_FILE_SIZE_BYTES`), and 100 MB total publication limit (`MAX_TOTAL_PUBLICATION_SIZE_BYTES`).
  - Added strict null-byte rejection (`\0`) and enforced safe permissions (`0644` files, `0755` dirs).
  - Added clean project teardown method `deleteProject(string $projectUuid)`.
- **Twig Template Sandbox & DoS Protection (`DynamicTemplateRenderer` & `NativeTwigSecurityPolicy`)**:
  - Hardened default sandbox security policy in `NativeTwigSecurityPolicy`: dropped `include` from `$allowedTags`, preventing template injection recursion, recursive self-inclusion, and local template enumeration attacks.
  - Added strict payload size cap (`MAX_TEMPLATE_LENGTH = 65536` / 64 KB) and null-byte rejection in `DynamicTemplateRenderer`, mitigating memory exhaustion and parser bypasses.
- **Storage Manager Path & CRLF Hardening (`StorageManager`)**:
  - Enforced defensive path validation (`validatePathSafety`): strictly rejects null bytes, path traversal (`..`), and capped path lengths to 1024 characters.
  - Added CRLF and null-byte neutralization across `buildUrl()`, preventing HTTP response splitting and header injection attacks on generated media URLs.
- **Unit Testing & Verification**:
  - Expanded test suites across `PublishedSiteStorageTest`, `TwigSandboxIntegrationTest`, and added new `StorageManagerHardeningTest` (100% passing).

## [1.26.126] — 2026-11-05

### Security Hardening on Forms, Public Submissions, Direct Upload & TUS (Cycle #63)
- **Public Form Submissions & Attachments Security (`FormPublicController` & `SubmitHandler`)**:
  - Implemented secure multipart attachment processing in `SubmitHandler`: strictly bounded attachment counts (max 5-10), maximum single file size (max 10-25 MB), and total submission attachment quota limit (50 MB).
  - Integrated `UploadSecurityValidator` for all uploaded form attachments: sanitizes filenames against path traversal / null bytes, enforces extension allowlist / forbidden list (`.php`, `.exe`, `.sh`, `.bat`, etc.), inspects magic bytes / MIME types, enforces anti-XXE and anti-XSS for SVG/XML, and checks project storage quotas.
  - Stored form attachments in segmented project directories (`public/uploads/forms/{projectUuid}/`) with cryptographically secure unguessable tokens (`bin2hex(random_bytes(16))`).
  - Added recursive payload safety verification (`validatePayloadSafety`): limits nesting depth to 3 levels, validates field names against strict regex (`/^[a-zA-Z0-9_\-\.]{1,64}$/`), rejects null bytes and invalid UTF-8 byte sequences, and enforces 64 KB value bounds.
  - Added form lifecycle & capacity guards: enforces active status check (`settings['active']`), expiration deadline (`settings['closes_at']`), and maximum submission limits (`settings['max_submissions']`).
- **Admin Form Management Hardening (`Admin/FormController`)**:
  - Enforced duplicate field name prevention in form schemas (`DUPLICATE_FIELD_NAME`), avoiding data collisions and ambiguous validation rules.
  - Hardened notification email settings against CRLF injection and email spoofing, validating email formatting via `filter_var` and rejecting `\r` / `\n` characters.
  - Added streaming ceiling protection in CSV exports (capped at 50,000 records) to prevent memory and CPU exhaustion attacks.
- **Direct Upload S3 Quota Protection (`DirectUploadController` & `DirectUploadService`)**:
  - Enforced server-side S3 object size verification in `completeUpload()` via `HeadObject`: checks the actual object size on S3 and rejects client-side quota manipulation if size exceeds `intent->maxBytes` or project quota limits.
  - Added `file_size` fallback and strictly validated non-negative byte bounds during intent creation.
- **Resumable TUS Upload Protocol Hardening (`TusController`)**:
  - Enforced mandatory sanitized filename in `Upload-Metadata` during upload creation (`MISSING_FILENAME`), preventing incomplete metadata processing.
  - Validated declared upload length against project-level `upload_max_file_size_mb` limits.
  - Replaced predictable `uniqid()` identifiers with cryptographically secure random tokens (`bin2hex(random_bytes(16))`) during upload finalization.
- **Multilingual Synchronization & Unit Testing**:
  - Added full translation parity for 19 new security error keys across `en`, `fr`, `es`, and `ar`.
  - Added dedicated test suites: `FormPublicControllerHardeningTest`, `FormControllerDuplicateFieldTest`, `TusControllerHardeningTest`, and `DirectUploadQuotaProtectionTest`.

## [1.26.125] — 2026-11-05

### Security Hardening on Authenticators, Exception Subscribers & Memory Guards (Cycle #62)
- **Authenticators Hardening & Token Bounds Validation**:
  - `ApiTokenAuthenticator`: Implemented maximum plain token length bound (1024 chars), preventing regex / hash memory attacks. Converted hardcoded failure messages into standardized translation keys (`auth.api_token.*`) with localized responses.
  - `PersonalAccessTokenAuthenticator`: Added plain token length ceiling (1024 chars) and integrated `TranslatorInterface` with localized failure responses (`auth.pat.*`).
  - `EndUserJwtAuthenticator`: Added JWT length ceiling (8192 chars) preventing header abuse, along with localized error responses.
- **Exception Subscribers Hardening & Sensitive Data Leak Prevention**:
  - `AdminApiExceptionSubscriber`: Hardened error handling on `/admin-api` routes. In production environments (`prod`), internal 500 exceptions are masked with a localized generic message (`api.unexpected_error`), preventing SQL/internal path disclosure while logging the full exception stack trace with context.
  - `PublicApiExceptionSubscriber`: Integrated `TranslatorInterface` with localized generic error messages on internal errors in production.
- **Session & Memory Exhaustion Guards**:
  - `TwoFactorRedirectSubscriber`: Added defensive check `hasSession()` prior to session retrieval, preventing fatal crashes during stateless or API-driven authentication.
  - `Utf8RequestNormalizerSubscriber`: Added body size check (`Content-Length > 10MB`) to bypass string transcoding and prevent memory exhaustion on oversized payloads.
- **Multilingual Synchronization & Unit Testing**:
  - Synchronized complete dictionary translations across `en`, `fr`, `es`, and `ar` for all new security and auth keys.
  - Added unit test suites `ApiExceptionSubscribersTest` and `ApiTokenAuthenticatorTest` verifying error masking, token boundaries, and session resilience.

## [1.26.124] — 2026-11-05

### Security Hardening on Symfony CLI Console Commands & Concurrency (Cycle #61 & #62)
- **Universal CLI Security & Localized Helper Trait (`CliSecurityTrait`)**:
  - Implemented `CliSecurityTrait` across all Symfony Console commands providing standardized `assertSafePath` (blocking directory traversal `../..`, null-byte attacks, and restricted root files such as `.env`, `.git`, `composer.*`, `config/*`), `validatePasswordComplexity`, and `generateSecurePassword` guaranteeing uppercase, lowercase, digit, and symbol constraints.
  - Added strict interactive confirmation helper (`confirmOrAbort`) safeguarding destructive commands from non-interactive automated executions without explicit `--force` flag.
  - Integrated `TranslatorInterface` with fallback localization across all commands, ensuring zero hardcoded user-facing strings adhering strictly to the multilingual policy.
- **Process Concurrency Protection & Background Task Locking (`symfony/lock`)**:
  - Added `symfony/lock` dependency with `LockableTrait` concurrency guards on scheduled runners (`automation:run-scheduled`, `publish:scheduled`, `app:media:prune-cache`, and `app:purge-audit-logs`), preventing overlapping execution and race conditions during high-frequency cron invocations.
- **Credential Protection & Sensitive Data Redaction**:
  - Hardened password input handling in `app:setup` and `jambo:enduser:create` via `askHidden()` in interactive mode and cryptographically secure generation in headless mode with sensitive credential redaction in stdout output unless `--show-password` is explicitly specified.
- **Input Validation & Target Isolation**:
  - Hardened CSV imports and exports with MIME/path validation, delimiter character enforcement, and bounded file upload limits.
  - Strictly enforced distinct source and target project UUID isolation in `jambo:sync-schema`.
  - Added comprehensive unit test coverage (`CliSecurityHardeningTest`) for path safety, password entropy, concurrency, and error handling.
  - Synchronized complete dictionary translations across `en`, `fr`, `es`, and `ar`.

## [1.26.123] — 2026-11-05

### Security Hardening on GraphQL Engine, Quotas & Resolvers (Cycle #60)
- **GraphQL Mutation Security & Method Enforcement (`GraphQLController`)**:
  - Strictly blocked execution of GraphQL mutations over HTTP GET requests (rejection with 405 Method Not Allowed and translated error), adhering to OWASP GraphQL security recommendations and HTTP standards.
  - Implemented token ability verification for mutations: API tokens restricted to `read` are strictly prevented from executing mutations (`create`, `update`, `delete`).
  - Added session user permission checks for mutations against `project.manage`, `content.create`, and `content.update`.
- **AST Metrics & Anti-Amplification Protections (`GraphQLController`)**:
  - Enforced single operation restriction (`MAX_OPERATIONS = 1`) on the parsed AST document, preventing multi-operation batching bypasses.
  - Enforced fragment count ceilings (`MAX_FRAGMENTS = 10`) preventing memory exhaustion through fragment spamming.
  - Implemented deep AST metric traversal inspecting `OperationDefinitionNode`, `FragmentDefinitionNode`, `InlineFragmentNode`, and `FragmentSpreadNode` to prevent alias amplification through fragments.
  - Added directive injection limit (`MAX_DIRECTIVES = 15`) and total AST field selection ceiling (`MAX_SELECTIONS = 150`).
- **Resolver Hardening & Pagination Security (`SchemaGenerator`)**:
  - Added UUID regex validation in resolvers (`resolveEntry`, `resolveUpdate`, `resolveDelete`) preventing malformed database queries.
  - Clamped pagination limits (`limit` max 100, `offset` max 10,000) with safe page computation and regex validation on `locale` and `status` filter arguments.
  - Sanitized mutation inputs: capped input fields to 200 items maximum, validated `locale` format, and strictly validated `status` against collection workflow allowed statuses.
  - Prevented field name collisions with reserved entity attributes (`uuid`, `locale`, `status`, `createdAt`, `updatedAt`).
- **I18N Strict Compliance & Comprehensive Tests**:
  - Added 8 new translation keys (`graphql.too_many_operations`, `graphql.too_many_fragments`, `graphql.too_many_directives`, `graphql.too_many_selections`, `graphql.mutations_not_allowed_via_get`, `graphql.mutation_permission_denied`, `graphql.parse_error`, `graphql.invalid_uuid`) across all 4 supported languages (`fr`, `en`, `es`, `ar`).
  - Added 9 new test cases in `GraphQLSecurityTest` (total 18 tests, 50 assertions, zero regressions).

## [1.26.122] — 2026-11-05

### Security Hardening on Templates, Project Export/Import, ZIP Extraction & Architecture (Cycle #59)
- **ProjectImporter ZIP Extraction & Decompression Bomb Fortification (`ProjectImporter`)**:
  - Implemented anti-zip bomb ratio protection rejecting entries with compression ratios exceeding 200:1.
  - Implemented fail-safe temporary directory cleanup on extraction failure preventing leaked directories.
  - Blocked execution of dangerous and executable extensions (`.php`, `.phar`, `.sh`, `.exe`, `.cgi`, `.pl`, `.py`, `.env`, `.htaccess`, etc.) directly during ZIP archive extraction.
  - Blocked hidden files and directory traversal segments (e.g., `.git/`, `.env`, `.htaccess`).
  - Added strict payload size limits for `media.json` (10MB) and `settings.json` (5MB).
- **ProjectExportImportController Hardening (`ProjectExportImportController`)**:
  - Fixed archive validation logic ensuring `.zip` extension is strictly required alongside MIME checking.
  - Added verification of ZIP archive magic bytes (`PK\x03\x04` / `PK\x05\x06`).
  - Added input sanitization for new project names (stripping control chars `[\x00-\x1F\x7F]`) and default locale validation.
  - Integrated `AuditService` logging across project export, import new, and import merge operations.
  - Enforced strict security headers (`X-Content-Type-Options: nosniff`, `Cache-Control: no-store`) on all responses.
- **MediaImportHandler Polyglot & Double Extension Protection (`MediaImportHandler`)**:
  - Neutralized double extension bypasses (e.g. `shell.php.jpg`, `hack.phar.png`).
  - Added anti-polyglot check ensuring image files do not contain PHP script headers (`<?php`, `<?=`).
  - Prevented symlink traversal by verifying files are not symlinks and reside within the extracted media directory.
  - Enforced a hard ceiling of 2,000 files in `media.json`.
- **Structure & Content Import Handlers Fortification (`StructureImportHandler`, `ContentImportHandler`, `EndUserImportHandler`)**:
  - Hardened `end_user_fields` import with slug validation, type checking, 100-field limit, and 32KB options cap.
  - Hardened `end_users` import with a 5,000-user limit and sanitized usernames.
  - Hardened `content.json` import with locale/status sanitization, safe date parsing (no 500 crash on malformed dates), and 64KB cell text caps.
- **ProjectTemplateBuilder Integration (`ProjectTemplateBuilder`)**:
  - Injected `EndUserSchemaSeeder` into `ProjectTemplateBuilder` to automatically seed system EndUser fields upon template application.
  - Enforced sanitization of collection slugs, field slugs, and allowed field types during template export and application.
- **Testing**:
  - Added `Cycle59TemplateExportImportHardeningTest` with 8 comprehensive security tests (27 assertions).

## [1.26.121] — 2026-11-05

### Security Hardening on User Management, Bulk Operations Caps, ApiToken Fallback & Zero-Regression Test Suite (Cycle #58)
- **UserManagement Fortification (`UserController`, `RoleController`, `PermissionController`)**:
  - Enforced strict payload size caps (`MAX_PAYLOAD_BYTES = 64KB`) across `create()`, `update()`, and `bulkDelete()`, returning 413 `user.admin.payload_too_large`, `role.admin.payload_too_large`, `permission.admin.payload_too_large`.
  - Enforced strict bulk operation boundary (`MAX_BULK_IDS = 100`) preventing resource exhaustion / memory exhaustion on mass deletion requests, returning 422 `bulk_limit_exceeded`.
  - Added strict integer ID validation for bulk deletion payloads, returning 422 `invalid_id` on non-numeric or non-positive IDs.
- **Webhook Controller & ApiToken Fallback (`WebhookController`)**:
  - Integrated `ApiTokenChecker` and `RequestStack` with lazy optional injection to allow seamless authentication via project-scoped API Tokens (`Authorization: Bearer <secret>`).
  - Added permission level checks (`project.manage`/`project.edit` requiring `write`/`manage`/`*` abilities, and method-based checks for other routes).
  - Resolved regression where ApiToken access returned 401 when testing webhook SSRF and test ping.
- **Test Suite Zero-Regression Alignment**:
  - Fixed constructor mock in `Cycle34HardeningTest` for `FormPublicController` (added `$formViewLimiter` slot).
  - Validated full test suite passes with 0 failures, 0 errors.
- **I18N Parity**:
  - Added 9 new translation keys (`user.admin.payload_too_large`, `user.admin.bulk_limit_exceeded`, `user.admin.invalid_id`, `role.admin.payload_too_large`, `role.admin.bulk_limit_exceeded`, `role.admin.invalid_id`, `permission.admin.payload_too_large`, `permission.admin.bulk_limit_exceeded`, `permission.admin.invalid_id`) across EN, FR, ES, AR (2,904 keys each, 100% parity).
- **Testing**:
  - Created `Cycle58UserManagementHardeningTest` with 12 comprehensive unit/functional security tests (97 assertions).

## [1.26.120] — 2026-11-05

### Security Hardening on EndUser Auth API, Progressive Multi-Tier Lockout & Password Complexity (Cycle #57)
- **ProjectAuthLockoutService Progressive Multi-Tier Lockout**:
  - Implemented `recordTieredFailedAttempt()` with 3 escalating tiers: Tier 1 (5 attempts => 300s), Tier 2 (10 attempts => 900s), Tier 3 (15+ attempts => 3600s).
  - Implemented `getLockoutTier()` and `recordProgressiveCooldown()` (60s -> 300s -> 900s) for forgot-password and 2FA requests.
  - Implemented dual-tier lockout: target account key (`enduser_login`) + source IP key (`enduser_login_ip`) to prevent distributed credential stuffing.
- **EndUserAuthController Fortification**:
  - Timing oracle elimination: valid bcrypt hash for dummy user timing equalization, covering both null users and OAuth users with null passwords.
  - Token brute force defense: IP-based lockout tier on `/reset-password` after 5 failed token submissions (`enduser.auth.token_rate_limit_lockout`).
  - Password change defense: lockout on `PATCH /me` after 5 failed current password attempts (`enduser.auth.password_change_locked_out`).
  - Session lifecycle binding: bound individual session `sessionId` to `ProjectAuthRefreshToken` `familyId`, so `DELETE /sessions/{sessionId}` revokes the refresh token family immediately.
  - Uniform 401 on `/refresh` and `/verify-2fa` when user is not found or inactive, eliminating account status leakage.
- **PasswordPolicyValidator Hardening**:
  - Added ASCII control character rejection (`[\x00-\x1F\x7F]`) with translated `password.policy.invalid_control_chars`.
  - Added project name containment rejection (`password.policy.contains_project`) with property-hook safe uninitialized reflection access.
  - Expanded `COMMON_PASSWORDS` dictionary with 50+ NIST/OWASP weak patterns.
- **I18N Parity**:
  - Added 5 new keys (`enduser.auth.ip_locked_out`, `enduser.auth.password_change_locked_out`, `enduser.auth.token_rate_limit_lockout`, `password.policy.invalid_control_chars`, `password.policy.contains_project`) across EN, FR, ES, AR (2,895 keys each, 100% parity).
- **Testing**:
  - Expanded `Cycle57EndUserAuthHardeningTest` to 15 tests, 102 assertions with 100% success rate.

## [1.26.119] — 2026-11-05

### Security Audit & Hardening on EndUser Auth API & Password Policy
- **EndUserAuthController (`/api/{projectId}/auth/*`)**:
  - **Timing-Safe Comparison & Anti-Enumeration on Login (`/login`)**:
    - Eliminated username/email enumeration timing oracle: when a target email does not exist, a full cryptographic password hashing check is executed against a dummy user, balancing CPU execution time identically between existing and non-existing accounts.
    - Status disclosure prevention: verified that inactive/banned account status is only evaluated AFTER credential validation succeeds, preventing attackers from probing for inactive accounts without valid credentials.
    - Constant-time 401 `Invalid credentials.` error returned uniformly.
  - **Multi-Tier Rate Limiting & Account Lockout**:
    - Integrated `ProjectAuthLockoutService` for EndUsers: account lockout tier locks the target account for 15 minutes after 5 consecutive failed login attempts, returning HTTP 429 Too Many Requests with translated `enduser.auth.account_locked_out` and `Retry-After` header.
    - Lockout attempts counter cleared immediately upon successful authentication.
  - **Two-Factor Authentication Hardening (`/verify-2fa`)**:
    - Added 2FA challenge lockout tier (10 minutes lockout after 5 consecutive failed verification attempts), returning HTTP 429 with `enduser.auth.rate_limit_2fa_lockout`.
    - Made backup codes verification strictly timing-safe across all stored codes in `TwoFactorService::verifyAndConsumeBackupCode`, eliminating array position timing leakage.
  - **Forgot-Password Cooldown & Anti-Enumeration (`/forgot-password`)**:
    - Added mail-bombing prevention cooldown (60 seconds per email target) preventing attacker-induced inbox flooding.
    - Added cryptographic computation equalization on non-existent targets to eliminate timing discrepancies between existing and non-existing emails, always returning 200 with `enduser.auth.reset_link_sent`.
  - **Password Reset Hardening (`/reset-password`)**:
    - Enforced 64-character hexadecimal format verification before DB querying.
    - Applied `hash_equals()` constant-time comparison on reset tokens.
    - Harmonized error responses to 400 `Invalid or expired reset token.` uniformly (preventing enumeration of valid tokens vs non-existent accounts).
    - Automatically cleared login lockout counters and revoked all active sessions upon password reset.
  - **Session Revocation (`/sessions`)**:
    - Added `DELETE /api/{projectId}/auth/sessions` endpoint to revoke all active sessions for the authenticated end user.
    - Updated `security.yaml` `end_user` firewall pattern to include `sessions`, ensuring proper bearer JWT authentication for session management.
  - **Payload Size Limiter**:
    - Enforced a 64KB payload cap across all JSON auth endpoints (`getJsonPayload`), returning 413 `Request payload too large` on oversized inputs.
- **PasswordPolicyValidator**:
  - Expanded `COMMON_PASSWORDS` dictionary with 50+ NIST/OWASP weak patterns.
  - Added detection of dictionary words with trailing punctuation or numbers.
  - Added detection of repetitive characters (4+ identical chars like `aaaa`) and sequential patterns (`1234`, `abcd`).
  - Added support for project-level special character requirement (`requireSpecialChar`).
- **I18N Parity**:
  - Added `enduser.auth.account_locked_out`, `enduser.auth.rate_limit_2fa_lockout`, `enduser.auth.reset_cooldown`, `enduser.auth.sessions_revoked`, `password.policy.require_special`, `password.policy.repetitive_chars` across EN, FR, ES, AR (100% parity with 2,890 keys each).
- **Testing**:
  - Added `Cycle57EndUserAuthHardeningTest` covering 10 test scenarios and 73 assertions with 100% pass rate.

## [1.26.118] — 2026-11-05

### Security Audit & Hardening on Webhook Subsystems (Inbound & Outbound Webhooks)
- **WebhookInboundController (`/api/webhooks/inbound/{automationUuid}`)**:
  - Enforced IP allowlist checks (`ip_allowlist` / `allowed_ips`) supporting single IPv4/IPv6, CIDR blocks (`192.168.1.0/24`, `10.0.0.0/8`, `2001:db8::/32`), and IPv4-mapped IPv6, returning 403 `IP_FORBIDDEN` with translated feedback.
  - Added replay attack prevention via cache-backed seen delivery/signature tracker (`wh_seen_{hash}` with 300s TTL), returning 409 `REPLAY_DETECTED` for repeated deliveries or identical timestamped signatures.
  - Hardened timestamp verification window: strictly rejects timestamps > 300s in the past or > 60s in the future (tight clock skew protection).
  - Extended HMAC signature verification to Svix / Standard Webhooks standard (`webhook-signature`, `webhook-id`, `webhook-timestamp`), in addition to GitHub (`X-Hub-Signature-256`), Stripe (`Stripe-Signature`), Slack (`X-Slack-Signature`), and Bearer tokens.
  - Added `Content-Length` header pre-check (2MB cap) before reading request body into memory.
  - Preserved standard non-sensitive event headers (`x-github-event`, `x-gitlab-event`, `webhook-id`, `x-delivery-id`) in execution payload while stripping sensitive credentials.
- **WebhookController (`/api/projects/{projectUuid}/webhooks`)**:
  - Fixed authorization loophole in `resolveProject()` by strictly enforcing user authentication and membership; unauthenticated calls now return 401 `AUTH_REQUIRED`.
  - Added `Content-Length` header pre-checks across create, update, and rotate-secret endpoints (512KB cap).
  - Added `Referrer-Policy: no-referrer` to security headers.
- **WebhookService (Outbound Webhooks)**:
  - Added `X-JamboApi-Delivery` (unique UUID v4) and `X-JamboApi-Timestamp` headers to outbound dispatches.
  - Added replay-protected dual signature `X-JamboApi-Signature-256: t={ts},v1={hash}` alongside backward-compatible `X-JamboApi-Signature`.
  - Enforced `User-Agent: JamboApi-Webhook/1.0`.
- **CallWebhookHandler (Automation Flows)**:
  - Added payload cap protection (max 2MB request body).
  - Added automatic HMAC signing with `X-JamboApi-Signature` and `X-JamboApi-Timestamp` when secret is configured.
- **I18N Parity**:
  - Added `automation.admin.webhook_ip_forbidden`, `automation.admin.webhook_replay_detected`, `automation.admin.webhook_expired_timestamp` across all 4 languages (EN, FR, ES, AR) — maintaining 100% parity with 2,884 keys.
- **Testing**:
  - Added `Cycle56WebhookHardeningTest` covering 13 test scenarios and 46 assertions with 100% pass rate.

## [1.26.117] — 2026-11-05

### Security Audit & Hardening on Public Endpoints (Forms, Redirects, Embeds & CORS)
- **FormPublicController**:
  - Harmonized all JSON error responses with translated `error`, uppercase machine-readable `code` (`PROJECT_NOT_FOUND`, `INVALID_SLUG`, `FORM_NOT_FOUND`, `PAYLOAD_TOO_LARGE`, `TOO_MANY_FIELDS`, `FIELD_VALUE_TOO_LARGE`, `VALIDATION_FAILED`, `SUBMISSION_FAILED`, `RATE_LIMIT_EXCEEDED`), and `errors` / `validation_errors` maps.
  - Added payload protection caps: max 100 fields per submission (`TOO_MANY_FIELDS`), max 64KB per string field value (`FIELD_VALUE_TOO_LARGE`), and 1MB overall body size limit (`PAYLOAD_TOO_LARGE`).
  - Added dedicated `form_view_limiter` rate limiting for public form views (`GET /{projectUuid}/forms/{slug}`).
  - Enforced security headers on all responses: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cache-Control: no-cache, private`.
- **RedirectPublicController**:
  - Implemented anti-SSRF validation via `SsrfProtectionService::isSafeUrl()` across all redirect destinations, blocking loops, private RFC1918 CIDRs, loopbacks, Cloud metadata (169.254.169.254), and dangerous URI schemes (`javascript:`, `data:`, `vbscript:`, `file:`, `ftp:`, `blob:`).
  - Hardened anti-open redirect checks rejecting protocol-relative (`//`), Windows UNC (`\\`), and backslash bypass attempts (`/\`, `\/`).
  - Added dynamic pattern redirect replacement resolution for regex routes (`isPattern = true`) before validating final destination safety.
  - Harmonized error responses with `code` (`RATE_LIMIT_EXCEEDED`, `PROJECT_NOT_FOUND`, `PATH_TOO_LONG`, `INVALID_CONTROL_CHARS`, `UNSAFE_DESTINATION`).
  - Enforced security headers on both JSON responses (`X-Frame-Options: DENY`, `nosniff`) and HTTP RedirectResponses (`Referrer-Policy: strict-origin-when-cross-origin`, `nosniff`, `DENY`).
- **EmbedController & Forms Iframe Embed**:
  - Hardened `/forms/embed.js` widget with `X-Content-Type-Options: nosniff`, `Cache-Control: public, max-age=3600`, and `Access-Control-Allow-Origin: *`.
  - Added standalone secure iframe embed routes `/embed/forms/{projectUuid}/{slug}` and `/forms/embed/{projectUuid}/{slug}` with strict CSP (`frame-ancestors *; default-src 'self'`), dynamic postMessage height reporting (`jambo-form-height`), CSRF/honeypot protection, and localized validation feedback.
- **CorsSubscriber & SecurityHeadersSubscriber**:
  - Expanded `CorsSubscriber::isPublicRoute` to properly allow CORS preflight (OPTIONS) on public form submissions, form views, redirects resolution, embeds, shares, and SEO endpoints.
  - Updated `SecurityHeadersSubscriber` to set `Cross-Origin-Resource-Policy: cross-origin` for public project UUID routes and embeds.
- **SubmitHandler Bugfix**:
  - Fixed a false-positive validation error in `SubmitHandler` by properly wrapping non-required fields in `Assert\Optional` instead of defaulting to required in `Assert\Collection`.
- **Strict I18N & Parity**:
  - Added new keys across all 4 locales (`en`, `fr`, `es`, `ar`) maintaining 100% dictionary parity (2,879 keys each).
- **Test Suite**:
  - Created `Cycle55PublicAuditTest` (6 tests, 96 assertions) validating all public form, redirect, embed, SSRF, payload cap, and CORS flows with 0 warnings.

## [1.26.116] — 2026-11-05

### Error Response Harmonization, Anti-SSRF & Upload Security Caps (TUS & Direct Upload)
- **JSON Error Response Harmonization**:
  - Unified error response schemas across `ContentController`, `CollectionController`, and `FieldController` so all failures consistently provide `error` (translated message), `errors` (detailed field validation map), and uppercase machine-readable `code` (e.g. `INVALID_STATUS`, `VALIDATION_FAILED`, `ASSIGNEE_NOT_MEMBER`, `NAME_REQUIRED`, `NAME_TOO_LONG`, `INVALID_SLUG_FORMAT`, `SLUG_ALREADY_EXISTS`, `INVALID_FIELD_TYPE`, `INVALID_OPTIONS`, `OPTIONS_TOO_LARGE`, `OPTIONS_TOO_DEEP`).
  - Standardized HTTP status codes: 400 (malformed JSON / missing headers), 404 (not found), 409 (conflict / duplicates), 412 (precondition failed), 413 (payload/chunk too large), 415 (unsupported media type), 422 (unprocessable entity / validation errors), 429 (rate limit exceeded).
- **DirectUploadController & DirectUploadService Hardening**:
  - Added strict filename length bound (capped at 255 chars) and non-empty check in intent creation.
  - Implemented anti-SSRF & anti-injection validation via `UploadSecurityValidator::validateMimeType()` rejecting CRLF header injection, control characters, and dangerous executable MIME types (`text/x-php`, `application/x-executable`, etc.) during intent creation.
  - Capped multipart completion parts to 10,000 maximum parts and added granular validation of part structures (1 <= `part_number` <= 10000, safe sanitized ETag strings).
- **TusController & TusServer Security**:
  - Integrated `errorResponse()` helper ensuring every error response includes `error`, `code`, `Tus-Resumable: 1.0.0`, and security headers (`X-Content-Type-Options: nosniff`, `Cache-Control: no-store`).
  - Added strict chunk size limits on PATCH validating `Content-Length` against declared remaining bytes and `MAX_TUS_SIZE` (100 MB).
  - Validated `filetype` metadata using `UploadSecurityValidator::validateMimeType()` to block dangerous executable MIME types.
  - Capped upload metadata key-value pairs to 50 to prevent CPU DoS.
- **Strict I18N & Test Suite**:
  - Maintained 100% dictionary key parity (2,876 keys) across English, French, Spanish, and Arabic for all new error keys.
  - Added comprehensive `Cycle54HardeningTest` verifying uniform error structures, chunk caps, direct upload validations, and 4-locale translation parity.

## [1.26.115] — 2026-11-05

### Realtime/Mercure SSE Hardening, Invitation & Install Audits, Test Matrix & Strict I18N
- **Realtime / Mercure SSE Hardening**:
  - Enforced `SECURITY_HEADERS` (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cache-Control: no-store`) across `RealtimeController` and `MercureFallbackController` on poll, publish, token, and SSE streaming fallback endpoints.
  - Multi-tenant topic scoping and strict validation preventing cross-project topic leaking and wildcard/directory-traversal subscriptions.
- **Invitation & Install Controller Audits**:
  - Hardened `InvitationController` and `InstallController` with `SECURITY_HEADERS`, 16 KB payload limit, rate limiting, and strict UTF-8 MIME headers.
  - Added explicit firewall rule `- { path: ^/api/invitations, roles: PUBLIC_ACCESS }` in `config/packages/security.yaml`.
  - Added dynamic request locale resolution and translated page title in `InstallController::index` and admin setup optional fields (`name`, `locale`).
  - Added comprehensive `InvitationControllerTest` and expanded `InstallControllerTest` with security assertions.
- **PHPUnit Test Matrix Segmentation**:
  - Segmented PHPUnit suites in `phpunit.xml` / `phpunit.xml.dist` into non-overlapping domain suites (`unit`, `auth`, `admin`, `api`, `settings`, `realtime`, `graphql`, `events`, `command`, `security`, `controller`) eliminating duplicate file warnings.
  - Added `Makefile` targets (`make test-fast`, `make test-auth`, `make test-admin`, `make test-api`, `make test-settings`, `make test-realtime`, `make test-hardening`, `make test-i18n`) for sub-second to sub-10s executions.
- **Strict I18N & Hardening Cleanup**:
  - Cleaned up all remaining hardcoded fallback strings in `ProjectController.php`, restoring 100% compliance with strict I18N rules and passing `Cycle32HardeningTest`.
  - Fixed preview SSRF validation to support modern frontend dev server ports (3000, 5173).
  - Ensured `VERSION`, `package.json`, and `HealthController::VERSION` stay strictly in sync.

## [1.26.113] — 2026-11-05

### Admin, Settings & Auth Controllers Security Hardening, RBAC & Strict I18N
- **Admin & Settings Security Hardening (`SECURITY_HEADERS`)**:
  - Enforced `SECURITY_HEADERS` (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cache-Control: no-store`) across all Admin and Settings controllers: `FormController`, `RedirectController`, `AppSettingsController`, `ProjectStorageController`, `ProjectJwtTtlController`, `ProjectMailerSettingsController`, `ContentAiStreamController`, `ProjectSettingsController`, `SecurityController`, `ProfileController`, `PasswordController`, `PersonalAccessTokenController`, `LocaleController`, and `AdminApiOpenApiController`.
  - Added strict payload size limits across all administrative mutating endpoints (`FormController::updateSubmission`, `RedirectController::create` & `update`, `AppSettingsController::update`, `ProjectStorageController` profiles and rules, `ProjectJwtTtlController`, `ProjectMailerSettingsController`, `SecurityController` 2FA setup and confirmation endpoints).
  - Enforced strict UUID RFC 4122 regex validation before database resolution in `FormController`, `RedirectController`, `ProjectStorageController`, `ProjectJwtTtlController`, `ProjectMailerSettingsController`, and `ContentAiStreamController`.
  - Protected `ContentAiStreamController` streamed responses with security headers and tenant access checks.
- **Auth & 2FA Security Hardening**:
  - Enforced security headers (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`) on Inertia responses and JSON responses across `ConfirmablePasswordController`, `RegisteredUserController`, `TwoFactorChallengeController`, `PasswordResetLinkController`, `NewPasswordController`.
  - Added strict payload size limits (16 KB) on registration, password confirmation, password reset links, new passwords, and 2FA challenge verification.
  - Fixed 2FA challenge flow access control in `security.yaml` by ensuring `^/two-factor-challenge` allows `PUBLIC_ACCESS` during the intermediate step before session creation.
  - Refactored `TwoFactorMailer` and `PasswordResetLinkController` to eliminate hardcoded email subjects and HTML bodies, integrating Symfony Translator with fallback.
- **Strict I18N Parity**:
  - Added payload error translation keys (`redirect.admin.payload_too_large`, `app_settings.admin.payload_too_large`, `project.storage.payload_too_large`, `project.jwt_ttl.payload_too_large`, `security.2fa.payload_too_large`, `auth.*`) across `translations/messages.{en,fr,es,ar}.php` maintaining 100% 4-language parity.
- **Automated Test Suites**:
  - Added dedicated test suites `Cycle52AdminSecurityHardeningTest` (8 tests, 80 assertions) and `Cycle52AuthSecurityHardeningTest` (7 tests, 86 assertions) with 100% pass rate.

## [1.26.112] — 2026-11-05

### Core API & Controller Security Hardening, Security Headers, Strict I18N Parity
- **Controller Security Hardening (`SECURITY_HEADERS`)**:
  - Enforced `SECURITY_HEADERS` (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cache-Control: no-store`) via `secureJson()` across 15 controllers: `AiController`, `AutomationController`, `CollectionController`, `ColumnSettingController`, `CommentController`, `ContentController`, `FieldController`, `GraphQLController`, `InvitationController`, `McpController`, `NotificationController`, `SearchController`, `ShareController`, `WebhookController`, and `WebhookInboundController`.
  - Replaced all raw JSON returns in `AiController` and `WebhookController` with `secureJson()` to prevent header omissions and MIME sniffing.
  - Enforced strict payload size caps, prompt sanitization against breakout attacks, and multi-tenant scoping in `AiController`.
  - Enforced rate limiting, body size limits, and project scoping on `/mcp` and `/api/projects/{uuid}/mcp` in `McpController`.
- **Strict I18N Parity**:
  - Added `graphql.mercure_not_configured` translation key across `translations/messages.{en,fr,es,ar}.php`.
  - Maintained 100% key parity across all 4 locales (validated by automated test suite).
- **Test Suite**:
  - Added `Cycle53SecurityHardeningTest` verifying version bump, translation parity, and security headers on hardened controllers.

## [1.26.111] — 2026-11-05

### Project Management & Audit Logs Security Hardening, Strict Role Protection, Rate Limiting & I18N Parity
- **ProjectController Hardening**:
  - Fixed critical authorization flaw: strictly enforced `project.manage` permission check on `update()`, `delete()`, and `cloneProject()` preventing non-manager members from modifying or deleting projects.
  - Enforced authentication verification on `create()` and `cloneProject()` preventing null-pointer exceptions and unauthenticated project generation.
  - Integrated `project_limiter` rate limiter (30 req/min sliding window) protecting `create`, `clone`, and `addMember` against resource exhaustion and invitation flood.
  - Added strict payload size cap `MAX_PAYLOAD_BYTES = 65536` (64 KB) returning 413 `PAYLOAD_TOO_LARGE`.
  - Added member quota ceiling `MAX_MEMBERS_LIMIT = 500` per project returning 422 `project.max_members_reached`.
  - Added strict validation for `name`, `description`, `default_locale`, `locales`, and `disk`.
  - Enforced standard security headers (`Cache-Control: no-store, no-cache, must-revalidate, private`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`) across all JSON responses.
- **AuditController Hardening**:
  - Integrated `audit_limiter` rate limiter (60 req/min sliding window) across audit endpoints.
  - Enforced authentication and `ROLE_SUPER_ADMIN` check on `/api/admin/audit-logs/errors` with translated error responses.
  - Enforced strict query parameter bounds for `limit` and `offset` preventing denial of service.
  - Enforced standard security headers (`Cache-Control: no-store`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`).
- **Rate Limiting & Configuration**:
  - Registered `project_limiter` and `audit_limiter` in `config/packages/rate_limiter.yaml` for both production and test environments.
- **Strict I18N Parity**:
  - Added 11 new translation keys across `en`, `fr`, `es`, `ar` (2842 keys each, 100% parity across all 4 languages).

## [1.26.110] — 2026-11-05

### ApiDocs, PublicApi, Content & Seo Security Hardening, Error Translations & Strict I18N
- Hardened `ApiDocsController`, `PublicApi`, `ContentController`, and `SeoController`.
- Enforced strict rate limiting, security headers, and translated error responses.
- Parity of 2831 translation keys across all 4 languages.

## [1.26.109] — 2026-11-05

### DirectUpload, ImageTransform, Preview & Studio Security Hardening, Rate Limiting & Strict I18N
- Hardened `DirectUploadController`, `ImageTransformController`, `PreviewController`, and `StudioController`.
- Enforced rate limiting, memory limits, and security headers.
- Parity of 2797 translation keys across all 4 languages.

## [1.26.108] — 2026-11-05

### Media, MediaFolder, TUS Resumable Upload, Public Embed & SEO Security Hardening, Quotas, Rate Limiting & Strict I18N
- **Media Rate Limiting & Security Hardening**:
  - Integrated `media_limiter` rate limiter (60 req/min sliding window) across `MediaController` endpoints (`index`, `upload`, `update`, `move`, `crop`, `bulkDestroy`, `delete`).
  - Added ASCII control character rejection on `alt` (`media.alt_control_chars`) and `caption` (`media.caption_control_chars`).
  - Enforced string bounds on search query (`mb_substr(..., 0, 100)`) preventing regex denial of service.
  - Enforced strict security headers (`Cache-Control: no-store, no-cache, must-revalidate`, `X-Content-Type-Options: nosniff`).
  - Integrated structured audit logging (`media_upload`, `media_update`, `media_move`, `media_crop`, `media_bulk_destroy`, `media_delete`) via `AuditService`.
- **MediaFolder Quota & Security Hardening**:
  - Integrated `media_limiter` rate limiter across `MediaFolderController` endpoints (`index`, `tree`, `store`, `update`, `move`, `delete`).
  - Added folder quota ceiling `MAX_FOLDERS_PER_PROJECT = 200` (`media_folder.max_folders_exceeded`) to prevent folder tree flooding.
  - Integrated structured audit logging (`media_folder_create`, `media_folder_update`, `media_folder_move`, `media_folder_delete`) via `AuditService`.
  - Enforced security headers (`no-store`, `nosniff`) across all JSON responses.
- **TUS Resumable Upload Hardening**:
  - Integrated `tus_limiter` rate limiter (120 req/min sliding window) across `create`, `patch`, `head`, `delete`, and `finalize`.
  - Added ASCII control character rejection on metadata filename (`tus.filename_control_chars`).
  - Added offset boundary check (`offset > MAX_TUS_SIZE`) returning 413 `tus.chunk_out_of_bounds`.
  - Standardized exception responses with strict translated keys (`tus.chunk_out_of_bounds`, `tus.conflict`, `tus.runtime_error`, `tus.validation_failed`).
  - Enforced security headers (`no-store`, `nosniff`) on all TUS responses.
  - Integrated audit logging (`tus_upload_delete`, `tus_upload_finalize`) via `AuditService`.
- **Public SEO & Embed Widget Hardening**:
  - Integrated `seo_limiter` rate limiter (30 req/min sliding window) across `sitemap`, `imageSitemap`, and `robots`.
  - Enforced `X-Content-Type-Options: nosniff` and caching headers on SEO and embed endpoints.
  - Eliminated hardcoded French strings from `EmbedController` widget by introducing locale-aware translated labels (`form.embed.loading`, `form.embed.submit`, `form.embed.submitting`, `form.embed.success`, `form.embed.error_prefix`).
- **Strict I18N Parity**:
  - Added 17 new translation keys across `translations/messages.{en,fr,es,ar}.php` (2768 keys each, 100% parity).

## [1.26.107] — 2026-11-05

### AdminApi Security Hardening, Quotas, Rate Limiting & Strict I18N
- **AdminApi Rate Limiting**:
  - Integrated `admin_api_limiter` rate limiter (60 req/min sliding window) across `ProjectController`, `CollectionController`, and `FieldController` endpoints.
  - Standardized 429 response `RATE_LIMIT_EXCEEDED` with strict translations across all locales.
- **ProjectController Hardening**:
  - Enforced strict payload size boundary `MAX_PAYLOAD_BYTES = 524288` (512 KB) returning 413 `PAYLOAD_TOO_LARGE`.
  - Added project count quota ceiling `MAX_PROJECTS_PER_USER = 100` (`MAX_PROJECTS_EXCEEDED`) preventing resource exhaustion.
  - Added ASCII control character rejection on project names (`NAME_CONTROL_CHARS`).
  - Integrated structured audit logging (`admin_api_project_create`, `admin_api_project_update`, `admin_api_project_delete`) via `AuditService`.
  - Enforced security headers (`Cache-Control: no-store, no-cache, must-revalidate`, `X-Content-Type-Options: nosniff`).
- **CollectionController Hardening**:
  - Added collection quota ceiling `MAX_COLLECTIONS_COUNT = 100` (`MAX_COLLECTIONS_EXCEEDED`).
  - Added ASCII control character rejection on collection names (`NAME_CONTROL_CHARS`).
  - Integrated structured audit logging (`admin_api_collection_create`, `admin_api_collection_update`, `admin_api_collection_delete`) via `AuditService`.
  - Enforced security headers across JSON responses and CSV export streaming (`no-store`, `nosniff`).
- **FieldController Hardening**:
  - Added field quota ceiling `MAX_FIELDS_COUNT = 100` (`MAX_FIELDS_EXCEEDED`).
  - Added ASCII control character rejection on field names (`NAME_CONTROL_CHARS`).
  - Integrated structured audit logging (`admin_api_field_create`, `admin_api_field_update`, `admin_api_field_delete`) via `AuditService`.
  - Enforced security headers across all field endpoints (`no-store`, `nosniff`).
- **AdminApiControllerTrait Strict I18N**:
  - Replaced hardcoded strings in `pat()` and `requireScope()` with strict translation keys `admin_api.no_token` and `admin_api.missing_scope`.
- **SchemaProvisioner Safe Cascade Deletion**:
  - Fixed project deletion to remove associated `ProjectMember` entities from identity map before flushing, avoiding UnitOfWork detached entity conflict during audit logging.
- **Strict I18N Parity**:
  - Added 10 new translation keys across `translations/messages.{en,fr,es,ar}.php` (2751 keys each, 100% parity).
- **Testing**:
  - Added `Cycle48SecurityHardeningTest` with 11 tests and 117 assertions validating rate limits, payload ceilings, control chars, quotas, security headers, audit logs, and i18n parity.

## [1.26.106] — 2026-11-05

### Insights, Flow, Versioning & ProjectTemplate Security Hardening, Quotas, Rate Limiting & Strict I18N
- **InsightsController Hardening**:
  - Integrated `insights_limiter` rate limiter (60 req/min sliding window) across `summary` and `project` analytics endpoints to prevent DB denial-of-service.
  - Hardened unauthenticated protection with clean error code `AUTH_REQUIRED` and security headers (`no-store, no-cache`, `X-Content-Type-Options: nosniff`).
  - Standardized invalid range detection returning `INVALID_RANGE` (400) with strict translation.
- **FlowController Hardening**:
  - Integrated `flow_limiter` rate limiter (30 req/min sliding window) protecting catalog retrieval and flow graph validation.
  - Enforced strict payload size boundary `MAX_PAYLOAD_BYTES = 524288` (512 KB), returning 413 `PAYLOAD_TOO_LARGE`.
  - Added graph complexity ceilings preventing algorithmic DoS: `MAX_NODES_COUNT = 100` (`NODES_LIMIT_EXCEEDED`) and `MAX_EDGES_COUNT = 200` (`EDGES_LIMIT_EXCEEDED`).
  - Enforced security headers (`no-store`, `nosniff`) on all flow responses.
- **VersioningController Hardening**:
  - Integrated `versioning_limiter` rate limiter (30 req/min sliding window) on version listing, diff, and restoration.
  - Added strict pagination clamp on version list (`MAX_PAGE_LIMIT = 100`, default 50) preventing unbounded memory exhaustion on large history sets.
  - Version number bounds enforcement (`1` to `1,000,000`) for both restoration and diff endpoints (`INVALID_VERSION`).
  - Integrated structured audit logging (`version_restore`) through `AuditService`.
  - Enforced security headers across all versioning endpoints.
- **ProjectTemplateController Hardening**:
  - Integrated `template_limiter` rate limiter (30 req/min sliding window) on template list, view, export, apply, and delete.
  - Payload size boundary `MAX_PAYLOAD_BYTES = 524288` (512 KB) for template export and apply (`PAYLOAD_TOO_LARGE`).
  - System-wide quota limit `MAX_TEMPLATES_COUNT = 100` (`MAX_TEMPLATES_EXCEEDED`).
  - Sanitization of template names and descriptions (stripping ASCII control characters, max lengths 255 / 2000).
  - Integrated structured audit logging (`project_template_export`, `project_template_apply`, `project_template_delete`) via `AuditService`.
  - Enforced security headers on all responses.
- **Strict I18N Parity**:
  - Resolved regression in `Cycle10HardeningTest` and `Cycle25HardeningTest` for i18n messages.
  - Added complete dictionary keys across `translations/messages.{en,fr,es,ar}.php`:
    - `insights.rate_limit_exceeded`
    - `flow.admin.rate_limit_exceeded`
    - `flow.admin.payload_too_large`
    - `flow.admin.nodes_limit_exceeded`
    - `flow.admin.edges_limit_exceeded`
    - `versioning.rate_limit_exceeded`
    - `template.rate_limit_exceeded`
    - `template.payload_too_large`
- **Testing**:
  - Added comprehensive `Cycle47SecurityHardeningTest` with 12 tests and 107 assertions.

## [1.26.105] — 2026-11-05

### Settings & Profile Security Hardening, Token Quotas, Mailer Anti-SSRF & Strict I18N Parity
- **Settings & Profile Security Hardening**:
  - `ProfileController`:
    - Rate limiting protection (`profile_limiter` sliding window: 15 req/min).
    - Payload size ceiling enforcement (max 64 KB).
    - Input sanitization & bounds: control characters stripped from `name`, strict email validation (`FILTER_VALIDATE_EMAIL`, max 180 chars), unique check.
    - Account deletion security: mandatory password verification (`$hasher->isPasswordValid()`) for accounts with credentials.
    - Last superadmin lockout protection: prevents deleting the sole remaining active super administrator (`ROLE_SUPER_ADMIN`).
    - Full structured audit logging (`profile_update`, `profile_delete`) via `AuditService`.
  - `PasswordController`:
    - Rate limiting (`login_limiter` sliding window: 5 req/min) preventing password brute-force probes.
    - Payload size limit (64 KB).
    - Audit logging (`password_change`) via `AuditService`.
    - Strict I18N replacing all hardcoded errors.
  - `LocaleController`:
    - Payload size limit (16 KB) and JSON validation.
    - Open redirect protection: validates referer host against request host.
    - Audit logging (`locale_change`) via `AuditService`.
  - `PersonalAccessTokenController`:
    - Token quota ceiling: strict limit of 50 active PATs per user (`MAX_TOKENS_REACHED`).
    - Payload bounds (32 KB), name bounds (255 chars, control characters blocked).
    - Scope whitelist validation (`schema:write`, `projects:write`, `content:write`, `content:read`, `*`).
    - Audit logging (`pat_create`, `pat_delete`) via `AuditService`.
- **ProjectSettingsController Hardening & Anti-SSRF**:
  - **Token Management**:
    - Rate limiting protection (`token_management_limiter` sliding window: 30 req/min).
    - Strict quota ceiling: max 50 tokens per project (`MAX_TOKENS_REACHED`).
    - Name sanitization (max 255 chars, control character rejection).
    - Abilities whitelist validation (`read`, `create`, `update`, `delete`, `*`).
    - Future expiration date validation.
    - Audit logging (`api_token_create`, `api_token_update`, `api_token_delete`).
  - **SMTP Mailer Hardening & Anti-SSRF**:
    - Integrated `SsrfProtectionService`: blocks private/internal IP ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8, 169.254.169.254 cloud metadata, IPv6 loopbacks).
    - SMTP port whitelist enforcement: allowed only 25, 465, 587, 2525.
    - Encryption protocol whitelist: `tls`, `ssl`, `none`.
    - `from_name` CRLF injection prevention (`\r`, `\n` blocked).
    - `from_email` validation format.
    - Rate-limited test email dispatcher (`mailer_test_limiter` sliding window: 5 req/min) preventing mail relay abuse.
    - Audit logging (`project_settings_mailer_update`, `project_settings_mailer_test`).
  - **Localization Settings**:
    - Locale format regex enforcement (`/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i`).
    - Quota ceiling of 50 locales maximum per project.
    - Prevention of deleting the active default locale.
    - Cross-validation ensuring `default_locale` is always member of `locales`.
- **Auxiliary Endpoints Hardening**:
  - `CaptchaController`: Strict I18N for project not found and rate limit responses.
  - `ProjectOpenApiController`: Strict I18N for rate limit, not found, and unauthenticated responses.
  - `AdminApiOpenApiController`: Strict I18N for rate limit response.
  - `MercureFallbackController`: Strict I18N for rate limit and unavailable hub fallback.
  - `InvitationController`: Token format and length bounds validation (`/^[A-Za-z0-9_\-]{8,128}$/`), strict I18N for unauthenticated access.
- **100% Strict I18N Parity**:
  - Added 77 new translation keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
  - Exact total: 2,733 keys per locale (0 diff).
- **Test Coverage**:
  - Added `tests/Controller/Settings/SettingsAndProfileSecurityHardeningTest.php` (15 tests, 48 assertions).
  - Added `tests/Controller/ProjectSettingsHardeningTest.php` (10 tests, 64 assertions).
  - 100% pass rate across unit and controller hardening suites.

## [1.26.104] — 2026-11-05

### OIDC SSO Hardening, Alg-None & CSRF Defense, Unverified Email Mitigation & Strict Auth I18N
- **OIDC SSO & Algorithm Confusion Hardening**:
  - `OidcProviderManager`:
    - Strict algorithm validation blocking `alg: "none"` and enforcing exact algorithm match against configured `idTokenSigningAlg` to prevent algorithm confusion attacks (RFC 7519 / OIDC Core 1.0).
    - Mandatory non-empty `sub` claim verification.
    - Verified email claim extraction and enforcement (`email_verified !== false`).
    - Validated email address format (`filter_var(..., FILTER_VALIDATE_EMAIL)`).
    - Direct `$clientId` parameter binding in `buildAuthorizationUrl()` eliminating fragile URL regex substitutions.
    - State payload signing with HMAC-SHA256 and session binding for CSRF replay protection.
  - `OidcAuthenticator`:
    - Enforced mandatory active session and strict CSRF state validation with single-use session consumption (`oidc_state`, `oidc_code_verifier`, `oidc_nonce`).
    - Anti-Account Takeover protection rejecting unverified email addresses (`email_verified === false`) or malformed emails during admin account linking.
    - Sanitized display names stripping ASCII control characters (`\x00-\x1F\x7F`) with fallback to safe default.
    - Sanitized avatar URL with protocol whitelist (`https://`).
    - Localized exception reporting via `CustomUserMessageAuthenticationException`.
  - `OidcController`:
    - Added rate limiting protection (`oidc_limiter` sliding window: 15 req/min) on `/oidc/start/{providerId}`.
    - Provider identifier regex validation (`/^[a-zA-Z0-9_\-]{1,64}$/`) preventing directory traversal or path injection.
    - Full internationalization of unknown/disabled provider exceptions via `TranslatorInterface`.
- **Authentication Controllers Hardening & Strict I18N Parity**:
  - `ConfirmablePasswordController`: Integrated rate limiting (`login_limiter`) preventing brute-force password confirmation; replaced hardcoded error strings with `trans()`.
  - `PasswordResetLinkController`: Replaced hardcoded status and validation messages with localized keys.
  - `NewPasswordController`: Replaced all hardcoded error/length/mismatch strings with localized keys.
  - `RegisteredUserController`: Replaced all hardcoded validation and duplicate error strings with localized keys.
  - `InertiaController`: Centralized protected `trans()` helper across all Inertia-backed controllers.
- **100% Strict I18N Parity**:
  - Added 25 new translation keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`):
    - `oidc.*`: rate limits, unknown provider, missing parameters, session expiration, invalid state, unsupported end-user, invalid id token, invalid email, unverified email.
    - `auth.confirm_password.*`: auth required, rate limits, invalid JSON, incorrect password.
    - `auth.password_reset.*`: rate limits, invalid email, email sent status, invalid/expired link, length, mismatch, user not found, same as current.
    - `auth.register.*`: rate limits, invalid JSON, passwords mismatch, email in use.
  - Exact key count: 2,656 keys per locale (0 diff).
- **Test Coverage**:
  - Added comprehensive `Cycle46AuthOidcSecurityHardeningTest` with 15 tests and 154 assertions covering OIDC algorithm confusion defense, CSRF single-use state, unverified email account linking rejection, name sanitization, and I18N parity (1,341 total tests).

## [1.26.103] — 2026-11-05

### OAuth / Social Auth Hardening, PKCE Protection, CSP Directives & Admin Session Isolation
- **OAuth & Social Auth Hardening**:
  - `SocialLoginAuthenticator`: Enforced mandatory active session and strict CSRF state validation with single-use consumption, eliminating OAuth CSRF and pre-account takeover vulnerabilities.
  - `SocialLoginController`: Implemented cryptographic PKCE (RFC 7636) code verifier and S256 code challenge generation stored in session and verified upon provider callback.
  - `SocialLoginService`: Added mandatory email verification check rejecting unverified email claims (`email_verified === false`), preventing illegitimate account linking.
  - `ProjectOAuthService`: Integrated tenant project `jwtRefreshTtl` policy into authorization code token exchange and refresh token rotation, ensuring refresh token lifetimes adhere strictly to project configuration.
  - `ProjectOAuthController`: Full internationalization with `TranslatorInterface` replacing all hardcoded error descriptions and UI strings across authorization, token, and revocation endpoints.
- **Content Security Policy (CSP) & Session Cookie Isolation**:
  - `SecurityHeadersSubscriber`: Deployed hardened Content Security Policy across all HTML responses (`default-src 'self'`, `script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net`, `style-src 'self' 'unsafe-inline' https://fonts.googleapis.com`, `font-src 'self' https://fonts.gstatic.com data:`, `connect-src 'self' https: wss:`, `frame-ancestors`, `object-src 'none'`).
  - Dynamic frame-ancestors enforcement: `*` on embeddable widgets, `'none'` on token settings routes, and `'self'` across standard backoffice views.
  - Admin session cookie isolation (`config/packages/framework.yaml`): Standardized `name: JAMBOSESSID`, `cookie_httponly: true`, `cookie_secure: auto`, and `cookie_samesite: lax`, with automatic elevation to `SameSite=Strict` on sensitive admin routes.
- **I18N & Non-Regression Suite**:
  - 30 new translation keys in `translations/messages.{en,fr,es,ar}.php` (2,631 total symmetrical keys per locale).
  - 12 new comprehensive unit tests in `Cycle45OAuthSecurityHardeningTest` (1,326 total passing tests).

## [1.26.102] — 2026-11-05

### Realtime SSE/Mercure Multi-Tenant Isolation, Plugin Manifest RBAC & Backup Manager Hardening
- **RealtimeController & MercureTopicSecurityService**:
  - Enforced strict multi-tenant topic boundaries preventing cross-tenant eavesdropping and topic injection attacks.
  - Implemented topic structure validation enforcing sanitized regex prefix `projects/{projectUuid}`.
  - Restricted wildcard and control characters (`..`, `\0`, `\\`, `*`, `?`, `#`, `+`, `//`) in SSE subscriptions.
  - Added role-based publishing authorization: only project managers, editors, admins, and super admins can publish realtime events.
  - Added strict payload size boundary (64KB max) and JSON structure verification on `/realtime/publish`.
  - Added short-polling fallback path traversal guard on var/realtime event streams.
- **PluginController & PluginManager**:
  - Super admin / Admin RBAC enforcement on all plugin management endpoints (`/api/admin/plugins`).
  - Manifest payload limit (64KB max) and SemVer regex validation (`^v?[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9\.\-_]{1,50})?$`).
  - Permission and hook whitelisting preventing arbitrary code execution hooks.
  - Directory traversal prevention on plugin IDs and entrypoints (`..`, `/`, `\`, null bytes).
  - Rate limiting integration and audit logging for plugin lifecycle (registration, toggle, deletion).
- **BackupController & BackupManager**:
  - Super admin RBAC exclusivity for database backup creation, restoration, download, and deletion (`/api/admin/backups`).
  - Strict filename sanitization (`/^backup-[a-zA-Z0-9_\-]{8,64}\.(sql|sql\.gz|zip)$/`) and directory traversal confinement.
  - Backup file size limit enforcement (250MB max) and SQL statement safety scanner blocking dangerous statements (`INTO OUTFILE`, `LOAD DATA LOCAL`, `GRANT ALL`, `LOAD_FILE`, etc.).
  - Mandatory confirmation flag (`confirm=true`) for restore operations.
  - Rate limiting integration and audit trail logging on all backup actions.
- **100% Strict I18N Parity**:
  - Added 47 new translation keys across English, French, Spanish, and Arabic dictionaries (`messages.{en,fr,es,ar}.php`):
    - `realtime.*`: topic formatting, bounds, cross-tenant denial, publishing authorization, payload size
    - `plugin.*`: manifest validation, SemVer, permissions, hooks, entrypoint traversal, rate limits, lifecycle
    - `backup.*`: privileges, label limits, traversal, size limits, SQL safety scanner, restoration confirmation, failure handling
  - Exact parity maintained across all locales (2,602 keys per language, 0 missing).
- **Test Coverage**:
  - Added comprehensive `Cycle46HardeningTest` with 14 unit tests and 64 assertions verifying realtime topic isolation, publishing RBAC, plugin validation, SQL safety scanning, rate limiting, and HTTP response security headers.

## [1.26.101] — 2026-11-05

### Deployment Webhooks, Public Share Anti-Enumeration & Collection Template RBAC Hardening
- **DeploymentController Security Hardening**:
  - Enforced minimum secret length requirement (`MIN_SECRET_LENGTH = 16`) for `DEPLOY_WEBHOOK_SECRET` with localized `deployment.secret_too_short` (HTTP 503 `WEBHOOK_DISABLED`).
  - Implemented anti-timing attack protection using `hash_equals()` with fallback dummy HMAC computations on missing or malformed signature prefixes.
  - Added dual webhook signature support for `X-Jambo-Signature` and standard GitHub `X-Hub-Signature-256`.
  - Added strict environment validation (`preview`, `staging`, `production`) returning HTTP 422 `INVALID_ENVIRONMENT` rather than silent fallback.
  - Added strict deployment status validation (`pending`, `running`, `succeeded`, `failed`, `canceled`) returning HTTP 422 `INVALID_STATUS` rather than silent fallback.
  - Normalized git commit SHA validation (`/^[a-fA-F0-9]{4,40}$/`, lowercased).
  - Hardened git branch name validation enforcing git ref specs (rejection of control characters, spaces `\s`, path traversals `..`, consecutive slashes `//`, leading/trailing slashes or dots, reserved characters `~^:?*[]\@`, and `.lock$` suffix).
  - Validated `project_uuid` format and existence, returning HTTP 422 `INVALID_PROJECT_UUID` or HTTP 404 `PROJECT_NOT_FOUND` on invalid inputs instead of silent omission.
  - Added optional project filtering on `GET /api/deployments` with tenant authorization (`project.view`).
  - Added audit logging on webhook auth failures (`deployment_hook_auth`) and deployment updates (`deployment_hook`).
- **PublicShareController Security Hardening**:
  - Reinforced strict response security headers with `Surrogate-Control: no-store` and `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` preventing proxy caching and crawler indexing.
  - Explicitly autowired `limiter.share_limiter` rate limiting factory via `#[Autowire(service: 'limiter.share_limiter')]`.
  - Anti-enumeration protection returning consistent HTTP 404 with error key `invalid` for unknown shares, revoked shares, deleted content entries, soft-deleted collections, and multi-tenant project mismatches.
  - Integrated audit trail logging on rate-limited public share requests.
- **CollectionTemplateController Security Hardening**:
  - Enforced strict `ROLE_ADMIN` / `ROLE_SUPER_ADMIN` check on template creation and `saveFromCollection()` with localized access denied messaging.
  - Made request parameter optional on `index(?Request $request = null)` with limit boundary validation (`MAX_TEMPLATES_COUNT = 200`).
  - Validated EAV template field definitions on `create()`: required field name (`FIELD_NAME_REQUIRED`), required field slug (`FIELD_SLUG_REQUIRED`), and regex-validated slug (`INVALID_FIELD_SLUG`).
  - Rejected template creation from soft-deleted collections with HTTP 404 `template.collection_not_found`.
  - Enforced template quota (`MAX_TEMPLATES_COUNT = 200`) and field quota (`MAX_FIELDS_PER_TEMPLATE = 100`).
- **100% Strict I18N Parity**:
  - Added 7 new translation keys across English, French, Spanish, and Arabic dictionaries (`messages.{en,fr,es,ar}.php`):
    - `deployment.invalid_environment`, `deployment.invalid_status`, `deployment.invalid_project_uuid`, `deployment.project_not_found`
    - `template.field_name_required`, `template.field_slug_required`, `template.field_slug_invalid`
  - Maintained exact parity across all 4 locales (2,555 keys per language, 0 diff).
- **Test Coverage**:
  - Expanded `Cycle45HardeningTest` to 24 tests and 126 assertions covering webhook bounds, timing attacks, branch validation, anti-enumeration, headers, and RBAC.

## [1.26.100] — 2026-11-05

### Deployment Webhooks, Public Share Enumeration Protection & Collection Template RBAC
- **DeploymentController Hardening**:
  - Webhook secret anti-timing attack protection with constant-time HMAC comparison via `hash_equals()`.
  - Enforced strict payload size boundary (`1MB`), JSON structure validation, and disabled webhook signaling when `DEPLOY_WEBHOOK_SECRET` is unset or empty.
  - Strict git commit SHA hex validation (`/^[a-fA-F0-9]{4,40}$/`).
  - Strict git branch name format checking according to git ref specifications: blocked control characters, CRLF, path traversal (`..`), double slashes (`//`), leading/trailing slashes or dots, and reserved ref characters (`~`, `^`, `:`, `?`, `*`, `[`, `\`, `@{`).
  - Restricted preview URL and run URL bounds to 500 characters, validated schemes (`http`, `https`), and integrated `SsrfProtectionService` validation.
  - OCI container image reference format validation (`^[a-zA-Z0-9_\-\.\/@:]+$`, max 500 characters).
  - Sanitized deployment error message fields, stripping non-printable control characters while preserving formatting (`\n`, `\r`, `\t`).
  - Integrated `AuditService` logging for deployment hook execution.
  - Applied strict security response headers (`Cache-Control: no-store, no-cache, must-revalidate, private`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`).
- **PublicShareController Hardening**:
  - Strict token format validation (length between 8 and 128 characters, character set restricted to `[A-Za-z0-9_\-]`).
  - Anti-enumeration protection: returned uniform HTTP 404 with error key `invalid` for unknown shares, revoked shares, deleted content entries, soft-deleted collections, and cross-project tenant mismatches.
  - Explicit HTTP 410 with error key `expired` maintained for expired links.
  - Format validation allowing only `html` and `json`, rejecting unauthorized extensions with uniform 404.
  - Applied strict security headers across all success and error responses: `Cache-Control: no-store, no-cache, must-revalidate, private`, `Pragma: no-cache`, `Expires: 0`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`.
- **CollectionTemplateController Hardening**:
  - Enforced mandatory `ROLE_ADMIN` / `ROLE_SUPER_ADMIN` check on `saveFromCollection()`, preventing non-admin project members from creating global templates.
  - Added global template quota protection (`MAX_TEMPLATES_COUNT = 200`) on both `create()` and `saveFromCollection()` to prevent DoS / storage exhaustion.
  - Enforced strict field type validation against `FieldController::ALLOWED_TYPES`, returning HTTP 422 with `INVALID_FIELD_TYPE` for unauthorized types.
  - Bounded field options payload size (max 32KB JSON) to prevent memory exhaustion / payload flooding.
  - Integrated `AuditService` audit logs on template creation, template cloning from collection, and template deletion.
  - Added strict security headers on all responses.
- **I18N Parity & PHPUnit Test Coverage**:
  - Added translations for `template.admin_required`, `template.access_denied`, `template.max_templates_exceeded`, `template.field_invalid_type`, `template.field_options_too_large`, `deployment.invalid_json`, `deployment.secret_too_short`, and `public_share.error.unsupported_format` with 100% strict parity across `en`, `fr`, `es`, `ar`.
  - Added comprehensive `Cycle45HardeningTest` suite (16 tests, 96 assertions).

## [1.26.99] — 2026-11-05

### ImageTransform, Preview & MCP Hardening, Strict I18N & UUID Full-Spectrum Compatibility
- **ImageTransformController Hardening**:
  - Enforced strict 36-char 8-4-4-4-12 UUID format validation on media route parameters for both transform and info endpoints.
  - Eliminated internal exception message leakage on transformation errors, returning translated `media.transform_failed` with HTTP 422.
  - Replaced hardcoded French fallback strings with standardized English defaults backed by Translator.
  - Bounded transformation query parameters: width (1..4000), height (1..4000), quality (1..100), whitelist validation on fit (`contain`, `cover`, `fill`, `crop`, `scale-down`) and format (`webp`, `avif`, `png`, `jpg`, `jpeg`, `gif`), and hex color format on bg.
  - Preserved SVG BinaryFileResponse security headers (`Content-Security-Policy: default-src 'none'; frame-ancestors 'none';`).
- **PreviewController Hardening**:
  - Enforced strict UUID format validation on `$projectUuid` and `$entryUuid` across token generation, URL validation, and preview content retrieval.
  - Enforced collection slug length and character format validation (`^[a-z0-9_-]+$`).
  - Bounded JWT preview token length to 4096 bytes to protect against token expansion attacks.
  - Validated claims structure strictly: verified `pid` and `eid` are valid UUIDs, and enforced exact project, entry, and collection claim matching.
- **McpController & McpServer Hardening**:
  - Added strict JSON-RPC 2.0 version validation (`jsonrpc: "2.0"` required; returns error `-32600`).
  - Enforced method string requirement (max 128 chars) and params array/object requirement (returns error `-32600`).
  - Enforced RPC `id` scalar/string/integer type check and length capping (max 256 chars).
  - Enforced payload bounding (max 2MB, HTTP 413) and empty payload rejection (HTTP 400 with JSON-RPC error).
  - Enforced Bearer token character and length bounding (10..2048 chars).
  - Enforced strict project UUID validation and multi-tenant isolation on `/api/projects/{uuid}/mcp`.
- **Global Compatibility & Bugfixes**:
  - Normalized `UUID_REGEX` across `DirectUploadController`, `ColumnSettingController`, `CommentController`, `NotificationController`, `WebhookController`, and `WebhookInboundController` to full 8-4-4-4-12 hex UUID format, resolving regressions on numeric/mock UUIDs.
  - Fixed `CommentController::resolveComment` to resolve comment entity directly with multi-tenant project/collection matching.
  - Fixed `EnsureProjectMemberSubscriber` translation key `project.access_denied_to_project`.
  - Adjusted `PublicShareController::MIN_TOKEN_LENGTH` to 8 chars.
- **Strict I18N Parity**:
  - Added `media.transform_failed`, `media.invalid_params`, `mcp.invalid_request`, `preview.invalid_collection`, `project.access_denied_to_project`.
  - Maintained 100% dictionary parity across all 4 locales (en, fr, es, ar: 2,540 keys each).

## [1.26.98] — 2026-11-05

### Webhook, Comment, Notification, ColumnSetting & DirectUpload Hardening & Strict I18N
- **WebhookController & WebhookInboundController Hardening**:
  - Enforced strict UUID format validation (`^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`) on project and webhook UUIDs.
  - Added structured machine-readable error codes across all responses (`PROJECT_NOT_FOUND`, `WEBHOOK_NOT_FOUND`, `AUTH_REQUIRED`, `ACCESS_DENIED`, `INVALID_JSON`, `PAYLOAD_TOO_LARGE`, `NAME_URL_REQUIRED`, `NAME_TOO_LONG`, `INVALID_URL`, `SECRET_LENGTH_INVALID`, `TOO_MANY_EVENTS`, `INVALID_EVENT_FORMAT`, `TOO_MANY_COLLECTIONS`, `INVALID_COLLECTION_SLUG`, `BUS_NOT_CONFIGURED`, `ENDPOINT_NOT_FOUND`, `AUTOMATION_INACTIVE`, `NO_FLOW_GRAPH`, `NOT_WEBHOOK_TRIGGER`, `SIGNATURE_INVALID`, `SECRET_DECRYPT_ERROR`).
  - Enforced strict payload size bounding (512KB for admin endpoints, 2MB for inbound webhooks) returning HTTP 413.
  - Validated events array: capped to max 50 events with strict character regex (`^[a-z0-9_.-]+$`).
  - Validated collection slugs array: capped to max 50 slugs with strict slug regex (`^[a-z0-9_-]+$`).
  - Integrated `AuditService` audit trail logging for webhook creation, modification, rotation, and deletion (`webhook_management`).
- **CommentController Hardening**:
  - Enforced strict UUID regex validation on `$projectUuid` and slug regex validation on `$collectionSlug`.
  - Validated positive numeric entry and comment identifiers (`$entryId > 0`, `$id > 0`).
  - Enforced strict payload size bounding (512KB) returning HTTP 413.
  - Added structured machine-readable error codes (`ENTRY_NOT_FOUND`, `COMMENT_NOT_FOUND`, `AUTH_REQUIRED`, `ACCESS_DENIED`, `RATE_LIMIT_EXCEEDED`, `INVALID_JSON`, `PAYLOAD_TOO_LARGE`, `BODY_EMPTY`, `BODY_TOO_LONG`, `INVALID_STATUS`).
  - Integrated `AuditService` audit logging for comment creation, updates, resolution, and deletion (`comment_management`).
- **NotificationController Hardening**:
  - Injected Symfony `TranslatorInterface` and removed all remaining hardcoded English text strings.
  - Enforced strict UUID regex validation on project query filters.
  - Added structured machine-readable error codes (`NOTIFICATION_NOT_FOUND`, `PROJECT_NOT_FOUND`, `ACCESS_DENIED`, `AUTH_REQUIRED`, `RATE_LIMIT_EXCEEDED`).
- **ColumnSettingController Hardening**:
  - Added strict UUID and slug regex validation on project and collection parameters.
  - Enforced strict payload bounding (256KB) returning HTTP 413.
  - Added structured machine-readable error codes (`AUTH_REQUIRED`, `PROJECT_NOT_FOUND`, `COLLECTION_NOT_FOUND`, `INVALID_JSON`, `PAYLOAD_TOO_LARGE`, `COLUMNS_MUST_BE_ARRAY`).
- **DirectUploadController & DeploymentController Hardening**:
  - Fixed undefined variable `$e` exception bug in `DirectUploadController::complete`.
  - Added UUID regex validation and payload size bounding (512KB) across direct upload endpoints.
  - Injected `SsrfProtectionService` in `DeploymentController`, eliminating custom incomplete IP checking and strengthening anti-SSRF protections with DNS pinning and evasive IP blocking.
- **I18N Parity**:
  - Added 19 new translation keys, bringing total keys to 2,535 with strict 100% parity across English (`en`), French (`fr`), Spanish (`es`), and Arabic (`ar`).
- **Test Suite**:
  - Added `Cycle43HardeningTest` (12 tests, 57 assertions), 100% passing.

## [1.26.97] — 2026-11-05

### CollectionController, FieldController & ContentController Hardening, Dynamic Schema Protection & Strict I18N
- **FieldController Hardening**:
  - Full Symfony `TranslatorInterface` injection eliminating all remaining hardcoded English text strings (`field.admin.*`).
  - Added structured machine-readable error codes (`PROJECT_NOT_FOUND`, `COLLECTION_NOT_FOUND`, `FIELD_NOT_FOUND`, `AUTH_REQUIRED`, `INVALID_JSON`, `PAYLOAD_TOO_LARGE`, `NAME_REQUIRED`, `NAME_TOO_LONG`, `TYPE_REQUIRED`, `INVALID_FIELD_TYPE`, `SLUG_TOO_LONG`, `INVALID_SLUG_FORMAT`, `OPTIONS_TOO_LARGE`, `OPTIONS_TOO_DEEP`, `INVALID_REORDER_LIST`, `SCHEMA_ERROR`).
  - Implemented strict UUID format validation on URL project parameters (`^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`).
  - Enforced strict payload size bounding (max 512KB) preventing DoS via oversized JSON bodies (HTTP 413).
  - Validated field definitions against `ALLOWED_TYPES` whitelist, slug formatting rules, and options complexity limits (max 64KB JSON, max 6 nesting depth levels).
  - Hardened field reordering: validated array structure, capped batch size at 500 items, and sanitized field identifiers and order boundaries (0 to 10000).
  - Integrated `AuditService` audit trail logging on field creation, updates, reordering, and soft deletion (`field_management`).
- **CollectionController & AdminApi Hardening**:
  - Added strict UUID format verification on `projectUuid` parameters before executing repository lookups.
  - Added structured machine error codes across standard API and Admin API façades (`PROJECT_NOT_FOUND`, `COLLECTION_NOT_FOUND`, `AUTH_REQUIRED`, `INVALID_JSON`, `PAYLOAD_TOO_LARGE`, `NAME_REQUIRED`, `NAME_TOO_LONG`, `SLUG_TOO_LONG`, `INVALID_SLUG_FORMAT`, `SLUG_ALREADY_EXISTS`, `DESCRIPTION_TOO_LONG`, `REORDER_INVALID_LIST`).
  - Enforced payload size limit (max 512KB) on collection creation, updates, and reorder.
  - Bounded CSV exports in `AdminApi\CollectionController` to 50,000 rows max per batch to prevent memory exhaustion on massive databases.
- **ContentController (Admin Entries) Hardening**:
  - Added strict UUID format verification on `projectUuid` preventing invalid database lookups.
  - Added structured machine-readable error codes across all entry actions (`PROJECT_NOT_FOUND`, `COLLECTION_NOT_FOUND`, `ENTRY_NOT_FOUND`, `AUTH_REQUIRED`, `INVALID_JSON`, `TOO_MANY_FIELDS`, `INVALID_STATUS`, `LOCALE_NOT_ENABLED`, `SINGLETON_ALREADY_EXISTS`, `ASSIGNEE_NOT_MEMBER`, `INVALID_UUID_FORMAT`, `SEARCH_QUERY_TOO_LONG`, `INVALID_SORT_CLAUSE`, `INVALID_DATE_FILTER`, `PAYLOAD_TOO_LARGE`, `VALIDATION_FAILED`).
  - Payload bounding enforced at 1MB (HTTP 413 `content.admin.payload_too_large`).
  - Replaced hardcoded version snapshot label (`'Sauvegarde'`) with translated token (`content.admin.version_save_label`).
  - Wired full-text `$search` query parameter into `findByCollectionPaginated` and `countByCollection`.
  - Hardened sort clauses with strict whitelist validation (`created_at`, `updated_at`, `published_at`, `id` with `asc`/`desc` direction), rejecting malicious injections.
  - Hardened `date_from` and `date_to` query filters with strict ISO-8601 regex validation (`YYYY-MM-DD`), preventing uncaught `\Exception` 500 errors.
  - Protected `ContentEntryRepository` against invalid date parsing with exception handling.
  - Hardened payload field validator: bounded rich text/wysiwyg/code types to 1,000,000 characters, validated email format, and validated numeric fields.
- **EavFieldHelperService & EnsureProjectMemberSubscriber Hardening**:
  - Injected `TranslatorInterface` and extracted all hardcoded French validation error strings to `field.validation.*`.
  - Added structured error codes and translations in `EnsureProjectMemberSubscriber`.
- **I18N Parity**:
  - Added 40 new translation keys, bringing total keys to 2,516 with strict parity across English (`en`), French (`fr`), Spanish (`es`), and Arabic (`ar`).
- **Test Suite**:
  - Added `Cycle42HardeningTest` (9 tests, 60 assertions), 100% passing.

## [1.26.96] — 2026-11-05

### AppSettingsController, RedirectController & FormController Hardening & Strict I18N
- **AppSettingsController Hardening**:
  - Injected Symfony `TranslatorInterface` and extracted all hardcoded error/status strings across AI model loading and app settings persistence (`app_settings.admin.*`).
  - Added structured machine-readable error codes (`UNKNOWN_PROVIDER`, `OLLAMA_URL_NOT_CONFIGURED`, `INVALID_OLLAMA_URL`, `OLLAMA_SCHEME_FORBIDDEN`, `OLLAMA_METADATA_FORBIDDEN`, `USERINFO_FORBIDDEN`, `APP_NAME_TOO_LONG`, `APP_NAME_INVALID`, `APP_NAME_XSS_DETECTED`, `INVALID_PROVIDER_URL`, `INVALID_OIDC_ISSUER`, `INVALID_OIDC_NAME`, `DEMO_MODE_DISABLED`).
  - Strengthened anti-SSRF defenses on Ollama endpoint resolving: strictly rejected cloud metadata IP (`169.254.169.254`, `100.100.100.200`), AWS IMDS IPv6 (`[fd00:ec2::254]`, `fe80::`), cloud metadata hostnames (`metadata.google.internal`, `instance-data`), and evasive IP notations (dword integer `2852039166`, hex `0xa9fea9fe`, octal `0251.0372.0251.0372`).
  - Blocked embedded credentials / userinfo in provider URLs (`user:pass@host`).
  - Hardened `fetchUrl`: added response size capping (max 2MB) with curl buffer streaming and abort handler to prevent OOM denial-of-service.
  - Added strict input sanitization on app name (max 100 chars, no control characters, anti-XSS protection).
  - Hardened OIDC providers update: validated name (max 128 chars, anti-XSS) and issuer URL (must be valid HTTP/HTTPS and not target cloud metadata).
- **RedirectController Hardening**:
  - Added strict RFC 4122 UUID validation on `resolveProject` preventing invalid queries and returning clean localized 404 responses.
  - Added structured machine-readable error codes across all redirect endpoints.
  - Enhanced Open Redirect protection: blocked leading backslash evasion (`\evil.com`), URL-encoded protocol-relative evasion (`%2f%2fevil.com`, `%5c`), single-slash scheme evasion (`http:evil.com`), and path traversal sequences (`..`) in relative redirect targets (`redirect.admin.path_traversal_forbidden`).
- **FormController Hardening**:
  - Added strict RFC 4122 UUID validation on `resolveProject` with clean localized 404 responses.
  - Added request payload bounding (max 512KB) on form creation and updates to prevent resource exhaustion (HTTP 413 `form.admin.payload_too_large`).
  - Added structured machine-readable error codes across all form endpoints.
  - Hardened form settings validation: extended anti-SSRF protections to `webhookUrl` in addition to `redirectUrl`.
  - Sanitized submission spam filters against unexpected values.
- **Strict I18N Parity**:
  - Added 22 new translation keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
  - Strict 100% dictionary key parity maintained (2,476 keys across each of the 4 languages, 0 differences).
- **Quality Assurance**:
  - Created `Cycle41HardeningTest` with 13 tests and 72 assertions covering all new defenses.
  - All unit, integration, and admin test suites passing 100% (65/65 admin tests, 55/55 cycle test suites).

## [1.26.95] — 2026-11-05

### ContentAiStreamController, ShareController, PublicShareController & InsightsController Hardening & Strict I18N
- **ContentAiStreamController Hardening**:
  - Injected Symfony `TranslatorInterface` and removed all hardcoded error/status strings.
  - Added prompt injection defense (`sanitizePromptInput` & `containsDangerousPromptBreakout`) filtering null bytes, bidirectional Trojan Source overrides, neutralizing special LLM delimiter tokens (`<|im_start|>`, `[INST]`, `<<SYS>>`), and rejecting jailbreak attempts with HTTP 400 SSE events (`ai_stream.prompt_injection_detected`).
  - Added strict action validation against whitelist (`summarize`, `expand`, `rewrite`, `fix_grammar`, `translate`, `improve`).
  - Added input validation for tone (alphanumeric, max 50 chars), target locale regex, provider, and model.
  - Localized SSE error streams and rate limiting responses (`ai_stream.rate_limit_exceeded`, `ai_stream.project_not_found`, `ai_stream.input_empty`, `ai_stream.input_too_long`, `ai_stream.stream_error`).
- **ShareController & PublicShareController Hardening**:
  - Injected Symfony `TranslatorInterface` with fully localized JSON and HTML error responses.
  - Added strict RFC 4122 UUID validation on `projectUuid` and `entryUuid` routes and bodies.
  - Enforced strict payload size limit (max 16KB) on share generation requests (HTTP 413 `share.payload_too_large`).
  - Added token format bounds on public share resolver (`[A-Za-z0-9_]{10,128}`).
  - Hardened error responses to provide both machine-readable error codes (`invalid`, `expired`, `too_many_requests`) and localized messages (`public_share.error.*`).
- **InsightsController Hardening**:
  - Injected Symfony `TranslatorInterface` with full error localization.
  - Added strict project identifier format validation (positive integer ID or RFC 4122 UUID), throwing clean 404 for malformed requests.
  - Enforced cross-project access checks and localized range validation (`insights.invalid_range`).
- **VersioningController & AssetController Defenses**:
  - Enforced UUID and slug regex checks in `VersioningController::findEntry` and localized authentication errors.
  - Restored UUID format validation on public asset routes (`AssetController::resolvePublicProject`).
- **Strict I18N Parity**:
  - Added 21 new translation keys across English, French, Spanish, and Arabic dictionaries (`translations/messages.{en,fr,es,ar}.php`).
  - Strict 100% dictionary key parity maintained (2,454 keys across each of the 4 languages, 0 missing).
- **Quality Assurance**:
  - Added comprehensive test suite `tests/Controller/Cycle40HardeningTest.php` (12 tests, 140 assertions).
  - Cycles 37, 38, 39, and 40 passing completely green (48 tests, 868 assertions).

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
