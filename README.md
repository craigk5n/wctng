# WebCalendar Next Generation (WCTNG)

A modern, full-featured calendar application built as a ground-up rewrite of [WebCalendar](https://github.com/craigk5n/webcalendar). Designed for self-hosting with support for 1000+ users and 100k+ events.

## Features

**Calendar & Events**
- Multi-view calendar (month, week, day, year, list) with drag-and-drop rescheduling and resize
- Recurring events with full RFC 5545 RRULE support (daily, weekly, monthly, yearly, custom)
- Event categories with color coding and multi-select filtering
- Per-event color overrides, rich text descriptions (TipTap editor)
- Natural language event creation ("Lunch with Bob tomorrow at noon")
- Conflict detection (warn or block mode)

**Collaboration**
- Multi-user calendar layers with color-coded overlays
- Event participants with accept/decline/maybe responses
- Scheduling polls (propose times, vote, auto-schedule winner)
- Room and resource booking
- Saved views for team calendars (personal + admin global views)
- User-to-user access control (view, edit, time-only)
- Event comments

**Integrations**
- CalDAV server (sabre/dav) — sync with Apple Calendar, Thunderbird, etc.
- ICS subscription feeds (holidays, shared calendars) with auto-refresh
- ICS file import/export with category auto-creation
- MCP server for AI assistant integration (Claude, ChatGPT)
- Webhooks for external system notifications
- OAuth2/OIDC and LDAP authentication

**Public & SEO**
- Server-rendered public event pages for search engine crawlers
- Schema.org JSON-LD structured data
- Open Graph and Twitter Card meta tags
- Auto-generated sitemap.xml and robots.txt
- OpenStreetMap integration (Leaflet.js maps on public pages)
- Configurable privacy: admin global toggle, per-user opt-out, noindex option

**Admin & Operations**
- Admin dashboard with system stats and health monitoring
- Security audit page (19 automated checks)
- Database backup and restore (MySQL + SQLite)
- Custom HTML header/trailer and CSS injection
- Feature flags for granular control (tasks, journals, attachments, comments, SEO, etc.)
- Legacy WebCalendar data import with schema auto-detection (v1.0 through v1.9.x)
- Structured JSON logging with request correlation IDs
- Email reminders and daily agenda notifications with one-click unsubscribe

**Progressive Web App**
- Installable PWA with offline fallback
- Web Push notifications for event reminders
- Service worker with cache-first static assets

**Internationalization**
- 6 languages: English, French, German, Spanish, Arabic, Hebrew
- Full RTL support for Arabic and Hebrew

## Architecture

```
webcalendar-api/     Symfony 7.x REST API (PHP 8.3+)
webcalendar-web/     React 18 SPA (TypeScript, Vite, Tailwind CSS)
webcalendar-core     Business logic library (Composer package from GitHub)
docker/              Docker Compose development environment
bin/                 CI, performance, and utility scripts
```

| Component | Technology |
|-----------|-----------|
| Backend | PHP 8.3+, Symfony 7.x, PDO (MySQL/SQLite) |
| Frontend | React 18, TypeScript strict, Vite, Tailwind CSS, Shadcn/ui |
| Calendar | FullCalendar v6 with drag-and-drop, recurrence, multi-view |
| Rich Text | TipTap editor with link support |
| CalDAV | sabre/dav 4.7 |
| Real-time | Mercure SSE hub |
| Auth | JWT (lexik/jwt-authentication-bundle), OAuth2/OIDC, LDAP |
| Maps | OpenStreetMap + Leaflet.js (no API key needed) |
| i18n | react-i18next with 6 languages + RTL |
| Testing | PHPUnit, PHPStan 9, Vitest, Playwright |

## Quick Start

### Prerequisites

- Docker and Docker Compose
- PHP 8.3+ and Composer (for local development)
- Node.js 18+ and npm

### Development Setup

```bash
# Clone the repository
git clone https://github.com/craigk5n/wctng.git
cd wctng

# Start the Docker development environment
cd webcalendar-api
docker compose up -d

# Install PHP dependencies
composer install

# Install frontend dependencies
cd ../webcalendar-web
npm install

# Start the Vite dev server
npm run dev
```

The application will be available at:
- **Web App**: http://localhost:47180
- **API**: http://localhost:47180/api/v2
- **MySQL**: localhost:47106
- **Vite HMR**: localhost:47173
- **Mercure Hub**: localhost:47181

### Default Credentials

Login: `admin` / `admin`

> **Important**: Change the default admin password immediately. The Security Audit page (Admin > Security Audit) will flag this.

### Environment Variables

Copy `.env` to `.env.local` in `webcalendar-api/` and configure:

```env
DATABASE_URL="mysql://user:pass@mysql:3306/webcalendar"
APP_SECRET=your-random-32-char-secret
JWT_TTL=3600
MAILER_DSN=smtp://mailhog:1025
DEFAULT_URI=http://localhost:47180
```

## Testing

WCTNG has a comprehensive test suite with **1,040+ tests** across 5 layers:

```bash
# Run all quick checks (no Docker needed for most)
bin/ci quick

# Individual test suites
bin/ci phpstan         # PHPStan level 9 static analysis
bin/ci phpcs           # PSR-12 code style (PHP_CodeSniffer)
bin/ci test            # PHPUnit (432 unit + 147 integration)
bin/ci tsc             # TypeScript strict mode
bin/ci vitest          # Vitest (461 React/TS tests)

# E2E tests (requires running Docker environment)
bin/ci e2e             # Playwright (~130 E2E tests)
bin/ci all             # Everything including E2E

# Code style auto-fix
php vendor/bin/php-cs-fixer fix
```

| Suite | Tests | What it covers |
|-------|-------|---------------|
| PHPStan | — | Type safety, logic errors (level 9) |
| PHPCS | — | PSR-12 code style enforcement |
| PHPUnit | 579 | Services, controllers, DB queries, API endpoints |
| TypeScript | — | Frontend type safety (strict mode) |
| Vitest | 461 | React components, hooks, utilities |
| Playwright | ~130 | Full user flows in Chromium |
| **Total** | **1,040+** | |

## Performance

Performance tested with 12,700 events and 1,160 users:

| Endpoint | Response Time |
|----------|--------------|
| Calendar month view | 92ms |
| Calendar with layers | 116ms |
| Search (FULLTEXT) | 27ms |
| Single event | 21ms |
| Sitemap (cached) | 131ms |

```bash
# Generate test data for performance testing
docker exec <php-container> php bin/console webcalendar:seed-test-data \
  --users=1000 --events=100000 --categories=20

# Run performance baseline
bin/perf-baseline

# Run load tests
bin/perf-load --scenario=mixed --concurrent=50 --duration=60

# Clean up test data
docker exec <php-container> php bin/console webcalendar:seed-test-data --cleanup
```

## Project Structure

```
webcalendar-api/
├── src/
│   ├── Controller/Api/      # REST API endpoints
│   ├── Controller/Seo/      # SSR pages for search engines
│   ├── Service/             # Business logic services
│   ├── Security/            # JWT auth, user provider
│   ├── EventSubscriber/     # Request ID, cache headers, exceptions
│   ├── Monolog/             # Structured logging
│   └── Command/             # CLI commands (import, seed, reminders)
├── config/                  # Symfony configuration
├── migrations/              # Database migration SQL
└── tests/
    ├── Unit/                # Pure logic tests (SQLite in-memory)
    └── Integration/         # Service + DB tests (SQLite in-memory)

webcalendar-web/
├── src/
│   ├── calendar/            # Calendar page, event dialogs, filters
│   ├── admin/               # Admin pages (users, categories, dashboard)
│   ├── settings/            # User settings pages
│   ├── components/          # Shared UI components
│   ├── hooks/               # Custom React hooks
│   └── auth/                # Authentication context and providers
├── tests/e2e/               # Playwright E2E tests
└── e2e/                     # Accessibility tests

bin/
├── ci                       # Unified CI script (test, phpstan, vitest, etc.)
├── perf-baseline            # Performance measurement tool
└── perf-load                # Concurrent load testing tool
```

## CLI Commands

```bash
# Database
webcalendar:seed-test-data    # Generate performance test data
webcalendar:import-legacy     # Import from legacy WebCalendar database

# Email
webcalendar:send-reminders    # Send event reminder emails (cron: every minute)
webcalendar:send-daily-agenda # Send daily agenda emails (cron: every hour)

# Maintenance
webcalendar:refresh-subscriptions  # Refresh ICS subscription feeds
```

## API

REST API at `/api/v2/` with JWT authentication. Standard envelope: `{data, meta, error}`.

Key endpoints:
- `POST /api/v2/auth/login` — JWT token
- `GET /api/v2/events?start=YYYYMMDD&end=YYYYMMDD` — list events
- `POST /api/v2/events` — create event
- `GET /api/v2/categories` — list categories
- `POST /api/v2/mcp` — MCP server for AI assistants
- `GET /api/v2/health` — health check
- `GET /api/v2/admin/security-audit` — security checks
- `GET /api/v2/admin/dashboard` — system statistics

CalDAV at `/dav/` — compatible with Apple Calendar, Thunderbird, and other CalDAV clients.

## Migrating from Legacy WebCalendar

```bash
# Auto-detects schema version (1.0 through 1.9.x)
docker exec <php-container> php bin/console webcalendar:import-legacy \
  --dsn="mysql://user:pass@host/old_webcalendar_db"

# Preview without writing
docker exec <php-container> php bin/console webcalendar:import-legacy \
  --dsn="mysql://user:pass@host/old_webcalendar_db" --dry-run
```

The import tool:
- Auto-detects schema version via column probing
- Imports users, events, categories, preferences, recurrence rules
- Generates UIDs for legacy events (`legacy-{id}@imported`)
- Creates missing categories as personal (non-global)
- Users get random passwords and must reset
- Idempotent: safe to re-run (skips already-imported via UID matching)

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Write tests first (TDD methodology)
4. Ensure CI passes: `bin/ci quick`
5. Submit a pull request

### Code Standards

- PHP: PSR-12, PHPStan level 9, `declare(strict_types=1)` on all files
- TypeScript: strict mode, no `any` types
- Tests: write tests before implementation (TDD)
- Commits: conventional commit messages (`feat:`, `fix:`, `perf:`, `test:`)

## License

GPL-2.0 — see [LICENSE](LICENSE) for details.

## Credits

- **Craig Knudsen** — Creator of [WebCalendar](https://github.com/craigk5n/webcalendar) (1999–present)
- Built with [webcalendar-core](https://github.com/craigk5n/webcalendar-core) and [php-icalendar-core](https://github.com/craigk5n/php-icalendar-core)
- Calendar UI: [FullCalendar](https://fullcalendar.io/)
- Maps: [OpenStreetMap](https://www.openstreetmap.org/) + [Leaflet.js](https://leafletjs.com/)
- CalDAV: [sabre/dav](https://sabre.io/)
