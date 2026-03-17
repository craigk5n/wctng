# WCTNG vs Legacy WebCalendar — Feature Gap Analysis

> **Date:** 2026-03-17
> **Legacy Version:** WebCalendar v1.9.x (craigk5n/webcalendar)
> **WCTNG Phases Completed:** 1–6 (175 stories)

---

## Executive Summary

WCTNG has reimplemented the vast majority of legacy WebCalendar functionality on a modern stack (Symfony 7 + React 18 + CalDAV). Several legacy features have been **superseded** by modern equivalents (CalDAV replaces the proprietary sync protocol, OAuth2/OIDC replaces IMAP/NIS auth, Mercure replaces polling). A handful of niche legacy features remain unimplemented, documented below.

**Overall coverage: ~90% of legacy functionality reimplemented or superseded.**

---

## Feature Comparison

### Fully Implemented (Parity or Better)

| Legacy Feature | WCTNG Implementation | Notes |
|---|---|---|
| Day/Week/Month/Year views | FullCalendar 6 with all views | Year view uses multimonth plugin (better) |
| Event CRUD | REST API + React dialog | Rich text descriptions (TipTap), HTML sanitization |
| Recurring events (RRULE/EXDATE) | CalDAV + RecurrenceService | RFC 5545 compliant |
| Tasks (VTODO) | TaskController + CalDAV | Percent complete, due dates |
| Journals (VJOURNAL) | JournalController + CalDAV | Rich text support |
| Categories with colors | CategoryController | Searchable combobox UI |
| User management | UserController | Profile self-editing, admin CRUD |
| Groups | GroupController | LDAP group sync |
| Layers (multi-user view) | LayerController + LayerPanel | Color per layer |
| Access control (view/edit/see-time-only) | AccessController | Per-user permissions |
| Assistant/Boss relationships | AssistantController | Full management UI |
| Import/Export (ICS) | Import/ExportController | Via API and CalDAV |
| Search (full-text) | SearchController | Filters, autocomplete, snippets |
| Activity log | ActivityLogController | Paginated viewer with filters |
| Reminders | ReminderRepository + VALARM | CalDAV-compatible alarms |
| Email notifications | EventNotificationService | Invitation, update, cancellation, reminder |
| Approval workflow | ApprovalController | Pending/approve/reject with visual indicators |
| Conflict detection | ConflictDetectionService | Warn/block/off modes, debounced UI |
| Custom event fields (site extras) | CustomFieldController | Dynamic form rendering |
| File attachments | AttachmentController + BlobService | Upload/download/delete with MIME validation |
| Public calendar access | PublicCalendarController | Per-user toggle, rate limited |
| Print styles | @media print CSS | All views, color preservation |
| User preferences | PreferencesPage | Default view, timezone, locale, conflict mode |
| System configuration | ConfigController | Feature flags (rich text, location, participants) |
| Password authentication | AuthController + JWT | Bcrypt hashing, rate limited |
| LDAP authentication | LdapAuthenticator | Auto-provisioning, group sync, TLS |
| Themes (light/dark) | ThemeProvider | System preference detection |
| Mobile responsive | Tailwind CSS | Hamburger menu, bottom sheets, swipe gestures |
| Webhooks | WebhookDispatcher | HMAC signatures, retry, delivery log |
| Real-time updates | MercurePublisher | Server-Sent Events (better than polling) |
| Multi-database support | PDO abstraction | MySQL + SQLite (via webcalendar-core) |
| Reports | ReportController | Activity, busy hours, categories, CSV export |
| Booking/scheduling | BookingController | Public availability page |
| Shareable links | ShareController | Token-based, embeddable iframe |

### Superseded by Modern Equivalents

| Legacy Feature | Legacy Approach | WCTNG Approach | Why |
|---|---|---|---|
| CalDAV/iCal sync | Proprietary `icalclient.php`, `freebusy.php` | sabre/dav 4.7 CalDAV server | Standards-based, works with Apple Calendar, Thunderbird, etc. |
| IMAP/NIS authentication | `user-imap.php`, `user-nis.php` | OAuth2/OIDC with PKCE | Modern, secure, supports Google/Azure/GitHub SSO |
| Joomla CMS integration | `user-app-joomla.php` | OAuth2 provider configuration | Generic SSO instead of CMS-specific |
| Polling for updates | Page refresh | Mercure SSE (Server-Sent Events) | True real-time, no polling overhead |
| PHP session auth | `$_SESSION` | JWT (stateless) | Scalable, multi-tenant, CalDAV compatible |
| PHP-rendered views | `day.php`, `week.php`, etc. | React SPA + FullCalendar | Faster navigation, offline-capable, modern UX |
| jQuery + Bootstrap | Legacy JS/CSS | React 18 + Tailwind + shadcn/ui | Type-safe, component-based, accessible |
| Mini calendar widget | `minical.php` | Embed via iframe (`/public/embed/{token}`) | Self-contained, no server-side includes needed |
| MCP server (AI) | `mcp.php` with custom protocol | REST API | Any AI tool can use the standard REST API |

### Partially Implemented (Functional but Simpler)

| Legacy Feature | Legacy Capability | WCTNG Status | Gap |
|---|---|---|---|
| Custom views | 7 view types (day/list/month/report/task/time/week) with arbitrary user groupings | FullCalendar 5 built-in views | No arbitrary custom view creation UI. FullCalendar covers the common cases. |
| Report templates | Custom HTML templates with placeholder substitution | CSS-based charts + CSV export | No custom template editor. Reports are functional but not templated. |
| Category icons | Category images stored as MIME blobs | Category colors only | No icon upload. Colors provide sufficient visual distinction. |
| Event URL field | Dedicated URL field on events | Not in default form | URL can be added via custom fields. Could be a built-in field. |
| Event priority field | Priority 1-9 on events | Not in default form | Priority can be added via custom fields. Tasks have priority. |
| External participants | Email-only participants (no account) via `webcal_entry_ext_user` | Participants must be registered users | External participants not supported. Booking page covers external scheduling. |
| Moon phases | Lunar phase display on calendar | Not implemented | Niche feature. Could be a plugin. |

### Not Implemented (Legacy-Only Features)

| Legacy Feature | Description | Reason Not Implemented |
|---|---|---|
| **RSS feeds** | `rss.php`, `rss_activity_log.php`, `rss_unapproved.php` — RSS 2.0 calendar feeds | RSS usage has declined significantly. CalDAV subscription provides the same functionality for calendar apps. Could be added as a small feature if demand exists. |
| **Free/Busy publishing** | `freebusy.php` — iCalendar VFREEBUSY URL for external clients | CalDAV scheduling (inbox/outbox) handles free/busy queries. Standalone URL publishing could be added. |
| **Remote calendar subscription** | `remotecal_mgmt.php` — subscribe to external ICS URLs and merge into calendar | Not implemented. Users can subscribe via their CalDAV client instead. Could be added as a backend feature. |
| **Security audit page** | `security_audit.php` — configuration vulnerability scanner | Not implemented. Production Docker handles most security concerns. Could be added as an admin tool. |
| **Database admin tools** | `tools/convert_latin1_to_utf8.php`, `tools/convert_passwords.php` | Not needed — WCTNG starts fresh with UTF-8 and modern password hashing. |
| **Server-Side Includes (SSI)** | `week_ssi.php` — embed calendar in Apache SSI pages | Superseded by iframe embed (`/public/embed/{token}`). |
| **Oracle/DB2/ODBC support** | Multi-database via `dbi4php.php` | WCTNG supports MySQL and SQLite. PostgreSQL via webcalendar-core. Oracle/DB2 are legacy enterprise DBs with declining usage. |
| **Availability grid** | `availability.php` — multi-user availability comparison | Not implemented as a standalone view. Conflict detection and booking page cover the use case partially. |
| **Plugin system** | `get_plugin_list()` — loadable plugin modules | Not implemented. Feature flags provide similar configurability. A plugin architecture could be a future enhancement. |
| **109 languages** | Legacy WebCalendar has 109 translation files | WCTNG has 6 languages (en, fr, de, es, ar, he). Additional translations can be added by copying JSON files. The infrastructure supports unlimited languages. |
| **Nonuser calendar admin UI** | `resourcecal_mgmt.php` — dedicated resource calendar management | ResourceService is wired but no dedicated admin UI. Resources can be managed as regular users. |
| **Custom view creation UI** | `edit_view.php` — create arbitrary views showing specific users/resources | ViewService is wired but no creation UI. FullCalendar views + layers cover most use cases. |
| **Report template editor** | `edit_report.php` — HTML template editor with placeholders | ReportService provides data. No visual template editor. CSV export available. |
| **Document management** | `doc.php`, `docadd.php` — standalone document upload (not event-attached) | Event attachments cover file uploads. Standalone document management not implemented. |

---

## Architecture Advantages of WCTNG

Features that WCTNG has that legacy WebCalendar does **not**:

| Feature | Description |
|---|---|
| **CalDAV server** | Native CalDAV with sabre/dav — works with Apple Calendar, Thunderbird, DAVx5 |
| **OAuth2/OIDC SSO** | Google, GitHub, Azure, any OIDC provider with PKCE |
| **Multi-tenancy** | Full tenant isolation with per-tenant databases, control plane API |
| **Real-time updates** | Mercure SSE — no page refresh needed |
| **Rich text descriptions** | TipTap WYSIWYG editor with HTML sanitization |
| **REST API** | Complete JSON API with OpenAPI spec |
| **JWT authentication** | Stateless, scalable, CalDAV-compatible |
| **React SPA** | Fast client-side navigation, code splitting, offline-capable |
| **VALARM support** | iCalendar alarm components in CalDAV |
| **Webhook notifications** | Event-driven integrations with HMAC signatures |
| **Public booking page** | External users can book time slots |
| **Embeddable calendar** | iframe embed with share tokens |
| **Feature flags** | Admin-toggleable fields (rich text, location, participants) |
| **Docker production** | Multi-stage build, health checks, TLS termination |
| **770+ automated tests** | Unit, integration, component, E2E (Playwright) |
| **TypeScript strict** | Full type safety on frontend |
| **PHPStan level 9** | Maximum static analysis on backend |

---

## Recommendations for Phase 7 (If Needed)

### High Priority (Real User Value)
1. **Remote calendar subscription** — subscribe to external ICS URLs, merge events
2. **Availability grid** — multi-user comparison view for meeting scheduling
3. **More languages** — community-contributed translations (infrastructure ready)

### Medium Priority (Feature Parity)
4. **RSS feeds** — calendar event feeds for legacy integrations
5. **Free/Busy URL publishing** — standalone VFREEBUSY for external tools
6. **External participants** — email-only invitees (no account required)
7. **Resource calendar admin UI** — dedicated management for rooms/equipment

### Low Priority (Niche)
8. **Custom view creation** — arbitrary user/resource grouping views
9. **Report template editor** — HTML templates with placeholder substitution
10. **Security audit page** — configuration vulnerability scanner
11. **Plugin architecture** — loadable extension modules
12. **Moon phases** — lunar display on calendar

---

## Conclusion

WCTNG successfully reimplements ~90% of legacy WebCalendar functionality while adding significant modern capabilities (CalDAV, OAuth2, multi-tenancy, real-time, rich text, feature flags). The remaining gaps are primarily niche features (RSS, moon phases, 109 languages) or legacy integrations (Oracle DB, Joomla, NIS) that have modern equivalents. The most impactful missing features — remote calendar subscription and availability grid — could be addressed in a focused Phase 7 if user demand warrants it.
