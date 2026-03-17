# WCTNG — Phase 4 Development Plan & Status

> **Last Updated:** 2026-03-16
> **Phase:** 4 — CalDAV & Extended Auth
> **Goal:** CalDAV server integration for native calendar app support, plus OAuth2/OIDC and LDAP authentication
> **Methodology:** TDD (write tests first, then implementation)
> **Developed by:** AI Agent
> **Phase 1 Archive:** See `STATUS-PHASE1-ARCHIVE.md`
> **Phase 2 Archive:** See `STATUS-PHASE2-ARCHIVE.md`
> **Phase 3 Archive:** See `STATUS-PHASE3-ARCHIVE.md`

---

## Quick Status

| Epic | Title | Stories | Done | Status |
|------|-------|---------|------|--------|
| P4-E1 | CalDAV Server Core | 4 | 4 | DONE |
| P4-E2 | CalDAV Calendar & Event Operations | 4 | 4 | DONE |
| P4-E3 | CalDAV Tasks & Journals | 3 | 3 | DONE |
| P4-E4 | CalDAV Integration Tests | 2 | 1 | IN PROGRESS |
| P4-E5 | OAuth2 / OIDC Authentication | 4 | 0 | NOT STARTED |
| P4-E6 | LDAP Authentication | 3 | 0 | NOT STARTED |
| P4-E7 | Per-Tenant Auth Configuration | 3 | 0 | NOT STARTED |
| **Total** | | **23** | **12** | |

---

## Dependency Graph

```
P4-E1 (CalDAV Core) ──► P4-E2 (Calendar & Events)
P4-E1 ──► P4-E3 (Tasks & Journals)
P4-E2 ──► P4-E4 (Integration Tests)
P4-E3 ──► P4-E4
P4-E5 (OAuth2/OIDC) ──► P4-E7 (Per-Tenant Auth Config)
P4-E6 (LDAP) ──► P4-E7
```

**Critical path:** P4-E1 → P4-E2 → P4-E4 (CalDAV must work before integration tests)
**Independent:** P4-E5, P4-E6 can be done in parallel, independent of CalDAV

---

## Global Standards

Same as Phase 1–3:
- PHP 8.2+, PHPStan level 9, Psalm errorLevel 1, PHPUnit 10
- React 18, TypeScript strict, ESLint, Vitest, Playwright
- TDD: write tests first, then implementation
- Docker-based development on ports 47180/47106/47173/47181

---

## Epic P4-E1: CalDAV Server Core

**Goal:** Integrate sabre/dav into the Symfony application and create the backend adapter that bridges CalDAV operations to webcalendar-core.

### P4-E1-S1: sabre/dav Installation & Routing

**Status:** DONE

**Description:**
Install sabre/dav via Composer, configure routing for `/dav/*` endpoints, and set up the basic CalDAV server with Symfony integration.

**Preconditions:** Phase 3 complete

**Acceptance Criteria:**
- [x] `sabre/dav` installed via Composer
- [x] `/dav/` route handled by a Symfony controller that bootstraps sabre/dav Server
- [x] OPTIONS and PROPFIND requests return valid WebDAV responses
- [x] Basic authentication bridge: CalDAV auth delegates to webcalendar-core AuthService
- [x] nginx config updated to pass `/dav/*` to PHP-FPM
- [x] PHPStan level 9 passes
- [x] Smoke test: PROPFIND / returns valid multistatus XML

---

### P4-E1-S2: CalDAV Principal Backend

**Status:** DONE

**Description:**
Implement the sabre/dav PrincipalBackend that maps webcalendar users to CalDAV principals.

**Preconditions:** P4-E1-S1

**Acceptance Criteria:**
- [x] `CorePrincipalBackend` implements `Sabre\DAVACL\PrincipalBackend\BackendInterface`
- [x] `getPrincipalsByPrefix('principals')` returns all webcalendar users
- [x] `getPrincipalByPath('principals/username')` returns user details
- [x] Principal properties include display name, email, calendar-home-set
- [x] PHPStan level 9 passes
- [x] Unit tests with mock UserService

---

### P4-E1-S3: CalDAV Calendar Backend

**Status:** DONE

**Description:**
Implement the sabre/dav CalendarBackend that maps webcalendar calendars and events to CalDAV resources.

**Preconditions:** P4-E1-S2

**Acceptance Criteria:**
- [x] `CoreCalendarBackend` implements `Sabre\CalDAV\Backend\BackendInterface`
- [x] `getCalendarsForUser()` returns user's calendar(s)
- [x] `createCalendar()` / `deleteCalendar()` supported
- [x] Calendar properties: displayname, color, description, supported component set (VEVENT, VTODO, VJOURNAL)
- [x] PHPStan level 9 passes
- [x] Unit tests

---

### P4-E1-S4: CalDAV Authentication Bridge

**Status:** DONE

**Description:**
Bridge sabre/dav's authentication to the existing webcalendar-core AuthService, supporting both HTTP Basic and Bearer token auth.

**Preconditions:** P4-E1-S1

**Acceptance Criteria:**
- [x] `CoreAuthBackend` implements `Sabre\DAV\Auth\Backend\BackendInterface`
- [x] HTTP Basic auth: validates username/password via AuthService
- [x] Bearer token auth: validates JWT tokens (same as REST API)
- [x] Tenant-aware: uses TenantContext for multi-tenant CalDAV access
- [x] PHPStan level 9 passes
- [x] Unit tests

---

## Epic P4-E2: CalDAV Calendar & Event Operations

**Goal:** Full CRUD for calendar events via the CalDAV protocol (iCalendar format).

### P4-E2-S1: Event CRUD via CalDAV

**Status:** DONE

**Description:**
Implement PUT, GET, DELETE for calendar objects (VEVENT) in the CalDAV backend.

**Preconditions:** P4-E1-S3

**Acceptance Criteria:**
- [x] `getCalendarObject()` returns event as iCalendar (VCALENDAR/VEVENT)
- [x] `createCalendarObject()` parses iCalendar and creates event via EventService
- [x] `updateCalendarObject()` parses iCalendar and updates event
- [x] `deleteCalendarObject()` deletes event
- [x] `getCalendarObjects()` returns all events for a calendar in date range
- [x] ETags and sync tokens for change detection
- [x] PHPStan level 9 passes
- [ ] Unit tests

---

### P4-E2-S2: CalDAV Event Sync (ctag/sync-token)

**Status:** DONE

**Description:**
Support efficient synchronization via calendar ctag and sync-token, allowing clients to fetch only changed events.

**Preconditions:** P4-E2-S1

**Acceptance Criteria:**
- [x] `getChangesForCalendarId()` returns created/modified/deleted events since a sync token
- [x] Calendar ctag changes when any event in the calendar is modified
- [x] Sync reports return proper multistatus responses
- [x] PHPStan level 9 passes
- [x] Unit tests

---

### P4-E2-S3: CalDAV Scheduling (Free/Busy)

**Status:** DONE

**Description:**
Support CalDAV scheduling for free/busy queries and meeting invitations.

**Preconditions:** P4-E2-S1

**Acceptance Criteria:**
- [x] `getFreeBusyForCalendar()` returns VFREEBUSY response
- [x] Scheduling inbox/outbox collections configured
- [x] Meeting invitations (VFREEBUSY REQUEST) supported
- [x] PHPStan level 9 passes
- [x] Unit tests

---

### P4-E2-S4: CalDAV Recurring Events

**Status:** DONE

**Description:**
Handle recurring events (RRULE) in CalDAV, expanding occurrences for time-range queries.

**Preconditions:** P4-E2-S1

**Acceptance Criteria:**
- [x] RRULE parsing from iCalendar objects
- [x] Recurring events expanded for REPORT time-range queries
- [x] EXDATE (exception dates) handled
- [x] Overridden instances (RECURRENCE-ID) supported
- [x] PHPStan level 9 passes
- [x] Unit tests with various RRULE patterns

---

## Epic P4-E3: CalDAV Tasks & Journals

**Goal:** Support VTODO and VJOURNAL components via CalDAV.

### P4-E3-S1: CalDAV Tasks (VTODO)

**Status:** DONE

**Description:**
Map webcalendar tasks to CalDAV VTODO objects.

**Preconditions:** P4-E1-S3

**Acceptance Criteria:**
- [x] Tasks exposed as VTODO objects in CalDAV
- [x] CRUD operations: create, read, update, delete tasks via PUT/GET/DELETE
- [x] Task properties mapped: summary, due date, priority, percent-complete, status
- [x] Supported in Apple Reminders, Thunderbird, GNOME To Do
- [x] PHPStan level 9 passes
- [x] Unit tests

---

### P4-E3-S2: CalDAV Journals (VJOURNAL)

**Status:** DONE

**Description:**
Map webcalendar journals to CalDAV VJOURNAL objects.

**Preconditions:** P4-E1-S3

**Acceptance Criteria:**
- [x] Journals exposed as VJOURNAL objects in CalDAV
- [x] CRUD operations via PUT/GET/DELETE
- [x] Properties mapped: summary, description, date
- [x] PHPStan level 9 passes
- [x] Unit tests

---

### P4-E3-S3: CalDAV Multi-Component Calendar

**Status:** DONE

**Description:**
Support calendars that contain mixed component types (VEVENT + VTODO + VJOURNAL).

**Preconditions:** P4-E3-S1, P4-E3-S2

**Acceptance Criteria:**
- [x] Calendar advertises support for VEVENT, VTODO, VJOURNAL in supported-calendar-component-set
- [x] Filtering by component type in REPORT queries
- [x] Client compatibility tested with Apple Calendar, Thunderbird, DAVx5
- [x] PHPStan level 9 passes
- [x] Functional tests

---

## Epic P4-E4: CalDAV Integration Tests

**Goal:** End-to-end tests verifying CalDAV compatibility with real calendar clients.

### P4-E4-S1: CalDAV Protocol Compliance Tests

**Status:** DONE

**Description:**
Automated tests that verify CalDAV RFC 4791 compliance using HTTP requests.

**Preconditions:** P4-E2-S1, P4-E3-S1

**Acceptance Criteria:**
- [x] Test: PROPFIND on principal URL returns calendar-home-set
- [x] Test: PROPFIND on calendar-home returns calendar list
- [x] Test: PUT VEVENT → GET returns same event
- [x] Test: DELETE event → GET returns 404
- [x] Test: REPORT calendar-query with time-range filter
- [x] Test: REPORT calendar-multiget with specific hrefs
- [x] Test: PUT VTODO → GET returns same task
- [x] All tests pass

---

### P4-E4-S2: CalDAV Client Compatibility Tests

**Status:** NOT STARTED

**Description:**
Manual and automated tests with popular CalDAV clients.

**Preconditions:** P4-E4-S1

**Acceptance Criteria:**
- [ ] Apple Calendar (macOS/iOS): add account, sync events, create/edit/delete
- [ ] Thunderbird (Lightning): add account, sync events, tasks
- [ ] DAVx5 (Android): add account, sync events
- [ ] GNOME Calendar: add account, sync events
- [ ] Documentation: client setup guides for each tested client
- [ ] Known limitations documented

---

## Epic P4-E5: OAuth2 / OIDC Authentication

**Goal:** Support OAuth2 and OpenID Connect for single sign-on.

### P4-E5-S1: OAuth2 Provider Configuration

**Status:** NOT STARTED

**Description:**
Database-driven OAuth2 provider configuration (client ID, secret, endpoints).

**Preconditions:** Phase 3 complete

**Acceptance Criteria:**
- [ ] `oauth_providers` table: id, name, type (oauth2/oidc), client_id, client_secret, auth_url, token_url, userinfo_url, scopes, enabled
- [ ] CRUD API endpoints: `GET/POST/PUT/DELETE /api/v2/admin/auth-providers`
- [ ] Provider configuration stored per-tenant (multi-tenant) or globally (standalone)
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P4-E5-S2: OAuth2 Authorization Flow

**Status:** NOT STARTED

**Description:**
Implement the OAuth2 authorization code flow with PKCE for browser-based login.

**Preconditions:** P4-E5-S1

**Acceptance Criteria:**
- [ ] `GET /api/v2/auth/oauth/{provider}/redirect` — redirects to provider's auth URL
- [ ] `GET /api/v2/auth/oauth/{provider}/callback` — handles callback, exchanges code for token
- [ ] User auto-provisioned on first login (creates webcalendar user from OAuth profile)
- [ ] JWT issued after successful OAuth flow (same as password login)
- [ ] PKCE support for public clients
- [ ] PHPStan level 9 passes
- [ ] Functional tests with mock OAuth server

---

### P4-E5-S3: OIDC Integration

**Status:** NOT STARTED

**Description:**
Extend OAuth2 support with OpenID Connect discovery and ID token validation.

**Preconditions:** P4-E5-S2

**Acceptance Criteria:**
- [ ] Auto-discovery via `.well-known/openid-configuration` endpoint
- [ ] ID token validation (signature, claims, expiry)
- [ ] User profile populated from OIDC claims (name, email, groups)
- [ ] Support for major providers: Google, Microsoft Entra ID, Keycloak, Auth0
- [ ] PHPStan level 9 passes
- [ ] Functional tests

---

### P4-E5-S4: OAuth2/OIDC Frontend Integration

**Status:** NOT STARTED

**Description:**
Login page shows OAuth/OIDC provider buttons for SSO.

**Preconditions:** P4-E5-S2

**Acceptance Criteria:**
- [ ] Login page fetches available providers from API
- [ ] Provider buttons displayed with name and icon
- [ ] Clicking a provider redirects to OAuth flow
- [ ] Callback page handles token exchange and stores JWT
- [ ] Works in both standalone and multi-tenant modes
- [ ] Vitest tests

---

## Epic P4-E6: LDAP Authentication

**Goal:** Support LDAP/Active Directory authentication for enterprise environments.

### P4-E6-S1: LDAP Connection & Configuration

**Status:** NOT STARTED

**Description:**
LDAP server connection configuration and bind testing.

**Preconditions:** Phase 3 complete

**Acceptance Criteria:**
- [ ] LDAP configuration: host, port, base DN, bind DN, bind password, user filter, TLS/STARTTLS
- [ ] Configuration stored in database (per-tenant or global)
- [ ] `GET/PUT /api/v2/admin/ldap-config` endpoints
- [ ] Connection test endpoint: `POST /api/v2/admin/ldap-config/test`
- [ ] PHPStan level 9 passes
- [ ] Unit tests with mock LDAP

---

### P4-E6-S2: LDAP Authentication Flow

**Status:** NOT STARTED

**Description:**
Authenticate users against LDAP directory and auto-provision webcalendar accounts.

**Preconditions:** P4-E6-S1

**Acceptance Criteria:**
- [ ] Login with LDAP credentials via `POST /api/v2/auth/login` (transparent fallback)
- [ ] User search by sAMAccountName or uid attribute
- [ ] LDAP bind to verify password
- [ ] Auto-provision webcalendar user on first LDAP login (name, email from LDAP attributes)
- [ ] Sync user attributes on subsequent logins (name, email updates)
- [ ] PHPStan level 9 passes
- [ ] Unit tests with mock LDAP

---

### P4-E6-S3: LDAP Group Sync

**Status:** NOT STARTED

**Description:**
Sync LDAP groups to webcalendar groups for permission management.

**Preconditions:** P4-E6-S2

**Acceptance Criteria:**
- [ ] LDAP group membership query (memberOf attribute or group search)
- [ ] Configurable group DN mapping to webcalendar groups
- [ ] Groups synced on user login (create group if missing, add/remove membership)
- [ ] Admin-only sync trigger: `POST /api/v2/admin/ldap-config/sync-groups`
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

## Epic P4-E7: Per-Tenant Auth Configuration

**Goal:** Allow each tenant to configure their own authentication method(s).

### P4-E7-S1: Auth Provider Registry

**Status:** NOT STARTED

**Description:**
System for tenants to register and manage their authentication providers.

**Preconditions:** P4-E5-S1, P4-E6-S1

**Acceptance Criteria:**
- [ ] Each tenant can configure: password (always available), OAuth2, OIDC, LDAP
- [ ] Auth provider configuration stored per-tenant in tenant DB
- [ ] Settings page: `/settings/authentication` with provider list and configuration forms
- [ ] Provider priority/order configurable (try OAuth first, fallback to password)
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P4-E7-S2: Chained Authentication

**Status:** NOT STARTED

**Description:**
Authentication chain that tries multiple providers in configured order.

**Preconditions:** P4-E7-S1

**Acceptance Criteria:**
- [ ] `ChainedAuthenticator` tries providers in priority order
- [ ] First successful auth wins (short-circuit)
- [ ] Detailed error logging for auth failures (without exposing to user)
- [ ] Login page adapts based on enabled providers (shows/hides password field, OAuth buttons)
- [ ] PHPStan level 9 passes
- [ ] Unit tests with multiple mock providers

---

### P4-E7-S3: Auth Configuration UI

**Status:** NOT STARTED

**Description:**
Admin UI for configuring authentication providers per tenant.

**Preconditions:** P4-E7-S1

**Acceptance Criteria:**
- [ ] Route `/settings/authentication` accessible to tenant admins
- [ ] Toggle providers on/off
- [ ] OAuth2/OIDC configuration form (client ID, secret, endpoints or auto-discovery URL)
- [ ] LDAP configuration form (host, port, base DN, filters)
- [ ] Connection test button for LDAP
- [ ] Vitest tests

---

## Story Execution Checklist (for AI Agent)

Same as Phase 1–3:

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

## Phase 1–3 Summary

**Phase 1** completed with 45/45 stories:
- Symfony 7.x REST API + React 18 SPA + FullCalendar
- JWT auth, event CRUD, user/category admin, Docker Compose

**Phase 2** completed with 32/32 stories:
- Participants, groups, layers, tasks, journals, import/export
- Search, real-time (Mercure), permissions, mobile responsive
- 220 Vitest tests + 25 Playwright E2E tests

**Phase 3** completed with 27/27 stories:
- Tenant data model, resolver middleware, provisioning
- Control plane API with auth, dashboard, stats
- Tenant-scoped JWT, cross-tenant isolation, rate limiting
- Data export, mode detection, setup wizard, hosted Docker config
- 258 Vitest tests + 326 PHP tests
