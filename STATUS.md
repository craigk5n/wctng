# WCTNG — Phase 5 Development Plan & Status

> **Last Updated:** 2026-03-17
> **Phase:** 5 — Polish & Scale
> **Goal:** Notifications, full-text search, reports, performance optimization, caching
> **Methodology:** TDD (write tests first, then implementation)
> **Developed by:** AI Agent
> **Phase 1 Archive:** See `STATUS-PHASE1-ARCHIVE.md`
> **Phase 2 Archive:** See `STATUS-PHASE2-ARCHIVE.md`
> **Phase 3 Archive:** See `STATUS-PHASE3-ARCHIVE.md`
> **Phase 4 Archive:** See `STATUS-PHASE4-ARCHIVE.md`

---

## Quick Status

| Epic | Title | Stories | Done | Status |
|------|-------|---------|------|--------|
| P5-E1 | Email Notifications | 4 | 4 | DONE |
| P5-E2 | Webhook Notifications | 3 | 3 | DONE |
| P5-E3 | Full-Text Search | 3 | 3 | DONE |
| P5-E4 | Reports & Analytics | 3 | 1 | IN PROGRESS |
| P5-E5 | Performance & Caching | 4 | 0 | NOT STARTED |
| P5-E6 | Production Readiness | 4 | 0 | NOT STARTED |
| **Total** | | **21** | **11** | |

---

## Dependency Graph

```
P5-E1 (Email) — independent
P5-E2 (Webhooks) — independent
P5-E3 (Search) — independent
P5-E4 (Reports) — independent
P5-E5 (Performance) — after E1-E4 ideally (optimize what exists)
P5-E6 (Production) — after E5 (deploy what's optimized)
```

**All epics E1-E4 are independent** and can be done in any order.
**E5 and E6** should come last.

---

## Global Standards

Same as Phase 1–4:
- PHP 8.2+, PHPStan level 9, Psalm errorLevel 1, PHPUnit 10
- React 18, TypeScript strict, ESLint, Vitest, Playwright
- TDD: write tests first, then implementation
- Docker-based development on ports 47180/47106/47173/47181

---

## Epic P5-E1: Email Notifications

**Goal:** Send email notifications for event invitations, reminders, and changes.

### P5-E1-S1: Email Transport Configuration

**Status:** DONE

**Description:**
Configure email sending via SMTP or API-based providers (Mailgun, SendGrid, SES).

**Preconditions:** Phase 4 complete

**Acceptance Criteria:**
- [x] `MAILER_DSN` env var configures Symfony Mailer transport
- [x] Support for SMTP, Mailgun, SendGrid, Amazon SES
- [x] `GET /api/v2/admin/email-config` returns current config (without password)
- [x] `POST /api/v2/admin/email-config/test` sends a test email
- [x] PHPStan level 9 passes
- [x] Unit tests

---

### P5-E1-S2: Event Invitation Emails

**Status:** DONE

**Description:**
Send email notifications when a user is added as a participant to an event.

**Preconditions:** P5-E1-S1

**Acceptance Criteria:**
- [ ] Email sent when participant added to event (includes event details + ICS attachment)
- [ ] Email sent when event is updated (if participants exist)
- [ ] Email sent when event is cancelled/deleted
- [ ] "Accept" / "Decline" links in email (one-click response via token)
- [ ] Configurable: users can opt out of email notifications
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P5-E1-S3: Event Reminder Emails

**Status:** DONE

**Description:**
Send reminder emails before upcoming events based on user preferences.

**Preconditions:** P5-E1-S1

**Acceptance Criteria:**
- [ ] User preference: reminder time (15min, 30min, 1hr, 1day, or disabled)
- [ ] `php bin/console webcalendar:send-reminders` CLI command (runs via cron)
- [ ] Reminder email includes event summary, time, location, and calendar link
- [ ] Tracks sent reminders to avoid duplicates
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P5-E1-S4: Notification Preferences UI

**Status:** DONE

**Description:**
User settings page for configuring email notification preferences.

**Preconditions:** P5-E1-S2

**Acceptance Criteria:**
- [ ] Route `/settings/notifications` accessible to all users
- [ ] Toggle: receive event invitation emails (on/off)
- [ ] Toggle: receive event update emails (on/off)
- [ ] Reminder time selector (15min, 30min, 1hr, 1day, disabled)
- [ ] Daily digest option (summary of next day's events)
- [ ] Vitest tests

---

## Epic P5-E2: Webhook Notifications

**Goal:** Allow external integrations via configurable webhooks for event lifecycle.

### P5-E2-S1: Webhook Configuration API

**Status:** DONE

**Description:**
CRUD API for managing webhook subscriptions.

**Preconditions:** Phase 4 complete

**Acceptance Criteria:**
- [ ] `webhooks` table: id, url, events (comma-separated), secret, enabled, created_at
- [ ] CRUD API: `GET/POST/PUT/DELETE /api/v2/admin/webhooks`
- [ ] Webhook events: `event.created`, `event.updated`, `event.deleted`, `task.created`, `task.completed`
- [ ] HMAC-SHA256 signature in `X-Webhook-Signature` header for verification
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P5-E2-S2: Webhook Dispatcher

**Status:** DONE

**Description:**
Dispatches webhook payloads asynchronously when events occur.

**Preconditions:** P5-E2-S1

**Acceptance Criteria:**
- [ ] Webhooks dispatched after event create/update/delete
- [ ] Payload includes event data, timestamp, and event type
- [ ] Async dispatch (fire-and-forget with retry on failure)
- [ ] Retry with exponential backoff (3 attempts, 1s/5s/30s)
- [ ] Webhook delivery log (last 100 deliveries per webhook)
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P5-E2-S3: Webhook Management UI

**Status:** DONE

**Description:**
Admin page for managing webhook subscriptions.

**Preconditions:** P5-E2-S1

**Acceptance Criteria:**
- [ ] Route `/admin/webhooks` accessible to admins
- [ ] List of configured webhooks with URL, events, enabled status
- [ ] Create/edit/delete webhooks
- [ ] Test button: sends a test payload to the webhook URL
- [ ] Delivery log viewer (last N deliveries with status codes)
- [ ] Vitest tests

---

## Epic P5-E3: Full-Text Search

**Goal:** Fast, typo-tolerant search across events, tasks, and journals.

### P5-E3-S1: Search Index Service

**Status:** DONE

**Description:**
Build a search index over calendar entries for fast full-text search.

**Preconditions:** Phase 4 complete

**Acceptance Criteria:**
- [ ] `SearchIndexService` indexes events, tasks, journals by title + description
- [ ] MySQL FULLTEXT index on `webcal_entry` (cal_name, cal_description)
- [ ] `GET /api/v2/search?q={query}&type={event|task|journal}` endpoint with relevance ranking
- [ ] Results include snippet with highlighted match
- [ ] Pagination support (limit, offset)
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P5-E3-S2: Search Suggestions & Autocomplete

**Status:** DONE

**Description:**
Typeahead suggestions in the search bar as the user types.

**Preconditions:** P5-E3-S1

**Acceptance Criteria:**
- [ ] `GET /api/v2/search/suggest?q={prefix}` returns top 5 matches
- [ ] Response within 100ms (indexed query)
- [ ] Searches across event titles, locations, and participant names
- [ ] Frontend SearchBar uses suggestions endpoint with debounce
- [ ] Vitest tests

---

### P5-E3-S3: Advanced Search Filters

**Status:** DONE

**Description:**
Search with filters for date range, category, type, and participant.

**Preconditions:** P5-E3-S1

**Acceptance Criteria:**
- [ ] Filter by date range: `start`, `end` query params
- [ ] Filter by category: `category_id` query param
- [ ] Filter by type: `type=event|task|journal`
- [ ] Filter by participant: `participant=login`
- [ ] Combined filters work together (AND logic)
- [ ] Search results page in frontend with filter sidebar
- [ ] Vitest tests

---

## Epic P5-E4: Reports & Analytics

**Goal:** Generate useful reports and visualizations from calendar data.

### P5-E4-S1: Report API Endpoints

**Status:** DONE

**Description:**
API endpoints for generating common calendar reports.

**Preconditions:** Phase 4 complete

**Acceptance Criteria:**
- [ ] `GET /api/v2/reports/activity?start={}&end={}` — event count by day/week/month
- [ ] `GET /api/v2/reports/busy-hours?start={}&end={}` — busiest hours of the week
- [ ] `GET /api/v2/reports/categories?start={}&end={}` — event count by category
- [ ] `GET /api/v2/reports/upcoming?days=7` — upcoming events summary
- [ ] All reports respect user permissions (only own events, unless admin)
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P5-E4-S2: Reports Dashboard Page

**Status:** NOT STARTED

**Description:**
Visual reports page with charts and summaries.

**Preconditions:** P5-E4-S1

**Acceptance Criteria:**
- [ ] Route `/reports` accessible to all users
- [ ] Activity chart: events per day/week (bar chart)
- [ ] Busy hours heatmap: hour-of-day × day-of-week
- [ ] Category breakdown: pie/donut chart
- [ ] Date range selector for all reports
- [ ] Vitest tests

---

### P5-E4-S3: Report Export

**Status:** NOT STARTED

**Description:**
Export reports as CSV or PDF.

**Preconditions:** P5-E4-S2

**Acceptance Criteria:**
- [ ] CSV export for activity, categories, and upcoming events reports
- [ ] PDF export with charts (server-side rendering or client-side)
- [ ] Download button on reports page
- [ ] Filename includes date range and report type
- [ ] PHPStan level 9 passes

---

## Epic P5-E5: Performance & Caching

**Goal:** Optimize API response times, reduce database load, and improve frontend performance.

### P5-E5-S1: API Response Caching

**Status:** NOT STARTED

**Description:**
Cache frequently-accessed API responses with ETags and conditional requests.

**Preconditions:** Phase 4 complete

**Acceptance Criteria:**
- [ ] ETag headers on `GET /events`, `GET /categories`, `GET /users` responses
- [ ] 304 Not Modified when ETag matches (If-None-Match)
- [ ] Cache-Control headers with appropriate max-age
- [ ] Cache invalidation on write operations (create/update/delete)
- [ ] PHPStan level 9 passes
- [ ] Functional tests

---

### P5-E5-S2: Database Query Optimization

**Status:** NOT STARTED

**Description:**
Optimize slow queries with indexes, query analysis, and N+1 elimination.

**Preconditions:** P5-E5-S1

**Acceptance Criteria:**
- [ ] Add database indexes on frequently-queried columns (cal_date, cal_create_by, cal_type)
- [ ] Batch-load categories and participants (eliminate N+1 queries)
- [ ] EXPLAIN analysis on top 10 slowest queries
- [ ] Event listing query under 50ms for 10,000 events
- [ ] Migration SQL file for index creation
- [ ] PHPStan level 9 passes

---

### P5-E5-S3: Frontend Bundle Optimization

**Status:** NOT STARTED

**Description:**
Optimize React bundle size, lazy loading, and rendering performance.

**Preconditions:** Phase 4 complete

**Acceptance Criteria:**
- [ ] Route-based code splitting (React.lazy for admin, settings, control pages)
- [ ] Bundle size under 500KB gzipped (excluding FullCalendar)
- [ ] Lighthouse performance score above 90 on calendar page
- [ ] Image/font optimization (preload critical resources)
- [ ] Service worker for offline calendar viewing (PWA basics)
- [ ] Vitest tests for lazy-loaded routes

---

### P5-E5-S4: Redis Cache Integration

**Status:** NOT STARTED

**Description:**
Optional Redis cache for session data, API response caching, and rate limiting.

**Preconditions:** P5-E5-S1

**Acceptance Criteria:**
- [ ] Redis container added to Docker Compose (optional, graceful fallback)
- [ ] Symfony cache adapter configured for Redis when available
- [ ] Rate limiter uses Redis instead of file-based counters when available
- [ ] Tenant rate limit data shared across PHP-FPM workers via Redis
- [ ] `REDIS_URL` env var (empty = fallback to filesystem)
- [ ] PHPStan level 9 passes

---

## Epic P5-E6: Production Readiness

**Goal:** Prepare the application for production deployment with monitoring, logging, and hardening.

### P5-E6-S1: Structured Logging

**Status:** NOT STARTED

**Description:**
JSON-formatted structured logging with request context and log levels.

**Preconditions:** Phase 4 complete

**Acceptance Criteria:**
- [ ] Monolog configured with JSON formatter for production
- [ ] Request ID in all log entries (X-Request-Id header)
- [ ] Tenant slug in log context for multi-tenant debugging
- [ ] Log levels: ERROR for exceptions, WARNING for auth failures, INFO for requests
- [ ] Sensitive data redacted (passwords, tokens)
- [ ] PHPStan level 9 passes

---

### P5-E6-S2: Health Check & Monitoring

**Status:** NOT STARTED

**Description:**
Comprehensive health check endpoint for load balancers and monitoring systems.

**Preconditions:** P5-E6-S1

**Acceptance Criteria:**
- [ ] `GET /api/v2/health` extended with component status: database, Mercure, Redis, disk space
- [ ] Individual component checks: `GET /api/v2/health/db`, `GET /api/v2/health/mercure`
- [ ] Response time tracking (average request duration)
- [ ] Prometheus-compatible metrics endpoint: `GET /api/v2/metrics`
- [ ] PHPStan level 9 passes

---

### P5-E6-S3: Security Hardening

**Status:** NOT STARTED

**Description:**
Security best practices for production deployment.

**Preconditions:** Phase 4 complete

**Acceptance Criteria:**
- [ ] CSRF protection on state-changing endpoints
- [ ] Content Security Policy (CSP) headers
- [ ] HSTS header configuration
- [ ] Rate limiting on auth endpoints (5 attempts per minute)
- [ ] SQL injection audit (parameterized queries verified)
- [ ] XSS audit (output encoding verified)
- [ ] Dependency vulnerability scan (composer audit, npm audit)

---

### P5-E6-S4: Production Docker Configuration

**Status:** NOT STARTED

**Description:**
Production-optimized Docker configuration with multi-stage builds.

**Preconditions:** P5-E6-S1

**Acceptance Criteria:**
- [ ] Multi-stage Dockerfile: build stage (npm/composer) → production stage (nginx+php-fpm)
- [ ] Production Docker Compose with TLS termination (Caddy or Traefik)
- [ ] Environment-specific configs (dev vs. production)
- [ ] Container health checks for all services
- [ ] Docker image size under 200MB
- [ ] GitHub Actions CI: build, test, push to registry
- [ ] Deployment documentation (docker-compose, Kubernetes hints)

---

## Story Execution Checklist (for AI Agent)

Same as Phase 1–4:

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

## Phase 1–4 Summary

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
- 426 PHP tests + 265 Vitest tests
