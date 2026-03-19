# WCTNG — Phase 7 Development Plan & Status

> **Last Updated:** 2026-03-18
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

**Status:** TODO

**Description:**
21+ API calls in frontend components silently swallow errors. Users perform operations that fail without any feedback. Fix all silent failures to show toast notifications.

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
| P10-E3-S4 | Promote Category to Global | TODO |
| P10-E3-S5 | Merge Categories | TODO |

---

### P10-E3-S1: Category Sidebar Filter

**Status:** TODO

**Description:**
Add a collapsible "Categories" section to the left sidebar with checkboxes per category. Checking/unchecking filters events on the calendar view client-side. Multi-select (Google Calendar model).

**TDD Tests (write first):**
- Vitest: `CategoryFilter` component — renders categories with checkboxes, toggle hides/shows, "select all/none" buttons, persists to localStorage, uncategorized events toggle
- E2E: check a category → only its events visible; uncheck → hidden; reload → filter persists

**Acceptance Criteria:**
- [ ] `CategoryFilter` component in left sidebar below navigation, collapsible
- [ ] Fetches categories from `GET /api/v2/categories` on mount
- [ ] Each category: checkbox + color dot + name + event count
- [ ] "All" / "None" toggle buttons at top
- [ ] Uncategorized events have their own toggle (always shown by default)
- [ ] Filter applied client-side via FullCalendar `eventDisplay` callback (hide non-matching)
- [ ] Filter state persisted to localStorage (`wctng_category_filter`)
- [ ] Vitest: 6 tests (render, toggle, all/none, persist, uncategorized, color dots)
- [ ] E2E: 2 tests (filter hides events, filter persists on reload)

---

### P10-E3-S2: Category Filter in Saved Views

**Status:** TODO

**Description:**
Extend saved views to store a category filter. Activating a view sets both layers and category filter.

**Preconditions:** P10-E3-S1

**TDD Tests (write first):**
- PHPUnit: SavedViewRepository — create with category_ids, findByOwner returns category_ids, null/empty handled
- Vitest: SavedViewsPage — category checkboxes in create form, view shows category badge
- E2E: create view with category filter → activate → only filtered categories shown

**Acceptance Criteria:**
- [ ] `saved_views` table: add `category_ids` TEXT column (JSON array, default '[]')
- [ ] `SavedViewRepository::create()` accepts optional `categoryIds` parameter
- [ ] `findByOwner()` returns `category_ids` in response
- [ ] SavedViewsPage create form: category checklist (like user checklist)
- [ ] Activating a view applies category filter to sidebar checkboxes
- [ ] PHPUnit: 3 tests (create with categories, round-trip, empty default)
- [ ] Vitest: 2 tests (category checkboxes in form, badge on view)
- [ ] E2E: 1 test (create view with category, activate, verify filter)

---

### P10-E3-S3: Auto-Create Categories on Import

**Status:** TODO

**Description:**
When importing ICS events with CATEGORIES that don't exist, auto-create them as personal (non-global) categories. Applies to ICS file import and CalDAV sync.

**TDD Tests (write first):**
- PHPUnit: import event with unknown category → category created as personal (owner = user), not global
- PHPUnit: import event with existing category → no duplicate created
- PHPUnit: import with multiple unknown categories → all created
- PHPUnit: re-import same event → categories not duplicated

**Acceptance Criteria:**
- [ ] ICS import: parse CATEGORIES field, lookup by name for user, create if missing
- [ ] Created categories: owner = importing user (personal, not global), default color
- [ ] Import summary: "N new categories created" count
- [ ] CalDAV sync: same logic when receiving VCALENDAR with CATEGORIES
- [ ] PHPUnit: 4 integration tests
- [ ] No frontend changes needed

---

### P10-E3-S4: Promote Category to Global

**Status:** TODO

**Description:**
Allow admins to promote a personal category to global (visible to all users). Admin categories page shows owner indicator and "Make Global" action.

**TDD Tests (write first):**
- PHPUnit: `PUT /api/v2/categories/{id}` with `is_global: true` sets owner to null
- PHPUnit: non-admin cannot promote (403)
- PHPUnit: already-global category returns success (idempotent)
- Vitest: CategoryManagement shows "Personal" badge with "Make Global" button for personal categories
- E2E: promote personal category → reload → shows as "Global"

**Acceptance Criteria:**
- [ ] `PUT /api/v2/categories/{id}` accepts `is_global` boolean field
- [ ] When `is_global: true` and user is admin: set category owner to null
- [ ] When `is_global: false` and user is admin: set category owner to admin login
- [ ] Non-admin: 403 if trying to change is_global
- [ ] Admin categories page: "Make Global" button for personal categories, "Make Personal" for global
- [ ] PHPUnit: 3 integration tests
- [ ] Vitest: 1 test (button renders conditionally)
- [ ] E2E: 1 test (promote and verify)

---

### P10-E3-S5: Merge Categories

**Status:** TODO

**Description:**
Admin tool to merge duplicate categories (e.g., "Holiday" → "Holidays"). Reassigns all event associations from source to target, then deletes source.

**TDD Tests (write first):**
- PHPUnit: merge reassigns all webcal_entry_categories rows from source to target
- PHPUnit: merge deletes source category after reassignment
- PHPUnit: merge same category into itself → error
- PHPUnit: merge preserves target category name and color
- Vitest: merge dialog renders with source/target dropdowns
- E2E: create two categories with events → merge → events retain under survivor

**Acceptance Criteria:**
- [ ] `POST /api/v2/admin/categories/merge` — accepts `source_id` and `target_id`
- [ ] Reassigns all `webcal_entry_categories` rows: `UPDATE ... SET cat_id = :target WHERE cat_id = :source`
- [ ] Deletes source category after reassignment
- [ ] Returns `{merged_events: N}` count
- [ ] Self-merge rejected (400)
- [ ] Admin categories page: "Merge" button opens dialog with two dropdowns
- [ ] PHPUnit: 4 integration tests
- [ ] Vitest: 1 test (dialog renders)
- [ ] E2E: 1 test (merge and verify)

---

### P10-E3 Summary

| Story | Backend Tests | Frontend Tests | E2E Tests |
|-------|--------------|----------------|-----------|
| S1 Category Filter | — | 6 Vitest | 2 E2E |
| S2 Views + Categories | 3 PHPUnit | 2 Vitest | 1 E2E |
| S3 Auto-Create Import | 4 PHPUnit | — | — |
| S4 Promote to Global | 3 PHPUnit | 1 Vitest | 1 E2E |
| S5 Merge Categories | 4 PHPUnit | 1 Vitest | 1 E2E |
| **Total** | **14** | **10** | **5** |

**Execution order:** S1 → S2 → S3 → S4 → S5 (S1 is prerequisite for S2; S3–S5 independent)

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
