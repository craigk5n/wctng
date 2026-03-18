# Test Coverage Analysis — WCTNG

> **Date:** 2026-03-18
> **Current Test Counts:** 408 Vitest + 400 PHPUnit + 11 Integration + 39 Playwright E2E = **858 total**

---

## Current Coverage Summary

| Layer | Tests | Coverage Estimate | Target |
|-------|-------|-------------------|--------|
| PHPUnit Unit Tests | 400 | ~55% of backend code | 80% |
| PHPUnit Integration Tests | 11 | ~15% of API flows | 50% |
| Vitest Component Tests | 408 | ~60% of frontend components | 80% |
| Playwright E2E Tests | 39 | ~30% of user flows | 70% |

**Overall estimated coverage: ~50%.** Target: **80%.**

---

## Backend Coverage Gaps

### Controllers Without Unit Tests (36 of 40)

Only 4 controllers have dedicated tests: AttachmentController, ConfigController, McpController, PublicCalendarController.

**Critical missing controller tests:**
| Controller | Priority | Why |
|-----------|----------|-----|
| EventController | P1 | Core CRUD — most complex controller |
| AuthController | P1 | Login, token refresh, security |
| CategoryController | P1 | CRUD + private/global filtering |
| UserController | P1 | User management, password change |
| TaskController | P2 | Task CRUD |
| JournalController | P2 | Journal CRUD |
| SearchController | P2 | Full-text search |
| PollController | P2 | Poll create/vote/finalize |
| BookingController | P2 | Public booking flow |
| ResourceController | P2 | Room management |
| ShareController | P2 | Share token CRUD |
| SubscriptionController | P3 | ICS subscription |
| PushController | P3 | Push subscription |
| LocationController | P3 | Working location |
| ApprovalController | P3 | Already tested via ApprovalWorkflowTest |

### Services Without Tests

| Service | Priority |
|---------|----------|
| DescriptionSanitizer | Already tested (23 tests) |
| ConflictDetectionService | Already tested (10 tests) |
| ValarmHelper | Already tested (8 tests) |
| EventNotificationService | P2 — email sending logic |
| ReminderService | P2 — cron reminder logic |
| EmailService | P3 — Symfony Mailer wrapper |

### Integration Test Gaps

Current: 6 test files covering event CRUD, public calendar, conflicts, share tokens, approval, config.

**Missing integration tests:**
- Auth flow (login → JWT → protected endpoint → expiry)
- CalDAV round-trip (PUT VEVENT → GET → verify)
- Webhook dispatch flow
- Email notification flow (mock mailer)
- Search with various filters
- Rate limiting behavior
- Multi-tenant isolation

---

## Frontend Coverage Gaps

### Components Without Any Tests (22 files)

**Critical (user-facing, interactive):**
| Component | Priority | Reason |
|-----------|----------|--------|
| QuickAddInput | P1 | NL parsing has unit test but component untested |
| WorkingLocationWidget | P1 | Stateful, API-calling widget |
| CustomFieldsSection | P1 | Dynamic form rendering |
| ImportDialog | P1 | File upload + ICS parsing |
| ExportButton | P2 | Calendar export |
| ViewSwitcher | Already tested (2 tests) |
| BookingPage | Already tested (4 tests) |

**Low priority (infrastructure/primitives):**
- shadcn/ui components (badge, button, card, etc.) — tested by usage
- ProtectedRoute, ToastProvider — infrastructure
- Control panel pages — admin-only, low frequency

### Pages With Minimal Tests

| Page | Current Tests | Gap |
|------|--------------|-----|
| CalendarPage | 2 (basic render) | No test for toolbar, dialog flows, layers |
| PreferencesPage | 0 | Preferences save/load |
| NotificationSettings | 0 | Toggle interactions |
| ShareSettings | 4 | Adequate |
| SubscriptionSettings | 4 | Adequate |

---

## E2E Coverage Gaps (Most Critical)

### User Flows With NO E2E Test

| Flow | Priority | Impact |
|------|----------|--------|
| **Recurring events** — create with RRULE, verify on calendar | P1 | Core feature, easy to regress |
| **Layer management** — add layer, toggle visibility, verify events | P1 | Critical multi-user feature |
| **Poll workflow** — create poll, add times, vote, finalize | P1 | New P7 feature, completely untested |
| **Journal CRUD** — create, edit, delete journal entries | P1 | Core feature, zero coverage |
| **Search** — search by title, verify results | P1 | Core feature, zero coverage |
| **Quick-add NLP** — type "Lunch tomorrow at noon", verify dialog | P1 | New P7 feature, parser tested but UI not |
| **Drag-and-drop** — drag event to new time, verify update | P1 | New P7 feature, only unit tested |
| **Custom fields** — admin creates field, user fills on event | P2 | Phase 6 feature, zero E2E |
| **Booking page** — visit /book/{user}, select slot, book | P2 | Public feature, zero E2E |
| **Profile editing** — change name/email, verify persisted | P2 | User-facing, zero E2E |
| **Resource management** — create room, book in event | P2 | New P7 feature |
| **Subscription management** — subscribe to ICS, verify display | P2 | New P7 feature |
| **API token management** — generate, copy, revoke | P3 | Settings page |
| **Language switching** — change language, verify UI updates | P3 | i18n feature |
| **Working location** — set office/remote, verify persisted | P3 | New P7 feature |
| **Sidebar collapse** — collapse, verify persisted, expand | P3 | UX feature |

### User Flows With Weak E2E Coverage

| Flow | Current | Gap |
|------|---------|-----|
| Rich text editing | Toolbar renders | No test of typing, formatting, save, display |
| Activity log | Page loads | No filtering, detail view, pagination |
| Admin settings | Page loads | No toggle interaction, state verification |
| Share links | API-level only | No UI flow (create, copy, revoke) |

---

## Recommendations

### Phase 8 Testing Epic: 5 Stories

**Story 1: Backend Controller Tests (P1)**
- Add tests for EventController, AuthController, CategoryController, UserController
- Target: 80% backend coverage
- Estimated: 30-40 new tests

**Story 2: Frontend Component Tests (P1)**
- Add tests for QuickAddInput, WorkingLocationWidget, CustomFieldsSection, ImportDialog
- Add missing tests for CalendarPage interactions
- Target: 80% component coverage
- Estimated: 20-30 new tests

**Story 3: E2E Core Flows (P1)**
- Recurring events, layer management, search, journal CRUD, drag-and-drop
- Target: All core user flows covered
- Estimated: 10-15 new E2E tests

**Story 4: E2E Phase 7 Features (P2)**
- Polls, quick-add NLP, subscriptions, resources, booking
- Target: All P7 features have E2E coverage
- Estimated: 10-12 new E2E tests

**Story 5: E2E Settings & Admin (P3)**
- Profile, API tokens, admin settings, language, sidebar
- Target: All settings pages exercised
- Estimated: 8-10 new E2E tests

**Total new tests needed: ~90-110 tests to reach 80% coverage.**
