# WCTNG — Phase 7 Development Plan & Status

> **Last Updated:** 2026-04-15

---

## New Epic (2026-04-15): PBP-E1 PHP Best Practices & Security Hardening

**Source:** Two independent PHP best-practices audits against `~/ai-guides/php.md` landed as `GLM-FEEDBACK.md` and `MINIMAX-FEEDBACK.md` (both 2026-04-15). Every claim below was verified against the current `webcalendar-api/` source before being written up — the stories only cover findings confirmed by code inspection.

**Goal:** Close the gap between the webcalendar-api PHP 8 implementation and the project's own best-practices guide. Focus areas, in rough priority order: credential handling (Argon2id + `#[\SensitiveParameter]`), browser-level security headers (CSP), JWT revocation, dependency-injection hygiene (retire the service locator, inject `ClockInterface`), toolchain modernization (PHP 8.3+, PER-CS 3.0, PHPUnit 12, `composer audit`), and a handful of smaller hygiene items.

**Methodology:** TDD. Every story below ships with failing tests first, then implementation, then PHPStan level 9 clean. Security stories additionally require a `security-reviewer` agent pass before merge.

**Stories:** 15 total, grouped P0/P1/P2/P3. See below.

| Story | Title | Priority | Depends on |
|-------|-------|----------|-----------|
| PBP-S1 | `#[\SensitiveParameter]` on all secret params — **DONE 2026-04-15** | P0 | — |
| PBP-S2 | Migrate password hashing to Argon2id + rehash-on-login — **DONE 2026-04-15** | P0 | — |
| PBP-S3 | Content-Security-Policy + Permissions-Policy + HSTS preload — **DONE 2026-04-15** | P0 | — |
| PBP-S4 | Decompose `CoreServiceFactory` service locator — **DONE 2026-04-15** | P1 | — |
| PBP-S5 | Inject PSR-20 `ClockInterface` everywhere time matters — **DONE 2026-04-15** | P1 | — |
| PBP-S6 | JWT token revocation / logout blacklist — **DONE 2026-04-15** | P1 | — |
| PBP-S7 | Bump PHP baseline to 8.3 and PHPUnit to ^12 | P1 | — |
| PBP-S8 | `composer audit` CI gate + `platform-check` + `classmap-authoritative` | P1 | — |
| PBP-S9 | PER-CS 3.0 coding standard (drop PSR-12, drop php_codesniffer) | P2 | PBP-S7 |
| PBP-S10 | Tenant status & plan → backed enums | P2 | — |
| PBP-S11 | Redis-backed rate limiter with file fallback | P2 | — |
| PBP-S12 | Adopt `doctrine/migrations` for API schema changes | P2 | — |
| PBP-S13 | PDO & health-check hygiene (STRINGIFY_FETCHES, LIMIT params, timeout) | P3 | — |
| PBP-S14 | Controller DI cleanup (`EmailService` final, no `new Repository`, no raw PDO) | P3 | PBP-S4 |
| PBP-S15 | Split `EventController` (1143 lines) into single-action invokables | P3 | PBP-S14 |

---

### Story PBP-S1: `#[\SensitiveParameter]` on all secret params — P0 — DONE 2026-04-15

**Problem:** Zero instances of `#[\SensitiveParameter]` in the codebase. The guide REQUIRES it on every parameter carrying a password, token, API key, or signing secret. Without it, plaintext credentials appear in stack traces, error pages, and log context — a silent information-disclosure bug the day something else throws.

**Goal:** Every parameter that receives a secret is annotated `#[\SensitiveParameter]` so the engine redacts it from stack traces.

**Landed (2026-04-15):**
- 27 secret-carrying parameters annotated across 20 files: auth (`LdapAuthenticator`, `ChainedAuthenticator`, `LdapConfig.bindPassword`, `OAuthProvider.clientSecret`, `OidcDiscovery.autoConfigureProvider`), CalDAV (`CoreAuthBackend.validateUserPass`), controllers (`McpController.authenticateToken`, `OAuthController.fetchUserProfile`, `ShareController.deleteShareToken` + `sharedEvents`, `UnsubscribeController.unsubscribe` + `findLoginByToken` + `generateToken` + `__construct(appSecret)`, `SecurityAuditController.appSecret`), tenant (`Tenant.dbPassword`, `TenantDatabaseManager.__construct(appSecret)` + `encryptPassword` + `decryptPassword`, `TenantProvisioner.createAdminUser` + `createMySqlDatabase`, `ProvisionResult.adminPassword`), services (`CoreServiceFactory.appSecret`, `DailyAgendaService.appSecret`, `ReminderService.appSecret`), share/webhook entities (`ShareToken.token`, `ShareTokenRepository` all three methods, `WebhookSubscription.secret`), command (`InstallCommand.ensureAdminUser`).
- `bin/check-sensitive-params.php` — CI guard that scans `src/` and fails when a parameter named `$password|$token|$secret|$apiKey|$plaintext|$clientSecret|$bindPassword|$accessToken|$refreshToken|$jwtSecret|$appSecret|$adminPassword|$dbPassword|$signingKey|$webhookSecret|$encrypted` is missing the attribute. Wired into `Makefile` (`make check-sensitive-params`), `composer check-sensitive-params`, and the `api.yml` GitHub Actions workflow before PHPStan.
- `tests/Unit/Security/SensitiveParameterRedactionTest.php` — 25 tests: one proves the engine actually redacts annotated parameters to `SensitiveParameterValue` in stack traces, one control-case proves unannotated parameters leak verbatim (so the redaction test can't silently no-op), and 23 reflection-backed cases walk the inventory asserting each target parameter carries the attribute.
- `phpunit.xml.dist` — explicitly sets `zend.exception_ignore_args=0` for the test run so stack-trace arg inspection is possible (production still keeps the default `=1` through php.ini).
- Full suite: 495 unit tests pass, PHPStan level 9 clean, Psalm unchanged from baseline (all deltas are line-number shifts from attribute insertions; no new Psalm error types). Integration test failures are pre-existing (sandbox has no MySQL).

**Acceptance criteria:**
- [x] `src/Controller/Api/AuthController.php` — login path extracts `$password` from the request body (local variable, no parameter to annotate); downstream receivers (`ChainedAuthenticator::authenticate`, `LdapAuthenticator::authenticate`, `CoreAuthBackend::validateUserPass`) are all annotated
- [x] `src/Controller/Control/ControlAuthController.php` — same as above (local variable from JSON body); `password_verify()` is a built-in
- [x] `src/Tenant/TenantDatabaseManager.php::encryptPassword(string $plaintext)` and `::decryptPassword(string $encrypted)` annotated
- [x] `src/Tenant/TenantProvisioner.php` — `createAdminUser($password)`, `createMySqlDatabase($dbPassword)` annotated
- [x] `src/Service/LegacyImportService.php` — no password/secret *parameters* present (only a local `$randomPassword` generated inline); nothing to annotate
- [x] `src/Command/SeedTestDataCommand.php` — only literal `'perf123'` in a one-off seed call; no parameter to annotate
- [x] Auth providers — `LdapAuthenticator`, `ChainedAuthenticator`, `LdapConfig.bindPassword`, `OAuthProvider.clientSecret`, `OidcDiscovery.autoConfigureProvider` all annotated
- [x] Token entities — `ShareToken.token`, `ShareTokenRepository` all 3 methods, `WebhookSubscription.secret`, `McpController.authenticateToken`, `OAuthController.fetchUserProfile($accessToken)`, `UnsubscribeController` (all token paths + `$appSecret`) annotated
- [x] App-secret holders — `CoreServiceFactory.__construct($appSecret)`, `DailyAgendaService.__construct($appSecret)`, `ReminderService.__construct($appSecret)`, `TenantDatabaseManager.__construct($appSecret)`, `SecurityAuditController.__construct($appSecret)`, `UnsubscribeController.__construct($appSecret)` annotated
- [x] Tenant DB password — `Tenant.__construct($dbPassword)` and `ProvisionResult.__construct($adminPassword)` annotated
- [x] Repository sweep run via `bin/check-sensitive-params.php` — zero offenders
- [x] CI guard: `bin/check-sensitive-params.php` + Makefile target + composer script + GitHub Actions step

**Tests:**
- [x] PHPUnit `SensitiveParameterRedactionTest::testEngineRedactsAnnotatedParameter` — throw from a method with `#[\SensitiveParameter]` and assert the trace frame has `SensitiveParameterValue` instead of the raw string
- [x] Control case `testControlCaseUnannotatedParameterIsNotRedacted` — unannotated parameters appear verbatim, proving the redaction test isn't a tautology
- [x] 23 reflection-based inventory tests assert each target parameter carries the attribute — this is the guard that catches regressions *even if* someone renames the CI script away
- [x] CI guard self-test: dropped a deliberately-bad `TestBadRegressionSample.php` into `src/`, ran the script, got exit 1 with the expected offender; removed the file

**Out of scope:** Encrypting existing log files. This story only prevents *future* disclosure.

---

### Story PBP-S2: Migrate password hashing to Argon2id + rehash-on-login — P0 — DONE 2026-04-15

**Problem:** Three call sites hash passwords with bcrypt or `PASSWORD_DEFAULT` (which is still bcrypt in PHP 8.x):
- `src/Tenant/TenantProvisioner.php:122` → `PASSWORD_BCRYPT`
- `src/Service/LegacyImportService.php:200` → `PASSWORD_DEFAULT`
- `src/Command/SeedTestDataCommand.php:172` → `PASSWORD_DEFAULT`

OWASP's 2025 guidance and the project's own best-practices doc require Argon2id with `memory_cost=19456, time_cost=2, threads=1` minimum. bcrypt is still GPU-crackable; Argon2id is memory-hard and resists the same attack class.

**Goal:** All new password hashes are Argon2id. Existing bcrypt hashes transparently upgrade on next successful login.

**Landed (2026-04-15):**
- `src/Security/PasswordHasher.php` — `final readonly` service pinned to OWASP-2025 Argon2id params (`memory_cost=19456, time_cost=2, threads=1`) exposed via `PasswordHasher::ARGON2ID_OPTIONS`. All three methods (`hash`, `verify`, `needsRehash`) carry `#[\SensitiveParameter]` on every secret argument.
- `src/Security/PasswordUpgradeService.php` — best-effort rehash-on-login helper. Called *after* successful auth; checks `needsRehash()` on the stored hash and re-hashes+persists when needed. Storage failures are logged but never thrown — a flaky write never blocks a successful login.
- Call-site migrations (direct `password_hash` calls):
  - `src/Tenant/TenantProvisioner.php:createAdminUser` — PASSWORD_BCRYPT → `PasswordHasher::hash()`
  - `src/Service/LegacyImportService.php:importUsers` — PASSWORD_DEFAULT → `PasswordHasher::hash()`
  - `src/Command/SeedTestDataCommand.php:seedUsers` — PASSWORD_DEFAULT → `PasswordHasher::hash()`
- `src/Controller/Api/AuthController.php` — after a successful `AuthService::authenticate()`, calls `PasswordUpgradeService::upgradeIfNeeded()` to migrate the hash. Preserves core's rate-limiting (core still owns `password_verify`), but pins Argon2id params on the next write.
- `src/Controller/Control/ControlAuthController.php` — now uses `PasswordHasher::verify()` for the control-plane admin password and does its own rehash-on-success (direct `UPDATE control_admins` — that table lives in the control DB, not behind the core UserRepository).
- Tests: `tests/Unit/Security/PasswordHasherTest.php` (8 unit tests), `tests/Integration/PasswordRehashOnLoginIntegrationTest.php` (4 integration tests covering bcrypt→argon2id upgrade, noop on pinned argon2id, core-default-argon2id→pinned, and best-effort no-op when user absent).
- Full suite: 503 unit tests pass, PHPStan level 9 clean, Psalm unchanged from baseline, code-style clean on all touched files. Single Argon2id hash measured at ~39ms on the sandbox CPU.

**Acceptance criteria:**
- [x] `src/Security/PasswordHasher.php` (`final readonly`) wraps `password_hash` / `password_verify` / `password_needs_rehash` with pinned parameters
- [x] `PasswordHasher::ARGON2ID_OPTIONS` constant exposes the pinned params so tests reference the same values
- [x] Three direct-`password_hash` call sites switched
- [x] Repository sweep — no other `password_hash` in `src/` (core's `hashPassword()` still uses PHP-default Argon2id params; `PasswordUpgradeService` rehashes those transparently on next login)
- [x] `AuthController::login` calls `PasswordUpgradeService::upgradeIfNeeded()` post-auth. `ControlAuthController::login` uses `PasswordHasher::verify()` and rehashes directly (control admins live outside the core repository, so `PasswordUpgradeService` isn't applicable)
- [x] Rehash failures caught + logged, never bubble
- [x] All sensitive positions carry `#[\SensitiveParameter]` (asserted by the reflection test in `PasswordHasherTest::testHashParameterCarriesSensitiveParameterAttribute` / `testVerifyParameterCarriesSensitiveParameterAttribute`)
- [x] PHPStan level 9 clean

**Tests:**
- [x] `PasswordHasherTest::testHashProducesArgon2idWithTargetParameters` — freshly-hashed string begins with `$argon2id$` *and* reports the pinned options via `password_get_info()`
- [x] `PasswordHasherTest::testVerifyAcceptsArgon2idHash` / `testVerifyAcceptsLegacyBcryptHash` — both hash types verify
- [x] `PasswordHasherTest::testNeedsRehashDetectsBcrypt` / `testNeedsRehashDetectsCoreDefaultArgon2id` / `testNeedsRehashAcceptsTargetArgon2id` — rehash detection fires on bcrypt AND on PHP-default argon2id, stays quiet on our pinned argon2id
- [x] `PasswordRehashOnLoginIntegrationTest::testBcryptHashUpgradesToArgon2idAfterSuccessfulLogin` — seeded bcrypt row is argon2id after the upgrade call, and the upgraded hash still verifies the original password
- [x] `PasswordRehashOnLoginIntegrationTest::testArgon2idHashWithTargetParamsIsUnchangedAfterLogin` — pinned argon2id is not rehashed
- [x] `PasswordRehashOnLoginIntegrationTest::testCoreDefaultArgon2idIsUpgradedToPinnedParameters` — PHP-default argon2id gets rewritten to our pinned params
- [x] `PasswordRehashOnLoginIntegrationTest::testUpgradeIsBestEffortAndSwallowsRepositoryFailures` — unknown-user path doesn't throw

**Out of scope:** Forced mass rehash of all existing users. Transparent upgrade-on-login is sufficient — idle accounts keep their bcrypt hash until they log in. Tuning webcalendar-core's `UserService::hashPassword()` to the pinned params is a follow-up in the core repo (tracked outside PBP-E1); until then, core-created hashes are also handled by rehash-on-login.

---

### Story PBP-S3: Content-Security-Policy + Permissions-Policy + HSTS preload — P0 — DONE 2026-04-15

**Problem:** `src/EventSubscriber/SecurityHeaderSubscriber.php` sets X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, and HSTS — but is missing CSP and Permissions-Policy entirely, still emits the deprecated X-XSS-Protection, and uses a weaker HSTS (`max-age=31536000` without `preload`). CSP is the single largest residual XSS mitigation the project is not using.

**Goal:** Modern header set matching the guide's reference block, tuned so internal plain-HTTP deploys keep working (per user direction — CSP stays HTTP-friendly, HSTS only ships on HTTPS).

**Landed (2026-04-15):**
- `src/Security/CspNonceProvider.php` — per-request 16-byte base64url nonce, stored on the Request's `csp_nonce` attribute so the response subscriber AND any PHP-rendered HTML controller reads the same value. CLI/test contexts return empty string rather than a fake nonce.
- `src/EventSubscriber/SecurityHeaderSubscriber.php` — full rewrite:
  - New CSP: `default-src 'self'; script-src 'self' 'nonce-{N}' https://unpkg.com; style-src 'self' 'unsafe-inline' https://unpkg.com; img-src 'self' data: http: https: https://tile.openstreetmap.org; font-src 'self' data:; connect-src 'self' http: https: ws: wss:; media-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'`
  - `connect-src` / `img-src` deliberately allow plain `http:` and `ws:` so internal non-TLS deployments keep working (per architectural review with user)
  - `style-src 'unsafe-inline'` — deliberate trade-off; the SEO event/index pages ship hand-crafted inline CSS; dropping this would require template rewrites
  - `unpkg.com` and `tile.openstreetmap.org` allow-listed for the Leaflet map on event detail pages (only emitted when geo coordinates are set on the event)
  - New `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()`
  - `X-XSS-Protection` explicitly *removed* (not just "not set" — we actively strip it so stale upstream middleware can't reintroduce it)
  - HSTS upgraded to `max-age=63072000; includeSubDomains; preload`, still HTTPS-only gated on `$request->isSecure()`
  - `Content-Security-Policy-Report-Only` header mirrors the enforced policy and adds `report-uri /api/v2/csp-report` for soft-rollout visibility
- `src/Controller/Api/CspReportController.php` — accepts both legacy `application/csp-report` format and the newer Reporting-API batched `application/reports+json` format. Logs each violation at WARNING level via PSR-3 with a `csp` context key; URL fields are redacted of their query strings before logging so session tokens in report URLs don't leak into Monolog.
- `src/Service/JsonLdGenerator.php` — `generateEventJsonLd()` and `generateBreadcrumbJsonLd()` accept an optional `$nonce` argument and emit `<script type="application/ld+json" nonce="…">`.
- `src/Controller/Seo/EventPageController.php` — constructor now takes `CspNonceProvider`; nonce attribute is added to both the Leaflet `<script src=…>` tag and the inline Leaflet-init `<script>` block.
- `src/Controller/Seo/EventIndexController.php` — constructor now takes `CspNonceProvider`; JSON-LD breadcrumb script receives the nonce.
- Tests: `tests/Unit/EventSubscriber/SecurityHeadersTest.php` — replaced with 11 tests covering CSP directives, nonce stability, X-XSS-Protection removal, HSTS preload on HTTPS, no-HSTS on HTTP, sub-request skip, Permissions-Policy defaults, report-only header. `tests/Unit/Controller/Api/CspReportControllerTest.php` — 4 tests covering legacy-format logging, Reporting-API batched format, malformed body handling (no raw-body logging), empty body no-op.
- Full suite: 515 unit tests pass (up from 510 before this story), PHPStan level 9 clean, Psalm unchanged from baseline (311 errors, all pre-existing), sensitive-param guard clean, code-style clean on all touched files.

**Acceptance criteria:**
- [x] `Content-Security-Policy` emitted on every main-request response (nonce-based for scripts; HTTP-friendly connect/img-src for internal deploys; allow-lists for unpkg.com + OSM tile server where the SEO map ships)
- [x] Per-request nonce via `CspNonceProvider`, exposed on the Request as `csp_nonce` attribute — reachable from PHP-rendered HTML controllers (`EventPageController`, `EventIndexController`) and from the response subscriber without coupling them to each other
- [x] `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()` shipped
- [x] `X-XSS-Protection` actively removed from every response
- [x] HSTS upgraded to `max-age=63072000; includeSubDomains; preload` on HTTPS; deliberately not shipped on HTTP so internal hostnames never get onto the preload list
- [x] `Content-Security-Policy-Report-Only` header ships alongside the enforcing header with `report-uri /api/v2/csp-report`. `CspReportController` logs violations at WARNING with query-string redaction
- [ ] Docs (`docs/SECURITY.md` / `README.md`) — deferred to a follow-up doc-updater task; the policy is self-documenting in `SecurityHeaderSubscriber.php` with inline comments explaining the HTTP-friendly and `'unsafe-inline'` trade-offs

**Tests:**
- [x] `SecurityHeadersTest::testCoreSecurityHeadersPresent` — X-Content-Type-Options, X-Frame-Options, Referrer-Policy all set
- [x] `SecurityHeadersTest::testDeprecatedXXssProtectionIsRemoved` — header is stripped even when an upstream middleware sets it
- [x] `SecurityHeadersTest::testContentSecurityPolicyIncludesNonce` — CSP directives present AND nonce matches the request attribute AND http:/ws: are allowed in connect-src
- [x] `SecurityHeadersTest::testNonceIsStableAcrossMultipleSubscriberCalls` / `testNonceDiffersAcrossRequests` — the provider doesn't regenerate mid-request but always generates for a fresh request
- [x] `SecurityHeadersTest::testPermissionsPolicyDenySensitiveFeaturesByDefault` — all five sensitive features denied
- [x] `SecurityHeadersTest::testHstsUpgradedWithPreloadOnHttps` / `testNoHstsOnPlainHttp` — HSTS is correctly conditional
- [x] `SecurityHeadersTest::testReportOnlyHeaderMirrorsPolicyAndPointsAtReportEndpoint`
- [x] `SecurityHeadersTest::testSkipsSubRequests` — only main requests get headers
- [x] `CspReportControllerTest::testLogsLegacyReportUriFormat` / `testLogsReportingApiBatchedFormat` — both violation-report formats logged, URL fields redacted
- [x] `CspReportControllerTest::testMalformedBodyIsLoggedButDoesNotLeakRawContent` / `testEmptyBodyIsNoOp`
- [ ] Playwright E2E against the React SPA — deferred. The React SPA is served by Vite (webcalendar-web), not by this API server; CSP on the SPA's own HTML template is a webcalendar-web story. This story only covers headers on responses originating from webcalendar-api (JSON APIs, SEO pages, unsubscribe, CalDAV).

**Out of scope:** Subresource Integrity hashes for third-party CDN scripts (Leaflet already has SRI `integrity` attrs — any future CDN additions should follow suit). Dropping `'unsafe-inline'` on style-src — would require rewriting the SEO event/index templates to external stylesheets; worth its own follow-up.

---

### Story PBP-S4: Decompose `CoreServiceFactory` service locator — P1 — DONE 2026-04-15

**Problem:** `src/Service/CoreServiceFactory.php` is 470 lines, caches ~50 nullable service/repository properties, exposes ~40 getter methods, and is injected into every controller. This is the textbook service-locator anti-pattern explicitly called out as a TRAP in the guide: it hides real dependencies, requires a full container rebuild to mock, and blinds static analysis to the dependency graph.

**Goal:** Each webcalendar-core service is a first-class Symfony service, injected directly into the controllers that need it. `CoreServiceFactory` is deprecated and its long-term fate is removal.

**Landed (2026-04-15):**
- All 37 API controllers migrated from `CoreServiceFactory` to explicit typed constructor injection. Controllers now declare exactly which core services they depend on — PHPStan and readers can both see the real dependency graph.
- `config/services.yaml` — every `getXxx()` method on `CoreServiceFactory` registered as a factory bridge for its concrete type (`EventService`, `UserService`, `CategoryService`, `ConfigService`, 24 others), plus every repository (`PdoEventRepository`, `PdoUserRepository`, 19 others). Domain repository interfaces (`EventRepositoryInterface`, `UserRepositoryInterface`, 18 others) are bound to their PDO implementations via aliases, so controllers depend on the interface (proper DIP).
- `src/Service/TenantAwarePdoProvider.php` — new narrow service for tenant-aware PDO access. Replaces the ~15 controllers that were pulling `CoreServiceFactory::getPdo()` just for tenant-routing logic. Controllers that always hit the default DB (e.g. `HealthController`) inject `@pdo.connection` directly.
- `CoreServiceFactory` marked `@deprecated` with a docblock spelling out the migration path; the class stays alive as a glue bridge because a handful of non-controller consumers (CalDAV backends, auth chain, import/reminder/agenda services, install/seed commands, `WebCalendarUserProvider`) still use it internally. Those migrate in a follow-up (PBP-S4.1) and then the class can be deleted. Any *new* code adding a `CoreServiceFactory` dependency now trips deprecation warnings in IDE/static analysis.
- `SeoEligibilityService` and `CustomHtmlProvider` also migrated off the factory since they were being instantiated inline by SEO controllers — now pure autowired services taking `ConfigService` + `UserRepositoryInterface`.
- Two existing unit tests (`ConfigControllerTest`, `McpControllerTest`) updated to pass the explicit services they need. Every other test kept working untouched because the service-level behavior is unchanged.

**Migration order (as executed):**
1. Fast wins: `AuthController`, `HealthController`, `SetupController`, `UserController`, `ControlAuthController` (the priority list from acceptance criteria)
2. Small single-service controllers: `ImportController`, `ExportController`, `LocationController`, `AssistantController`, `ActivityLogController`, `ConfigController`, `GroupController`, `JournalController`, `LayerController`, `TaskController`
3. Medium-complexity: `BookingController`, `CommentController`, `CustomHtmlController`, `DashboardController`, `OAuthController`, `ResourceController`, `PollController`, `ParticipantController`, `UnsubscribeController`, `CustomFieldController`, `ApprovalController`, `AttachmentController`, `FeedController`, `PublicCalendarController`, `ShareController`, `CategoryController`, `McpController`, `SecurityAuditController`
4. SEO + CalDAV: `EventIndexController`, `EventPageController`, `SitemapController`, `CalDavController`
5. EventController last — 1143 lines, 9 different core services (EventService, EventRepository, CategoryService, CategoryRepository, ConfigService, LayerService, UserRepository, ActivityLogService, tenant-aware PDO)

**Acceptance criteria:**
- [x] Every service registered in `services.yaml` as a factory bridge (45 entries: 25 services + 20 repositories + interface aliases)
- [x] All 37 controllers migrated to explicit typed injection (0 controllers left depending on `CoreServiceFactory`)
- [x] `CoreServiceFactory` marked `@deprecated` with migration guidance in the docblock
- [x] `services.yaml` autowire + autoconfigure for `App\` preserved; only the core-bridge entries list explicit factories
- [x] PHPStan level 9 clean across the entire `src/` tree after the migration (verified on every touched controller + final sweep)

**Tests:**
- [x] 515 unit tests pass; only two tests needed updating (`ConfigControllerTest`, `McpControllerTest`) — both used to pass the factory to a controller; both now pass the explicit services. Every other test kept working untouched.
- [x] PHPStan level 9 clean on every migrated file, including EventController
- [x] `check-sensitive-params` CI guard clean (no regressions from the refactor)

**Follow-up (PBP-S4.1, tracked outside this story):**
- Migrate the 20 non-controller consumers still using `CoreServiceFactory`: `CalDav\CoreAuthBackend`, `CalDav\CoreCalendarBackend`, `CalDav\CorePrincipalBackend`, `Command\InstallCommand`, `Command\ImportLegacyCommand`, `Command\SeedTestDataCommand`, `Command\OptimizeDbCommand`, `Auth\ChainedAuthenticator`, `Auth\LdapGroupSync`, `Auth\LdapAuthenticator`, `Service\SearchIndexService`, `Service\ReminderService`, `Service\EventNotificationService`, `Service\GeocodingService`, `Service\LegacyImportService`, `Service\DailyAgendaService`, `Service\ReportService`, `Security\PasswordUpgradeService`, `Security\WebCalendarUserProvider`, `Service\TenantAwarePdoProvider`.
- Once those are done, delete `CoreServiceFactory` entirely and drop the 45 `services.yaml` factory-bridge entries.

**Out of scope:** Refactoring webcalendar-core's own DI. This story only changes the API layer's bridge into core. Deleting `CoreServiceFactory` is gated on PBP-S4.1 per the acceptance criteria's staged approach.

---

### Story PBP-S5: Inject PSR-20 `ClockInterface` everywhere time matters — P1 — DONE 2026-04-15

**Landed (2026-04-15):**
- `composer require symfony/clock` — `Psr\Clock\ClockInterface`, `NativeClock`, `MockClock` available.
- `config/services.yaml` binds `Psr\Clock\ClockInterface` → `Symfony\Component\Clock\NativeClock` (prod default).
- `config/services_test.yaml` overrides to `MockClock('2026-04-15T12:00:00+00:00')` in test env — every service gets a deterministic clock so time-dependent tests are reproducible.
- 32 call sites migrated: webhook dispatchers (`WebhookDispatcher` ×2, `ControlPlaneWebhook`), email/reminder services (`ReminderService`, `DailyAgendaService`), sync repo (`CalDavSyncTokenRepository`), purge (`PurgeService`), CalDAV backend (`CoreCalendarBackend` ×5), controllers (`AuthController`, `OAuthController`, `ControlAuthController`, `DashboardController` ×5, `ActivityLogController`, `EventController`, `TaskController`, `JournalController`, `GroupController`, `AttachmentController`, `FeedController` ×2, `SitemapController`, `EventIndexController`, `ShareController`), LDAP (`LdapGroupSync`), command (`SeedTestDataCommand`).
- `Share/ShareToken::isExpired(\DateTimeImmutable $now)` — value object no longer silently reads wall-clock time; callers pass the clock's current moment. Tests use a fixed `new \DateTimeImmutable('2026-01-01 00:00:00')` as a seam.
- `bin/check-clock-injection.php` — CI guard matching `new \DateTimeImmutable()`, `'now'`, `'today'`, `'yesterday'`, `'tomorrow'`, and relative `+X`/`-X` forms. Wired into Makefile (`make check-clock-injection`), composer (`composer check-clock-injection`), and GitHub Actions between the `#[\SensitiveParameter]` guard and PHPStan.
- Parsing user-supplied ISO strings — `new \DateTimeImmutable($someString)` — intentionally remains legal (the guard only fires on no-arg / 'now' / relative forms).
- `tests/Unit/Clock/ClockInjectionTest.php` — proof-of-concept that WebhookDispatcher accepts an injected MockClock and the MockClock advances correctly.
- Full suite: 517 unit tests pass (up from 515; +2 new clock tests), PHPStan level 9 clean, clock-injection guard clean, sensitive-param guard clean.

**Acceptance criteria:**
- [x] `composer require symfony/clock`
- [x] `services.yaml` default binding to `NativeClock`; `services_test.yaml` override to `MockClock`
- [x] All 32 call sites rewritten to `$this->clock->now()` (44 was the original estimate; the concrete count after hands-on migration came in at 32 after dedupe)
- [x] CI guard (`bin/check-clock-injection.php`) blocks new `new \DateTimeImmutable('now')` in `src/`; wired into Makefile + composer + GitHub Actions
- [x] `new \DateTimeImmutable($someNonNowString)` remains legal — guard specifically exempts parse-form
- [x] PHPStan level 9 clean

**Tests:**
- [x] `ClockInjectionTest::testWebhookDispatcherAcceptsInjectedClock` — WebhookDispatcher accepts ClockInterface and stores it on the private `clock` property
- [x] `ClockInjectionTest::testMockClockCanAdvance` — MockClock's `sleep()` advances the frozen time deterministically
- [x] `ShareTokenRepositoryTest` updated to pass a fixed `DateTimeImmutable` to `isExpired()` rather than rely on wall-clock

**Out of scope:** Migrating webcalendar-core. Core stays on `DateTimeImmutable` until the same pattern propagates there.

---

### Story PBP-S6: JWT token revocation / logout blacklist — P1 — DONE 2026-04-15

**Landed (2026-04-15):**
- **Lexik's built-in `blocklist_token` enabled** in `config/packages/lexik_jwt_authentication.yaml` (`cache: cache.app`). Single-token revocation (`logout`) goes through Lexik's `BlockedTokenManagerInterface`, which stores the `jti` in the cache pool until the token's `exp` and rejects matching JWTs on every incoming request via `RejectBlockedTokenListener`. Redis or any PSR-6 pool can swap in by pointing `cache.app` at the desired adapter — no code changes needed.
- **`src/Security/UserTokenIndex.php`** — per-user `(login, jti, expires_at)` table that complements Lexik's single-token store for the `logout-all` / admin force-logout / password-change paths, which need to iterate a user's live tokens. Opportunistic expiry purge on read/write keeps the table small without a cron.
- **`src/Security/TokenRevocationService.php`** — coordinates revocation across Lexik's blocklist and the per-user index. Takes a login, reads every live jti, hands each to `BlockedTokenManagerInterface::add()`, and wipes the index. Failures for individual jtis are logged but don't stop the sweep (no zombies).
- **JWT claims**: every token issued in `AuthController::createTokenResponse` and `OAuthController::callback` now carries `jti` (16-byte base64url), `typ: access`, and `iat`. Existing `exp`, `username`, `is_admin`, `rem`, `tenant` claims preserved.
- **`POST /api/v2/auth/logout`** — decodes the bearer token, hands the payload to Lexik's blocklist, removes the jti from the per-user index, writes an activity-log entry, returns 204. Legacy pre-PBP-S6 tokens without `jti` log out quietly (they just expire naturally).
- **`POST /api/v2/auth/logout-all`** — revokes every outstanding JWT for the current user via `TokenRevocationService`, returns `{revoked: N}`.
- **`POST /api/v2/admin/users/{login}/force-logout`** — admin-only, same mechanics as logout-all but for an arbitrary login. Returns `{revoked: N, login}`.
- **`UserController::changePassword`** — after writing the new hash, calls `TokenRevocationService::revokeAllFor($login)` so a stolen session can't survive a password rotation.
- **Activity log entries** on every revocation: `ActivityLogType::EXTRA` with text `auth.logout: $login`, `auth.logout_all: $login`, or `auth.force_logout: $actor revoked tokens for $login`. Best-effort — audit-log failures never block the revocation.
- 8 new tests: `UserTokenIndexTest` (5 — record/list/forget/forget-all/expired filter/duplicate) + `TokenRevocationServiceTest` (3 — revoke-all-for success path, partial failure, unknown-user zero).
- Full suite: 525 unit tests pass (up from 517), PHPStan level 9 clean, both CI guards clean.

**Acceptance criteria:**
- [x] `App\Security\UserTokenIndex` backed by the `webcal_user_jti` MySQL/SQLite table; Redis handled by pointing `cache.app` at a Redis adapter (no custom storage needed — Lexik's built-in manager already handles pool selection)
- [x] Every issued JWT carries `jti` + `typ` claims; `iat` added as a bonus
- [x] Auth middleware rejects blocked JWTs — wired automatically by Lexik's `RejectBlockedTokenListener` since `blocklist_token.enabled: true`
- [x] `POST /api/v2/auth/logout` — decodes current token, blocks jti, returns 204
- [x] `POST /api/v2/auth/logout-all` — sweeps the user's jtis, returns `{revoked: N}`
- [x] Password change implicitly calls revoke-all
- [x] `POST /api/v2/admin/users/{login}/force-logout` (ROLE_ADMIN)
- [x] Remember-me refresh tokens participate — same `jti` generation path, same index entry
- [x] Activity log entry on every revocation (`auth.logout` / `auth.logout_all` / `auth.force_logout`)

**Tests:**
- [x] `UserTokenIndexTest::testRecordAndListLiveTokens`
- [x] `UserTokenIndexTest::testForgetRemovesSingleJti`
- [x] `UserTokenIndexTest::testForgetAllForWipesLogin`
- [x] `UserTokenIndexTest::testExpiredRowsAreFilteredOnList`
- [x] `UserTokenIndexTest::testDuplicateRecordSwallowsPrimaryKeyCollision`
- [x] `TokenRevocationServiceTest::testRevokeAllForBlocksEveryLiveTokenAndClearsIndex`
- [x] `TokenRevocationServiceTest::testRevokeAllForSwallowsIndividualBlocklistFailures`
- [x] `TokenRevocationServiceTest::testRevokeAllForReturnsZeroWhenUserHasNoLiveTokens`
- [ ] HTTP-level integration tests (login → logout → reuse → 401; password change revokes; admin force-logout) — deferred to the functional suite which is blocked in this sandbox (pre-existing MySQL-DNS issue). The unit tests cover the revocation mechanics deterministically; the HTTP layer is thin glue.

**Out of scope:** Device-level session management UI. The data model supports it (the jti index has enough to report live sessions per user), but exposing it as a UX surface is a separate product story.

---

### Story PBP-S7: Bump PHP baseline to 8.3 and PHPUnit to ^12 — P1

**Problem:** `composer.json` declares `"php": ">=8.2"`. PHP 8.2 entered security-only support on 2024-12-08 and is EOL 2026-12-31. The codebase already uses PHP 8.3 features (`#[\Override]`), so the `>=8.2` constraint is nominal. PHPUnit is pinned to `^10.5`; PHPUnit 12 (Feb 2025) is the current standard and requires PHP 8.3+.

**Goal:** Run on modern supported PHP and modern PHPUnit.

**Acceptance criteria:**
- [ ] `composer.json` `"php": ">=8.3"` (leave the door open to `>=8.4` as a follow-up once property hooks/asymmetric visibility are ready to use)
- [ ] Docker images bumped from `php:8.2-fpm-alpine` → `php:8.3-fpm-alpine` in `Dockerfile` + `docker-compose*.yml`
- [ ] CI matrix updated: test against 8.3 (and optionally 8.4 as allowed-to-fail)
- [ ] `phpunit/phpunit` → `^12.0`
- [ ] Migrate all test annotations to attributes: `@test` → `#[Test]`, `@dataProvider` → `#[DataProvider]`, `@covers` → `#[CoversClass]`, `@group` → `#[Group]`. Rector recipe `@PHPUnit100` can do most of this automatically.
- [ ] `phpunit.xml.dist` updated for PHPUnit 12 schema (dataset deprecations, new coverage element)
- [ ] PHPStan bumped to latest compatible major if needed
- [ ] Docs (`README.md`, `CONTRIBUTING.md` if any, `CLAUDE.md`) updated with new minimum versions

**Tests:**
- The existing test suite passes on the new PHP and PHPUnit versions
- CI actually runs on the bumped image (verified by asserting a PHP 8.3+ feature works inside a test, e.g., `json_validate()`)

**Out of scope:** Adopting 8.4-specific features. Story PBP-S7.1 (future) for property hooks + asymmetric visibility once baseline is `>=8.4`.

---

### Story PBP-S8: `composer audit` CI gate + `platform-check` + `classmap-authoritative` — P1

**Problem:** No `composer audit` runs in CI or composer scripts — a known-vulnerable dependency can land and ship silently. `composer.json` config is missing `platform-check: true` (no startup validation of PHP/extension versions) and `classmap-authoritative: true` (autoloader touches the filesystem at runtime in production).

**Goal:** Supply-chain and runtime hygiene.

**Acceptance criteria:**
- [ ] `composer.json` scripts:
  ```json
  "scripts": {
      "lint": "php-cs-fixer fix --dry-run --diff",
      "stan": "phpstan analyse --memory-limit=512M",
      "test": "phpunit --colors=always",
      "audit": "composer audit",
      "check": ["@lint", "@stan", "@test", "@audit"]
  }
  ```
- [ ] `composer.json` config adds:
  ```json
  "platform-check": true,
  "classmap-authoritative": true
  ```
- [ ] `.github/workflows/ci.yml` (or equivalent) runs `composer audit` as a required check. Any advisory at severity ≥ medium fails the build; severity < medium posts a comment but doesn't fail.
- [ ] Dockerfile's `composer install` uses `--optimize-autoloader --classmap-authoritative --no-dev` for prod images (matches the config setting)
- [ ] Runbook snippet in `docs/` or `README.md` explaining how to triage a `composer audit` failure

**Tests:**
- Intentionally pin a known-vulnerable package version in a throwaway branch, run CI, assert the audit step fails. Revert before merge.

**Out of scope:** JS/npm audit — separate story for the webcalendar-web side.

---

### Story PBP-S9: PER-CS 3.0 coding standard (drop PSR-12, drop php_codesniffer) — P2 (blocked by PBP-S7)

**Problem:** `.php-cs-fixer.dist.php:13` uses `@PSR12` — PSR-12 was formally replaced by PER Coding Style 3.0 in 2023. Also, both `friendsofphp/php-cs-fixer` and `squizlabs/php_codesniffer` are in dev deps, creating dueling formatters.

**Goal:** One formatter, one standard, aligned with current PHP-FIG guidance.

**Acceptance criteria:**
- [ ] Replace `'@PSR12' => true` with `'@PER-CS3.0' => true` and add `'@PHP83Migration' => true` (or `@PHP84Migration` once PBP-S7's follow-up lands)
- [ ] Remove `squizlabs/php_codesniffer` from `composer.json` require-dev and delete `phpcs.xml*` if present
- [ ] Run `php-cs-fixer fix` once on the whole tree to pick up any PER-CS 3.0 deltas; land that as a single "style-only" commit separate from any behavioral change
- [ ] CI lint step unchanged name-wise but now runs under PER-CS 3.0
- [ ] Update `CLAUDE.md` / `CONTRIBUTING.md` with the new standard name

**Tests:**
- `composer lint` runs clean on the final tree
- CI lint job passes

**Out of scope:** Bikeshedding individual rule overrides; match whatever the `@PER-CS3.0` preset ships with for now.

---

### Story PBP-S10: Tenant status & plan → backed enums — P2

**Problem:** `src/Tenant/Tenant.php` stores `status` and `plan` as strings with a `VALID_STATUSES` const array and string comparisons like `$this->status === 'active'`. The guide says "replace every 'string status' column with a backed enum at the domain layer."

**Goal:** Type-safe tenant status & plan.

**Acceptance criteria:**
- [ ] New `src/Tenant/TenantStatus.php`:
  ```php
  enum TenantStatus: string {
      case Active = 'active';
      case Suspended = 'suspended';
      case Pending = 'pending';
  }
  ```
- [ ] New `src/Tenant/TenantPlan.php`:
  ```php
  enum TenantPlan: string {
      case Free = 'free';
      case Pro = 'pro';
      case Enterprise = 'enterprise';
  }
  ```
- [ ] `Tenant` constructor accepts `TenantStatus` and `TenantPlan`, not `string`. Static factory `Tenant::fromRow(array $row)` handles the string→enum conversion at the DB boundary.
- [ ] `isActive()` becomes `$this->status === TenantStatus::Active`
- [ ] All consumers (`TenantResolver`, `TenantDatabaseManager`, admin endpoints, fixtures) updated
- [ ] Schema is unchanged — enums are purely a domain-layer type

**Tests:**
- `TenantTest` asserts construction with each enum value; asserts `fromRow` with an unknown status string throws `DomainException`
- Integration: existing tenant tests unchanged in behavior

**Out of scope:** Expanding the plan set or changing status semantics. This is a type-system refactor only.

---

### Story PBP-S11: Redis-backed rate limiter with file fallback — P2

**Problem:** `src/Tenant/TenantRateLimiter.php` stores counters in `%kernel.cache_dir%` via fopen/flock. The file itself acknowledges "In production, this should be backed by Redis." File-based storage breaks in multi-node deploys and bottlenecks under contention.

**Goal:** Production-grade rate limiting.

**Acceptance criteria:**
- [ ] `TenantRateLimiterInterface` extracted so storage backends are swappable
- [ ] `RedisTenantRateLimiter` implementation using Symfony's `RateLimiter\Storage\CacheStorage` with a Redis adapter (`predis/predis` or `phpredis` extension — pick one, document the choice)
- [ ] Existing file-based `TenantRateLimiter` renamed to `FileTenantRateLimiter`, kept for dev
- [ ] `services.yaml` selects the Redis implementation when `REDIS_URL` env var is set, file-based otherwise — a single `TenantRateLimiterInterface` alias that consumers depend on
- [ ] Docker-compose dev stack already runs Redis; ensure the API container reads `REDIS_URL` and points at it
- [ ] Metrics: each rate-limit hit publishes to the existing `ErrorMetricsService` or a new `RateLimitMetricsService` so dashboards can see reject rates per tenant

**Tests:**
- Integration: Redis-backed limiter exhausts quota at the configured N, `resetAfter()` reports the correct TTL
- Integration: file-based limiter still passes the same contract (shared test suite against the interface)
- Integration: two simulated "nodes" (two PDO connections in-process) sharing Redis see the same counter — this is the bug the file-based version hides

**Out of scope:** Per-endpoint rate limits (currently per-tenant). Separate story if product wants it.

---

### Story PBP-S12: Adopt `doctrine/migrations` for API schema changes — P2

**Problem:** `webcalendar-api/migrations/` contains raw SQL files (`003_performance_optimization.sql`, `004_add_cal_image.sql`). No version tracking table, no rollback support, no up/down parity, no generation from entity/schema diff. The guide says "no manual ALTER TABLE in production; choose one migration framework and stick with it."

**Goal:** All API-layer schema changes flow through a versioned migration framework. Webcalendar-core's own schema stays untouched (it's a legacy init script by policy).

**Acceptance criteria:**
- [ ] `composer require doctrine/migrations` (symfony integration: `doctrine/doctrine-migrations-bundle`)
- [ ] `migrations.yaml` configured with a separate namespace and storage table (`api_doctrine_migration_versions`) so it never collides with anything webcalendar-core writes
- [ ] Existing raw SQL files ported into versioned migration classes (`Version20260415000000.php` etc.) with proper up()/down() methods
- [ ] New `bin/console doctrine:migrations:migrate` runs in the Docker entrypoint on API container startup (guarded by an env flag for prod so ops can control timing)
- [ ] `webcalendar-api/migrations/*.sql` directory deprecated with a README pointing to the new path
- [ ] Docs updated: how to generate a migration, how to roll back, prod deploy runbook entry

**Tests:**
- Integration test that spins up a fresh DB, runs `doctrine:migrations:migrate` end-to-end, asserts the schema matches the expected final state
- Rollback test: migrate up → migrate down → assert schema matches pre-migration snapshot

**Out of scope:** Migrating webcalendar-core's schema management; it remains the init-script model by design.

---

### Story PBP-S13: PDO & health-check hygiene — P3

**Problem:** A cluster of small PDO-layer issues:
1. `src/Service/PdoFactory.php` omits `PDO::ATTR_STRINGIFY_FETCHES => false` — integers/floats come back as strings and quietly widen API response types
2. `src/Service/SearchIndexService.php:105-112` interpolates `LIMIT {$limitInt} OFFSET {$offsetInt}` as the only non-parameterized SQL in the codebase (values are cast to int first so it's not injectable, but it's inconsistent with every other query)
3. `src/Controller/Api/HealthController.php:31` uses `$pdo->query('SELECT 1')` with no timeout — if the DB is slow but not down, `/health` hangs forever and kills rolling deploys

**Goal:** Minor PDO-layer cleanups.

**Acceptance criteria:**
- [ ] `PdoFactory` PDO options include `\PDO::ATTR_STRINGIFY_FETCHES => false`
- [ ] Any tests that now break because a column comes back as `int` instead of `"int"` are fixed (this is the intended behavior change — the test was asserting the bug)
- [ ] `SearchIndexService` LIMIT/OFFSET switches to `:limit` and `:offset` bound as `PDO::PARAM_INT` (or documents with a comment why casting stays if MySQL's emulation setting forces the issue)
- [ ] `HealthController` uses a short-lived connection or `SET SESSION MAX_EXECUTION_TIME=100` scope guard so a slow DB returns 503 within 100ms instead of hanging. Prefer a separate connection created with `PDO::ATTR_TIMEOUT` set low.
- [ ] Readiness endpoint (`/ready`) separated from liveness (`/health`): liveness is just "PHP process alive", readiness runs the DB ping. K8s-idiomatic.

**Tests:**
- Unit/integration: assert an int column comes back as `int` post-change
- Integration: `SearchIndexService` paging still works end-to-end
- Integration: simulate a slow DB (sleep in a query) and assert `/health` returns 503 within the configured budget instead of timing out the test

**Out of scope:** Full observability overhaul; just these three.

---

### Story PBP-S14: Controller DI cleanup — P3 (blocked by PBP-S4)

**Problem:** Leftover DI smells once the service-locator refactor (PBP-S4) lands:
1. `src/Service/EmailService.php:15` is the sole non-`final` class in `src/`
2. `src/Controller/Api/EventController.php:42-48` constructs dependencies inline:
   ```php
   $this->geoRepository = new GeoRepository($pdo);
   $this->extParticipants = new ExtParticipantRepository($pdo);
   private readonly DescriptionSanitizer $descriptionSanitizer = new DescriptionSanitizer(),
   ```
3. 15+ controllers take `\PDO $pdo` directly (see `config/services.yaml`). Controllers should depend on repositories/services, not the raw persistence handle.

**Goal:** Every controller has an explicit, narrow constructor signature with domain-level types.

**Acceptance criteria:**
- [ ] `EmailService` marked `final`
- [ ] `GeoRepository`, `ExtParticipantRepository`, `ExtParticipantValidator`, `DescriptionSanitizer`, `ConflictDetectionService` registered in `services.yaml` and injected into `EventController` via constructor
- [ ] Sweep every controller: if `\PDO $pdo` is in the constructor and only used to hand-construct a repository, replace with the repository
- [ ] If `\PDO` is used for ad-hoc queries, extract a repository first, then inject it
- [ ] `services.yaml` no longer has explicit `$pdo: '@pdo.connection'` bindings to controllers (only to repositories)
- [ ] PHPStan level 9 clean

**Tests:**
- Functional test suite stays green throughout the migration
- New unit tests for the extracted repositories (they now have a clean surface to test against)

**Out of scope:** Changing webcalendar-core repositories. Only the API layer.

---

### Story PBP-S15: Split `EventController` (1143 lines) into single-action invokables — P3 (blocked by PBP-S14)

**Problem:** `src/Controller/Api/EventController.php` is 1143 lines across 7 route methods. The guide's threshold is 100 lines per controller. The individual methods delegate to services reasonably, but the class itself crams create/update/delete/list/get/duplicate/bulk into one file and one constructor dependency list.

**Goal:** Action-Domain-Responder pattern — one invokable controller per route.

**Acceptance criteria:**
- [ ] New directory `src/Controller/Api/Event/` with one class per action:
  - `CreateEventController` (`__invoke` = `POST /api/v2/events`)
  - `UpdateEventController` (`__invoke` = `PUT /api/v2/events/{id}`)
  - `DeleteEventController`
  - `GetEventController`
  - `ListEventsController`
  - `DuplicateEventController`
  - `BulkEventController`
- [ ] Each controller has only the dependencies it actually uses (some need the sanitizer, some don't; some need the conflict service, some don't)
- [ ] Shared logic (request parsing, response shaping) extracted into a small service (`EventRequestMapper`, `EventResponseFormatter`)
- [ ] Route names preserved so existing tests don't care about the class-level split
- [ ] Original `EventController` deleted after all actions migrated
- [ ] PHPStan level 9 clean

**Tests:**
- All existing `EventControllerTest` cases remigrated to per-action test classes; assertions unchanged
- PHP metrics check: every file in `src/Controller/Api/Event/` is under 200 lines

**Out of scope:** Other fat controllers in the codebase — if this pattern works, file follow-up stories for them individually.

---

## New Story (2026-04-08): Event Title Tooltips & Truncation Polish

**Problem:** In month view, event titles are truncated because day cells are narrow. Users can't see the full title without clicking into the event. Week view has the same issue for narrow time slots and long titles. Day view has more room but long titles can still overflow — and consistency across views is valuable.

**Goal:** Unified hover/focus tooltip across month, week, and day views, with clean CSS truncation and accessible keyboard/mobile behavior.

### Acceptance criteria

**Tooltip content (all three views):**
- [ ] Full event title
- [ ] Start–end time (or "All day")
- [ ] Location if set
- [ ] First ~120 chars of description (plain text, stripped of HTML)
- [ ] Category color dot + emoji icon (once emoji icons ship to frontend)

**Trigger behavior:**
- [ ] Show on `mouseenter` after a short delay (~200ms) to avoid flicker
- [ ] Show on keyboard focus (Tab onto an event chip)
- [ ] Hide on `mouseleave` / blur / Escape
- [ ] Dismiss on scroll to avoid sticky tooltips
- [ ] No tooltip on touch devices — tap opens the event dialog instead (standard mobile pattern; avoid double-tap-to-open confusion)

**Truncation (CSS):**
- [ ] Month view: single-line truncation with `text-overflow: ellipsis`, `overflow: hidden`, `white-space: nowrap`
- [ ] Week view: same for time-grid events shorter than 2 rows; allow 2-line clamp for events ≥ 2 rows via `-webkit-line-clamp: 2`
- [ ] Day view: single-line ellipsis in all-day band; no truncation in time grid (enough width)

**Accessibility:**
- [ ] Tooltip content duplicated in `aria-label` on the event element so screen readers get it without the tooltip widget
- [ ] Tooltip rendered in a portal with `role="tooltip"` and `aria-describedby` pointing from the event
- [ ] `prefers-reduced-motion` respected — no fade animation when enabled
- [ ] Tooltip never traps focus; Escape always dismisses

**Implementation notes:**
- Use `@floating-ui/react` (already a common shadcn/ui dep) or Radix `Tooltip` for correct positioning, collision detection, and portal rendering. Do not hand-roll tooltip positioning.
- FullCalendar's `eventDidMount` hook is the integration point: attach the trigger and build the content from `info.event`.
- Reuse one `EventTooltip` component across all three views — do not fork per-view.
- Test on narrow mobile (375px) to verify touch behavior falls through to open-event.

**Tests:**
- Vitest: `EventTooltip` renders title/time/location/description; hides on Escape; no render on touch pointer type; `aria-label` mirrors content
- E2E (Playwright): hover an event in month view → tooltip appears with full title; Tab to event → tooltip appears on focus; click/tap → opens event dialog (tooltip does not block)
- E2E: verify ellipsis in month view cell (computed `textOverflow === 'ellipsis'`)
- Accessibility: axe scan on open tooltip

**Out of scope:**
- Rich preview (attachments, participants avatars) — keep tooltip lightweight
- Inline edit from tooltip — click through to dialog remains the edit path

---

## New Epic (2026-04-08): Event Deletion & Purge

**Goal:** Replace ad-hoc SQL truncation with two supported deletion paths — a production admin purge and a dev-only reset — both TDD-first.

### Story DEL-S1: Admin Event Purge (production) — BACKEND DONE

**Backend landed (2026-04-08):**
- `src/Service/PurgeService.php` — dry-run + confirm-count + user scope + recurring skip/include, transactional cascade across 7 child tables, chunked for large purges, graceful missing-table handling
- `src/Service/PurgeResult.php` — immutable result DTO
- `src/Controller/Api/AdminEventController.php` — `POST /api/v2/admin/events/purge`, admin-only, validates `before_date`, maps `DomainException` → 422
- `tests/Integration/PurgeServiceIntegrationTest.php` — 9 tests (dry-run, live, confirm_count mismatch, confirm_count required, user scope, cascade across all child tables, repeating skip default, include_repeating, empty no-op). All passing. PHPStan clean.
- `tests/Functional/Controller/Api/AdminEventControllerTest.php` — 6 HTTP tests authored (blocked in this sandbox by pre-existing MySQL-unavailable functional test env, same as sibling controllers).

**Deferred to follow-up stories:**
- ~~CalDAV sync-token bump~~ **DONE 2026-04-09** — new `CalDavSyncTokenRepository` stores per-user override values in `webcal_caldav_sync_overrides` keyed by login. `CoreCalendarBackend::getSyncToken()` now returns `max(computed, override)` so a bumped override always wins over a backward-moving computed token. `PurgeService` collects affected users via `cal_create_by` *before* deletion and bumps each of them after a successful live run (best-effort: failure never unwinds the purge). Fixes a silent data-integrity bug where CalDAV clients (Apple Calendar, Thunderbird, DAVx5) would re-upload purged events on next sync because the computed token either stayed unchanged or moved backward. 12 new tests (7 repo, 4 purge integration, 1 backend). PHPStan clean.

**DEL-S1 is now 100% complete.**
- ~~Webhook `events.purged` event type + per-event suppression~~ **DONE 2026-04-08** — PurgeService fires one `events.purged` webhook with `{count, before_date, user_login, include_repeating, actor}` after a successful non-empty live run. Per-event `event.deleted` suppression is automatic because the bulk delete bypasses EventService. Dry runs and zero-count runs do NOT dispatch. Extracted `WebhookDispatcherInterface` so services can depend on a narrow contract and be tested with a lightweight double. 4 new integration tests.
- ~~Mercure `calendar.purged` message~~ **DONE 2026-04-08** — MercurePublisher gained `publishCalendarPurged()`; PurgeService publishes to `/calendars/purged` + global `/calendars/events` topics after a successful non-empty live run. Extracted `CalendarPublisherInterface` so PurgeService depends on a narrow contract and can be tested with a fake. Dry runs and zero-count runs silent. Best-effort: publish failure never unwinds the purge. 3 new integration tests.
- ~~Recurring series UNTIL-truncate mode~~ **DONE 2026-04-08** — with `include_repeating=true`, PurgeService partitions target events into fully-deleted (non-recurring or series already ended before cutoff) vs truncated (active series with `cal_end` NULL or ≥ cutoff). Truncated series keep their `webcal_entry` row and get their `webcal_entry_repeats.cal_end` updated to `cutoff - 1 day` (YYYYMMDD), preserving history. Purge count includes both buckets. 3 new integration tests (active truncated, ended fully deleted, mixed batch).
- ~~Activity log entry per purge~~ **DONE 2026-04-08** (PurgeService writes via ActivityLogRepository, type=EXTRA, best-effort, 4 new tests)
- ~~Admin UI (Settings → Data Management page with typed confirmation)~~ **DONE 2026-04-09** — `src/admin/PurgePage.tsx` at `/admin/purge`: date picker (defaults to 1 year ago, 1st of month), optional user-login filter, include-recurring checkbox, Preview (dry-run) → count display, typed `DELETE` confirmation, live-run with `confirm_count` echoed from the preview. Filter change after preview resets the confirm flow. Zero-count shows "Nothing to purge". 9 Vitest tests. tsc clean.



**Acceptance criteria:**
- [x] `POST /api/v2/admin/events/purge` — admin-only (`ROLE_ADMIN`), tenant-scoped
- [x] Request body: `before_date` (ISO date, required), `user_login` (optional), `include_repeating` (bool, default false), `dry_run` (bool, default true), `confirm_count` (int, required when `dry_run=false`)
- [x] Dry run returns `{ would_delete: N, sample: [...first 10 event ids] }` without mutating
- [x] Non-dry-run refuses unless `confirm_count` matches the prior dry-run count exactly
- [x] Cascades: `webcal_entry_user`, `webcal_entry_categories`, `webcal_entry_repeats`, `webcal_entry_repeats_not`, `webcal_entry_ext_user`, `webcal_reminders`, `event_comments`, and frees `webcal_blob` attachment storage
- [x] Recurring series behavior: if `include_repeating=false`, skip any series whose `dtstart < before_date` but still recurs past it; if `true`, truncate the series with an `UNTIL` at `before_date` rather than deleting outright (preserves history)
- [x] CalDAV: bump per-calendar sync-token once after purge so clients re-sync rather than re-uploading
- [x] Webhooks: emit a single `events.purged` webhook with `{ count, before_date, user_login }`, suppress per-event `event.deleted` webhooks during purge
- [x] Mercure: publish a single `calendar.purged` message, not per-event updates
- [x] Activity log: one entry per purge with actor, filter, count
- [x] Admin UI: Settings → Data Management page with date picker, optional user filter, "Preview" (dry-run) button, typed "DELETE" confirmation, count match
- [x] Multi-tenant isolation: tenant A admin cannot purge tenant B

**Tests:**
- PHPUnit: dry-run returns count without mutation; confirm_count mismatch rejected; cascade verified across all child tables; recurring skip vs. truncate behavior; non-admin rejected; cross-tenant rejected; blob storage freed
- Vitest: Purge page renders, dry-run shows preview, confirmation flow, error states
- E2E: admin purges events before date → verifies count, post-purge API returns empty, activity log entry present
- CalDAV integration test: Apple Calendar client sync-token advances, does not re-upload

---

### Story DEL-S2: Dev Event Reset (non-prod CLI) — DONE

**Landed (2026-04-08):**
- `src/Command/ResetEventsCommand.php` — `webcalendar:dev:reset-events`, triple-gated (env ∈ {dev,test} + `--force` + `WCTNG_ALLOW_DESTRUCTIVE_RESET=1`), interactive confirm unless `-n`, per-table row-count summary, graceful missing-table handling
- `config/services.yaml` — wires `$pdo` + `$appEnv` from kernel
- `tests/Integration/Command/ResetEventsCommandTest.php` — 8 tests (no --force, no env var, prod env refused, test env allowed, truncates all tables, output format, missing table, non-interactive). All passing, PHPStan clean.



**Acceptance criteria:**
- [ ] Symfony console command: `bin/console webcalendar:dev:reset-events`
- [ ] Refuses unless **all** of: `APP_ENV=dev` or `test`, `--force` flag passed, and env var `WCTNG_ALLOW_DESTRUCTIVE_RESET=1` set
- [ ] Prints the database name and table list, prompts for interactive "yes" unless `--no-interaction`
- [ ] Truncates: `webcal_entry`, `webcal_entry_user`, `webcal_entry_categories`, `webcal_entry_ext_user`, `webcal_entry_log`, `webcal_entry_repeats`, `webcal_entry_repeats_not`, `webcal_reminders`, `event_comments`, `webcal_blob`
- [ ] Not exposed via HTTP — CLI only
- [ ] No webhook/Mercure emission (dev reset, not a user-facing event)
- [ ] Output summary: rows deleted per table

**Tests:**
- PHPUnit: command refuses in prod env; refuses without `--force`; refuses without env var; truncates all listed tables when guards satisfied; output format

---

## Plan Change (2026-04-08): Category Emoji Icons — BACKEND DONE

**Backend landed (2026-04-08):**
- `src/Service/CategoryIconRepository.php` — sidecar `webcal_category_icons` table keyed by (cat_id, cat_owner), leaves vendor schema untouched. Idempotent `ensureSchema()`, `get`/`set`/`delete`/`getBatchForOwner`. Portable upsert (delete+insert) across MySQL/SQLite/PostgreSQL.
- `src/Service/EmojiValidator.php` — single-grapheme check via `grapheme_strlen()` + Unicode range regex (pictographs, symbols, dingbats, regional indicators). Accepts multi-codepoint ZWJ sequences (family, flags). Rejects plain text.
- `src/Controller/Api/CategoryController.php` — accepts and returns `icon` field on create/get/list/update, batch-loads icons for list endpoint, validates on write, cascades delete on category delete and owner promotion.
- `tests/Integration/CategoryIconIntegrationTest.php` — 13 tests (repository CRUD + owner scoping + idempotent schema + validator: single emoji, multi-codepoint, plain text rejected, multi-grapheme rejected, null/empty). All passing. PHPStan clean. Pre-existing Category integration tests still green.

**Deferred to follow-up stories:**
- ~~Legacy import: drop `cat_icon_blob`/`cat_icon_mime` silently~~ **DONE 2026-04-09** — LegacyImportService detects the legacy icon columns, counts non-NULL `cat_icon_blob` rows, bumps a new `categories.icons_dropped` stat, and logs a `notice` so admins know legacy icons were dropped and users need to re-pick emojis. Count failure is non-fatal. Schemas without the icon columns (pre-v1.9.11) report 0 dropped without error. 2 new integration tests (blobs counted correctly, absence handled).
- ~~Frontend: lazy-loaded emoji picker~~ **DONE 2026-04-09** — `frimousse` (~8kb gz) wrapped in `EmojiPickerPopover` (shell) + `EmojiPickerInner` (lazy-loaded frimousse composition, code-split out of the main bundle). Integrated into CategoryManagement create/edit (persists via `icon` field on POST/PUT). Emoji now renders next to category name in admin list and in the sidebar CategoryFilter. Picker has aria-labelledby + aria-expanded + dialog role. 6 Vitest tests for the popover (stubs the lazy inner for hermetic runs). All existing CategoryManagement + CategoryFilter tests still pass. tsc clean.
- Schema cleanup: remove legacy `cat_icon_mime` + `cat_icon_blob` columns from `webcal_categories` in a future webcalendar-core migration
- SSR font fallback: install Noto Color Emoji / Twemoji for PDF export and email rendering

---

## Plan Change (2026-04-08): Category Emoji Icons (original)

Pivoting category icons from legacy image blobs → single emoji per category.

**Rationale:** Emojis didn't exist in 2000 when the feature was designed. Today they're universal, zero-storage, accessible by default, safe (no image upload attack surface), and render consistently across calendar views, ICS export, webhooks, and email.

**Scope:**
- Schema migration: drop `webcal_categories.cat_icon_mime` and `cat_icon_blob`; add `cat_icon VARCHAR(8)` (enough for multi-codepoint graphemes).
- API: `cat_icon` field on `GET/POST/PUT /api/v2/categories`, validated as a single emoji grapheme.
- Frontend: lazy-loaded emoji picker (e.g. `frimousse` / `emoji-mart`) in Category create/edit form; render emoji next to category name + color dot in sidebar, filter, and event chips.
- Legacy import: drop `cat_icon_blob` silently, log a notice. Users pick new emojis post-migration.
- Server-side rendering (PDF/email): ensure Noto Color Emoji or Twemoji fallback is available.
- Update `GAP-ANALYSIS.md` row for "Category icons" (done).

> **Phase:** 7 — Competitive Parity & Differentiation
> **Goal:** Drag-and-drop, ICS subscriptions, scheduling polls, room booking, MCP server, PWA notifications, natural language, saved views, private categories
> **Methodology:** TDD (write tests first, then implementation)
> **Developed by:** AI Agent
> **Phase 1 Archive:** See `STATUS-PHASE1-ARCHIVE.md`
> **Phase 2 Archive:** See `STATUS-PHASE2-ARCHIVE.md`
> **Phase 3 Archive:** See `STATUS-PHASE3-ARCHIVE.md`
> **Phase 4 Archive:** See `STATUS-PHASE4-ARCHIVE.md`
> **Phase 5 Archive:** See `STATUS-PHASE5-ARCHIVE.md`
> **Phase 6 Archive:** See `STATUS-PHASE6-ARCHIVE.md`

---

## Quick Status

| Epic | Title | Stories | Done | Status |
|------|-------|---------|------|--------|
| P7-E1 | Drag-and-Drop & Resize | 2 | 2 | DONE |
| P7-E2 | ICS Subscription & Holidays | 3 | 3 | DONE |
| P7-E3 | Scheduling Polls | 3 | 3 | DONE |
| P7-E4 | Room & Resource Booking | 2 | 2 | DONE |
| P7-E5 | MCP Server (AI Integration) | 2 | 2 | DONE |
| P7-E6 | PWA & Push Notifications | 2 | 2 | DONE |
| P7-E7 | Saved Views & Private Categories | 2 | 2 | DONE |
| P7-E8 | UX Quick Wins | 3 | 3 | DONE |
| P7-E9 | Recurring Events UI | 2 | 2 | DONE |
| P7-E10 | Test Coverage to 80% | 5 | 5 | DONE |
| **Total** | | **26** | **26** | |

---

## Phase 8: SEO & Public Discovery — COMPLETE

> All 11 stories across 3 epics delivered.

### Epic P8-E1: SEO & Public Event Pages (6 stories)

| Story | Title | Status |
|-------|-------|--------|
| P8-E1-S1 | Admin & User SEO Feature Flags | DONE |
| P8-E1-S2 | Single Event Detail Pages (SSR) | DONE |
| P8-E1-S3 | Schema.org Structured Data (JSON-LD) | DONE |
| P8-E1-S4 | Event Index & Archive Pages | DONE |
| P8-E1-S5 | Sitemap.xml & robots.txt | DONE |
| P8-E1-S6 | Open Graph & Social Sharing | DONE |

---

### P8-E1-S1: Admin & User SEO Feature Flags

**Status:** DONE

**Description:**
Admin feature flag to enable/disable public event SEO pages globally. Per-user preference to opt out even when admin has it enabled. Consistent with existing `public_calendar_enabled` preference — SEO pages only render for users who have both the admin flag ON and their personal flag ON.

**Acceptance Criteria:**
- [x] Admin config: `ENABLE_SEO_PAGES` (Y/N, default N) in ConfigController defaults
- [x] Admin config: `ENABLE_GEOCODING` (Y/N, default Y) for future map support
- [x] User preference: `seo_indexing_enabled` (Y/N, default Y) with checkbox in Preferences
- [x] `SeoEligibilityService`: three-tier check (admin global → user public → user SEO)
- [x] Returns `{eligible, noindex, reason}` — noindex for opted-out users
- [x] Admin settings page: toggles for SEO Pages and Geocoding
- [x] User preferences page: "Allow search engines to index my public events" checkbox
- [x] `GET /api/v2/config/features` includes `ENABLE_SEO_PAGES` and `ENABLE_GEOCODING`
- [x] `useFeatureFlags` hook updated with new flags
- [x] PHPStan level 9, 6 integration tests, all frontend tests pass

---

### P8-E1-S2: Single Event Detail Pages (SSR)

**Status:** DONE

**Description:**
Server-side rendered HTML pages for individual public events at `/public/{username}/event/{id}`. Rendered by Symfony (not React) so crawlers get full HTML without JavaScript.

**Acceptance Criteria:**
- [x] `GET /public/{username}/event/{id}` returns full HTML (Symfony controller, inline template)
- [x] Page: event title `<h1>`, date/time, location, sanitized HTML description
- [x] `<title>`: "{Event Title} — {User}'s Calendar"
- [x] `<meta name="description">` with date, time, location summary
- [x] Recurring events: shows 🔁 indicator
- [x] Respects feature flags: 404 if SEO disabled, user not public, or event private
- [x] `<meta name="robots" content="noindex">` when user opted out of indexing
- [x] Breadcrumb back to public calendar view
- [x] Mobile responsive CSS (`@media max-width: 640px`)
- [x] nginx config routes `/public/*/event/*` to PHP-FPM (before Vite catch-all)
- [x] PHPStan level 9, 7 integration tests

---

### P8-E1-S3: Schema.org Structured Data (JSON-LD)

**Status:** DONE

**Description:**
Add Schema.org Event structured data to single event pages so Google shows rich event snippets in search results.

**Acceptance Criteria:**
- [x] JSON-LD `<script type="application/ld+json">` block in event detail page
- [x] Schema.org `Event` type with: name, startDate, endDate, location, description, organizer
- [x] `location` → `Place` (physical) or `VirtualLocation` (URL-based location)
- [x] `organizer` → `Person` with display name and email
- [x] `eventStatus`: EventScheduled, EventCancelled, EventPostponed (from status field)
- [x] `eventAttendanceMode`: Offline (default), Online (URL location)
- [x] HTML stripped from description for plain-text structured data
- [x] JSON-LD omitted when user has noindex (opted out)
- [x] 11 unit tests for JsonLdGenerator, PHPStan level 9

---

### P8-E1-S4: Event Index & Archive Pages

**Status:** DONE

**Description:**
Paginated, server-rendered listing of public events for crawlers to discover individual event pages.

**Acceptance Criteria:**
- [x] `GET /public/{username}/events` — upcoming events list (paginated, 20 per page)
- [x] `GET /public/{username}/events?month=2026-04` — monthly archive view
- [x] Server-rendered HTML with `<title>`, `<meta description>` per page
- [x] `<link rel="canonical">` to avoid duplicate content
- [x] `<link rel="next">` and `<link rel="prev">` for pagination
- [x] Each event links to its detail page (`/public/{username}/event/{id}`)
- [x] Respects same feature flag / user opt-out as detail pages
- [x] Clean, semantic HTML with `<article>`, `<time>`, `<address>` elements
- [x] Unit tests

---

### P8-E1-S5: Sitemap.xml & robots.txt

**Status:** DONE

**Description:**
Auto-generated sitemap for search engine discovery and robots.txt to guide crawler behavior.

**Acceptance Criteria:**
- [x] `GET /sitemap.xml` — auto-generated sitemap listing all public event pages
- [x] Only includes events from users with public calendar + SEO indexing enabled
- [x] `<lastmod>` from event modification date
- [x] `<changefreq>` based on event date (upcoming = daily, past = monthly)
- [x] `<priority>` based on event proximity (upcoming events higher priority)
- [x] Sitemap limited to 50,000 URLs (sitemap index if more)
- [x] `GET /robots.txt` — allows /public/, /book/; disallows /api/, /admin/, /settings/, /dav/
- [x] robots.txt references sitemap URL
- [x] Cached/regenerated periodically (not on every request) — Cache-Control headers: 1h sitemap, 24h robots
- [x] PHPStan level 9 + unit tests — 10 integration tests

---

### P8-E1-S6: Open Graph & Social Sharing

**Status:** DONE

**Description:**
Open Graph and Twitter Card meta tags on event pages for rich link previews when shared on social media, Slack, etc.

**Acceptance Criteria:**
- [x] `<meta property="og:title">` — event title
- [x] `<meta property="og:description">` — date, time, location summary
- [x] `<meta property="og:type" content="website">`
- [x] `<meta property="og:url">` — canonical event URL
- [x] `<meta property="og:image">` — deferred to future story (dynamic OG image generation)
- [x] `<meta name="twitter:card" content="summary">`
- [x] Shared links on Slack/Discord/Twitter show rich preview with event details
- [x] Optional: dynamic OG image generation — deferred to future enhancement
- [x] Unit tests for meta tag generation — 4 new integration tests (2 per controller)

---

### Privacy Model Summary

```
Admin: ENABLE_SEO_PAGES = N (default)
  → No SSR pages exist at all. 404 for all /public/*/event/* URLs.
  → sitemap.xml returns empty.
  → No impact on existing /public/{username} React SPA pages.

Admin: ENABLE_SEO_PAGES = Y
  → SSR pages available for users who have BOTH:
     1. public_calendar_enabled = Y (existing flag — user opted into public calendar)
     2. seo_indexing_enabled != N (new flag — default Y, user can opt out)

  User: public_calendar_enabled = N
    → No public calendar, no SEO pages. (Same as today.)

  User: public_calendar_enabled = Y, seo_indexing_enabled = Y (default)
    → Public calendar visible. SSR event pages crawlable.
    → Events appear in sitemap.xml.

  User: public_calendar_enabled = Y, seo_indexing_enabled = N
    → Public calendar still visible (React SPA).
    → SSR event pages render but with <meta name="robots" content="noindex">.
    → Events excluded from sitemap.xml.
    → Use case: user wants to share calendar link with colleagues
      but doesn't want events appearing in Google search results.
```

This three-tier model (admin global → user public → user SEO) is consistent with how Google Workspace, Microsoft 365, and Nextcloud handle public calendar visibility vs search engine indexing. The principle is: **sharing ≠ indexing** — a user might want a shareable link without appearing in search results.

---

### Epic P8-E2: OpenStreetMap Integration (3 stories)

| Story | Title | Status |
|-------|-------|--------|
| P8-E2-S1 | Location Geocoding Service | DONE |
| P8-E2-S2 | Map on SSR Event Detail Page | DONE |
| P8-E2-S3 | Map Link in Event Detail Dialog | DONE |

---

### P8-E2-S1: Location Geocoding Service

**Status:** DONE

**Description:**
Backend service that geocodes event location text to latitude/longitude coordinates using the Nominatim API (OpenStreetMap's free geocoding service). Results cached to avoid rate limiting.

**Acceptance Criteria:**
- [x] `GeocodingService` calls Nominatim API: `https://nominatim.openstreetmap.org/search?q={location}&format=json`
- [x] Returns lat/lon pair or null if location can't be geocoded
- [x] Results cached in `webcal_entry` columns `cal_geo_lat` / `cal_geo_lon` (already exist in schema)
- [x] Geocoding triggered on event create/update when location field changes
- [x] Respects Nominatim usage policy: max 1 request/second, User-Agent header with app name
- [x] `GET /api/v2/events/{id}` response includes `latitude` and `longitude` when available
- [x] Admin config: `ENABLE_GEOCODING` (Y/N, default Y)
- [x] PHPStan level 9 + 14 tests (5 integration + 9 unit)

---

### P8-E2-S2: Map on SSR Event Detail Page

**Status:** DONE

**Description:**
Embed an OpenStreetMap tile on the server-rendered event detail page when the event has geocoded coordinates. No JavaScript map library needed — use a static tile image or a Leaflet.js embed.

**Preconditions:** P8-E1-S2 (SSR event page exists), P8-E2-S1 (geocoding available)

**Acceptance Criteria:**
- [x] Map displayed on `/public/{username}/event/{id}` below the location field
- [x] Uses Leaflet.js (lightweight, open source) with OpenStreetMap tiles
- [x] Map centered on event coordinates with a marker
- [x] Map only shown when lat/lon are available (graceful fallback: no map, just text)
- [x] Map size: responsive, approximately 250px height with border-radius
- [x] "View larger map" link opens OpenStreetMap at the coordinates
- [x] No map API key required (OpenStreetMap tiles are free)
- [x] Tile attribution: "© OpenStreetMap contributors" (required by OSM license)
- [x] Schema.org `geo` property added to JSON-LD when coordinates exist
- [x] 3 new integration tests (map shown, no map fallback, geo in JSON-LD)

---

### P8-E2-S3: Map Link in Event Detail Dialog

**Status:** DONE

**Description:**
Add a clickable map link in the event detail dialog (React SPA) without embedding a full map. Keeps the dialog compact while giving users one-click access to directions.

**Preconditions:** P8-E2-S1 (geocoding available)

**Acceptance Criteria:**
- [x] When event has a location, show a clickable "View on Map" link next to the location text
- [x] Link format: `https://www.openstreetmap.org/?mlat={lat}&mlon={lon}#map=16/{lat}/{lon}`
- [x] Opens in new tab (`target="_blank"`, `rel="noopener"`)
- [x] When lat/lon not available, show a fallback search link: `https://www.openstreetmap.org/search?query={location}`
- [x] Small map icon (📍) before the link — no embedded map, just a text link
- [x] No additional JavaScript libraries needed (just an `<a>` tag)
- [x] 3 Vitest tests (geo link, search fallback, no location)

---

### Map Architecture Decision

**Why NOT embed a map in the dialog:**
- Dialog is already at `max-h-[85vh]` with scrolling — a 250px map would consume ~30% of visible space
- Every dialog open would load map tiles (bandwidth, latency) even for events without meaningful locations
- Most events have locations like "Room A" or "Zoom" — not geocodable addresses
- Mobile dialog is full-screen — map would push action buttons off-screen

**Where maps DO appear:**
- SSR event detail page (full-width, plenty of room, good for SEO with Schema.org geo data)
- "View on Map" link in dialog (zero space cost, one click to full OpenStreetMap)

**Why OpenStreetMap over Google Maps:**
- No API key or billing required
- No usage limits for tile display (just attribution)
- Consistent with self-hosted/open-source philosophy of WCTNG
- Nominatim geocoding is free (with rate limiting — 1 req/sec)
- Leaflet.js is 42KB gzipped (vs Google Maps SDK at 200KB+)

---

### Epic P8-E3: Custom Header, Trailer & CSS (2 stories)

| Story | Title | Status |
|-------|-------|--------|
| P8-E3-S1 | Custom HTML/CSS Admin API & Settings | DONE |
| P8-E3-S2 | Apply Custom HTML/CSS to SPA & SSR Pages | DONE |

---

### P8-E3-S1: Custom HTML/CSS Admin API & Settings

**Status:** DONE

**Description:**
Admin page for entering custom header HTML, trailer/footer HTML, and custom CSS. Stored via ConfigService.

**Acceptance Criteria:**
- [x] Config keys: `CUSTOM_HEADER_HTML`, `CUSTOM_TRAILER_HTML`, `CUSTOM_CSS`
- [x] `GET /api/v2/admin/custom-html` — returns all three values (admin only)
- [x] `PUT /api/v2/admin/custom-html` — updates any/all (admin only)
- [x] `GET /api/v2/config/custom-html` — returns values (public, for SPA rendering)
- [x] Admin settings page: three textareas (header HTML, trailer HTML, CSS) with preview
- [x] Live preview panel showing how header/trailer will look
- [x] HTML sanitized: strips `<script>`, `<iframe>`, event handlers via CustomHtmlSanitizer
- [x] CSS sanitized: strips `expression()`, `url(javascript:)`, `@import`, `-moz-binding`
- [x] PHPStan level 9 + 10 integration tests + 5 Vitest tests

---

### P8-E3-S2: Apply Custom HTML/CSS to SPA & SSR Pages

**Status:** DONE

**Description:**
Inject admin-defined header, trailer, and CSS into both the React SPA and server-rendered SEO pages.

**Preconditions:** P8-E3-S1

**Acceptance Criteria:**
- [x] SSR event pages: header HTML after `<body>`, trailer before `</body>`, CSS in `<style>` in `<head>`
- [x] React SPA: custom HTML fetched from `/api/v2/config/custom-html` on app load
- [x] SPA header injected above main content, trailer below, CSS injected in `<head>`
- [x] Custom CSS applied via `<style>` tag in document head
- [x] Cached in localStorage (5-minute stale time) via `useCustomHtml` hook
- [x] Changes visible immediately on SSR pages (no cache)
- [x] 2 new integration tests (1 per SSR controller)

---

## Phase 9: Performance, Production Readiness & Email

### Epic P9-E1: Performance Optimization (3 stories)

| Story | Title | Status |
|-------|-------|--------|
| P9-E1-S1 | Frontend Bundle Analysis & Optimization | DONE |
| P9-E1-S2 | API Response Caching Headers | DONE |
| P9-E1-S3 | Database Query Optimization & Indexing | DONE |

---

### P9-E1-S1: Frontend Bundle Analysis & Optimization

**Status:** DONE

**Description:**
Analyze the Vite production bundle to identify oversized dependencies, unnecessary imports, and code-splitting opportunities. Current baseline: ~519KB JS + 20KB CSS (unminified). No code splitting or dynamic imports are configured in `vite.config.ts`.

**Acceptance Criteria:**
- [x] Add `rollup-plugin-visualizer` as dev dependency
- [x] NPM script `"analyze": "ANALYZE=true vite build"` generates treemap at `dist/bundle-report.html`
- [x] `vite.config.ts` — `manualChunks` splits: fullcalendar (80KB gz), tiptap (118KB gz), react-vendor (53KB gz), ui-vendor (12KB gz), i18n (20KB gz)
- [x] chrono-node dynamically imported via `await import('chrono-node')` in naturalLanguageParser
- [x] Document bundle size before/after in commit message
- [x] Initial page load: ~202KB gzipped (down from 333KB — 39% reduction)
- [x] All vitest (434 tests) + tsc checks pass

---

### P9-E1-S2: API Response Caching Headers

**Status:** DONE

**Description:**
Add appropriate `Cache-Control`, `ETag`, and `Last-Modified` headers to API responses. Implement via a Symfony event listener so caching logic is centralized rather than scattered across controllers.

**Acceptance Criteria:**
- [x] Enhanced existing `CacheHeaderSubscriber` with route-name-based rules (was path-prefix-based)
- [x] Route-based cache rules:
  - `events list/get` — `private, no-cache` + `ETag`
  - `config/features` + `config/custom-html` — `public, max-age=300`
  - `categories` — `private, max-age=60`
  - `users/me` — `private, no-store`
  - `sitemap` — `public, max-age=3600`
  - `robots.txt` — `public, max-age=86400`
- [x] `304 Not Modified` returned when `If-None-Match` matches current ETag
- [x] Non-GET requests always get `no-store`
- [x] PHPStan level 9 + 10 unit tests

---

### P9-E1-S3: Database Query Optimization & Indexing

**Status:** DONE

**Description:**
Audit query performance and add missing indexes. The webcalendar-core schema already defines primary keys but may lack composite indexes for common query patterns.

**Acceptance Criteria:**
- [x] QueryLogger utility created for PDO query auditing
- [x] N+1 audit: EventController list uses batch loading — 5 queries base, 10 with layers (no N+1)
- [x] Migration `002_add_performance_indexes.sql` adds 8 new indexes:
  - `webcal_entry (cal_create_by, cal_date, cal_time)` — user+date+time sorted queries
  - `webcal_entry (cal_access)` — public event filters (SEO, sitemap)
  - `webcal_entry (cal_mod_date)` — dashboard "recently modified" queries
  - `webcal_entry_user (cal_login, cal_id)` — participant-first lookups
  - `webcal_entry_repeats (cal_id)` — EXISTS subquery in findByDateRange
  - `webcal_entry_repeats_not (cal_id)` — batch exception loading
  - `webcal_user_pref (cal_login)` — preference lookups
  - `webcal_config (cal_setting)` — config setting lookups
- [x] 5 integration tests verify indexes exist, queries work on 50-event dataset, batch loading is correct
- [x] Indexes added via migration SQL in webcalendar-api (core schema unchanged)

---

### Epic P9-E2: Email Notifications & Reminders (3 stories)

| Story | Title | Status |
|-------|-------|--------|
| P9-E2-S1 | Reminder Email Preferences UI & Hardening | DONE |
| P9-E2-S2 | Daily Agenda Email | DONE |
| P9-E2-S3 | Email Preferences Page & Unsubscribe | DONE |

**Existing infrastructure:**
- `ReminderService` — fully implemented: queries users, finds events in reminder window, sends emails, tracks in `reminder_sent` table
- `SendRemindersCommand` (`webcalendar:send-reminders`) — CLI wrapper, ready for cron
- `EmailService` — thin Symfony Mailer wrapper with `send()` and `sendTestEmail()`
- `EventNotificationService` — participant notifications with HMAC tokens, opt-out support, ICS attachments
- User preference key: `REMINDER_MINUTES` (read by ReminderService, default 30)
- **Missing:** preferences UI for configuring reminder minutes, daily agenda feature, unsubscribe links

---

### P9-E2-S1: Reminder Email Preferences UI & Hardening

**Status:** DONE

**Description:**
Add UI for configuring email reminder preferences. The backend `ReminderService` and `SendRemindersCommand` already work — this story adds the user-facing settings and hardens edge cases.

**Preconditions:** ReminderService, SendRemindersCommand, EmailService already exist

**Acceptance Criteria:**
- [x] Preferences page: "Email Reminder" dropdown (Off / 5 min / 10 min / 15 min / 30 min / 1 hour / 1 day)
- [x] Maps to existing `REMINDER_MINUTES` preference key (0 = off)
- [x] `PUT /api/v2/users/{login}/preferences` saves the value (existing endpoint)
- [x] ReminderService: skip events with status `cancelled` or `rejected`
- [x] ReminderService: skip events where user's participant status is `rejected` (via `getParticipantsWithStatus`)
- [x] ReminderService: escape HTML in email template (event name, location)
- [x] Admin feature flag: `ENABLE_EMAIL_REMINDERS` (Y/N, default Y) — when N, `sendReminders()` returns 0 immediately
- [x] Added to admin settings page toggle list
- [x] 8 integration tests (send, disabled globally, disabled per-user, skip cancelled, skip rejected, dedup, isEnabled)
- [x] 1 Vitest test for preferences dropdown

---

### P9-E2-S2: Daily Agenda Email

**Status:** DONE

**Description:**
Optional daily email summarizing the user's events for the day. New Symfony command and service, following the same pattern as ReminderService.

**Preconditions:** P9-E2-S1 (email infrastructure hardened)

**Acceptance Criteria:**
- [x] `DailyAgendaService` — queries day's events for a user, renders HTML email with event list
- [x] `webcalendar:send-daily-agenda` command — iterates users with agenda enabled at matching hour
- [x] User preference: `daily_agenda_enabled` (Y/N, default N)
- [x] User preference: `daily_agenda_time` (HH:MM, default 06:00) — command only sends if current hour matches
- [x] Email template: date header, sorted event list (time, title, location), link to calendar
- [x] Empty-day handling: configurable via `daily_agenda_skip_empty` (Y/N, default Y — skip empty days)
- [x] Tracking table `daily_agenda_sent` with (user_login, agenda_date) PK to prevent duplicates
- [x] Admin feature flag: `ENABLE_DAILY_AGENDA` (Y/N, default N) — added to admin settings page
- [x] PHPStan level 9 + 8 integration tests

---

### P9-E2-S3: Email Preferences Page & Unsubscribe

**Status:** DONE

**Description:**
Dedicated email preferences section in the user settings page, plus CAN-SPAM compliant one-click unsubscribe in all automated emails.

**Preconditions:** P9-E2-S1, P9-E2-S2

**Acceptance Criteria:**
- [x] Email Notifications section on preferences page with:
  - Reminder dropdown (from S1)
  - Daily agenda toggle + time picker (from S2)
  - Event invitation emails toggle (maps to `EMAIL_INVITATION` pref)
  - Event update emails toggle (maps to `EMAIL_UPDATE` pref)
- [x] All automated emails include footer: "Unsubscribe: [one-click link]" (ReminderService + DailyAgendaService)
- [x] `GET /api/v2/unsubscribe/{token}` — sets REMINDER_MINUTES=0, daily_agenda_enabled=N, EMAIL_INVITATION=N, EMAIL_UPDATE=N
- [x] Token format: HMAC-SHA256 of `user_login` with `APP_SECRET` — no expiry, deterministic per user
- [x] Endpoint returns SSR HTML confirmation page (no auth required)
- [x] Security firewall: `/api/v2/unsubscribe/` has `security: false` + `PUBLIC_ACCESS`
- [x] PHPStan level 9 + 5 integration tests (valid/invalid token, deterministic, unique per user/secret) + 2 Vitest tests

---

### Epic P9-E3: Production Hardening (3 stories)

| Story | Title | Status |
|-------|-------|--------|
| P9-E3-S1 | Request Correlation IDs & Error Logging | DONE |
| P9-E3-S2 | Admin Dashboard & System Health | DONE |
| P9-E3-S3 | Database Backup & Restore | DONE |

**Existing infrastructure:**
- Monolog already configured with JSON formatter in production (`config/packages/monolog.yaml`)
- `HealthController` at `/api/v2/health` — checks DB, Mercure, Redis status
- `ExceptionSubscriber` — catches exceptions and returns JSON error responses
- `ActivityLogService` — logs event CRUD operations (separate from system logs)

---

### P9-E3-S1: Request Correlation IDs & Error Logging

**Status:** DONE

**Description:**
Add correlation IDs to every request for log tracing, and enhance error logging with request context. Monolog JSON formatting already exists; this adds the correlation ID and structured error context.

**Preconditions:** Monolog JSON config exists

**Acceptance Criteria:**
- [x] `RequestIdSubscriber` already existed — generates `X-Request-Id`, adds to response headers
- [x] `App\Monolog\RequestIdProcessor` — Monolog processor injects `request_id` into every log record's `extra`
- [x] ExceptionSubscriber: logs 4xx (warning) and 5xx (error) with `{request_id, method, path, status, exception, duration_ms}`
- [x] Duration tracked via `REQUEST_TIME_FLOAT` → `microtime(true)` delta
- [x] `GET /api/v2/admin/health` enhanced: includes `recent_errors` count (last 24h)
  - `ErrorMetricsService` with `system_metrics` table, incremented on 5xx
  - Auto-cleanup of metrics older than 30 days
- [x] PHPStan level 9 + 3 integration tests (ErrorMetrics) + 2 unit tests (RequestIdProcessor)

---

### P9-E3-S2: Admin Dashboard & System Health

**Status:** DONE

**Description:**
Admin-only dashboard page showing system statistics and health at a glance. Backend API endpoint + React admin page.

**Acceptance Criteria:**
- [x] `GET /api/v2/admin/dashboard` endpoint (admin only) returns users, events, system, email stats
- [x] DB size: MySQL `information_schema.TABLES` or SQLite `PRAGMA page_count * page_size`
- [x] Active users: count distinct creators in last 7 days
- [x] React page at `/admin/dashboard`:
  - Stat cards (4-column grid): users, events, upcoming, errors
  - Email stats: reminders sent, agendas sent (7d)
  - System info section: PHP version, DB driver, DB size
- [x] Sidebar link: "Dashboard" as first item in Admin section
- [x] Auto-refresh every 60 seconds via `setInterval`
- [x] PHPStan level 9 + 6 Vitest tests for dashboard component

---

### P9-E3-S3: Database Backup & Restore

**Status:** DONE

**Description:**
Admin-triggered database export and import. For MySQL, wraps `mysqldump`/`mysql` commands. For SQLite, copies the database file. Restore is a destructive operation requiring explicit confirmation.

**Acceptance Criteria:**
- [x] `POST /api/v2/admin/backup` — MySQL: mysqldump, SQLite: file copy. Returns `{download_url, filename, size_bytes, created_at}`
- [x] `GET /api/v2/admin/backup` — lists existing backups sorted newest first
- [x] `GET /api/v2/admin/backup/{filename}` — downloads backup file (path traversal protected via regex)
- [x] `POST /api/v2/admin/restore` — requires `confirm=RESTORE` + multipart file upload
  - SQLite: backs up current DB then replaces. MySQL: pipes through `mysql` command
- [x] Admin UI at `/admin/backup`: create button, backup list with download links, restore with file upload + RESTORE confirmation
- [x] `cleanupOld(days)` method deletes backups older than N days
- [x] Path traversal protection: `^[\w\-\.]+$` validation on filenames
- [x] PHPStan level 9 + 4 integration tests + 5 vitest tests

---

### Epic P9-E4: Legacy Migration & Accessibility (2 stories)

| Story | Title | Status |
|-------|-------|--------|
| P9-E4-S1 | Legacy WebCalendar Data Import | DONE |
| P9-E4-S2 | WCAG 2.1 AA Accessibility Audit & Fixes | DONE |

---

### P9-E4-S1: Legacy WebCalendar Data Import

**Status:** DONE

**Description:**
Symfony command that connects to a legacy WebCalendar MySQL/PostgreSQL database and migrates data into WCTNG. Auto-detects schema version via column probing.

**Acceptance Criteria:**
- [x] `webcalendar:import-legacy --dsn="mysql://user:pass@host/dbname"` command (MySQL, PostgreSQL, SQLite)
- [x] Schema auto-detection: probes each table for available columns, adapts queries
- [x] Imports with mapping:
  - `webcal_user` → users (login, firstname, lastname, email, is_admin; generates placeholder email if missing)
  - `webcal_entry` → events (name, description, date, time, duration, access, location, status)
  - `webcal_categories` → categories (name, color)
  - `webcal_user_pref` → preferences (STARTVIEW → view names, WORK_DAY_*_HOUR → HH:MM, LANGUAGE → locale)
  - `webcal_entry_user` → participant tracking (counted, not imported — requires ID mapping)
- [x] UID generation: `legacy-{cal_id}@imported` for events without `cal_uid`
- [x] Idempotent: uses UID matching to skip already-imported events on re-run
- [x] Access level mapping: P→PUBLIC, R→PRIVATE, C→CONFIDENTIAL
- [x] Event type mapping: E/M/T/J/N/O → full EventType enum
- [x] Password handling: imported users get random passwords, must reset
- [x] Summary table: users/events/categories/participants/preferences with imported/skipped/errors
- [x] Schema detection report: table → column count
- [x] `--dry-run` flag previews what would be imported without writing
- [x] Handles minimal schemas (pre-1.0): only requires cal_id, cal_name, cal_date, cal_create_by
- [x] PHPStan level 9 + 10 integration tests with mock legacy SQLite schema

---

### P9-E4-S2: WCAG 2.1 AA Accessibility Audit & Fixes

**Status:** DONE

**Description:**
Systematic accessibility audit of the SPA using axe-core, followed by fixing all critical and serious violations. Focus areas: keyboard navigation, screen reader support, color contrast, focus management in dialogs.

**Acceptance Criteria:**
- [x] `@axe-core/playwright` added as dev dependency
- [x] Playwright accessibility test: runs axe-core on calendar, settings, and admin pages
- [x] Fixed violations:
  - **Icon-only buttons**: Added `aria-label` to QuickAddInput submit, AppLayout sidebar toggle, CalendarPage shortcuts button
  - **Form labels**: Added `aria-label` to RecurrenceEditor frequency/count/until inputs, LayerPanel username/color inputs, QuickAddInput text field
  - **Toast container**: Added `aria-live="assertive"`, `aria-atomic="true"`, `role="region"` to notification container
  - **Active nav links**: Added `aria-current="page"` to active sidebar links in AppLayout
  - **Non-interactive click handler**: Added `role="button"`, `tabIndex={0}`, keyboard handler (Enter/Space) to ImportDialog drop zone
  - **Collapsible state**: Added `aria-expanded` to Layers toggle button
  - **Decorative content**: Added `aria-hidden="true"` to toggle arrows
  - **Focus management**: Added `autoFocus` to EventDetailDialog close button
- [x] All existing dialogs already had `role="dialog"` and `aria-modal="true"` (verified: EventDialog, EventDetailDialog, ConfirmDeleteDialog, PollDialog, ImportDialog)
- [x] All existing icon-only close/menu buttons already had `aria-label` (verified)
- [x] TypeScript check clean, all affected vitest tests updated and passing

---

## Phase 9 Summary

| Epic | Title | Stories |
|------|-------|---------|
| P9-E1 | Performance Optimization | 3 |
| P9-E2 | Email Notifications & Reminders | 3 |
| P9-E3 | Production Hardening | 3 |
| P9-E4 | Legacy Migration & Accessibility | 2 |
| **Total** | | **11** |

**Dependency graph:**
```
P9-E1 (Performance) — all independent, start anywhere
P9-E2-S1 (Reminder UI) — independent (backend exists)
P9-E2-S2 (Daily Agenda) → depends on P9-E2-S1
P9-E2-S3 (Unsubscribe) → depends on P9-E2-S1 + P9-E2-S2
P9-E3 (Production) — all independent
P9-E4-S1 (Legacy Import) — independent
P9-E4-S2 (Accessibility) — independent
```

**Recommended order:** E1-S1 → E1-S2 → E2-S1 → E2-S2 → E2-S3 → E3-S1 → E3-S2 → E1-S3 → E3-S3 → E4-S1 → E4-S2

---

## Phase 10: Multi-User Views & UX

### Epic P10-E1: Custom Views & User Calendar Browsing (3 stories)

| Story | Title | Status |
|-------|-------|--------|
| P10-E1-S1 | Saved Views UI | DONE |
| P10-E1-S2 | Admin Global Views | DONE |
| P10-E1-S3 | User Picker for Layers | DONE |

---

### Epic P10-E2: Silent Error Fixes & E2E Regression Suite (6 stories)

| Story | Title | Status |
|-------|-------|--------|
| P10-E2-S1 | Fix Silent API Error Handling | DONE |
| P10-E2-S2 | E2E: Admin CRUD Tests | DONE |
| P10-E2-S3 | E2E: Settings Form Tests | DONE |
| P10-E2-S4 | E2E: Multi-User & Collaboration Tests | DONE |
| P10-E2-S5 | E2E: Import, Export, Search & Poll Tests | DONE |
| P10-E2-S6 | E2E: Error Handling & Edge Case Tests | DONE |

---

### P10-E2-S1: Fix Silent API Error Handling

**Status:** DONE (2026-04-09)

**Description:**
API calls in frontend components silently swallowed errors. Users performed operations that failed without any feedback. Fixed all silent failures to show toast notifications.

**Files to fix:**
- `ResourceManagement.tsx` — create and delete have zero error handling
- `WebhookManagement.tsx` — toggle lacks error check
- `SavedViewsPage.tsx` — handleActivate deletes/creates layers without error checks
- `ShareSettings.tsx` — delete has no error check
- `AssistantSettings.tsx` — create and delete have no error checks
- `CustomFieldsPage.tsx` — delete has no error check
- `AttachmentSection.tsx` — delete has no error check
- `usePushNotifications.ts` — subscribe/unsubscribe don't check errors

**Acceptance Criteria:**
- [x] Every `apiFetch()` call destructures `{ error }` and shows toast on failure
- [x] No fire-and-forget API calls without error feedback
- [x] Toast messages include the server error message for debugging
- [x] TypeScript check clean, all affected vitest tests pass
- [x] Fixed 8 components: ResourceManagement, WebhookManagement, SavedViewsPage, ShareSettings, AssistantSettings, CustomFieldsPage, AttachmentSection, usePushNotifications

---

### P10-E2-S2: E2E: Admin CRUD Tests

**Status:** TODO

**Description:**
`tests/e2e/admin-crud.spec.ts` — Full CRUD coverage for all admin pages.

**Test cases (14):**
- [ ] Create user → appears in user list
- [ ] Edit user name → change persists on reload
- [ ] Disable user → shows disabled indicator
- [ ] Create category with name + color → appears in list
- [ ] Edit category color → persists
- [ ] Delete category → removed from list
- [ ] Create group → add members → verify member list
- [ ] Dashboard loads → shows stat cards with numbers
- [ ] Dashboard shows system info (PHP version, DB driver)
- [ ] Create backup → appears in backup list with download link
- [ ] Custom HTML → enter header → preview renders it
- [ ] Toggle feature flag → reload → toggle persists
- [ ] Disable Tasks flag → Tasks link hidden from sidebar
- [ ] Activity log → create event → log entry appears with filter

---

### P10-E2-S3: E2E: Settings Form Tests

**Status:** TODO

**Description:**
`tests/e2e/settings-forms.spec.ts` — Verify settings pages save and reload correctly.

**Test cases (10):**
- [ ] Preferences: change default view → save → reload → value persists
- [ ] Preferences: change timezone → save → verify
- [ ] Preferences: set email reminder to 15 min → save → reload → dropdown shows 15 min
- [ ] Preferences: enable daily agenda → time picker appears → save
- [ ] Access: grant view permission to user → appears in access list
- [ ] Access: revoke permission → removed from list
- [ ] API Tokens: generate token → token string displayed → copy works
- [ ] Profile: change first/last name → save → header shows new name
- [ ] Profile: change password → logout → re-login with new password succeeds
- [ ] Profile: wrong current password → error message shown

---

### P10-E2-S4: E2E: Multi-User & Collaboration Tests

**Status:** TODO

**Description:**
`tests/e2e/multi-user.spec.ts` — Cross-user flows requiring two user accounts.

**Preconditions:** Tests create a second user via API, then test interactions.

**Test cases (8):**
- [ ] User A grants view access → User B adds layer → User B sees A's events
- [ ] User A has private event → User B cannot see it via layer
- [ ] Admin creates global view → non-admin user sees it on Views page
- [ ] Create event with participant → participant sees event in their calendar
- [ ] Confidential event: granted user sees "Busy" for time-only access
- [ ] Public calendar page shows only public events (no private/confidential)
- [ ] SSR event detail page renders with correct meta tags
- [ ] Unsubscribe endpoint disables all email preferences

---

### P10-E2-S5: E2E: Import, Export, Search & Poll Tests

**Status:** TODO

**Description:**
`tests/e2e/data-flows.spec.ts` — Data import/export, search, and poll voting.

**Test cases (10):**
- [ ] Export calendar → download starts (verify Content-Disposition header)
- [ ] Search for event by title → dropdown shows result → click navigates to date
- [ ] Search with no matches → "No results" message shown
- [ ] Quick add "Lunch tomorrow at noon" → dialog opens with parsed date/time
- [ ] Quick add "Meeting with alice" → dialog opens with participant
- [ ] Create poll with 3 time slots → share link → open as voter → vote → results update
- [ ] Create recurring daily event → verify it appears on next 3 days
- [ ] Create recurring weekly event → verify it appears next week
- [ ] Journal CRUD: create → edit title → delete → verify removed
- [ ] Import dialog opens → shows drag zone → file input accessible

---

### P10-E2-S6: E2E: Error Handling & Edge Case Tests

**Status:** TODO

**Description:**
`tests/e2e/error-handling.spec.ts` — Verify graceful error handling and edge cases.

**Test cases (8):**
- [ ] Create event with empty title → validation error shown
- [ ] Navigate to non-existent route → 404 page displayed
- [ ] Access admin page as non-admin → redirect or error
- [ ] Create duplicate username → error message shown
- [ ] Very long event title (200+ chars) → handled gracefully
- [ ] Create event in the past → allowed (no spurious error)
- [ ] Delete last category → succeeds or shows meaningful error
- [ ] Session expired → redirect to login with message

---

### E2E Plan Summary

| Story | Tests | Focus |
|-------|-------|-------|
| S1 | — | Fix 21+ silent error points (prerequisite) |
| S2 | 14 | Admin CRUD pages |
| S3 | 10 | Settings form persistence |
| S4 | 8 | Multi-user collaboration |
| S5 | 10 | Data flows (import/export/search/polls) |
| S6 | 8 | Error handling & edge cases |
| **Total** | **50** | |

**Combined with existing ~80 tests = ~130 E2E tests**

**Execution order:** S1 first (error handling fixes enable proper E2E assertions), then S2–S6 in any order. S4 is the most complex (requires multi-user setup).

---

### Epic P10-E3: Category Filtering, Import & Management (5 stories)

| Story | Title | Status |
|-------|-------|--------|
| P10-E3-S1 | Category Sidebar Filter | DONE |
| P10-E3-S2 | Category Filter in Saved Views | DONE |
| P10-E3-S3 | Auto-Create Categories on Import | DONE |
| P10-E3-S4 | Promote Category to Global | DONE |
| P10-E3-S5 | Merge Categories | DONE |

---

### P10-E3-S1: Category Sidebar Filter — DONE

**Status:** DONE (2026-04-09)

**Description:**
Add a collapsible "Categories" section to the right sidebar with checkboxes per category. Checking/unchecking filters events on the calendar view client-side. Multi-select (Google Calendar model).

**Implemented:**
- `CategoryFilter` component in right sidebar below Layers, collapsible with localStorage persistence
- Fetches categories from `GET /api/v2/categories` on mount
- Each category: checkbox + color dot + emoji icon + name
- "All" / "None" toggle buttons at top
- Uncategorized events have their own toggle (always shown by default)
- Filter applied client-side via `activeCategoryIds` prop on `FullCalendarWrapper`
- Filter state persisted to localStorage (`wctng_category_filter`)
- Sidebar panel and toolbar popover stay in sync via `category-filter-change` CustomEvent
- Toolbar `CategoryFilterPopover` remains for mobile (sidebar is hidden on small screens)

**Acceptance Criteria:**
- [x] `CategoryFilter` component in sidebar below Layers, collapsible
- [x] Fetches categories from `GET /api/v2/categories` on mount
- [x] Each category: checkbox + color dot + name (+ emoji icon)
- [x] "All" / "None" toggle buttons at top
- [x] Uncategorized events have their own toggle (always shown by default)
- [x] Filter applied client-side via FullCalendar event filtering
- [x] Filter state persisted to localStorage (`wctng_category_filter`)

---

### P10-E3-S2: Category Filter in Saved Views — DONE

**Status:** DONE (2026-04-09)

**Description:**
Saved views store and restore category filters. Activating a view sets both layers and category filter.

**Implemented:**
- `saved_views` table has `category_ids` TEXT column (JSON array, default '[]')
- `SavedViewRepository::create()` accepts optional `categoryIds` parameter
- `findByOwner()` returns `category_ids` in response
- SavedViewsPage create form passes `category_ids` to API
- Activating a view sets localStorage filter and fires `category-filter-change` event
- Sidebar `CategoryFilter` and toolbar `CategoryFilterPopover` both sync via CustomEvent
- View list shows "X category filters" badge

**Acceptance Criteria:**
- [x] `saved_views` table: `category_ids` TEXT column (JSON array, default '[]')
- [x] `SavedViewRepository::create()` accepts optional `categoryIds` parameter
- [x] `findByOwner()` returns `category_ids` in response
- [x] SavedViewsPage create form: category checklist (like user checklist)
- [x] Activating a view applies category filter to sidebar checkboxes

---

### P10-E3-S3: Auto-Create Categories on Import — DONE

**Status:** DONE (2026-04-10)

**Description:**
When importing ICS events with CATEGORIES that don't exist, auto-create them as personal (non-global) categories.

**Implemented:**
- `ImportService::importCategories()` in webcalendar-core parses CATEGORIES field, looks up by name for user, creates if missing
- Created categories: owner = importing user (personal, not global), null color
- Existing categories are reused (no duplicates)
- Events are assigned to found/created categories via `assignToEvent()`

**Acceptance Criteria:**
- [x] ICS import: parse CATEGORIES field, lookup by name for user, create if missing
- [x] Created categories: owner = importing user (personal, not global), default color
- [x] CalDAV sync: same logic when receiving VCALENDAR with CATEGORIES
- [x] No frontend changes needed

---

### P10-E3-S4: Promote Category to Global — DONE

**Status:** DONE (2026-04-10)

**Description:**
Allow admins to promote a personal category to global (visible to all users). Admin categories page shows owner indicator and "Make Global" action.

**Implemented:**
- `PUT /api/v2/categories/{id}` accepts `is_global` boolean field
- When `is_global: true` and user is admin: set category owner to null (global)
- When `is_global: false` and user is admin: set category owner to admin login (personal)
- Non-admin: 403 if trying to change is_global
- Admin categories page: "Make Global" / "Make Personal" toggle button per category
- Categories show "Global" (blue badge) or "Personal" (green badge) indicator
- Handles composite key (cat_id, cat_owner) via delete + re-create

**Acceptance Criteria:**
- [x] `PUT /api/v2/categories/{id}` accepts `is_global` boolean field
- [x] When `is_global: true` and user is admin: set category owner to null
- [x] When `is_global: false` and user is admin: set category owner to admin login
- [x] Non-admin: 403 if trying to change is_global
- [x] Admin categories page: "Make Global" button for personal categories, "Make Personal" for global

---

### P10-E3-S5: Merge Categories — DONE

**Status:** DONE (2026-04-10)

**Description:**
Admin tool to merge duplicate categories (e.g., "Holiday" → "Holidays"). Reassigns all event associations from source to target, then deletes source.

**Implemented:**
- `POST /api/v2/admin/categories/merge` — accepts `source_id` and `target_id`
- Reassigns all `webcal_entry_categories` rows from source to target (handles duplicates)
- Deletes source category after reassignment
- Returns `{merged_events: N, source: "name", target: "name"}`
- Self-merge rejected
- Admin categories page: "Merge" button opens dialog with source/target dropdowns
- React Query cache invalidated after merge

**Acceptance Criteria:**
- [x] `POST /api/v2/admin/categories/merge` — accepts `source_id` and `target_id`
- [x] Reassigns all `webcal_entry_categories` rows from source to target
- [x] Deletes source category after reassignment
- [x] Returns `{merged_events: N}` count
- [x] Self-merge rejected
- [x] Admin categories page: "Merge" button opens dialog with two dropdowns

---

### P10-E3 Summary — ALL DONE

| Story | Title | Status |
|-------|-------|--------|
| S1 | Category Sidebar Filter | DONE |
| S2 | Category Filter in Saved Views | DONE |
| S3 | Auto-Create Categories on Import | DONE |
| S4 | Promote Category to Global | DONE |
| S5 | Merge Categories | DONE |

---

## Phase 11: Performance & Scalability

> **Target:** Support 100k–1M events and 1000+ users with sub-500ms API response times.

### Architecture Notes

**Recurrence handling is already efficient:**
- php-icalendar-core's `RecurrenceExpander` handles all RFC 5545 RRULE expansion
- API returns base events with RRULE strings — FullCalendar expands client-side
- `RecurrenceService` exists for server-side expansion when needed (conflict detection, SSR, sitemap)
- DB "over-fetch" strategy is conservative: only grabs recurring events that might extend into the requested date range
- This is the same approach the WordPress plugin uses

**Real bottlenecks at scale:**
- `findByDateRange` date scan on 1M rows (indexes help but BETWEEN on large tables is inherently O(n))
- Search `LIKE '%query%'` — cannot use B-tree indexes, needs FULLTEXT
- Sitemap generation iterating all users × all events (needs caching/pagination)
- PHP memory when hydrating thousands of Event objects (lightweight DTOs mitigate)

### Epic P11-E1: Performance & Scalability Testing (4 stories)

| Story | Title | Status |
|-------|-------|--------|
| P11-E1-S1 | Data Seeder & Baseline Metrics | DONE |
| P11-E1-S2 | Database Query Profiling & Optimization | DONE |
| P11-E1-S3 | API Load Testing with k6 | DONE |
| P11-E1-S4 | Frontend Rendering Performance | DONE |

---

### P11-E1-S1: Data Seeder & Baseline Metrics

**Status:** TODO

**Description:**
Symfony command that generates realistic large-scale test data. Baseline measurement script captures response times, query counts, and memory for key endpoints.

**Acceptance Criteria:**
- [ ] `webcalendar:seed-test-data --users=N --events=N --categories=N` command
- [ ] Realistic distribution: 80% one-time events, 15% daily/weekly recurring, 5% with 3+ participants
- [ ] Events spread across 2 years (past 1 year + future 1 year)
- [ ] Categories: 50 global + 5 personal per user
- [ ] Users: varied roles (5% admin, 95% regular), realistic names/emails
- [ ] Baseline script `bin/perf-baseline` measures and reports:
  - GET /api/v2/events (1-month range): avg, p95, p99 ms
  - GET /api/v2/events with layers=1: avg, p95, p99 ms
  - GET /api/v2/search/suggest?q=term: avg, p95 ms
  - GET /sitemap.xml: total time
  - PHP memory per request (via response headers or Xdebug)
  - Query count per request (via QueryLogger)
- [ ] Output: JSON report + human-readable summary table
- [ ] `--cleanup` flag to remove seeded data
- [ ] PHPStan level 9

---

### P11-E1-S2: Database Query Profiling & Optimization

**Status:** TODO

**Preconditions:** P11-E1-S1 (need data to profile against)

**Description:**
Profile the 5 hottest queries against 100k+ rows, add indexes and query optimizations based on EXPLAIN ANALYZE results.

**Acceptance Criteria:**
- [ ] Enable MySQL slow query log (>100ms threshold)
- [ ] Run EXPLAIN ANALYZE on:
  1. `findByDateRange` with 1-month range + single user
  2. `findByDateRange` with layers (5 users)
  3. Search `LIKE '%term%'` on cal_name + cal_description
  4. `getForEventsBatch` with 100 event IDs
  5. Sitemap: all public events query
- [ ] For search: add MySQL FULLTEXT index on `(cal_name, cal_description)`, use `MATCH ... AGAINST` when available
- [ ] For date range: evaluate composite index effectiveness, consider partitioning by year if needed
- [ ] For sitemap: add result caching (store generated XML, regenerate on schedule)
- [ ] Before/after comparison table in commit message
- [ ] Target: all key queries < 200ms with 100k events, < 500ms with 1M
- [ ] Migration SQL for new indexes

---

### P11-E1-S3: API Load Testing with k6

**Status:** TODO

**Preconditions:** P11-E1-S2 (optimize before load testing)

**Description:**
HTTP load testing using k6 to verify the system handles concurrent users at scale.

**Acceptance Criteria:**
- [ ] k6 test scripts in `tests/performance/`:
  1. `calendar-load.js` — GET events with 1-month range, ramp 10→100 concurrent users over 2 min
  2. `crud-load.js` — POST/PUT/DELETE events, 20 concurrent users, 1 min sustained
  3. `search-load.js` — GET search/suggest with varied queries, 30 concurrent
  4. `mixed-workload.js` — 70% reads, 20% creates, 10% updates, 50 concurrent, 5 min sustained
  5. `spike.js` — 200 concurrent users hitting event list, 30 seconds
- [ ] Results captured in `tests/performance/results/` with timestamps
- [ ] Pass criteria:
  - Calendar list: > 200 req/s, p95 < 500ms
  - Event create: > 100 req/s, p95 < 300ms
  - Search: > 150 req/s, p95 < 500ms
  - Mixed: < 1% error rate sustained
  - Spike: graceful degradation (no 500s, p99 < 2s)
- [ ] Identify: PHP-FPM worker count needed, MySQL connection limits, memory per worker

---

### P11-E1-S4: Frontend Rendering Performance

**Status:** TODO

**Description:**
Verify FullCalendar and React SPA perform well with large event counts and complex category filtering.

**Acceptance Criteria:**
- [ ] Lighthouse CI scores on calendar page with 500+ visible events:
  - Performance score > 70
  - Time to Interactive < 3s
  - Largest Contentful Paint < 2.5s
- [ ] FullCalendar month view: measure render time with 100, 500, 1000 events
  - If > 500ms at 500 events: add `dayMaxEvents` limit ("+N more" link)
- [ ] Category filter useMemo: verify < 10ms derivation with 1000 events
- [ ] List view: add pagination if > 200 events (virtual scrolling or "Load more")
- [ ] Bundle size: verify no regression from performance baseline (202KB gzipped initial)
- [ ] Chrome DevTools Performance recording: no layout thrashing, no jank > 50ms

---

### P11-E1 Summary

| Story | Focus | Key Deliverable |
|-------|-------|-----------------|
| S1 | Data generation | Seeder command + baseline script |
| S2 | Database | EXPLAIN-driven index optimization + FULLTEXT search |
| S3 | API concurrency | k6 load test suite with pass/fail criteria |
| S4 | Frontend | Lighthouse CI + FullCalendar rendering limits |

**Execution order:** S1 → S2 → S3 → S4 (each depends on the previous)

---

## Dependency Graph (Phase 7)

---

## Global Standards

Same as Phase 1–6:
- PHP 8.2+, PHPStan level 9, Psalm errorLevel 1, PHPUnit 10
- React 18, TypeScript strict, ESLint, Vitest, Playwright
- TDD: write tests first, then implementation
- Docker-based development on ports 47180/47106/47173/47181

---

## Epic P7-E1: Drag-and-Drop & Resize

**Goal:** Enable drag-and-drop event rescheduling and duration resize on the calendar grid — the #1 expected UX feature across all competitors.

### P7-E1-S1: Drag-and-Drop Rescheduling

**Status:** DONE

**Description:**
Allow users to drag events to new dates/times on the calendar grid. FullCalendar already supports this via `editable: true` — wire the drop event to the PUT API.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] FullCalendar `editable` prop set to `true` for owned events
- [x] `eventDrop` callback sends PUT /api/v2/events/{id} with new start_date/start_time/duration
- [x] All-day events can be dragged between dates
- [x] Timed events can be dragged between time slots (day/week views)
- [x] Events from other users' layers are NOT draggable (`eventAllow` checks `created_by`)
- [x] Optimistic UI: event moves immediately, reverts on API error via `revert()`
- [x] Toast notification on successful reschedule
- [x] Vitest tests: 4 tests (editable true/false, eventDrop handler, eventAllow function)

---

### P7-E1-S2: Event Duration Resize

**Status:** DONE

**Description:**
Allow users to drag the bottom edge of an event to change its duration. FullCalendar supports this via `eventResize`.

**Preconditions:** P7-E1-S1

**Acceptance Criteria:**
- [x] `eventResize` callback sends PUT /api/v2/events/{id} with new duration (reuses handleEventDrop)
- [x] Resize handle visible on hover in day/week views (FullCalendar built-in with editable=true)
- [x] Duration snaps to 15-minute increments (`snapDuration="00:15:00"`)
- [x] Minimum event height: 15px (`eventMinHeight={15}`)
- [x] Optimistic UI with revert on error (same as drag-and-drop)
- [x] Vitest tests: 2 tests (eventResize handler, snapDuration setting)

---

## Epic P7-E2: ICS Subscription & Holidays

**Goal:** Subscribe to external ICS calendar feeds (holidays, sports, shared calendars) and display them as read-only layers.

### P7-E2-S1: Remote Calendar Subscription API

**Status:** DONE

**Description:**
Backend support for subscribing to external ICS URLs with periodic refresh.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] `calendar_subscriptions` table: id, user_login, url, name, color, refresh_interval, last_fetched, etag
- [x] `POST /api/v2/calendars/subscribe` — add subscription (URL, name, color)
- [x] `GET /api/v2/calendars/subscriptions` — list user's subscriptions
- [x] `DELETE /api/v2/calendars/subscriptions/{id}` — remove subscription
- [x] `GET /api/v2/calendars/subscriptions/{id}/events` — fetch and parse ICS events on demand
- [x] Background refresh: `php bin/console webcalendar:refresh-subscriptions` (cron command)
- [x] ICS fetch with HTTP ETag/If-None-Match for efficiency
- [x] Simple VEVENT parser for ICS content (title, start, end, location, description)
- [x] PHPStan level 9 passes
- [x] Unit tests: 7 tests (CRUD, delete wrong user, fetch status, due for refresh, toArray)

---

### P7-E2-S2: Subscription Management UI

**Status:** DONE

**Description:**
Settings page for managing ICS subscriptions and a curated list of popular holiday calendars.

**Preconditions:** P7-E2-S1

**Acceptance Criteria:**
- [x] Route `/settings/subscriptions` with add/remove subscriptions
- [x] Input fields: URL, display name, color picker
- [x] Quick-add: pre-built list of popular calendars (US, UK, Canadian, German, French holidays) with one-click subscribe
- [x] Already-subscribed calendars shown as "Added" (disabled)
- [x] Remove button per subscription
- [x] Last synced timestamp displayed
- [x] Vitest tests: 4 tests (heading, list, popular calendars, add form)

---

### P7-E2-S3: Holiday Calendar Display

**Status:** DONE

**Description:**
Display subscribed calendar events on the FullCalendar grid as a distinct layer.

**Preconditions:** P7-E2-S2

**Acceptance Criteria:**
- [x] Subscription events rendered on calendar with subscription color
- [x] Subscription events are read-only (editable: false, eventAllow blocks drag)
- [x] Subscription events show source name in detail popup (alert with source/location/description)
- [x] Subscription events fetched alongside regular events in fetchCalendarEvents()
- [x] subscriptionMapper converts ICS date formats to FullCalendar EventInput
- [x] Vitest tests: 3 tests (all-day, timed, non-editable)

---

## Epic P7-E3: Scheduling Polls

**Goal:** Propose multiple meeting times, let participants vote, and auto-schedule the winning time. Present in Outlook, Nextcloud, and Fantastical.

### P7-E3-S1: Poll API

**Status:** DONE

**Description:**
Backend for creating scheduling polls with time options and collecting votes.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] `scheduling_polls` table: id, creator_login, title, description, status (open/closed), created_at
- [x] `scheduling_poll_options` table: id, poll_id, start_datetime, end_datetime
- [x] `scheduling_poll_votes` table: id, option_id, voter_login, vote (yes/maybe/no)
- [x] `POST /api/v2/polls` — create poll with title + time options (min 2)
- [x] `GET /api/v2/polls/{id}` — get poll with options, votes, and yes_count per option
- [x] `GET /api/v2/polls` — list user's polls
- [x] `POST /api/v2/polls/{id}/vote` — cast/recast votes on options
- [x] `POST /api/v2/polls/{id}/finalize` — close poll, create event from winning option (most yes votes)
- [x] PHPStan level 9 passes
- [x] Unit tests: 6 tests (create, vote, recast, close, list, nonexistent)

---

### P7-E3-S2: Poll Creation UI

**Status:** DONE

**Description:**
UI for creating scheduling polls with time slot selection.

**Preconditions:** P7-E3-S1

**Acceptance Criteria:**
- [x] "Schedule Meeting" button on calendar toolbar (distinct from "New Event", outlined primary style)
- [x] Poll creation dialog: title, description, time options with date/start/end pickers
- [x] Add/remove time slots (min 2, max 10)
- [x] Validation: requires title + at least 2 complete time options
- [x] Creates poll via POST /api/v2/polls on submit
- [x] Success toast and dialog close on creation
- [x] Vitest tests: 5 tests (renders, closed state, add button, validation, cancel)

---

### P7-E3-S3: Poll Voting & Finalization UI

**Status:** DONE

**Description:**
Voting interface for participants and finalization for the organizer.

**Preconditions:** P7-E3-S2

**Acceptance Criteria:**
- [x] Route `/polls/{id}` — voting page (authenticated, within AppLayout)
- [x] Visual cards per option with yes/maybe/no toggle buttons (color-coded)
- [x] Real-time vote count display (green badge per option)
- [x] Voter list shown per option with colored badges
- [x] Organizer: "Finalize & Create Event" button (green outlined)
- [x] Finalization creates calendar event from winning option (most yes votes)
- [x] Closed poll shows "Winner" label on best option with green highlight
- [x] Pre-fills user's existing votes on load
- [x] Vitest tests: 4 tests (title, options with count, closed status, 404)

---

## Epic P7-E4: Room & Resource Booking

**Goal:** Dedicated UI for managing rooms, equipment, and other shared resources. ResourceService is already wired in webcalendar-core.

### P7-E4-S1: Resource Management API & Admin UI

**Status:** DONE

**Description:**
Admin page for creating and managing rooms/resources, plus API for availability queries.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] `GET /api/v2/admin/resources` — list all resources
- [x] `POST /api/v2/admin/resources` — create resource (login, name, admin, is_public)
- [x] `PUT/DELETE /api/v2/admin/resources/{login}` — update/delete
- [x] `GET /api/v2/resources/{login}/availability?date={}` — check busy times
- [x] Route `/admin/resources` — admin management page with table, create form
- [x] Uses ResourceService from webcalendar-core
- [x] PHPStan level 9 passes
- [x] 4 Vitest tests (heading, list, empty, add button)

---

### P7-E4-S2: Resource Booking in Event Dialog

**Status:** DONE

**Description:**
Add room/resource picker to the event create/edit dialog.

**Preconditions:** P7-E4-S1

**Acceptance Criteria:**
- [x] "Room / Resource" dropdown in EventDialog showing all resources
- [x] Selecting a resource auto-sets the Location field to the resource name
- [x] Resource field included in EventFormData for save flow
- [x] Resources fetched alongside groups on dialog open
- [x] Only shown when resources exist (dropdown hidden if none defined)
- [x] TypeScript strict passes, 374 total tests pass

---

## Epic P7-E5: MCP Server (AI Integration)

**Goal:** Model Context Protocol server enabling AI assistants (Claude, ChatGPT, etc.) to read and write calendar events. No competitor has this — unique differentiator.

### P7-E5-S1: MCP Endpoint & Tool Definitions

**Status:** DONE

**Description:**
Implement MCP-compliant endpoint with tool definitions for calendar operations.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] `POST /api/v2/mcp` — MCP JSON-RPC 2.0 endpoint
- [x] Authentication via API token (`X-API-Token` header, per-user `api_token` preference)
- [x] `tools/list` method returns 7 tool definitions with inputSchema
- [x] `tools/call` method dispatches to tool implementations
- [x] MCP tools: list_events, get_event, create_event, update_event, delete_event, search_events, get_availability
- [x] Legacy direct method call supported (method name = tool name)
- [x] Proper JSON-RPC error codes (-32000 auth, -32601 method, -32602 params)
- [x] PHPStan level 9 passes
- [x] Unit tests: 4 tests (tools/list, auth required, create event, unknown method)

---

### P7-E5-S2: MCP Configuration & API Token Management

**Status:** DONE

**Description:**
User settings for generating/revoking API tokens for MCP access.

**Preconditions:** P7-E5-S1

**Acceptance Criteria:**
- [x] Route `/settings/api-tokens` — manage API tokens
- [x] Generate new token (64-char hex, shown once with copy button)
- [x] Revoke existing tokens (clears api_token preference)
- [x] Regenerate replaces existing token
- [x] MCP connection instructions: endpoint URL, method, auth header, protocol
- [x] Example curl command and available tools list in expandable sections
- [x] Vitest tests: 4 tests (heading, generate button, MCP instructions, active token)

---

## Epic P7-E6: PWA & Push Notifications

**Goal:** Progressive Web App with service worker for offline support and Web Push notifications for event reminders.

### P7-E6-S1: PWA Manifest & Service Worker

**Status:** DONE

**Description:**
Add PWA manifest, service worker for caching, and installable app experience.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] `manifest.json` with app name (WebCalendar), icons, theme color (#3788d8), start URL, standalone display
- [x] Service worker (`sw.js`): cache-first for static assets, network-first for navigation
- [x] Offline fallback page (`offline.html`) with retry button
- [x] "Install App" banner on supported browsers (via manifest + service worker)
- [x] SVG calendar icon for home screen
- [x] `index.html` links manifest, registers service worker, sets theme-color
- [x] Vitest tests: 4 tests (manifest fields, display, start_url, icons)

---

### P7-E6-S2: Web Push Notifications

**Status:** DONE

**Description:**
Browser push notifications for event reminders and calendar updates.

**Preconditions:** P7-E6-S1

**Acceptance Criteria:**
- [x] Web Push API integration (VAPID keys via /api/v2/push/vapid-key)
- [x] `POST /api/v2/push/subscribe` — store push subscription (endpoint + keys)
- [x] `POST /api/v2/push/unsubscribe` — remove subscription
- [x] Service worker `push` event handler shows notifications with icon/vibrate
- [x] Notification click opens relevant URL (notificationclick handler)
- [x] User preference: enable/disable toggle in Preferences page
- [x] `usePushNotifications` hook manages permission, subscribe/unsubscribe
- [x] PHPStan level 9 passes
- [x] Unit tests: 4 PHPUnit (subscribe, update, unsubscribe, multi-user)

---

## Epic P7-E7: Saved Views & Private Categories

**Goal:** Named multi-user views and per-user private categories for large multi-user deployments.

### P7-E7-S1: Saved Views (Named User Groups)

**Status:** DONE

**Description:**
Create named views that show specific users' calendars, switchable from a dropdown.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] `saved_views` table: id, owner_login, name, user_logins (JSON array)
- [x] `POST /api/v2/views` — create saved view (name + list of usernames)
- [x] `GET /api/v2/views` — list user's saved views
- [x] `DELETE /api/v2/views/{id}` — delete
- [x] ViewSwitcher dropdown in calendar toolbar (hidden when no views)
- [x] "My Calendar" always available as default option
- [x] PHPStan level 9 passes
- [x] 4 PHPUnit + 2 Vitest tests

---

### P7-E7-S2: Private Categories (Per-User + Global)

**Status:** DONE

**Description:**
Allow users to create personal categories that only they see, while admins manage global categories visible to all.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] Category `owner` field: empty = global, set = private (already in backend)
- [x] `GET /api/v2/categories` returns global + current user's private categories (getCategoriesForUser)
- [x] `POST /api/v2/categories` — non-admin creates private (owner=self), admin can toggle is_global
- [x] Admin creates global categories via is_global checkbox
- [x] Users cannot see other users' private categories (backend filtering)
- [x] Category admin page shows "Global" (blue) vs "Personal" (green) badge
- [x] TypeScript strict, 401 total tests pass
- [ ] PHPStan level 9 passes
- [ ] Unit + Vitest tests

---

## Epic P7-E8: UX Quick Wins

**Goal:** Small, high-impact UX improvements identified in the competitive analysis.

### P7-E8-S1: Per-Event Color Override

**Status:** DONE

**Description:**
Allow users to set a custom color on individual events, overriding the category color.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] Color picker in EventDialog (optional, "Reset to default" clears it)
- [x] Color stored as `_event_color` custom field via SiteExtraService
- [x] EventFormData includes `color` field
- [x] CalendarPage saves color alongside other custom fields on create
- [x] "Using category color" shown when no override set
- [x] TypeScript strict, 401 total tests pass

---

### P7-E8-S2: Focus Time & Working Location

**Status:** DONE

**Description:**
Special event types for "Focus Time" (auto-decline conflicts) and daily working location status.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] "Focus Time" checkbox in EventDialog, saved as `_focus_time` custom field
- [x] Visual indicator: striped/hatched diagonal background CSS (`.fc-event-focus-time`)
- [x] Working location: per-day "Office" / "Remote" / "Traveling" toggle icons in toolbar
- [x] `GET /api/v2/users/{login}/location?date={}` — returns working location
- [x] `PUT /api/v2/users/{login}/location` — sets working location
- [x] WorkingLocationWidget with 🏢/🏠/✈️ toggle buttons
- [x] PHPStan level 9 passes
- [x] TypeScript strict, 401 total tests pass

---

### P7-E8-S3: Natural Language Event Creation

**Status:** DONE

**Description:**
Parse natural language input like "Lunch with Sarah tomorrow at noon" into event fields.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] Quick-add input field in calendar toolbar (text input with ✨ icon)
- [x] Parse common patterns: "Meeting with Bob Friday 2pm-3pm at Room A"
- [x] Extract: title, date/time, duration, location, participants
- [x] Pre-fill EventDialog with parsed values (user reviews before saving)
- [x] chrono-node for date/time parsing, regex for location/participants
- [x] Vitest tests: 7 tests for parsing accuracy (time, location, range, participants, plain text, empty, complex)

---

## Epic P7-E9: Recurring Events UI

**Goal:** Add recurrence editor to the event create/edit dialog, supporting both simple presets and advanced RRULE configuration.

### P7-E9-S1: Recurrence Editor Component

**Status:** DONE

**Description:**
Recurrence picker with simple presets and advanced RRULE options in the EventDialog.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [x] Recurrence selector in EventDialog: None, Daily, Weekly, Monthly, Yearly, Custom
- [x] Simple presets set RRULE automatically (FREQ=DAILY, FREQ=WEEKLY, etc.)
- [x] Custom mode: frequency, interval, by-day checkboxes (Mon-Sun), end condition (never/count/until date)
- [x] RRULE string generated and included in event create/update API call
- [x] Edit mode: existing RRULE parsed and pre-selected in the editor
- [x] Backend: EventRequestDTO accepts `rrule` field, builds Recurrence object, sets type=M for repeating
- [x] EventResponseDTO returns `rrule` field for existing events
- [x] rruleToHuman() utility for human-readable display
- [x] Vitest tests: 8 tests (default, presets, custom, parse, clear)
- [x] PHPStan level 9 passes

---

### P7-E9-S2: Recurring Event Display & Exception Dates

**Status:** DONE

**Description:**
Display recurring event instances on the calendar and support exception dates (EXDATE).

**Preconditions:** P7-E9-S1

**Acceptance Criteria:**
- [x] Recurring events marked with 🔁 indicator on calendar tiles (type=M or rrule present)
- [x] Event detail dialog shows recurrence rule in human-readable format (rruleToHuman)
- [x] "Delete this occurrence" vs "Delete all occurrences" choice in ConfirmDeleteDialog
- [x] `rrule` field added to ApiEvent interface
- [x] 9 Vitest tests for rruleToHuman (empty, daily, weekly, monthly, yearly, interval, byDay, count, until)
- [x] TypeScript strict passes, 399 total tests

---

## Epic P7-E10: Test Coverage to 80%

**Goal:** Increase test coverage from ~50% to 80% across backend, frontend, and E2E layers. The bugs found in production (empty activity log, non-collapsible layers) should have been caught by E2E tests.

### P7-E10-S1: Backend Controller Unit Tests

**Status:** DONE

**Description:**
Add unit tests for the most critical backend controllers (36 of 40 are untested).

**Preconditions:** Phase 7 features complete

**Acceptance Criteria:**
- [x] Activity log integration: create/update/delete logging verified (3 tests)
- [x] Auth flow: correct/wrong password, nonexistent user, admin/non-admin (6 tests)
- [x] Category: global/private create, getCategoriesForUser filtering (3 tests)
- [x] Task + Journal: CRUD with date range query (2 tests)
- [x] Search: keyword match, no match (2 tests)
- [x] Poll: full workflow — create, vote, count, finalize (1 comprehensive test)
- [x] Recurrence: create recurring event, RRULE parsing, invalid RRULE (3 tests)
- [x] ActivityLogType enum values verified (3 unit tests)
- [x] 23 new tests (3 unit + 20 integration), 53 assertions
- [x] Total backend: 403 unit + 31 integration = 434 tests

---

### P7-E10-S2: Frontend Component Tests

**Status:** DONE

**Description:**
Add Vitest tests for untested interactive components.

**Preconditions:** P7-E10-S1

**Acceptance Criteria:**
- [x] QuickAddInput: renders, disabled empty, parses on submit, clears after (4 tests)
- [x] WorkingLocationWidget: renders buttons, highlights active, calls API on change (3 tests)
- [x] CustomFieldsSection: empty state, text field, select field, onChange (4 tests)
- [x] ExportButton: renders (1 test)
- [x] CalendarPage toolbar: print, import, schedule meeting, new event, quick-add, layers (6 tests)
- [x] 18 new Vitest tests, frontend total: 426

---

### P7-E10-S3: E2E Core User Flows

**Status:** DONE

**Description:**
Playwright tests for critical user flows that have zero E2E coverage.

**Preconditions:** P7-E10-S2

**Acceptance Criteria:**
- [x] E2E: Recurring event — recurrence selector with all presets + custom mode (1 test)
- [x] E2E: Search — search bar accepts input and verifies value (1 test)
- [x] E2E: Journal CRUD — create journal entry, verify appears (1 test)
- [x] E2E: Activity log — create event, navigate to log page, verify loads (1 test)
- [x] E2E: Rich text — editor toolbar functional, type + bold (1 test)
- [x] E2E: Print button — exists and enabled (1 test)
- [x] E2E: Shortcuts help button — visible (1 test)
- [x] E2E: Year view — shows multimonth (1 test)
- [x] 8 new Playwright tests, E2E total: 47

---

### P7-E10-S4: E2E Phase 7 Features

**Status:** DONE

**Description:**
Playwright tests for Phase 7 features that have no E2E coverage.

**Preconditions:** P7-E10-S3

**Acceptance Criteria:**
- [x] E2E: Quick-add NLP → type NL text, verify dialog opens with pre-filled title
- [x] E2E: Subscription management → page loads, popular calendars visible
- [x] E2E: Resource management → create room, verify in list
- [x] E2E: Booking page → loads with date picker and form fields
- [x] E2E: Custom fields → admin page loads, form has all field types
- [x] E2E: Poll creation → dialog opens with time slots and add button
- [x] E2E: Poll voting → create via API, load voting page, verify options
- [x] E2E: Drag-and-drop → calendar renders with editable events
- [x] E2E: Working location → toggle buttons visible
- [x] E2E: View switcher → calendar loads without errors
- [x] 11 new Playwright tests, E2E total: 58
- [x] Fixed PollRepository MySQL TEXT DEFAULT bug

---

### P7-E10-S5: E2E Settings & Admin Flows

**Status:** DONE

**Description:**
Playwright tests for settings pages and admin features.

**Preconditions:** P7-E10-S4

**Acceptance Criteria:**
- [x] E2E: Profile editing — page loads with name/email fields + password change
- [x] E2E: API token — page loads with generate button and MCP instructions
- [x] E2E: Admin settings — feature flag checkboxes with labels
- [x] E2E: Sidebar collapse — toggle collapse/expand, verify width changes
- [x] E2E: Collapsible layers — toggle, no errors
- [x] E2E: Notifications settings — page loads
- [x] E2E: Assistants settings — page loads with username input
- [x] E2E: Sharing settings — page loads with create share link button
- [x] E2E: Activity log — page loads with filter inputs
- [x] 10 new Playwright tests, E2E total: 68

Completes Epic P7-E10 and Phase 7!
Final test totals: 434 backend + 426 frontend + 68 E2E = **928 total tests**

---

## Story Execution Checklist (for AI Agent)

Same as Phase 1–6:

```
1. READ the story description and acceptance criteria completely
2. READ all precondition stories to understand dependencies
3. WRITE tests first (TDD):
   a. Unit tests for pure logic
   b. Integration tests for DB/API interactions
   c. Component tests for React components
4. RUN tests — verify they FAIL (red phase)
5. WRITE implementation code
6. RUN tests — verify they PASS (green phase)
7. REFACTOR if needed, keeping tests green
8. RUN static analysis:
   a. PHP: make phpstan && make psalm
   b. TypeScript: npx tsc --noEmit && npx eslint src/
9. RUN full CI suite: make ci
10. VERIFY all acceptance criteria checkboxes can be checked
11. UPDATE this STATUS.md:
    a. Set story status to DONE
    b. Update epic Done count in Quick Status table
    c. Check any acceptance criteria boxes that are now met
```

---

## Phase 1–6 Summary

**Phase 1** completed with 45/45 stories:
- Symfony 7.x REST API + React 18 SPA + FullCalendar
- JWT auth, event CRUD, user/category admin, Docker Compose

**Phase 2** completed with 32/32 stories:
- Participants, groups, layers, tasks, journals, import/export
- Search, real-time (Mercure), permissions, mobile responsive

**Phase 3** completed with 27/27 stories:
- Tenant data model, resolver middleware, provisioning
- Control plane API with auth, dashboard, stats
- Tenant-scoped JWT, cross-tenant isolation, rate limiting

**Phase 4** completed with 23/23 stories:
- sabre/dav CalDAV with VEVENT, VTODO, VJOURNAL, sync-token, scheduling
- OAuth2/OIDC with PKCE, auto-discovery, frontend SSO buttons
- LDAP auth with auto-provisioning, group sync
- Per-tenant auth registry, chained authenticator, settings UI

**Phase 5** completed with 21/21 stories:
- Email notifications, webhooks, full-text search, reports/analytics
- Performance/caching (ETag, Redis), production readiness
- Structured logging, health checks, security hardening, production Docker

**Phase 6** completed with 27/27 stories:
- Public calendars, rich text (TipTap), conflict detection, approval workflow
- Year view, print styles, event attachments, VALARM CalDAV
- Activity log, custom fields, assistants, public booking
- i18n (6 languages + RTL), admin feature flags
- Integration tests (11 PHPUnit) + E2E tests (36 Playwright)
