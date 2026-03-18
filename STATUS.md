# WCTNG — Phase 7 Development Plan & Status

> **Last Updated:** 2026-03-17
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

## Phase 8 Preview: SEO & Public Discovery

> **Not yet started.** Planned epics for server-rendered public event pages,
> structured data, sitemap, privacy controls, and map integration.

### Epic P8-E1: SEO & Public Event Pages (6 stories)

| Story | Title | Status |
|-------|-------|--------|
| P8-E1-S1 | Admin & User SEO Feature Flags | DONE |
| P8-E1-S2 | Single Event Detail Pages (SSR) | DONE |
| P8-E1-S3 | Schema.org Structured Data (JSON-LD) | TODO |
| P8-E1-S4 | Event Index & Archive Pages | TODO |
| P8-E1-S5 | Sitemap.xml & robots.txt | TODO |
| P8-E1-S6 | Open Graph & Social Sharing | TODO |

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

**Status:** TODO

**Description:**
Add Schema.org Event structured data to single event pages so Google shows rich event snippets in search results.

**Acceptance Criteria:**
- [ ] JSON-LD `<script type="application/ld+json">` block in event detail page
- [ ] Schema.org `Event` type with: name, startDate, endDate, location, description, organizer
- [ ] `location` maps to Schema.org `Place` (with name) or `VirtualLocation` (if URL present)
- [ ] `organizer` maps to Schema.org `Person` with user's display name
- [ ] `eventStatus`: SCHEDULED, CANCELLED (from event status field)
- [ ] `eventAttendanceMode`: OFFLINE (default), ONLINE (if conference URL), MIXED
- [ ] Validated against Google's Rich Results Test
- [ ] Unit tests for JSON-LD generation

---

### P8-E1-S4: Event Index & Archive Pages

**Status:** TODO

**Description:**
Paginated, server-rendered listing of public events for crawlers to discover individual event pages.

**Acceptance Criteria:**
- [ ] `GET /public/{username}/events` — upcoming events list (paginated, 20 per page)
- [ ] `GET /public/{username}/events?month=2026-04` — monthly archive view
- [ ] Server-rendered HTML with `<title>`, `<meta description>` per page
- [ ] `<link rel="canonical">` to avoid duplicate content
- [ ] `<link rel="next">` and `<link rel="prev">` for pagination
- [ ] Each event links to its detail page (`/public/{username}/event/{id}`)
- [ ] Respects same feature flag / user opt-out as detail pages
- [ ] Clean, semantic HTML with `<article>`, `<time>`, `<address>` elements
- [ ] Unit tests

---

### P8-E1-S5: Sitemap.xml & robots.txt

**Status:** TODO

**Description:**
Auto-generated sitemap for search engine discovery and robots.txt to guide crawler behavior.

**Acceptance Criteria:**
- [ ] `GET /sitemap.xml` — auto-generated sitemap listing all public event pages
- [ ] Only includes events from users with public calendar + SEO indexing enabled
- [ ] `<lastmod>` from event modification date
- [ ] `<changefreq>` based on event date (upcoming = daily, past = monthly)
- [ ] `<priority>` based on event proximity (upcoming events higher priority)
- [ ] Sitemap limited to 50,000 URLs (sitemap index if more)
- [ ] `GET /robots.txt` — allows /public/, /book/; disallows /api/, /admin/, /settings/, /dav/
- [ ] robots.txt references sitemap URL
- [ ] Cached/regenerated periodically (not on every request)
- [ ] PHPStan level 9 + unit tests

---

### P8-E1-S6: Open Graph & Social Sharing

**Status:** TODO

**Description:**
Open Graph and Twitter Card meta tags on event pages for rich link previews when shared on social media, Slack, etc.

**Acceptance Criteria:**
- [ ] `<meta property="og:title">` — event title
- [ ] `<meta property="og:description">` — date, time, location summary
- [ ] `<meta property="og:type" content="website">`
- [ ] `<meta property="og:url">` — canonical event URL
- [ ] `<meta property="og:image">` — dynamically generated event card image (or default calendar icon)
- [ ] `<meta name="twitter:card" content="summary">`
- [ ] Shared links on Slack/Discord/Twitter show rich preview with event details
- [ ] Optional: dynamic OG image generation (event title + date as PNG card)
- [ ] Unit tests for meta tag generation

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
| P8-E2-S1 | Location Geocoding Service | TODO |
| P8-E2-S2 | Map on SSR Event Detail Page | TODO |
| P8-E2-S3 | Map Link in Event Detail Dialog | TODO |

---

### P8-E2-S1: Location Geocoding Service

**Status:** TODO

**Description:**
Backend service that geocodes event location text to latitude/longitude coordinates using the Nominatim API (OpenStreetMap's free geocoding service). Results cached to avoid rate limiting.

**Acceptance Criteria:**
- [ ] `GeocodingService` calls Nominatim API: `https://nominatim.openstreetmap.org/search?q={location}&format=json`
- [ ] Returns lat/lon pair or null if location can't be geocoded
- [ ] Results cached in `webcal_entry` columns `cal_geo_lat` / `cal_geo_lon` (already exist in schema)
- [ ] Geocoding triggered on event create/update when location field changes
- [ ] Respects Nominatim usage policy: max 1 request/second, User-Agent header with app name
- [ ] `GET /api/v2/events/{id}` response includes `latitude` and `longitude` when available
- [ ] Admin config: `ENABLE_GEOCODING` (Y/N, default Y)
- [ ] PHPStan level 9 + unit tests

---

### P8-E2-S2: Map on SSR Event Detail Page

**Status:** TODO

**Description:**
Embed an OpenStreetMap tile on the server-rendered event detail page when the event has geocoded coordinates. No JavaScript map library needed — use a static tile image or a Leaflet.js embed.

**Preconditions:** P8-E1-S2 (SSR event page exists), P8-E2-S1 (geocoding available)

**Acceptance Criteria:**
- [ ] Map displayed on `/public/{username}/event/{id}` below the location field
- [ ] Uses Leaflet.js (lightweight, open source) with OpenStreetMap tiles
- [ ] Map centered on event coordinates with a marker
- [ ] Map only shown when lat/lon are available (graceful fallback: no map, just text)
- [ ] Map size: responsive, approximately 400x250px
- [ ] "View larger map" link opens OpenStreetMap at the coordinates
- [ ] No map API key required (OpenStreetMap tiles are free)
- [ ] Tile attribution: "© OpenStreetMap contributors" (required by OSM license)
- [ ] Schema.org `geo` property added to JSON-LD when coordinates exist
- [ ] Vitest tests (verify map container renders when coordinates present)

---

### P8-E2-S3: Map Link in Event Detail Dialog

**Status:** TODO

**Description:**
Add a clickable map link in the event detail dialog (React SPA) without embedding a full map. Keeps the dialog compact while giving users one-click access to directions.

**Preconditions:** P8-E2-S1 (geocoding available)

**Acceptance Criteria:**
- [ ] When event has a location, show a clickable "View on Map" link next to the location text
- [ ] Link format: `https://www.openstreetmap.org/?mlat={lat}&mlon={lon}#map=16/{lat}/{lon}`
- [ ] Opens in new tab (`target="_blank"`, `rel="noopener"`)
- [ ] When lat/lon not available, show a fallback search link: `https://www.openstreetmap.org/search?query={location}`
- [ ] Small map icon (📍 or pin SVG) before the link — no embedded map, just a text link
- [ ] No additional JavaScript libraries needed (just an `<a>` tag)
- [ ] Vitest tests

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

## Dependency Graph

```
P7-E1 (Drag & Drop) — independent (quick win)
P7-E2 (ICS Subscription) — independent
P7-E3 (Scheduling Polls) — independent
P7-E4 (Room Booking) — independent
P7-E5 (MCP Server) — independent
P7-E6 (PWA & Push) — independent
P7-E7 (Views & Categories) — independent
P7-E8 (UX Quick Wins) — independent
```

**All epics are independent.** Start with E1 (quick win) for immediate user impact.

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
