# WCTNG — Phase 6 Development Plan & Status

> **Last Updated:** 2026-03-17
> **Phase:** 6 — Feature Completion
> **Goal:** Public calendars, rich text, conflict detection, year view, attachments, remaining service UIs, i18n
> **Methodology:** TDD (write tests first, then implementation)
> **Developed by:** AI Agent
> **Phase 1 Archive:** See `STATUS-PHASE1-ARCHIVE.md`
> **Phase 2 Archive:** See `STATUS-PHASE2-ARCHIVE.md`
> **Phase 3 Archive:** See `STATUS-PHASE3-ARCHIVE.md`
> **Phase 4 Archive:** See `STATUS-PHASE4-ARCHIVE.md`
> **Phase 5 Archive:** See `STATUS-PHASE5-ARCHIVE.md`

---

## Quick Status

| Epic | Title | Stories | Done | Status |
|------|-------|---------|------|--------|
| P6-E1 | Public Calendar & Sharing | 3 | 3 | DONE |
| P6-E2 | Rich Text Descriptions | 3 | 3 | DONE |
| P6-E3 | Conflict Detection & Approval | 3 | 3 | DONE |
| P6-E4 | Additional Views & Print | 2 | 2 | DONE |
| P6-E5 | Event Attachments & VALARM | 3 | 0 | TODO |
| P6-E6 | Remaining Service UIs | 4 | 0 | TODO |
| P6-E7 | Internationalization | 3 | 0 | TODO |
| P6-E8 | Admin Feature Configuration | 3 | 0 | TODO |
| **Total** | | **24** | **5** | |

---

## Dependency Graph

```
P6-E1 (Public Calendar) — independent
P6-E2 (Rich Text) — independent
P6-E3 (Conflict & Approval) — independent
P6-E4 (Views & Print) — independent
P6-E5 (Attachments & VALARM) — independent
P6-E6 (Service UIs) — independent
P6-E7 (i18n) — after E1-E8 ideally (translate what exists)
P6-E8 (Admin Config) — independent, but best after E2 (rich text toggle)
```

**All epics E1-E6, E8 are independent** and can be done in any order.
**E7 (i18n)** should come last so all UI strings exist before extracting translations.

---

## Global Standards

Same as Phase 1–5:
- PHP 8.2+, PHPStan level 9, Psalm errorLevel 1, PHPUnit 10
- React 18, TypeScript strict, ESLint, Vitest, Playwright
- TDD: write tests first, then implementation
- Docker-based development on ports 47180/47106/47173/47181

---

## Epic P6-E1: Public Calendar & Sharing

**Goal:** Allow unauthenticated access to public calendars with shareable links and embeddable views.

### P6-E1-S1: Public Calendar API

**Status:** DONE

**Description:**
API endpoints for accessing public calendar data without authentication. Admins configure which calendars are publicly visible.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [x] `webcal_entry` visibility field respected: public entries accessible without auth
- [x] `GET /api/v2/public/calendars` lists publicly visible calendars
- [x] `GET /api/v2/public/calendars/{username}/events?start={}&end={}` returns public events (date-range filtered)
- [x] Admin setting: per-user `public_calendar_enabled` flag (default off)
- [x] Admin API: `PUT /api/v2/admin/users/{login}/public-calendar` toggles public visibility
- [x] Public endpoints skip JWT authentication (firewall config)
- [x] Rate limiting on public endpoints (stricter than authenticated: 30 req/min)
- [x] PHPStan level 9 passes
- [x] Unit tests (13 tests, 26 assertions)

---

### P6-E1-S2: Public Calendar Frontend

**Status:** DONE

**Description:**
Read-only calendar view accessible without login, showing public events in a FullCalendar instance.

**Preconditions:** P6-E1-S1

**Acceptance Criteria:**
- [x] Route `/public/{username}` renders a read-only FullCalendar (month/week/day views)
- [x] No login required — page loads without JWT
- [x] Event click shows detail popup (title, time, location, description — no edit)
- [x] Calendar header shows owner's display name
- [x] Graceful 404 if user has no public calendar enabled
- [x] Mobile responsive
- [x] Vitest tests (6 tests)

---

### P6-E1-S3: Shareable Links & Embed

**Status:** DONE

**Description:**
Generate shareable URLs with optional access tokens for private sharing, plus an embeddable iframe snippet.

**Preconditions:** P6-E1-S2

**Acceptance Criteria:**
- [x] `POST /api/v2/calendars/share` generates a share token (UUID) with optional expiry
- [x] `GET /api/v2/public/shared/{token}/events` returns events for the shared calendar
- [x] Share tokens can be revoked: `DELETE /api/v2/calendars/share/{token}`
- [x] Settings page lists active share links with copy-to-clipboard button
- [x] Embed snippet generator: `<iframe src="/public/embed/{token}" ...>` with configurable dimensions
- [x] `/public/embed/{token}` renders a minimal calendar (no header/nav, just the grid)
- [x] PHPStan level 9 passes
- [x] Unit tests (17 PHP) + Vitest tests (6 frontend)

---

## Epic P6-E2: Rich Text Descriptions

**Goal:** Enable HTML rich text editing for event descriptions with a WYSIWYG editor and safe server-side sanitization.

### P6-E2-S1: Backend HTML Sanitization

**Status:** DONE

**Description:**
Add server-side HTML sanitization for event descriptions using Symfony HtmlSanitizer. Ensures stored HTML is safe against XSS while preserving formatting.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [x] `symfony/html-sanitizer` installed via Composer
- [x] `DescriptionSanitizer` service with allowlist: `p, br, strong, em, b, i, u, ul, ol, li, a[href], h2, h3, blockquote, code, pre`
- [x] Strips all other tags (`<script>`, `<style>`, `<iframe>`, `<img>`, event handlers like `onclick`)
- [x] Strips dangerous attributes (`style`, `on*` event handlers)
- [x] `a[href]` restricted to `http:`, `https:`, `mailto:` schemes (no `javascript:`)
- [x] Sanitization applied in EventController, TaskController, and JournalController on description field
- [x] Existing plain-text descriptions pass through unchanged
- [x] PHPStan level 9 passes
- [x] Unit tests: 23 tests — XSS payloads stripped, valid HTML preserved, plain text unchanged

---

### P6-E2-S2: TipTap Rich Text Editor

**Status:** DONE

**Description:**
Replace the plain textarea for event descriptions with a TipTap WYSIWYG editor styled with Tailwind/shadcn.

**Preconditions:** P6-E2-S1

**Acceptance Criteria:**
- [x] `@tiptap/react`, `@tiptap/starter-kit`, `@tiptap/extension-link` installed
- [x] `RichTextEditor` component with toolbar: bold, italic, bullet list, ordered list, link, heading (H2/H3), blockquote, code
- [x] Toolbar buttons styled with Tailwind, consistent with shadcn/ui design system
- [x] Editor outputs HTML string (not JSON) for API compatibility
- [x] Editor accepts initial HTML content and renders it correctly (edit mode)
- [x] Empty editor returns empty string (not `<p></p>`)
- [x] Editor integrated into event create/edit dialog, task create dialog, and journal create/edit
- [x] Keyboard shortcuts: Ctrl+B (bold), Ctrl+I (italic), Ctrl+K (link) via TipTap/ProseMirror
- [x] Vitest tests: 5 tests — renders, toolbar buttons, initial content, onChange

---

### P6-E2-S3: Rich Text Display & CalDAV Round-Trip

**Status:** DONE

**Description:**
Render stored HTML descriptions safely in event detail views and verify CalDAV import/export preserves rich text.

**Preconditions:** P6-E2-S2

**Acceptance Criteria:**
- [x] Event detail dialog renders description HTML using `dangerouslySetInnerHTML` with CSS scoping (prose class)
- [x] Task and journal detail views also render HTML descriptions (via RichTextDisplay component)
- [x] Search results snippets strip HTML tags for clean display (RichTextDisplay handles plain text fallback)
- [x] CalDAV export: HTML descriptions produce STYLED-DESCRIPTION + X-ALT-DESC + plain DESCRIPTION (verified via EventMapper)
- [x] CalDAV import: STYLED-DESCRIPTION / X-ALT-DESC HTML imported and sanitized before storage
- [x] ICS file export includes X-ALT-DESC for Outlook compatibility (via webcalendar-core EventMapper)
- [x] Vitest tests: 5 tests for RichTextDisplay rendering
- [x] PHPUnit tests: 3 tests — HTML round-trip, plain text round-trip, XSS sanitization

---

## Epic P6-E3: Conflict Detection & Approval

**Goal:** Detect scheduling conflicts and support approval workflows for event creation.

### P6-E3-S1: Conflict Detection API

**Status:** DONE

**Description:**
Server-side detection of overlapping events when creating or updating events.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [x] `ConflictDetectionService` checks for time overlaps with existing events for the same user
- [x] `GET /api/v2/events/conflicts?start={}&end={}&exclude_id={}` returns list of conflicting events
- [x] `POST /api/v2/events` and `PUT /api/v2/events/{id}` include `conflicts` array in response meta when overlaps exist
- [x] All-day events conflict with other all-day events on the same date
- [x] Recurring event instances checked via date-range query (RecurrenceService expands via EventService)
- [x] Configurable per-user: `conflict_mode` preference (`warn` | `block` | `off`, default `warn`)
- [x] `block` mode returns 409 Conflict and prevents save
- [x] `warn` mode returns 200 with `conflicts` in meta (client decides)
- [x] PHPStan level 9 passes
- [x] Unit tests: 10 tests — overlap, no overlap, all-day, exclude self, multiple conflicts, same-user only, response format

---

### P6-E3-S2: Conflict Detection UI

**Status:** DONE

**Description:**
Show conflict warnings in the event create/edit dialog when overlapping events are detected.

**Preconditions:** P6-E3-S1

**Acceptance Criteria:**
- [x] When saving an event, if API returns `conflicts` array, show a warning banner in the dialog
- [x] Warning lists conflicting event titles, times, and calendar names
- [x] User can dismiss warning and save anyway (in `warn` mode) via "Save Anyway" button
- [x] In `block` mode, shows "cannot be saved" message without dismiss button
- [x] Real-time check: debounced (500ms) conflict query fires when date/time/duration changes
- [x] Conflict indicator shown inline below time/duration fields in EventDialog
- [x] User preference toggle in Settings > Preferences: conflict detection mode (warn/block/off)
- [x] Vitest tests: 6 tests for ConflictWarning component

---

### P6-E3-S3: Approval Workflow

**Status:** DONE

**Description:**
Allow events to require admin approval before appearing on the calendar.

**Preconditions:** P6-E3-S1

**Acceptance Criteria:**
- [x] Event status field: `confirmed` (default), `tentative`, `needs_approval`, `rejected`
- [x] Admin setting: `require_event_approval` per-user preference flag (default off)
- [x] When enabled, new events from that user saved as `needs_approval` instead of `confirmed`
- [x] `GET /api/v2/admin/events/pending` lists events needing approval (admin only)
- [x] `PUT /api/v2/admin/events/{id}/approve` sets status to `confirmed`
- [x] `PUT /api/v2/admin/events/{id}/reject` sets status to `rejected` (with optional reason)
- [x] Pending events shown with visual indicator (dashed border, muted gray, 70% opacity) on calendar
- [x] Rejected events hidden from calendar (filtered out in FullCalendarWrapper)
- [x] Email notification: uses existing EventNotificationService infrastructure (triggered by status change)
- [x] Admin notification: pending events visible via GET /admin/events/pending endpoint
- [x] PHPStan level 9 passes
- [x] Unit tests: 6 PHPUnit (approve/reject/pending/auth) + frontend styling integrated

---

## Epic P6-E4: Additional Views & Print

**Goal:** Add year view and print-friendly stylesheets for all calendar views.

### P6-E4-S1: Year View

**Status:** DONE

**Description:**
Full-year calendar grid showing all 12 months with event indicators.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [x] Year button in view switcher toolbar (multiMonthYear)
- [x] 12-month grid layout (responsive via FullCalendar multimonth plugin)
- [x] Days with events show dot indicators (colored by category)
- [x] Click on a day navigates to that day's day view (FullCalendar built-in)
- [x] Click on a month header navigates to that month's month view (FullCalendar built-in)
- [x] Year navigation: previous/next year arrows (FullCalendar built-in)
- [x] Current day highlighted (bg-primary/10)
- [x] FullCalendar `multiMonthYear` view via @fullcalendar/multimonth plugin
- [x] Keyboard shortcut: Y for year view
- [x] Vitest tests: 2 tests

---

### P6-E4-S2: Print Styles

**Status:** DONE

**Description:**
CSS `@media print` rules for clean printable output from day, week, month, and year views.

**Preconditions:** P6-E4-S1

**Acceptance Criteria:**
- [x] Print button in toolbar (triggers `window.print()`) with printer icon
- [x] `@media print` hides: sidebar, toolbar buttons, header nav, scrollbars
- [x] Day view: events listed chronologically (FullCalendar renders visible content)
- [x] Week view: 7-column grid with events (scroller overflow set to visible)
- [x] Month view: month grid with event titles (page-break-inside: avoid)
- [x] Year view: 12-month grid with dot indicators (page-break-inside: avoid)
- [x] Event colors print as background colors (`-webkit-print-color-adjust: exact`)
- [x] Page header shows calendar title centered (fc-toolbar-title)
- [x] Vitest tests: 2 tests for PrintButton (renders, calls window.print)

---

## Epic P6-E5: Event Attachments & VALARM

**Goal:** File attachment support for events and iCalendar alarm (VALARM) support in CalDAV.

### P6-E5-S1: File Upload API

**Status:** TODO

**Description:**
API endpoints for uploading, listing, and downloading file attachments on events.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [ ] `POST /api/v2/events/{id}/attachments` accepts multipart/form-data file upload
- [ ] `GET /api/v2/events/{id}/attachments` lists attachments (id, filename, mime_type, size, created_at)
- [ ] `GET /api/v2/events/{id}/attachments/{attachmentId}` downloads the file
- [ ] `DELETE /api/v2/events/{id}/attachments/{attachmentId}` removes attachment
- [ ] Storage via BlobService (webcalendar-core `webcal_blob` table)
- [ ] File size limit: 10MB per file (configurable via env var)
- [ ] Allowed MIME types: images, PDF, Office docs, text files (configurable)
- [ ] Max 10 attachments per event
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P6-E5-S2: Attachment UI

**Status:** TODO

**Description:**
File attachment management in the event detail dialog.

**Preconditions:** P6-E5-S1

**Acceptance Criteria:**
- [ ] Attachment section in event detail dialog showing file list
- [ ] Upload button with drag-and-drop zone
- [ ] Upload progress indicator
- [ ] File preview for images (thumbnail), download link for others
- [ ] Delete button with confirmation (event owner or admin only)
- [ ] File size and type displayed for each attachment
- [ ] Error messages for oversized or disallowed file types
- [ ] Vitest tests

---

### P6-E5-S3: VALARM CalDAV Support

**Status:** TODO

**Description:**
Support iCalendar VALARM components in CalDAV for trigger-based reminders recognized by native calendar apps.

**Preconditions:** P6-E5-S1

**Acceptance Criteria:**
- [ ] `CoreCalendarBackend` reads/writes VALARM components in VEVENT and VTODO
- [ ] VALARM DISPLAY type supported (popup reminder in calendar apps)
- [ ] VALARM AUDIO type supported (sound reminder)
- [ ] TRIGGER property: relative duration (e.g., `-PT15M` = 15 min before) and absolute datetime
- [ ] Default alarm: user preference maps to VALARM on export (e.g., "15 min before" → `-PT15M`)
- [ ] Import: VALARM trigger extracted and stored as reminder setting on the event
- [ ] Multiple alarms per event supported
- [ ] PHPStan level 9 passes
- [ ] Unit tests: VALARM round-trip (create with alarm → export ICS → verify VALARM → import → verify alarm preserved)

---

## Epic P6-E6: Remaining Service UIs

**Goal:** Build frontend admin/user interfaces for core services that are wired but lack UI.

### P6-E6-S1: Activity Log Viewer

**Status:** TODO

**Description:**
Admin page to browse the activity/audit log.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [ ] Route `/admin/activity-log` accessible to admins
- [ ] Paginated table of activity log entries (newest first)
- [ ] Columns: timestamp, user, action (create/update/delete), entity type, entity name, IP address
- [ ] Filter by user, action type, date range
- [ ] Search by entity name
- [ ] Click on entry shows full detail (before/after values if available)
- [ ] Uses ActivityLogService from webcalendar-core
- [ ] Vitest tests

---

### P6-E6-S2: Custom Event Fields Admin

**Status:** TODO

**Description:**
Admin page for defining custom event fields (site extras) that appear on event forms.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [ ] Route `/admin/custom-fields` accessible to admins
- [ ] List of defined custom fields with name, type, required flag
- [ ] Create/edit custom field: name, type (text, number, date, select, checkbox), required, sort order
- [ ] Select type: define options list
- [ ] Delete custom field (with confirmation — warns about data loss)
- [ ] Custom fields appear dynamically on event create/edit dialog
- [ ] Custom field values saved via SiteExtraService
- [ ] Custom field values displayed in event detail dialog
- [ ] PHPStan level 9 passes (API endpoint for CRUD)
- [ ] Unit + Vitest tests

---

### P6-E6-S3: Boss/Assistant Management

**Status:** TODO

**Description:**
Settings page for managing boss/assistant calendar relationships.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [ ] Route `/settings/assistants` accessible to all users
- [ ] User can add assistants: search users by name, grant calendar access
- [ ] User can see who they are an assistant for (boss list)
- [ ] Assistant permissions: view events, create events on behalf, edit events on behalf
- [ ] Assistants see boss's calendar in their layer list
- [ ] `GET /api/v2/users/{login}/assistants` and `POST/DELETE` endpoints
- [ ] Uses AssistantService from webcalendar-core
- [ ] PHPStan level 9 passes
- [ ] Unit + Vitest tests

---

### P6-E6-S4: Public Booking Page

**Status:** TODO

**Description:**
Public-facing availability/booking page where external users can schedule time on a user's calendar.

**Preconditions:** P6-E1-S1 (public calendar API)

**Acceptance Criteria:**
- [ ] Route `/book/{username}` accessible without login
- [ ] Shows available time slots based on user's calendar (free/busy)
- [ ] Configurable booking settings per user: slot duration (15/30/60 min), available hours, buffer between slots
- [ ] `GET /api/v2/public/availability/{username}?date={}` returns available slots for a given day
- [ ] Booking form: name, email, description (no account required)
- [ ] `POST /api/v2/public/book/{username}` creates a tentative event + sends confirmation email
- [ ] Booking confirmation email with cancel link (HMAC-signed token)
- [ ] Uses BookingService from webcalendar-core
- [ ] PHPStan level 9 passes
- [ ] Unit + Vitest tests

---

## Epic P6-E7: Internationalization

**Goal:** Multi-language support for the entire application with language selector and RTL support.

### P6-E7-S1: Backend i18n Setup

**Status:** TODO

**Description:**
Configure Symfony translations for API error messages, email templates, and system strings.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [ ] Symfony Translation component configured with `translations/` directory
- [ ] Default locale: `en` with fallback chain
- [ ] API error messages use translation keys (e.g., `error.event.not_found`)
- [ ] Email notification templates use translated strings
- [ ] `Accept-Language` header sets request locale
- [ ] User preference: `locale` field stored in user profile
- [ ] `GET /api/v2/i18n/locales` returns list of supported locales with display names
- [ ] Initial languages: English (en), French (fr), German (de), Spanish (es)
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P6-E7-S2: Frontend i18n Integration

**Status:** TODO

**Description:**
Integrate react-i18next for frontend string translation with lazy-loaded locale bundles.

**Preconditions:** P6-E7-S1

**Acceptance Criteria:**
- [ ] `react-i18next` and `i18next` installed
- [ ] All hardcoded UI strings extracted to translation JSON files (`en.json`, `fr.json`, `de.json`, `es.json`)
- [ ] `useTranslation()` hook used in all components
- [ ] Locale JSON files lazy-loaded (only active locale downloaded)
- [ ] Date/time formatting uses `Intl.DateTimeFormat` with active locale
- [ ] Number formatting uses `Intl.NumberFormat` with active locale
- [ ] FullCalendar locale set dynamically via `locale` prop
- [ ] Vitest tests: components render with different locales

---

### P6-E7-S3: Language Selector & RTL

**Status:** TODO

**Description:**
Language selector in user settings and app header, plus RTL layout support.

**Preconditions:** P6-E7-S2

**Acceptance Criteria:**
- [ ] Language dropdown in user settings page (saves to user profile via API)
- [ ] Language dropdown in app header (quick switch, no page reload)
- [ ] Language persisted to localStorage for unauthenticated pages (login, public calendar)
- [ ] RTL layout support: `dir="rtl"` on `<html>` element when locale is RTL
- [ ] Tailwind CSS RTL utilities: logical properties (`ms-`, `me-`, `ps-`, `pe-` instead of `ml-`, `mr-`)
- [ ] Arabic (ar) and Hebrew (he) locale files added with RTL flag
- [ ] Calendar grid, dialogs, and navigation properly mirrored in RTL
- [ ] Vitest tests: RTL rendering, language switch

---

## Epic P6-E8: Admin Feature Configuration

**Goal:** Allow admins to enable/disable features (rich text descriptions, location field, URL field, etc.) via system settings, matching legacy WebCalendar's ConfigService capabilities.

### P6-E8-S1: Config API Endpoints

**Status:** TODO

**Description:**
API endpoints for reading and updating system configuration settings using ConfigService from webcalendar-core.

**Preconditions:** Phase 5 complete

**Acceptance Criteria:**
- [ ] `GET /api/v2/admin/config` returns all system settings as key-value pairs
- [ ] `PUT /api/v2/admin/config` accepts partial updates (key-value map)
- [ ] Settings stored via ConfigService in `webcal_config` table
- [ ] Default settings defined: `ALLOW_HTML_DESCRIPTION` (Y/N), `DISABLE_LOCATION_FIELD` (Y/N), `DISABLE_URL_FIELD` (Y/N), `DISABLE_PRIORITY_FIELD` (Y/N), `DISABLE_PARTICIPANTS_FIELD` (Y/N)
- [ ] `GET /api/v2/config/features` returns feature flags (public, no admin required) for frontend conditional rendering
- [ ] PHPStan level 9 passes
- [ ] Unit tests

---

### P6-E8-S2: Admin Settings Page

**Status:** TODO

**Description:**
Admin page for toggling feature flags with clear labels and descriptions.

**Preconditions:** P6-E8-S1

**Acceptance Criteria:**
- [ ] Route `/admin/settings` accessible to admins
- [ ] Toggle switches for each feature: rich text descriptions, location field, URL field, priority field, participants
- [ ] Each toggle shows label, description, and current state
- [ ] Changes saved immediately via API (optimistic UI)
- [ ] Success toast on save
- [ ] Vitest tests

---

### P6-E8-S3: Frontend Feature Flag Integration

**Status:** TODO

**Description:**
Frontend reads feature flags from the API and conditionally shows/hides fields in event, task, and journal forms.

**Preconditions:** P6-E8-S2

**Acceptance Criteria:**
- [ ] `useFeatureFlags()` hook fetches and caches feature flags from `/api/v2/config/features`
- [ ] EventDialog hides location field when `DISABLE_LOCATION_FIELD=Y`
- [ ] EventDialog shows plain textarea instead of RichTextEditor when `ALLOW_HTML_DESCRIPTION=N`
- [ ] EventDialog hides participants section when `DISABLE_PARTICIPANTS_FIELD=Y`
- [ ] TasksPage and JournalsPage respect `ALLOW_HTML_DESCRIPTION` flag
- [ ] Feature flags cached in React Query with 5-minute stale time
- [ ] Vitest tests

---

## Story Execution Checklist (for AI Agent)

Same as Phase 1–5:

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

## Phase 1–5 Summary

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
