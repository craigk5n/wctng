# WCTNG — Phase 2 Development Plan & Status

> **Last Updated:** 2026-03-16
> **Phase:** 2 — Multi-User & Collaboration
> **Goal:** Participants, groups, permissions, layers, tasks, journals, import/export, real-time updates
> **Methodology:** TDD (write tests first, then implementation)
> **Developed by:** AI Agent
> **Phase 1 Archive:** See `STATUS-PHASE1-ARCHIVE.md`

---

## Quick Status

| Epic | Title | Stories | Done | Status |
|------|-------|---------|------|--------|
| P2-E1 | Event Participants | 4 | 4 | DONE |
| P2-E2 | Groups | 3 | 3 | DONE |
| P2-E3 | Calendar Layers | 3 | 0 | NOT STARTED |
| P2-E4 | Tasks | 4 | 2 | IN PROGRESS |
| P2-E5 | Journals | 3 | 2 | IN PROGRESS |
| P2-E6 | Import/Export | 3 | 3 | DONE |
| P2-E7 | Search | 2 | 2 | DONE |
| P2-E8 | Real-time (Mercure) | 3 | 0 | NOT STARTED |
| P2-E9 | Permissions & Access Control | 3 | 0 | NOT STARTED |
| P2-E10 | UI Polish & UX | 4 | 3 | IN PROGRESS |
| **Total** | | **32** | **19** | |

---

## Dependency Graph

```
P2-E1 (Participants) ──► P2-E8 (Real-time)
P2-E2 (Groups) ──► P2-E9 (Permissions)
P2-E3 (Layers) ──► P2-E10 (UI Polish)
P2-E4 (Tasks)
P2-E5 (Journals)
P2-E6 (Import/Export) — depends on P2-E4 (tasks in iCal)
P2-E7 (Search)
P2-E9 (Permissions) ──► P2-E3 (Layers need permissions)
```

**Critical path:** P2-E1 → P2-E8 (participants before real-time)
**Independent:** P2-E4, P2-E5, P2-E7 can be done in parallel

---

## Global Standards

Same as Phase 1:
- PHP 8.2+, PHPStan level 9, Psalm errorLevel 1, PHPUnit 10
- React 18, TypeScript strict, ESLint, Vitest, Playwright
- TDD: write tests first, then implementation
- Docker-based development on ports 47180/47106/47173

---

## Epic P2-E1: Event Participants

**Goal:** Allow events to have multiple participants with status tracking (accepted, rejected, tentative).

### P2-E1-S1: Participants API Endpoints

**Status:** DONE

**Description:**
Implement REST endpoints for managing event participants. Delegates to webcalendar-core's `EventService::addParticipant()`, `removeParticipant()`, `setParticipantStatus()`.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] `GET /api/v2/events/{id}/participants` — returns list of participants with status
- [x] `POST /api/v2/events/{id}/participants` — add participants (body: `{participants: ["user1", "user2"]}`)
- [x] `DELETE /api/v2/events/{id}/participants/{login}` — remove a participant
- [x] `PUT /api/v2/events/{id}/participants/{login}` — update participant status (body: `{status: "A"}`)
- [x] Only event owner or admin can add/remove participants (via EventService authorization)
- [x] Participant can update their own status
- [x] Event GET response includes `participants` array with login + status
- [x] PHPStan level 9 passes
- [x] Psalm passes

---

### P2-E1-S2: Approve/Reject Event Endpoints

**Status:** DONE

**Description:**
Implement `POST /api/v2/events/{id}/approve` and `POST /api/v2/events/{id}/reject` for participants to respond to event invitations.

**Preconditions:** P2-E1-S1

**Acceptance Criteria:**
- [x] `POST /api/v2/events/{id}/approve` — sets current user's status to Accepted
- [x] `POST /api/v2/events/{id}/reject` — sets current user's status to Rejected
- [x] Only participants of the event can approve/reject (via EventService authorization)
- [x] Returns 400 if user is not a participant, 404 if event not found
- [x] PHPStan level 9 passes, Psalm clean

---

### P2-E1-S3: Participants UI in Event Detail

**Status:** DONE

**Description:**
Show participants in EventDetailDialog and allow adding/removing participants in EventDialog.

**Preconditions:** P2-E1-S1

**Acceptance Criteria:**
- [x] EventDetailDialog shows participant list with name + status badge (Accepted/Rejected/Tentative)
- [x] EventDialog (edit mode) has a participant input field — type username, press Enter to add
- [x] Participants can be removed from the edit dialog (chip ✕ button)
- [x] Status badges are color-coded (green=accepted, red=rejected, yellow=pending)
- [x] Vitest tests pass (8 new tests)

---

### P2-E1-S4: Participant Status Response UI

**Status:** DONE

**Description:**
When viewing an event the user is invited to, show Accept/Reject buttons.

**Preconditions:** P2-E1-S2, P2-E1-S3

**Acceptance Criteria:**
- [x] Events where current user is a pending participant show Accept/Reject buttons in detail view
- [x] Clicking Accept calls `POST /events/{id}/approve`, refreshes event detail
- [x] Clicking Reject calls `POST /events/{id}/reject`, refreshes event detail
- [x] Already-responded events show current status with option to change (Accept↔Decline)
- [x] Vitest tests pass (6 new tests)

---

## Epic P2-E2: Groups

**Goal:** User groups for organizing participants and permissions.

### P2-E2-S1: Groups API Endpoints

**Status:** DONE

**Description:**
CRUD endpoints for groups and group membership.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] `GET /api/v2/groups` — list all groups
- [x] `POST /api/v2/groups` — create group (body: `{name}`) returns 201
- [x] `GET /api/v2/groups/{id}` — get group with members array
- [x] `DELETE /api/v2/groups/{id}` — delete group (204)
- [x] `POST /api/v2/groups/{id}/members` — add members (body: `{users: ["user1"]}`)
- [x] `DELETE /api/v2/groups/{id}/members/{login}` — remove member (204)
- [x] PHPStan level 9 passes, Psalm clean

---

### P2-E2-S2: Groups Management UI

**Status:** DONE

**Description:**
Admin page for managing groups and their members.

**Preconditions:** P2-E2-S1

**Acceptance Criteria:**
- [x] Route `/admin/groups` with sidebar link (admin only)
- [x] List all groups with member count
- [x] Create group form with name field
- [x] Click group to see/manage members
- [x] Add/remove members from a group
- [x] Vitest tests pass

---

### P2-E2-S3: Group Selection in Event Participants

**Status:** DONE

**Description:**
Allow adding an entire group as participants to an event.

**Preconditions:** P2-E2-S1, P2-E1-S3

**Acceptance Criteria:**
- [x] EventDialog participant input shows groups in addition to individual users
- [x] Selecting a group expands to all group members as individual participants
- [x] Groups shown with a distinct icon/badge
- [x] Vitest tests pass

---

## Epic P2-E3: Calendar Layers

**Goal:** Overlay other users' calendars on your own view.

### P2-E3-S1: Layers API Endpoints

**Status:** NOT STARTED

**Description:**
CRUD endpoints for calendar layers (overlays).

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [ ] `GET /api/v2/layers` — list current user's layers
- [ ] `POST /api/v2/layers` — add a layer (body: `{source_user, color, visible}`)
- [ ] `PUT /api/v2/layers/{id}` — update layer settings
- [ ] `DELETE /api/v2/layers/{id}` — remove a layer
- [ ] Events from layered users included in `GET /events` when layers are active
- [ ] PHPStan level 9 passes

---

### P2-E3-S2: Layer Management UI

**Status:** NOT STARTED

**Description:**
Sidebar panel for managing calendar layers.

**Preconditions:** P2-E3-S1

**Acceptance Criteria:**
- [ ] Sidebar section showing active layers with color + user name
- [ ] Toggle visibility per layer (checkbox)
- [ ] Add layer: user search dropdown + color picker
- [ ] Remove layer button
- [ ] Layer events shown on calendar with layer color
- [ ] Vitest tests pass

---

### P2-E3-S3: Multi-User Calendar View

**Status:** NOT STARTED

**Description:**
When layers are active, fetch and display events from multiple users on the same calendar with distinct colors.

**Preconditions:** P2-E3-S2

**Acceptance Criteria:**
- [ ] FullCalendarWrapper fetches events from all active layers + own events
- [ ] Events from different users have different colors (from layer settings)
- [ ] Event detail shows which user's calendar the event belongs to
- [ ] Layer toggle immediately adds/removes events without page reload
- [ ] Vitest tests pass

---

## Epic P2-E4: Tasks

**Goal:** Task (to-do) management with due dates, priority, and completion tracking.

### P2-E4-S1: Tasks API Endpoints

**Status:** DONE

**Description:**
CRUD endpoints for tasks. Tasks are calendar entries with type 'T' or 'N'.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] `GET /api/v2/tasks?start=YYYYMMDD&end=YYYYMMDD` — list tasks in date range
- [x] `POST /api/v2/tasks` — create task (body: `{title, due_date, priority}`)
- [x] `GET /api/v2/tasks/{id}` — get task details
- [x] `PUT /api/v2/tasks/{id}` — update task (including `percent_complete`)
- [x] `DELETE /api/v2/tasks/{id}` — delete task (204)
- [x] Task response includes: `title`, `due_date`, `due_time`, `priority`, `percent_complete`, `status`
- [x] PHPStan level 9 passes, Psalm clean

---

### P2-E4-S2: Tasks Page UI

**Status:** DONE

**Description:**
Dedicated tasks page with list view, filtering, and inline completion.

**Preconditions:** P2-E4-S1

**Acceptance Criteria:**
- [x] Route `/tasks` with sidebar link (✅ Tasks)
- [x] Task list with title, due date, completion %, status
- [x] Filter by status: All, Pending, Completed
- [x] Checkbox to mark task complete/incomplete (toggles percent_complete 0↔100)
- [x] Delete button per task
- [x] Create task form with title + due date
- [x] Vitest tests pass (5 new tests)

---

### P2-E4-S3: Tasks on Calendar

**Status:** NOT STARTED

**Description:**
Show tasks as events on the calendar (on their due date).

**Preconditions:** P2-E4-S1

**Acceptance Criteria:**
- [ ] Tasks appear on calendar on their due date with a distinct style (e.g., dashed border, task icon)
- [ ] Clicking a task on calendar opens task detail (not event detail)
- [ ] Completed tasks shown with strikethrough or muted style
- [ ] Vitest tests pass

---

### P2-E4-S4: Tasks E2E Tests

**Status:** NOT STARTED

**Description:**
Playwright E2E tests for task CRUD workflow.

**Preconditions:** P2-E4-S2

**Acceptance Criteria:**
- [ ] Create task via UI, verify it appears in list
- [ ] Mark task complete, verify status changes
- [ ] Edit task details
- [ ] Delete task
- [ ] All tests pass in under 30 seconds

---

## Epic P2-E5: Journals

**Goal:** Journal/diary entries associated with dates.

### P2-E5-S1: Journals API Endpoints

**Status:** DONE

**Description:**
CRUD endpoints for journal entries (VJOURNAL type).

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] `GET /api/v2/journals?start=YYYYMMDD&end=YYYYMMDD` — list journals in date range
- [x] `POST /api/v2/journals` — create journal (body: `{date, title, text}`) returns 201
- [x] `GET /api/v2/journals/{id}` — get journal entry
- [x] `PUT /api/v2/journals/{id}` — update journal (title, text)
- [x] `DELETE /api/v2/journals/{id}` — delete journal (204)
- [x] PHPStan level 9 passes, Psalm clean

---

### P2-E5-S2: Journals Page UI

**Status:** DONE

**Description:**
Journal page with chronological list and editing.

**Preconditions:** P2-E5-S1

**Acceptance Criteria:**
- [x] Route `/journals` with sidebar link (📓 Journals)
- [x] Chronological list of journal entries (newest first)
- [x] Create journal form with title, date picker, text area
- [x] Inline edit (title + text) with save/cancel
- [x] Delete with toast notification
- [x] Vitest tests pass (4 new tests)

---

### P2-E5-S3: Journals on Calendar

**Status:** NOT STARTED

**Description:**
Show journal entries on the calendar as small indicators on their date.

**Preconditions:** P2-E5-S1

**Acceptance Criteria:**
- [ ] Journal entries appear as small icons/dots on their date in month view
- [ ] Clicking the indicator opens the journal entry
- [ ] Distinct visual style from events and tasks
- [ ] Vitest tests pass

---

## Epic P2-E6: Import/Export

**Goal:** Import and export calendar data in iCalendar (ICS) format.

### P2-E6-S1: Export API Endpoint

**Status:** DONE

**Description:**
Export calendar events as an ICS file.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] `GET /api/v2/export?format=ics&start=YYYYMMDD&end=YYYYMMDD` — returns ICS file
- [x] Response Content-Type: `text/calendar; charset=utf-8`
- [x] Exported ICS contains valid VCALENDAR/VEVENT structure
- [x] Content-Disposition header with filename `webcalendar-START-to-END.ics`
- [x] Empty calendar returned when no events in range
- [x] PHPStan level 9 passes, Psalm clean

---

### P2-E6-S2: Import API Endpoint

**Status:** DONE

**Description:**
Import events from an uploaded ICS file.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] `POST /api/v2/import` — accepts multipart/form-data with ICS file
- [x] Returns import result: `{imported, skipped, warnings}`
- [x] Handles duplicate detection (via ImportService UID handling)
- [x] Validates ICS content before importing (checks for BEGIN:VCALENDAR)
- [x] Invalid ICS returns 400 with error message
- [x] PHPStan level 9 passes, Psalm clean

---

### P2-E6-S3: Import/Export UI

**Status:** DONE

**Description:**
UI for importing and exporting calendar data.

**Preconditions:** P2-E6-S1, P2-E6-S2

**Acceptance Criteria:**
- [x] Export button in calendar toolbar — downloads ICS file (±1 year range)
- [x] Import dialog — file upload with drag-and-drop zone
- [x] Import result shows imported/skipped counts
- [x] Confirm/Cancel buttons, Import button disabled without file
- [x] Success/error toast with import summary
- [x] Vitest tests pass (5 new tests)

---

## Epic P2-E7: Search

**Goal:** Full-text search across events, tasks, and journals.

### P2-E7-S1: Search API Endpoint

**Status:** DONE

**Description:**
Global search endpoint using webcalendar-core's SearchService.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] `GET /api/v2/search?q=keyword&start=YYYYMMDD&end=YYYYMMDD` — searches event titles and descriptions
- [x] Optional date range filters (defaults to ±1 year)
- [x] Returns standard envelope with matching events
- [x] Results include event type, date, title, description
- [x] PHPStan level 9 passes, Psalm clean

---

### P2-E7-S2: Search UI

**Status:** DONE

**Description:**
Search input in the app header with results dropdown.

**Preconditions:** P2-E7-S1

**Acceptance Criteria:**
- [x] Search input in the app header (always visible on desktop)
- [x] Debounced search (300ms) as user types (min 2 chars)
- [x] Results dropdown showing matching events with type icon (📅/✅/📓)
- [x] Clicking a result navigates to the event's date on the calendar (day view)
- [x] Empty state message when no results
- [x] Keyboard navigation (ArrowUp/Down, Enter to select, Escape to close)
- [x] Vitest tests pass (5 new tests)

---

## Epic P2-E8: Real-time Updates (Mercure)

**Goal:** Live updates when other users create/modify/delete events.

### P2-E8-S1: Mercure Hub Setup

**Status:** NOT STARTED

**Description:**
Add Mercure hub to Docker Compose and configure Symfony to publish events.

**Preconditions:** P2-E1 (participants — so there are multi-user scenarios)

**Acceptance Criteria:**
- [ ] Mercure hub added to `docker-compose.dev.yml` on port 47181
- [ ] `config/packages/mercure.yaml` configured
- [ ] `MercurePublisher` service publishes event changes to topics
- [ ] Topics follow pattern: `/calendars/events/{eventId}`
- [ ] JWT token for Mercure publisher configured
- [ ] Health check for Mercure hub

---

### P2-E8-S2: Server-Side Event Publishing

**Status:** NOT STARTED

**Description:**
Publish SSE notifications when events are created, updated, or deleted.

**Preconditions:** P2-E8-S1

**Acceptance Criteria:**
- [ ] Event create publishes `{type: "event.created", event: {...}}`
- [ ] Event update publishes `{type: "event.updated", event: {...}}`
- [ ] Event delete publishes `{type: "event.deleted", eventId: ...}`
- [ ] Participant changes publish `{type: "participant.changed", ...}`
- [ ] Only published to relevant users (event participants + owner)
- [ ] PHPStan level 9 passes

---

### P2-E8-S3: Client-Side SSE Subscription

**Status:** NOT STARTED

**Description:**
React hook that subscribes to Mercure SSE and updates the calendar in real-time.

**Preconditions:** P2-E8-S2

**Acceptance Criteria:**
- [ ] `src/hooks/useMercure.ts` — subscribes to event topics via EventSource
- [ ] On `event.created` / `event.updated`: refetch events (invalidate React Query cache)
- [ ] On `event.deleted`: remove event from calendar immediately
- [ ] Reconnects automatically on connection loss
- [ ] Toast notification: "Calendar updated by [user]"
- [ ] Vitest tests pass

---

## Epic P2-E9: Permissions & Access Control

**Goal:** Fine-grained permissions for viewing and editing other users' calendars.

### P2-E9-S1: Access Control API

**Status:** NOT STARTED

**Description:**
Endpoints for managing user-to-user access permissions.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [ ] `GET /api/v2/access/users` — get user access permissions
- [ ] `PUT /api/v2/access/users/{login}` — set permissions (body: `{can_view, can_edit}`)
- [ ] Permissions enforced on `GET /events` when viewing other users' events
- [ ] Private events hidden from users without permission
- [ ] Confidential events show as "Busy" to users without full access
- [ ] PHPStan level 9 passes

---

### P2-E9-S2: Access Control Settings UI

**Status:** NOT STARTED

**Description:**
User settings page for managing who can view/edit their calendar.

**Preconditions:** P2-E9-S1

**Acceptance Criteria:**
- [ ] Route `/settings/access` accessible to all users
- [ ] List of users with checkboxes: Can View, Can Edit
- [ ] Save button to persist changes
- [ ] Toast on save success/failure
- [ ] Vitest tests pass

---

### P2-E9-S3: Permission Enforcement in UI

**Status:** NOT STARTED

**Description:**
Respect permissions when showing events from other users (via layers).

**Preconditions:** P2-E9-S1, P2-E3-S2

**Acceptance Criteria:**
- [ ] Private events from other users not shown in layers
- [ ] Confidential events shown as "Busy" with no details
- [ ] Edit/delete buttons hidden for events user doesn't have permission to modify
- [ ] Vitest tests pass

---

## Epic P2-E10: UI Polish & UX

**Goal:** Quality-of-life improvements and UI refinements.

### P2-E10-S1: User Preferences

**Status:** DONE

**Description:**
User preferences page for default view, timezone, language.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] Route `/settings/preferences` with sidebar link (⚙ Settings)
- [x] Default calendar view selector (month/week/day/list)
- [x] Timezone text input
- [x] Work day start/end time settings
- [x] Preferences saved via `PUT /api/v2/users/{login}/preferences`
- [x] Calendar loads with saved STARTVIEW preference
- [x] Vitest tests pass (3 new frontend + 3 backend tests)

---

### P2-E10-S2: Dark Mode

**Status:** DONE

**Description:**
Toggle between light and dark themes using the existing Tailwind CSS variable system.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] Dark mode toggle in header (🌙/☀️ button)
- [x] Preference saved in localStorage (`wctng_theme`)
- [x] All components render correctly in dark mode (including FullCalendar via CSS variables)
- [x] System preference detection (`prefers-color-scheme` media query)
- [x] Vitest tests pass (7 new tests)

---

### P2-E10-S3: Mobile Responsive Improvements

**Status:** NOT STARTED

**Description:**
Improve mobile experience with swipe gestures, collapsible sidebar, and touch-friendly controls.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [ ] Hamburger menu for mobile sidebar
- [ ] Swipe left/right on calendar for prev/next navigation
- [ ] Touch-friendly event creation (long-press on time slot)
- [ ] Event dialogs full-screen on mobile
- [ ] Bottom sheet for event details on mobile
- [ ] Playwright mobile viewport tests

---

### P2-E10-S4: Keyboard Shortcuts Help

**Status:** DONE

**Description:**
Keyboard shortcut help dialog and additional shortcuts.

**Preconditions:** Phase 1 complete

**Acceptance Criteria:**
- [x] `?` key opens keyboard shortcuts help dialog
- [x] Lists all shortcuts: navigation (←→T), view switching (MWD), event creation (N)
- [x] `N` key opens new event dialog
- [x] `?` button in calendar toolbar opens help
- [x] Vitest tests pass (10 new tests)

---

## Story Execution Checklist (for AI Agent)

Same as Phase 1:

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

## Phase 1 Summary

Phase 1 completed 2026-03-16 with 45/45 stories + post-Phase-1 enhancements:

**Backend:** 147 PHP tests, PHPStan level 9, Psalm errorLevel 1
- Symfony 7.x REST API (auth, events, users, categories)
- webcalendar-core integration (27 services, 19 repositories)
- JWT authentication, CORS, standard JSON envelope

**Frontend:** 118 Vitest tests + 16 Playwright E2E tests
- React 18 + Vite + TypeScript + Tailwind CSS + Shadcn/ui
- FullCalendar with themed CSS, category colors
- Event CRUD with create/edit/delete dialogs + toast notifications
- User management + category management admin pages
- Login, routing, protected routes, keyboard shortcuts

**Infrastructure:** Docker Compose (nginx, PHP-FPM, MySQL, Vite), GitHub Actions CI
