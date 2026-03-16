# WebCalendar Next Generation (WCTNG) — Architecture Plan

## Overview

A ground-up rewrite of WebCalendar leveraging `webcalendar-core` for business logic,
with a modern SPA frontend and a standards-based API layer. Designed for both
self-hosted and multi-tenant hosted deployment, with a 20+ year longevity target.

---

## Repository Structure (Polyrepo)

```
webcalendar-core      — Business logic library (existing, Composer)
webcalendar-api       — Symfony REST API + CalDAV + real-time
webcalendar-web       — React SPA (primary frontend)
webcalendar-openapi   — Shared OpenAPI spec + generated clients
```

### Why polyrepo?
- Multiple SPA frontends can consume the same API independently
- OpenAPI spec repo acts as the contract between API and all frontends
- webcalendar-core remains a standalone, reusable library
- Independent versioning and release cycles per repo

---

## Tech Stack

| Layer | Technology | Rationale |
|-------|-----------|-----------|
| Business Logic | webcalendar-core (PHP 8.2+) | Clean architecture, 27 services, 19 repositories, RFC 5545 |
| API Framework | Symfony 7.x | Component-based, extensible auth, Mercure support, long-term stability |
| Real-time | Mercure Hub (SSE) | Symfony-native, simpler than WebSockets, works through proxies/firewalls |
| CalDAV | sabre/dav (future) | De facto PHP CalDAV, used by Nextcloud/ownCloud |
| Frontend | React 18+ via Vite | Fast builds, static output, no Node server in production |
| UI Components | Shadcn/ui + Tailwind CSS | Copy/paste ownership, no vendor lock-in, Radix primitives |
| Calendar Views | FullCalendar (MIT core) | 10+ year track record, day/week/month/list, drag-and-drop |
| Auth (MVP) | Username/password + JWT | Symfony Security component, extensible to OAuth2/LDAP/OIDC |
| API Contract | OpenAPI 3.1 | Auto-generated TypeScript clients via openapi-typescript |
| Database | MySQL/PostgreSQL (hosted), SQLite (standalone) | Per-tenant database isolation |
| Deployment | Docker Compose (hosted) + traditional LAMP (standalone) | Broad accessibility |

---

## System Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                         Clients                              │
│                                                              │
│  React SPA #1   React SPA #N   CalDAV Clients   Mobile App  │
│  (webcalendar-  (future         (Apple Calendar,  (future)   │
│   web)           frontends)     Thunderbird)                 │
└──────┬──────────────┬───────────────┬──────────────┬─────────┘
       │ REST/JSON     │ REST/JSON     │ CalDAV       │ REST
       │               │               │              │
┌──────▼───────────────▼───────────────▼──────────────▼─────────┐
│                    nginx / reverse proxy                       │
│  ┌─────────────────┐  ┌──────────────────┐  ┌──────────────┐ │
│  │ /api/*  → PHP   │  │ /dav/*  → PHP    │  │ /*  → static │ │
│  │         FPM     │  │          FPM     │  │      SPA     │ │
│  └────────┬────────┘  └────────┬─────────┘  └──────────────┘ │
│           │                    │                              │
│  ┌────────▼────────────────────▼──────────────────────────┐   │
│  │              Symfony Application                        │   │
│  │                                                         │   │
│  │  ┌─────────────┐ ┌──────────────┐ ┌─────────────────┐  │   │
│  │  │ REST API    │ │ CalDAV       │ │ Auth Providers  │  │   │
│  │  │ Controllers │ │ (sabre/dav)  │ │ ┌─────────────┐ │  │   │
│  │  │             │ │              │ │ │ Password+JWT│ │  │   │
│  │  │ - Events    │ │ Phase 2      │ │ │ OAuth2     ◄├─┤  │   │
│  │  │ - Users     │ │              │ │ │ LDAP       ◄├─┤  │   │
│  │  │ - Calendar  │ │              │ │ │ OIDC       ◄├─┤  │   │
│  │  │ - Tasks     │ │              │ │ └─────────────┘ │  │   │
│  │  │ - Groups    │ │              │ │                  │  │   │
│  │  │ - Import/   │ │              │ │ Extensible via   │  │   │
│  │  │   Export    │ │              │ │ Symfony Security │  │   │
│  │  │ - Search    │ │              │ │ component        │  │   │
│  │  │ - Admin     │ │              │ │                  │  │   │
│  │  └──────┬──────┘ └──────┬───────┘ └──────────────────┘  │   │
│  │         │               │                                │   │
│  │  ┌──────▼───────────────▼────────────────────────────┐   │   │
│  │  │         Tenant Resolver Middleware                 │   │   │
│  │  │  - Resolves tenant from subdomain / header / JWT  │   │   │
│  │  │  - Selects correct PDO connection for tenant DB   │   │   │
│  │  │  - Falls through to default DB for standalone     │   │   │
│  │  └──────┬────────────────────────────────────────────┘   │   │
│  │         │                                                │   │
│  │  ┌──────▼────────────────────────────────────────────┐   │   │
│  │  │          webcalendar-core (Composer)               │   │   │
│  │  │                                                    │   │   │
│  │  │  Domain:         Application:      Infrastructure: │   │   │
│  │  │  - 11 Entities   - 27 Services     - PDO Repos    │   │   │
│  │  │  - 13 VOs        - 7 DTOs          - Email        │   │   │
│  │  │  - 19 Repo IFs   - 5 Contracts     - iCal Mapper  │   │   │
│  │  └──────┬────────────────────────────────────────────┘   │   │
│  │         │ PDO                                            │   │
│  └─────────┼────────────────────────────────────────────────┘   │
│            │                                                     │
│  ┌─────────▼──────────┐  ┌──────────────────────────────────┐   │
│  │  Mercure Hub       │  │  Tenant Database Pool            │   │
│  │  (SSE real-time)   │  │  ┌────────────────────────────┐  │   │
│  │                    │  │  │ tenant_001 (MySQL/PG)      │  │   │
│  │  - Event updates   │  │  │ tenant_002 (MySQL/PG)      │  │   │
│  │  - Calendar sync   │  │  │ ...                        │  │   │
│  │  - Notifications   │  │  │ standalone (SQLite option) │  │   │
│  │                    │  │  └────────────────────────────┘  │   │
│  └────────────────────┘  └──────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
```

---

## Repository Details

### 1. `webcalendar-openapi` — API Contract

The source of truth for the API surface. All other repos consume this.

```
webcalendar-openapi/
├── openapi.yaml              # OpenAPI 3.1 spec
├── schemas/                  # Shared JSON Schema definitions
│   ├── Event.yaml
│   ├── User.yaml
│   ├── Category.yaml
│   └── ...
├── generated/
│   └── typescript/           # Auto-generated TS types + client
├── scripts/
│   └── generate.sh           # Runs openapi-typescript + openapi-fetch
├── package.json              # Published as @webcalendar/api-client
└── README.md
```

**Workflow:**
1. API changes start here — update the spec
2. CI generates TypeScript client and publishes to npm (private or public)
3. webcalendar-web (and future frontends) depend on `@webcalendar/api-client`
4. webcalendar-api validates its controllers against the spec (CI check)

**Key tools:**
- `openapi-typescript` — generates TypeScript types from the spec
- `openapi-fetch` — type-safe fetch client using the generated types
- `spectral` — lints the OpenAPI spec for consistency

---

### 2. `webcalendar-api` — Symfony Application

```
webcalendar-api/
├── composer.json
├── config/
│   ├── packages/
│   │   ├── security.yaml       # Auth providers config
│   │   ├── mercure.yaml        # Real-time config
│   │   └── webcalendar.yaml    # Core library wiring
│   ├── routes/
│   │   ├── api.yaml            # /api/v1/* routes
│   │   └── dav.yaml            # /dav/* routes (phase 2)
│   └── services.yaml           # DI container config
├── src/
│   ├── Controller/
│   │   ├── Api/
│   │   │   ├── EventController.php
│   │   │   ├── UserController.php
│   │   │   ├── CategoryController.php
│   │   │   ├── TaskController.php
│   │   │   ├── GroupController.php
│   │   │   ├── SearchController.php
│   │   │   ├── ImportExportController.php
│   │   │   └── AdminController.php
│   │   └── CalDav/              # Phase 2
│   │       └── CalDavController.php
│   ├── Security/
│   │   ├── JwtAuthenticator.php
│   │   ├── ApiTokenProvider.php
│   │   └── TenantUserProvider.php
│   ├── Middleware/
│   │   ├── TenantResolver.php
│   │   └── CorsMiddleware.php
│   ├── Service/
│   │   ├── TenantDatabaseManager.php  # PDO connection per tenant
│   │   ├── CoreServiceFactory.php     # Wires webcalendar-core services
│   │   └── MercurePublisher.php       # Publishes events to Mercure
│   ├── EventSubscriber/
│   │   └── CoreEventSubscriber.php    # Listens to core events → Mercure
│   └── CalDav/                        # Phase 2
│       └── CoreBackend.php            # sabre/dav ↔ webcalendar-core adapter
├── docker/
│   ├── Dockerfile
│   ├── nginx.conf
│   └── php-fpm.conf
├── docker-compose.yaml
├── Makefile
└── tests/
```

**Key patterns:**

```php
// Controller — thin, delegates everything to webcalendar-core
#[Route('/api/v1/events', methods: ['GET'])]
public function list(Request $request): JsonResponse
{
    $user = $this->getUser();
    $dateRange = DateRange::fromRequest($request);
    $events = $this->eventService->getEventsForDateRange(
        $user->getLogin(), $dateRange
    );
    return $this->json(EventResponse::fromCollection($events));
}

// TenantResolver — selects DB based on request context
// Standalone mode: always uses default connection
// Hosted mode: resolves from subdomain (acme.webcalendar.com → tenant_acme)
```

**Symfony service wiring for webcalendar-core:**

```yaml
# config/services.yaml
services:
    # webcalendar-core repositories (bound to tenant PDO)
    WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository:
        arguments:
            $pdo: '@tenant.pdo_connection'

    # webcalendar-core services (auto-wired)
    WebCalendar\Core\Application\Service\EventService:
        autowire: true

    # Alias interfaces to implementations
    WebCalendar\Core\Domain\Repository\EventRepositoryInterface:
        alias: WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository
```

---

### 3. `webcalendar-web` — React SPA

```
webcalendar-web/
├── package.json
├── vite.config.ts
├── tailwind.config.ts
├── index.html
├── public/
├── src/
│   ├── main.tsx
│   ├── App.tsx
│   ├── api/
│   │   └── client.ts            # openapi-fetch instance
│   ├── auth/
│   │   ├── AuthProvider.tsx      # JWT token management
│   │   ├── LoginPage.tsx
│   │   └── useAuth.ts
│   ├── calendar/
│   │   ├── CalendarPage.tsx      # Main calendar view
│   │   ├── CalendarToolbar.tsx   # Navigation, view switcher
│   │   ├── FullCalendarWrapper.tsx
│   │   ├── EventDialog.tsx       # Create/edit event modal
│   │   └── useCalendarEvents.ts  # Data fetching hook
│   ├── tasks/
│   │   ├── TasksPage.tsx
│   │   └── TaskList.tsx
│   ├── admin/
│   │   ├── AdminPage.tsx
│   │   ├── UserManagement.tsx
│   │   └── GroupManagement.tsx
│   ├── realtime/
│   │   └── useMercure.ts        # SSE subscription hook
│   ├── components/              # Shadcn/ui components
│   │   ├── ui/
│   │   │   ├── button.tsx
│   │   │   ├── dialog.tsx
│   │   │   ├── dropdown-menu.tsx
│   │   │   └── ...
│   │   └── layout/
│   │       ├── Sidebar.tsx
│   │       └── Header.tsx
│   ├── hooks/
│   │   └── useApi.ts
│   └── lib/
│       └── utils.ts
├── .env.example                  # VITE_API_URL, VITE_MERCURE_URL
└── tests/
```

**Key patterns:**

```tsx
// Type-safe API client from generated OpenAPI types
import createClient from 'openapi-fetch';
import type { paths } from '@webcalendar/api-client';

export const api = createClient<paths>({
  baseUrl: import.meta.env.VITE_API_URL,
});

// Usage — fully typed, autocompleted
const { data, error } = await api.GET('/api/v1/events', {
  params: { query: { start: '20260301', end: '20260331' } },
});
// data is typed as EventResponse[]
```

```tsx
// Real-time updates via Mercure
function useMercure(topic: string) {
  useEffect(() => {
    const url = new URL(import.meta.env.VITE_MERCURE_URL);
    url.searchParams.append('topic', topic);
    const source = new EventSource(url, { withCredentials: true });
    source.onmessage = (e) => {
      const update = JSON.parse(e.data);
      queryClient.invalidateQueries(['events']); // or optimistic update
    };
    return () => source.close();
  }, [topic]);
}
```

---

## Multi-Tenancy Design

```
                    ┌─────────────────────────┐
                    │   Control Plane (future) │
                    │   - Tenant provisioning  │
                    │   - Billing              │
                    │   - DB creation          │
                    └────────────┬─────────────┘
                                 │ provisions
                                 ▼
┌──────────────────────────────────────────────────────┐
│                  Tenant Registry                     │
│  ┌────────────────────────────────────────────────┐  │
│  │ tenants table (in control DB)                  │  │
│  │ ┌─────────┬──────────────┬──────────────────┐  │  │
│  │ │ slug    │ db_host      │ db_name          │  │  │
│  │ ├─────────┼──────────────┼──────────────────┤  │  │
│  │ │ acme    │ db-host-1    │ wc_tenant_acme   │  │  │
│  │ │ globex  │ db-host-1    │ wc_tenant_globex │  │  │
│  │ │ initech │ db-host-2    │ wc_tenant_initech│  │  │
│  │ └─────────┴──────────────┴──────────────────┘  │  │
│  └────────────────────────────────────────────────┘  │
│                                                      │
│  Standalone mode: no registry, single hardcoded DB   │
└──────────────────────────────────────────────────────┘

Resolution order:
1. Subdomain:  acme.webcalendar.com → slug "acme"
2. Header:     X-Tenant-Id: acme
3. JWT claim:  { "tenant": "acme" }
4. Default:    standalone single-tenant mode
```

Each tenant DB uses webcalendar-core's schema (`webcal_*` tables) — identical structure, fully isolated data.

---

## Authentication Architecture

```
Phase 1 (MVP):
  Username/Password → Symfony authenticator → JWT issued
  JWT sent as Bearer token on all API requests

Phase 2:
  ┌─────────────────────────────────────────────────┐
  │         Symfony Security Component               │
  │                                                  │
  │  ┌──────────────┐  Configurable per tenant:     │
  │  │ Authenticator│  - PasswordAuthenticator ✓    │
  │  │ Chain        │  - OAuth2Authenticator         │
  │  │              │  - LdapAuthenticator           │
  │  │              │  - OidcAuthenticator           │
  │  │              │  - SamlAuthenticator           │
  │  └──────┬───────┘                                │
  │         │                                        │
  │  ┌──────▼───────┐                                │
  │  │ UserProvider  │  Loads user from tenant DB    │
  │  │ (per-tenant) │  via webcalendar-core          │
  │  └──────────────┘  UserService                   │
  └─────────────────────────────────────────────────┘
```

Symfony's Security component natively supports chained authenticators —
different tenants can have different auth methods configured without code changes.

---

## Real-time Updates (Mercure)

```
1. User A creates event via REST API
2. Symfony controller calls webcalendar-core EventService::create()
3. CoreEventSubscriber publishes to Mercure:
     Topic:   /calendars/{tenantSlug}/users/{userId}/events
     Data:    { "type": "event.created", "event": { ... } }
4. User B's browser has EventSource listening on that topic
5. React query cache invalidates → calendar re-renders with new event

Why Mercure over WebSockets:
- SSE works over standard HTTP (no upgrade needed)
- Works through corporate proxies and load balancers
- Mercure Hub handles auth, topic authorization, reconnection
- Symfony has first-party Mercure support
- Simpler to deploy (single binary, or Docker image)
```

---

## Deployment Models

### Docker Compose (Hosted / Development)

```yaml
# docker-compose.yaml
services:
  nginx:
    image: nginx:alpine
    ports: ["80:80", "443:443"]
    volumes:
      - ./webcalendar-web/dist:/usr/share/nginx/html  # Static SPA
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf
    depends_on: [api, mercure]

  api:
    build: ./webcalendar-api
    environment:
      DATABASE_URL: mysql://user:pass@db:3306/webcalendar
      MERCURE_URL: http://mercure/.well-known/mercure
      MERCURE_JWT_SECRET: ${MERCURE_JWT_SECRET}
      APP_MODE: hosted  # or "standalone"
    depends_on: [db]

  mercure:
    image: dunglas/mercure
    environment:
      MERCURE_PUBLISHER_JWT_KEY: ${MERCURE_JWT_SECRET}
      MERCURE_SUBSCRIBER_JWT_KEY: ${MERCURE_JWT_SECRET}

  db:
    image: mysql:8.0
    volumes:
      - db_data:/var/lib/mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}

volumes:
  db_data:
```

### Standalone (Traditional Hosting)

```
Requirements: PHP 8.2+, Apache/nginx, MySQL/PostgreSQL/SQLite
No Docker, no Node.js, no Mercure (real-time disabled gracefully)

Installation:
1. composer install in webcalendar-api/
2. Copy pre-built SPA dist/ to web root
3. Configure .env with database credentials
4. Run php bin/console webcalendar:install (creates schema)
5. Point web server at public/

The SPA is pre-built static files — no Node.js needed on the server.
Mercure is optional — app works without real-time (falls back to polling).
```

---

## Development Phases

### Phase 1 — MVP
- [ ] webcalendar-openapi: Define core spec (events, users, auth, categories)
- [ ] webcalendar-api: Symfony skeleton, JWT auth, REST endpoints for events CRUD, tenant middleware (standalone mode only)
- [ ] webcalendar-web: React app with login, calendar views (day/week/month), event create/edit
- [ ] Docker Compose for local dev
- [ ] CI/CD pipelines for all repos

### Phase 2 — Multi-User & Collaboration
- [ ] Participants, groups, permissions endpoints
- [ ] Layers (calendar overlays)
- [ ] Mercure real-time integration
- [ ] Tasks and journals
- [ ] Import/export (iCal, CSV)

### Phase 3 — Hosted / Multi-Tenant
- [ ] Tenant resolver middleware (subdomain-based)
- [ ] Tenant provisioning (DB creation, schema migration)
- [ ] Control plane API (tenant CRUD, configuration)
- [ ] Admin dashboard for tenant management

### Phase 4 — CalDAV & Extended Auth
- [ ] sabre/dav integration with webcalendar-core backend adapter
- [ ] OAuth2 / OIDC authenticator
- [ ] LDAP authenticator
- [ ] Per-tenant auth configuration

### Phase 5 — Polish & Scale
- [ ] Notifications (email, webhook)
- [ ] Search (full-text)
- [ ] Reports
- [ ] Performance optimization, caching
- [ ] Standalone installer / setup wizard
