# WCTNG — Phase 3 Development Plan & Status

> **Last Updated:** 2026-03-16
> **Phase:** 3 — Hosted / Multi-Tenant
> **Goal:** Subdomain-based tenant isolation, provisioning, control plane API, admin dashboard
> **Methodology:** TDD (write tests first, then implementation)
> **Developed by:** AI Agent
> **Phase 1 Archive:** See `STATUS-PHASE1-ARCHIVE.md`
> **Phase 2 Archive:** See `STATUS-PHASE2-ARCHIVE.md`

---

## Quick Status

| Epic | Title | Stories | Done | Status |
|------|-------|---------|------|--------|
| P3-E1 | Tenant Data Model | 3 | 3 | DONE |
| P3-E2 | Tenant Resolver Middleware | 3 | 3 | DONE |
| P3-E3 | Tenant Provisioning | 4 | 4 | DONE |
| P3-E4 | Control Plane API | 4 | 4 | DONE |
| P3-E5 | Tenant Admin Dashboard | 4 | 4 | DONE |
| P3-E6 | Tenant-Aware Auth | 3 | 0 | NOT STARTED |
| P3-E7 | Tenant Isolation & Security | 3 | 0 | NOT STARTED |
| P3-E8 | Standalone ↔ Hosted Mode | 3 | 0 | NOT STARTED |
| **Total** | | **27** | **18** | |

---

## Dependency Graph

```
P3-E1 (Tenant Data Model) ──► P3-E2 (Resolver Middleware)
P3-E1 ──► P3-E3 (Provisioning)
P3-E2 ──► P3-E6 (Tenant-Aware Auth)
P3-E2 ──► P3-E7 (Isolation & Security)
P3-E3 ──► P3-E4 (Control Plane API)
P3-E4 ──► P3-E5 (Admin Dashboard)
P3-E1 ──► P3-E8 (Standalone ↔ Hosted)
```

**Critical path:** P3-E1 → P3-E2 → P3-E3 → P3-E4 → P3-E5
**Independent after E1:** P3-E8 can be done any time after E1

---

## Global Standards

Same as Phase 1 & 2:
- PHP 8.2+, PHPStan level 9, Psalm errorLevel 1, PHPUnit 10
- React 18, TypeScript strict, ESLint, Vitest, Playwright
- TDD: write tests first, then implementation
- Docker-based development on ports 47180/47106/47173/47181

---

## Epic P3-E1: Tenant Data Model

**Goal:** Define the tenant registry schema, entity, and repository for tracking tenants and their database connections.

### P3-E1-S1: Tenant Registry Schema & Entity

**Status:** DONE

**Description:**
Create the `tenants` table in the control database and a Symfony entity to represent a tenant. The control database is the default connection; tenant databases are separate.

**Preconditions:** Phase 2 complete

**Acceptance Criteria:**
- [x] Schema SQL creates `tenants` table with: `id`, `slug` (unique), `name`, `db_host`, `db_name`, `db_user`, `db_password` (encrypted), `plan`, `status` (active/suspended/pending), `created_at`, `updated_at`
- [x] `Tenant` entity with getters, validation (slug format: lowercase alphanumeric + hyphens, 3-50 chars)
- [x] `TenantRepository` with `findBySlug()`, `findAll()`, `save()`, `delete()`
- [x] Reserved slugs list (api, www, admin, app, mail, etc.) enforced on creation
- [x] PHPStan level 9 passes
- [x] Unit tests for entity validation and repository

---

### P3-E1-S2: Tenant Database Configuration Service

**Status:** DONE

**Description:**
Service that creates PDO connections to tenant databases dynamically based on tenant registry data.

**Preconditions:** P3-E1-S1

**Acceptance Criteria:**
- [x] `TenantDatabaseManager` service creates PDO connections from tenant credentials
- [x] Connection pooling / caching within a single request lifecycle
- [x] Credentials decrypted at connection time (using APP_SECRET as encryption key)
- [x] Graceful error handling when tenant DB is unreachable (503 response)
- [x] PHPStan level 9 passes
- [x] Unit tests with mock PDO

---

### P3-E1-S3: Tenant Context Service

**Status:** DONE

**Description:**
Request-scoped service that holds the current tenant context, making it available throughout the request lifecycle.

**Preconditions:** P3-E1-S1

**Acceptance Criteria:**
- [x] `TenantContext` service holds current `Tenant` entity (or null for standalone mode)
- [x] `setTenant()` / `getTenant()` / `isMultiTenant()` methods
- [x] Registered as a scoped service (reset per request)
- [x] `CoreServiceFactory` uses tenant PDO when `TenantContext` has a tenant, default PDO otherwise
- [x] PHPStan level 9 passes
- [x] Unit tests

---

## Epic P3-E2: Tenant Resolver Middleware

**Goal:** Automatically resolve the current tenant from the incoming request (subdomain, header, or JWT claim).

### P3-E2-S1: Subdomain Resolver

**Status:** DONE

**Description:**
Symfony event listener that resolves the tenant from the request subdomain (e.g., `acme.webcalendar.com` → slug `acme`).

**Preconditions:** P3-E1-S3

**Acceptance Criteria:**
- [x] `TenantResolverListener` runs on `kernel.request` with high priority
- [x] Extracts subdomain from `Host` header: `{slug}.{base_domain}`
- [x] Base domain configurable via `TENANT_BASE_DOMAIN` env var
- [x] Looks up tenant by slug, sets `TenantContext`, configures tenant PDO
- [x] Returns 404 JSON response for unknown tenant slugs
- [x] Skips resolution for standalone mode (when `APP_MODE=standalone`)
- [x] PHPStan level 9 passes
- [x] Functional tests with mock subdomains

---

### P3-E2-S2: Header & JWT Resolver

**Status:** DONE

**Description:**
Alternative tenant resolution via `X-Tenant-Id` header or JWT `tenant` claim, for API clients that can't use subdomains.

**Preconditions:** P3-E2-S1

**Acceptance Criteria:**
- [x] `X-Tenant-Id` header resolution (fallback when subdomain is not present)
- [x] JWT `tenant` claim resolution (extracted from authenticated token)
- [x] Resolution priority: subdomain > header > JWT claim > standalone default
- [x] Tenant mismatch between JWT claim and subdomain returns 403
- [x] PHPStan level 9 passes
- [x] Functional tests

---

### P3-E2-S3: Tenant Resolver Integration Tests

**Status:** DONE

**Description:**
End-to-end tests verifying the full tenant resolution chain across all resolution methods.

**Preconditions:** P3-E2-S2

**Acceptance Criteria:**
- [x] Test: subdomain resolution creates correct PDO and returns tenant-specific data
- [x] Test: header resolution works for API-only clients
- [x] Test: standalone mode (no tenant) uses default database
- [x] Test: invalid/suspended tenant returns appropriate error
- [x] Test: cross-tenant data isolation (tenant A cannot see tenant B's data)
- [x] All tests pass in under 30 seconds

---

## Epic P3-E3: Tenant Provisioning

**Goal:** Automate creation of new tenant databases, schema setup, and initial admin user.

### P3-E3-S1: Schema Deployment Service

**Status:** DONE

**Description:**
Service that creates a new database and deploys the webcalendar-core schema for a new tenant.

**Preconditions:** P3-E1-S2

**Acceptance Criteria:**
- [x] `TenantProvisioner` service: `provision(slug, name, adminEmail)` → creates DB, runs schema, creates admin user
- [x] Uses webcalendar-core's SQL schema file for table creation
- [x] Creates initial admin user with generated password
- [x] Returns provisioning result with credentials and connection details
- [x] Handles DB creation errors gracefully (duplicate name, permissions, etc.)
- [x] PHPStan level 9 passes
- [x] Integration tests (creates real test DB, verifies schema, tears down)

---

### P3-E3-S2: Tenant Provisioning CLI Command

**Status:** DONE

**Description:**
Symfony console command for manually provisioning tenants (useful for ops and testing).

**Preconditions:** P3-E3-S1

**Acceptance Criteria:**
- [x] `php bin/console tenant:create {slug} {name} --admin-email={email}` provisions a new tenant
- [x] `php bin/console tenant:list` shows all tenants with status
- [x] `php bin/console tenant:suspend {slug}` suspends a tenant (sets status, blocks access)
- [x] `php bin/console tenant:delete {slug} --force` deletes tenant DB and registry entry
- [x] Output shows provisioning details (URL, admin credentials)
- [x] PHPStan level 9 passes

---

### P3-E3-S3: Schema Migration Service

**Status:** DONE

**Description:**
Service to run schema migrations across all tenant databases when webcalendar-core is updated.

**Preconditions:** P3-E3-S1

**Acceptance Criteria:**
- [x] `TenantMigrator` service iterates all active tenants and applies pending migrations
- [x] `php bin/console tenant:migrate` runs migrations on all tenant DBs
- [x] `php bin/console tenant:migrate --tenant={slug}` runs on a single tenant
- [x] Reports success/failure per tenant with summary
- [x] Handles connection failures gracefully (skips, reports, continues)
- [x] PHPStan level 9 passes

---

### P3-E3-S4: Tenant Provisioning E2E Tests

**Status:** DONE

**Description:**
Full lifecycle tests: create tenant, access via subdomain, verify data isolation, delete.

**Preconditions:** P3-E3-S2

**Acceptance Criteria:**
- [x] Test: provision tenant → login via subdomain → create event → verify event exists only in tenant DB
- [x] Test: two tenants provisioned, each has isolated data
- [x] Test: suspend tenant → API returns 403
- [x] Test: delete tenant → DB removed, slug available for reuse
- [x] All tests pass

---

## Epic P3-E4: Control Plane API

**Goal:** REST API for managing tenants programmatically (used by admin dashboard and ops tools).

### P3-E4-S1: Control Plane Auth

**Status:** DONE

**Description:**
Separate authentication for the control plane (super-admin level, not per-tenant).

**Preconditions:** P3-E2-S1

**Acceptance Criteria:**
- [x] Control plane routes under `/control/v1/*` with separate JWT auth
- [x] Super-admin user stored in control database (not tenant DB)
- [x] `POST /control/v1/auth/login` returns control plane JWT
- [x] Control plane JWT includes `role: "super_admin"` claim
- [x] Regular tenant JWTs cannot access control plane routes
- [x] PHPStan level 9 passes
- [x] Functional tests

---

### P3-E4-S2: Tenant CRUD Endpoints

**Status:** DONE

**Description:**
REST endpoints for creating, reading, updating, and deleting tenants.

**Preconditions:** P3-E4-S1, P3-E3-S1

**Acceptance Criteria:**
- [x] `GET /control/v1/tenants` — list all tenants with status, plan, created_at
- [x] `POST /control/v1/tenants` — provision new tenant (body: `{slug, name, admin_email, plan}`)
- [x] `GET /control/v1/tenants/{slug}` — get tenant details including user count, event count
- [x] `PUT /control/v1/tenants/{slug}` — update tenant (name, plan, status)
- [x] `DELETE /control/v1/tenants/{slug}` — deprovision tenant (requires `?confirm=true`)
- [x] Provisioning is async-safe (returns 202 if DB creation takes time)
- [x] PHPStan level 9 passes
- [x] Functional tests for all endpoints

---

### P3-E4-S3: Tenant Statistics Endpoints

**Status:** DONE

**Description:**
Endpoints for monitoring tenant health and usage metrics.

**Preconditions:** P3-E4-S2

**Acceptance Criteria:**
- [x] `GET /control/v1/tenants/{slug}/stats` — returns user count, event count, storage size, last activity
- [x] `GET /control/v1/stats/summary` — aggregate stats across all tenants
- [x] Stats queries run against tenant DBs efficiently (cached for 5 minutes)
- [x] PHPStan level 9 passes
- [x] Functional tests

---

### P3-E4-S4: Control Plane Webhook Notifications

**Status:** DONE

**Description:**
Webhook notifications for tenant lifecycle events (provisioned, suspended, deleted).

**Preconditions:** P3-E4-S2

**Acceptance Criteria:**
- [x] `CONTROL_WEBHOOK_URL` env var configures webhook endpoint
- [x] `tenant.provisioned` webhook sent after successful provisioning
- [x] `tenant.suspended` / `tenant.activated` webhooks for status changes
- [x] `tenant.deleted` webhook sent after deprovisioning
- [x] Webhook payload includes tenant slug, name, timestamp, event type
- [x] Fire-and-forget (webhook failure doesn't block operations)
- [x] PHPStan level 9 passes

---

## Epic P3-E5: Tenant Admin Dashboard

**Goal:** Web UI for super-admins to manage tenants, view stats, and handle provisioning.

### P3-E5-S1: Dashboard Layout & Auth

**Status:** DONE

**Description:**
Separate React app (or route group) for the control plane dashboard with super-admin authentication.

**Preconditions:** P3-E4-S1

**Acceptance Criteria:**
- [x] Route group `/control/*` with separate login page
- [x] Super-admin login via control plane auth API
- [x] Dashboard layout with sidebar: Tenants, Stats, Settings
- [x] Protected routes (redirects to control login if not authenticated)
- [x] Vitest tests for auth flow

---

### P3-E5-S2: Tenant List & Management Page

**Status:** DONE

**Description:**
Page showing all tenants with status, actions, and search/filter.

**Preconditions:** P3-E5-S1, P3-E4-S2

**Acceptance Criteria:**
- [x] Table of tenants: slug, name, plan, status, user count, created date
- [x] Status badges (active=green, suspended=yellow, pending=gray)
- [x] Search/filter by name or slug
- [x] Quick actions: suspend/activate toggle, delete (with confirmation)
- [x] Pagination for large tenant lists
- [x] Vitest tests

---

### P3-E5-S3: Tenant Provisioning Wizard

**Status:** DONE

**Description:**
Multi-step form for provisioning a new tenant with validation and progress feedback.

**Preconditions:** P3-E5-S2

**Acceptance Criteria:**
- [x] Step 1: Slug + name (validates slug format, checks availability in real-time)
- [x] Step 2: Admin email + plan selection
- [x] Step 3: Review & confirm
- [x] Progress indicator during provisioning (polling for status)
- [x] Success screen with tenant URL and admin credentials
- [x] Error handling with retry option
- [x] Vitest tests

---

### P3-E5-S4: Tenant Detail & Stats Page

**Status:** DONE

**Description:**
Detail page for a single tenant showing configuration, stats, and management actions.

**Preconditions:** P3-E5-S2, P3-E4-S3

**Acceptance Criteria:**
- [x] Shows tenant details: slug, name, plan, status, DB host, created date
- [x] Usage stats: user count, event count, storage, last activity
- [x] Actions: edit name/plan, suspend/activate, reset admin password, delete
- [x] Audit log of tenant lifecycle events (provisioned, suspended, etc.)
- [x] Vitest tests

---

## Epic P3-E6: Tenant-Aware Auth

**Goal:** Ensure authentication and JWT tokens are tenant-scoped.

### P3-E6-S1: Tenant-Scoped JWT Tokens

**Status:** NOT STARTED

**Description:**
Include tenant slug in JWT tokens so the API can verify tenant context from the token.

**Preconditions:** P3-E2-S1

**Acceptance Criteria:**
- [ ] JWT tokens include `tenant` claim with the tenant slug
- [ ] `WebCalendarUserProvider` loads users from the tenant's database (not the control DB)
- [ ] Token validation checks that the `tenant` claim matches the resolved tenant context
- [ ] Mismatched tenant (token says "acme" but request goes to "globex") returns 403
- [ ] Standalone mode: no `tenant` claim in JWT, works as before
- [ ] PHPStan level 9 passes
- [ ] Functional tests

---

### P3-E6-S2: Tenant-Scoped Login

**Status:** NOT STARTED

**Description:**
Login endpoint resolves the user from the correct tenant database.

**Preconditions:** P3-E6-S1

**Acceptance Criteria:**
- [ ] `POST /api/v2/auth/login` on tenant subdomain authenticates against tenant DB
- [ ] Same username can exist in different tenants (isolated user stores)
- [ ] Login response includes tenant slug for frontend context
- [ ] Failed login returns tenant-appropriate error (doesn't leak other tenant info)
- [ ] PHPStan level 9 passes
- [ ] Functional tests with two tenants, same username, different passwords

---

### P3-E6-S3: Frontend Tenant Context

**Status:** NOT STARTED

**Description:**
React app detects tenant from subdomain and stores tenant context for API calls.

**Preconditions:** P3-E6-S2

**Acceptance Criteria:**
- [ ] `useTenant()` hook extracts tenant slug from `window.location.hostname`
- [ ] Tenant slug displayed in header/sidebar for tenant branding
- [ ] Login page shows tenant name (fetched from `/api/v2/tenant/info` public endpoint)
- [ ] Standalone mode: no tenant context, works as before
- [ ] Vitest tests

---

## Epic P3-E7: Tenant Isolation & Security

**Goal:** Ensure complete data isolation between tenants and prevent cross-tenant access.

### P3-E7-S1: Cross-Tenant Access Prevention

**Status:** NOT STARTED

**Description:**
Security middleware that prevents any cross-tenant data access at the API level.

**Preconditions:** P3-E2-S1

**Acceptance Criteria:**
- [ ] All API controllers use tenant-scoped PDO (never the control DB for tenant data)
- [ ] Mercure topics are tenant-scoped: `/tenants/{slug}/calendars/events`
- [ ] Layers can only reference users within the same tenant
- [ ] Group membership is tenant-scoped
- [ ] Search is tenant-scoped
- [ ] PHPStan level 9 passes
- [ ] Security test: authenticate as tenant A, attempt to access tenant B's events → 403

---

### P3-E7-S2: Tenant Rate Limiting

**Status:** NOT STARTED

**Description:**
Per-tenant rate limiting to prevent a single tenant from consuming excessive resources.

**Preconditions:** P3-E7-S1

**Acceptance Criteria:**
- [ ] Rate limiter keyed by tenant slug (not just IP)
- [ ] Configurable limits per plan (e.g., free=100 req/min, pro=1000 req/min)
- [ ] Rate limit headers in response: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
- [ ] 429 Too Many Requests response when exceeded
- [ ] PHPStan level 9 passes
- [ ] Functional tests

---

### P3-E7-S3: Tenant Data Export & Portability

**Status:** NOT STARTED

**Description:**
Allow tenants to export all their data for portability and compliance.

**Preconditions:** P3-E7-S1

**Acceptance Criteria:**
- [ ] `GET /api/v2/tenant/export` — downloads full tenant data as ZIP (ICS + JSON)
- [ ] Export includes: all events, tasks, journals, users, categories, groups
- [ ] Export is rate-limited (max 1 per hour per tenant)
- [ ] Control plane can trigger export for any tenant: `POST /control/v1/tenants/{slug}/export`
- [ ] PHPStan level 9 passes
- [ ] Functional tests

---

## Epic P3-E8: Standalone ↔ Hosted Mode

**Goal:** Ensure the application works seamlessly in both standalone (single-tenant) and hosted (multi-tenant) modes with a single codebase.

### P3-E8-S1: Mode Detection & Configuration

**Status:** NOT STARTED

**Description:**
Configuration system that detects and switches between standalone and hosted modes.

**Preconditions:** P3-E1-S3

**Acceptance Criteria:**
- [ ] `APP_MODE` env var: `standalone` (default) or `hosted`
- [ ] Standalone mode: all tenant resolution skipped, uses `DATABASE_URL` directly
- [ ] Hosted mode: tenant resolution active, control plane enabled
- [ ] `GET /api/v2/health` includes `mode` field in response
- [ ] All existing Phase 1/2 functionality works unchanged in standalone mode
- [ ] PHPStan level 9 passes
- [ ] Functional tests for both modes

---

### P3-E8-S2: Standalone Setup Wizard

**Status:** NOT STARTED

**Description:**
Browser-based setup wizard for standalone installations (replaces CLI-only setup).

**Preconditions:** P3-E8-S1

**Acceptance Criteria:**
- [ ] `/setup` route shown when no admin user exists (first-run detection)
- [ ] Step 1: Database connection test (auto-detects from `DATABASE_URL`)
- [ ] Step 2: Create admin account (username, password, email)
- [ ] Step 3: Basic settings (timezone, site name)
- [ ] Runs schema installation via `InstallCommand` internally
- [ ] Redirects to login after completion
- [ ] Vitest tests

---

### P3-E8-S3: Docker Compose Hosted Mode

**Status:** NOT STARTED

**Description:**
Docker Compose configuration for running in hosted/multi-tenant mode with control plane.

**Preconditions:** P3-E8-S1, P3-E4-S1

**Acceptance Criteria:**
- [ ] `docker-compose.hosted.yml` extends base compose with hosted-mode settings
- [ ] Control database container (separate from tenant DBs)
- [ ] Wildcard subdomain support via nginx config (`*.webcalendar.local`)
- [ ] Control plane accessible at `admin.webcalendar.local`
- [ ] Documentation: local development setup with `/etc/hosts` entries
- [ ] Health checks for all services

---

## Story Execution Checklist (for AI Agent)

Same as Phase 1 & 2:

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

## Phase 1 & 2 Summary

**Phase 1** completed with 45/45 stories:
- Symfony 7.x REST API + React 18 SPA + FullCalendar
- JWT auth, event CRUD, user/category admin, Docker Compose

**Phase 2** completed with 32/32 stories:
- Participants, groups, layers, tasks, journals, import/export
- Search, real-time (Mercure), permissions, mobile responsive
- 220 Vitest tests + 25 Playwright E2E tests
- 210 PHP tests, PHPStan level 9, Psalm errorLevel 1
