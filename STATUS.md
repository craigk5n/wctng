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
| P7-E3 | Scheduling Polls | 3 | 0 | TODO |
| P7-E4 | Room & Resource Booking | 2 | 0 | TODO |
| P7-E5 | MCP Server (AI Integration) | 2 | 0 | TODO |
| P7-E6 | PWA & Push Notifications | 2 | 0 | TODO |
| P7-E7 | Saved Views & Private Categories | 2 | 0 | TODO |
| P7-E8 | UX Quick Wins | 3 | 0 | TODO |
| **Total** | | **19** | **0** | |

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

**Status:** TODO

**Description:**
Backend for creating scheduling polls with time options and collecting votes.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] `scheduling_polls` table: id, creator_login, title, description, status (open/closed), created_at
- [ ] `scheduling_poll_options` table: id, poll_id, start_datetime, end_datetime
- [ ] `scheduling_poll_votes` table: id, option_id, voter_login, vote (yes/maybe/no)
- [ ] `POST /api/v2/polls` — create poll with title + time options
- [ ] `GET /api/v2/polls/{id}` — get poll with options and vote counts
- [ ] `POST /api/v2/polls/{id}/vote` — cast vote on options
- [ ] `POST /api/v2/polls/{id}/finalize` — close poll, create event from winning option
- [ ] Email notification to participants with voting link
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P7-E3-S2: Poll Creation UI

**Status:** TODO

**Description:**
UI for creating scheduling polls with time slot selection.

**Preconditions:** P7-E3-S1

**Acceptance Criteria:**
- [ ] "Schedule Meeting" button on calendar toolbar (distinct from "New Event")
- [ ] Poll creation dialog: title, description, participant usernames
- [ ] Time slot picker: click on calendar grid to add proposed times
- [ ] Minimum 2 options, maximum 10
- [ ] Preview before sending
- [ ] Vitest tests

---

### P7-E3-S3: Poll Voting & Finalization UI

**Status:** TODO

**Description:**
Voting interface for participants and finalization for the organizer.

**Preconditions:** P7-E3-S2

**Acceptance Criteria:**
- [ ] Route `/polls/{id}` — voting page (accessible to participants)
- [ ] Visual grid: options as columns, voters as rows, yes/maybe/no toggles
- [ ] Real-time vote count display
- [ ] Organizer: "Finalize" button picks the option with most "yes" votes
- [ ] Finalization creates a calendar event and notifies all participants
- [ ] Email with direct voting link (HMAC-signed token for unauthenticated voting)
- [ ] Vitest tests

---

## Epic P7-E4: Room & Resource Booking

**Goal:** Dedicated UI for managing rooms, equipment, and other shared resources. ResourceService is already wired in webcalendar-core.

### P7-E4-S1: Resource Management API & Admin UI

**Status:** TODO

**Description:**
Admin page for creating and managing rooms/resources, plus API for availability queries.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] `GET /api/v2/admin/resources` — list all resources (rooms, equipment)
- [ ] `POST /api/v2/admin/resources` — create resource (name, type, capacity, location)
- [ ] `PUT/DELETE /api/v2/admin/resources/{id}` — update/delete
- [ ] `GET /api/v2/resources/{id}/availability?date={}` — check resource availability
- [ ] Route `/admin/resources` — admin management page
- [ ] Uses ResourceService from webcalendar-core
- [ ] PHPStan level 9 passes
- [ ] Unit + Vitest tests

---

### P7-E4-S2: Resource Booking in Event Dialog

**Status:** TODO

**Description:**
Add room/resource picker to the event create/edit dialog.

**Preconditions:** P7-E4-S1

**Acceptance Criteria:**
- [ ] "Room" dropdown in EventDialog showing available resources
- [ ] Availability check: only show resources free during the event's time slot
- [ ] Selected resource shown in event detail dialog
- [ ] Resource calendar viewable as a layer
- [ ] Double-booking prevention (409 if resource already booked)
- [ ] Vitest tests

---

## Epic P7-E5: MCP Server (AI Integration)

**Goal:** Model Context Protocol server enabling AI assistants (Claude, ChatGPT, etc.) to read and write calendar events. No competitor has this — unique differentiator.

### P7-E5-S1: MCP Endpoint & Tool Definitions

**Status:** TODO

**Description:**
Implement MCP-compliant endpoint with tool definitions for calendar operations.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] `POST /api/v2/mcp` — MCP JSON-RPC endpoint
- [ ] Authentication via API token (per-user `cal_api_token` field)
- [ ] MCP tools defined:
  - `list_events` — list events in date range
  - `get_event` — get event details by ID
  - `create_event` — create a new event (title, date, time, duration, description, location)
  - `update_event` — update event fields
  - `delete_event` — delete an event
  - `search_events` — search by keyword
  - `get_availability` — check free/busy for a user
- [ ] Rate limiting: 60 requests/minute per token
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P7-E5-S2: MCP Configuration & API Token Management

**Status:** TODO

**Description:**
User settings for generating/revoking API tokens for MCP access.

**Preconditions:** P7-E5-S1

**Acceptance Criteria:**
- [ ] Route `/settings/api-tokens` — manage API tokens
- [ ] Generate new token (shown once, then hashed)
- [ ] Revoke existing tokens
- [ ] Token permissions: read-only or read-write
- [ ] MCP connection instructions displayed (endpoint URL, token format)
- [ ] Admin setting: enable/disable MCP server globally
- [ ] Vitest tests

---

## Epic P7-E6: PWA & Push Notifications

**Goal:** Progressive Web App with service worker for offline support and Web Push notifications for event reminders.

### P7-E6-S1: PWA Manifest & Service Worker

**Status:** TODO

**Description:**
Add PWA manifest, service worker for caching, and installable app experience.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] `manifest.json` with app name, icons, theme color, start URL
- [ ] Service worker caches static assets (HTML, CSS, JS, fonts)
- [ ] Offline fallback page when network unavailable
- [ ] "Install App" banner on supported browsers
- [ ] App icon on home screen (mobile) and desktop
- [ ] Vitest tests for service worker registration

---

### P7-E6-S2: Web Push Notifications

**Status:** TODO

**Description:**
Browser push notifications for event reminders and calendar updates.

**Preconditions:** P7-E6-S1

**Acceptance Criteria:**
- [ ] Web Push API integration (VAPID keys)
- [ ] `POST /api/v2/push/subscribe` — store push subscription
- [ ] Push notifications for: event reminders, invitation received, event updated
- [ ] User preference: enable/disable push notifications
- [ ] Notification click opens the relevant event
- [ ] Works when browser tab is closed (service worker handles)
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

## Epic P7-E7: Saved Views & Private Categories

**Goal:** Named multi-user views and per-user private categories for large multi-user deployments.

### P7-E7-S1: Saved Views (Named User Groups)

**Status:** TODO

**Description:**
Create named views that show specific users' calendars, switchable from a dropdown.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] `saved_views` table: id, owner_login, name, user_logins (JSON array)
- [ ] `POST /api/v2/views` — create saved view (name + list of usernames)
- [ ] `GET /api/v2/views` — list user's saved views
- [ ] `PUT/DELETE /api/v2/views/{id}` — update/delete
- [ ] View switcher dropdown in calendar toolbar
- [ ] Selecting a view loads those users' events as layers
- [ ] "My Calendar" always available as default view
- [ ] Vitest tests

---

### P7-E7-S2: Private Categories (Per-User + Global)

**Status:** TODO

**Description:**
Allow users to create personal categories that only they see, while admins manage global categories visible to all.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] Category `owner` field: empty = global (admin-managed), set = private to that user
- [ ] webcalendar-core `webcal_categories.cat_owner` column already exists — wire the filtering
- [ ] `GET /api/v2/categories` returns global + current user's private categories
- [ ] `POST /api/v2/categories` — non-admin users create private categories (owner = self)
- [ ] Admin creates global categories (owner = empty)
- [ ] Users cannot see other users' private categories
- [ ] Category admin page shows "Global" vs "Personal" badge
- [ ] PHPStan level 9 passes
- [ ] Unit + Vitest tests

---

## Epic P7-E8: UX Quick Wins

**Goal:** Small, high-impact UX improvements identified in the competitive analysis.

### P7-E8-S1: Per-Event Color Override

**Status:** TODO

**Description:**
Allow users to set a custom color on individual events, overriding the category color.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] Color picker in EventDialog (optional, defaults to category color)
- [ ] `color` field stored on event (webcal_entry.cal_color column exists)
- [ ] FullCalendar renders event with custom color when set
- [ ] Event detail dialog shows color swatch
- [ ] PHPStan level 9 passes
- [ ] Vitest tests

---

### P7-E8-S2: Focus Time & Working Location

**Status:** TODO

**Description:**
Special event types for "Focus Time" (auto-decline conflicts) and daily working location status.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] "Focus Time" event type in EventDialog — creates event that auto-declines overlapping invitations
- [ ] Visual indicator: striped/hatched background on focus time blocks
- [ ] Working location preference: per-day "Office" / "Remote" / "Traveling" status
- [ ] `GET /api/v2/users/{login}/location?date={}` — returns working location
- [ ] Working location shown in user's calendar header or sidebar
- [ ] Vitest tests

---

### P7-E8-S3: Natural Language Event Creation

**Status:** TODO

**Description:**
Parse natural language input like "Lunch with Sarah tomorrow at noon" into event fields.

**Preconditions:** Phase 6 complete

**Acceptance Criteria:**
- [ ] Quick-add input field in calendar toolbar (text input with magic wand icon)
- [ ] Parse common patterns: "Meeting with Bob Friday 2pm-3pm at Room A"
- [ ] Extract: title, date/time, duration, location, participants
- [ ] Pre-fill EventDialog with parsed values (user can review before saving)
- [ ] Rule-based parser (chrono-node or similar) — no LLM dependency
- [ ] Vitest tests for parsing accuracy

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
