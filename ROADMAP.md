# WCTNG — Feature Roadmap

> **Last Updated:** 2026-03-17
> **Current State:** Phase 5 complete (148 stories across 5 phases)
> **Reference:** Original WebCalendar at github.com/craigk5n/webcalendar

---

## Gap Analysis: WCTNG vs Original WebCalendar

### Core Services Integration Status

| Core Service | Wired | Used in WCTNG | Notes |
|---|---|---|---|
| EventService | Yes | Yes | Full CRUD, CalDAV, participants |
| UserService | Yes | Yes | Auth, prefs, admin |
| TaskService | Yes | Yes | VTODO via CalDAV |
| JournalService | Yes | Yes | VJOURNAL via CalDAV |
| GroupService | Yes | Yes | LDAP sync included |
| CategoryService | Yes | Yes | CRUD + combobox UI |
| LayerService | Yes | Yes | Calendar overlays |
| ImportService | Yes | Yes | ICS import |
| ExportService | Yes | Yes | ICS export |
| SecurityService | Yes | Yes | Tokens, CSRF |
| PermissionService | Yes | Yes | UAC checks |
| ConfigService | Yes | Yes | System settings |
| ActivityLogService | Yes | Yes | Audit trail |
| BlobService | Yes | Yes | Attachments & comments |
| ResourceService | Yes | Yes | Shared calendars |
| TemplateService | Yes | Yes | Custom templates |
| ViewService | Yes | Yes | Custom views |
| SiteExtraService | Yes | Yes | Custom event fields |
| ReportService | Yes | Yes | Reports + export |
| SearchService | Yes | Yes | Full-text search |
| RecurrenceService | Yes | Yes | RRULE/EXDATE |
| AssistantService | Yes | Yes | Boss/assistant |
| BookingService | Yes | Yes | Public scheduling |
| NotificationService | Yes | Yes | Email & webhooks |
| **FeedService** | **No** | **No** | RSS, Free/Busy feeds |
| **TranslationService** | **No** | **No** | Core i18n (Symfony i18n used instead) |

**24 of 26 core services are wired.** FeedService and TranslationService remain.

---

### Missing Features

Features present in the original WebCalendar that are not yet implemented in WCTNG:

#### Priority 1 — High Value

| Feature | Description | Effort |
|---|---|---|
| **Public Calendar View** | Unauthenticated read-only calendar view with configurable visibility | Medium |
| **Conflict Detection** | Warn when creating overlapping events; optional hard-block mode | Small |
| **Approval Workflow** | Events with status Tentative/Waiting → admin approves/rejects | Medium |
| **Internationalization (i18n)** | Multi-language UI; original supported 30+ languages | Large |
| **Rich Text Descriptions** | HTML/rich text event descriptions with WYSIWYG editor | Medium |

#### Priority 2 — Feature Parity

| Feature | Description | Effort |
|---|---|---|
| **Year View** | Full-year calendar grid showing all 12 months | Small |
| **Print Styles** | CSS `@media print` for day/week/month views | Small |
| **VALARM Support** | iCalendar alarm components (trigger-based reminders in CalDAV) | Medium |
| **Nonuser Calendars** | Resource/room calendars (partially via ResourceService) | Medium |
| **Event Attachments** | File uploads on events (BlobService wired but no upload UI) | Medium |

#### Priority 3 — Nice to Have

| Feature | Description | Effort |
|---|---|---|
| **RSS/Atom Feeds** | Calendar event feeds (FeedService exists in core) | Small |
| **Free/Busy Publishing** | Standard iCalendar free/busy URL for external clients | Small |
| **Custom Event Fields UI** | SiteExtraService wired but no admin UI for field definitions | Medium |
| **Activity Log Viewer** | ActivityLogService wired but no admin UI to browse logs | Small |
| **Boss/Assistant UI** | AssistantService wired but no UI for managing relationships | Small |
| **Booking/Availability UI** | BookingService wired but no public-facing booking page | Medium |

---

### Implemented Features (Phases 1-5)

For reference, features already complete:

- **Phase 1:** Symfony REST API, React SPA, FullCalendar, JWT auth, event CRUD, user/category admin, Docker Compose
- **Phase 2:** Participants, groups, layers, tasks, journals, import/export, search, real-time (Mercure), permissions, mobile responsive
- **Phase 3:** Multi-tenancy (subdomain resolution, per-tenant DBs, control plane API, tenant-scoped JWT, rate limiting)
- **Phase 4:** CalDAV (sabre/dav with VEVENT/VTODO/VJOURNAL, sync-token, scheduling), OAuth2/OIDC with PKCE, LDAP with group sync, per-tenant auth registry
- **Phase 5:** Email notifications, webhooks, full-text search, reports/analytics, performance/caching (ETag, Redis), production readiness (structured logging, health checks, security hardening, production Docker)

---

## Phase 6 Proposal: Feature Completion

### Epic P6-E1: Public Calendar & Sharing
- Public read-only calendar view (no auth required)
- Shareable calendar links with optional token
- Embeddable iframe snippet

### Epic P6-E2: Rich Text Descriptions
- WYSIWYG editor for event descriptions (TipTap or Lexical)
- HTML sanitization on API input (HTMLPurifier)
- CalDAV round-trip via STYLED-DESCRIPTION / X-ALT-DESC (already supported in core EventMapper)

### Epic P6-E3: Conflict Detection & Approval
- Overlap warning on event create/edit
- Optional hard-block mode per calendar
- Approval workflow: Tentative → Approved/Rejected

### Epic P6-E4: Additional Views & Print
- Year view (12-month grid)
- Print-friendly CSS for day/week/month/year views

### Epic P6-E5: Event Attachments
- File upload API endpoint (multipart/form-data)
- Attachment list in event detail dialog
- Storage via BlobService (already wired)

### Epic P6-E6: Remaining Service UIs
- Activity log viewer (admin)
- Custom event fields admin
- Boss/assistant management
- Public booking page

### Epic P6-E7: Internationalization
- Symfony translation files for UI strings
- Language selector in user preferences
- RTL support for Arabic/Hebrew

---

## Rich Text Description Analysis

### Current State in webcalendar-core

The core already has solid rich text support at the data and iCalendar layers:

**Storage:** The `cal_description` column is `TEXT` with no format restrictions — HTML is stored as-is.

**iCalendar Export** (`EventMapper::toVEvent`): When description contains HTML (detected via `strip_tags()` comparison), a triple-output strategy is used:

1. `STYLED-DESCRIPTION;VALUE=TEXT;FMTTYPE=text/html` — RFC 9073 standard
2. `X-ALT-DESC;FMTTYPE=text/html` — Outlook/Thunderbird compatibility
3. `DESCRIPTION;DERIVED=TRUE` — Plain-text fallback via `htmlToPlainText()` conversion

For plain-text descriptions, only a single `DESCRIPTION` property is emitted.

**iCalendar Import** (`EventMapper::fromVEvent`): Reads with priority chain:
STYLED-DESCRIPTION > X-ALT-DESC (with FMTTYPE=text/html) > DESCRIPTION

**What's missing:**
- No HTML sanitization on API input (`SecurityService::sanitizeHtml()` exists but is not called for descriptions — XSS risk)
- No rich text editor in the frontend UI — descriptions use a plain `<textarea>`

### UI Rich Text Editor Options

| Editor | Bundle Size (gzip) | Approach | React Support | Pros | Cons |
|--------|-------------------|----------|---------------|------|------|
| **TipTap** | ~45KB | Headless (ProseMirror) | Excellent | Style with Tailwind/shadcn, great API, active community, easy to constrain allowed markup | Slightly larger |
| **Lexical** (Meta) | ~22KB | Plugin-based | Native | Very lightweight, extensible | Younger ecosystem, more DIY for toolbar |
| **Slate** | ~35KB | Framework | React-first | Highly customizable | Unstable API history, steep learning curve |
| **React Quill** | ~43KB | Wrapper (Quill) | Good | Easy drop-in | Quill 1.x aging, less customizable |

**Recommendation: TipTap**
- Headless architecture integrates perfectly with our Tailwind + shadcn/ui design system
- Built-in extensions for bold, italic, lists, links, headings — exactly what event descriptions need
- Excellent TypeScript support with full type safety
- ProseMirror foundation is battle-tested and rock-solid
- Easy to restrict allowed HTML elements (no `<script>`, `<style>`, `<iframe>`, etc.)
- Active maintenance and large community

**Backend sanitization:** Use `symfony/html-sanitizer` (Symfony's built-in component) to strip dangerous tags/attributes before storage. This avoids adding a third-party dependency like HTMLPurifier.

### Implementation Plan (P6-E2)

1. Add `symfony/html-sanitizer` — configure allowlist (p, br, strong, em, ul, ol, li, a, h2, h3)
2. Sanitize description in `EventRequestDTO` or a Symfony request listener before persistence
3. Install `@tiptap/react`, `@tiptap/starter-kit`, `@tiptap/extension-link`
4. Replace `<textarea>` with TipTap editor in event create/edit dialog
5. Render stored HTML safely in event detail view (already escaped by React's JSX)
6. CalDAV round-trip works automatically — EventMapper already handles HTML descriptions

---

## Technical Debt & Improvements

| Item | Description |
|---|---|
| HTML sanitization | Add HTMLPurifier or Symfony HtmlSanitizer for description input |
| VALARM in CalDAV | Add alarm component support in CoreCalendarBackend |
| E2E test coverage | Expand Playwright tests beyond smoke tests |
| Accessibility audit | WCAG 2.1 AA compliance review |
| API versioning strategy | Plan for v3 if breaking changes needed |
