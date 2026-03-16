# WCTNG — Phase 1 Development Plan & Status

> **Last Updated:** 2026-03-15
> **Phase:** 1 — MVP
> **Goal:** Working calendar app with login, event CRUD, day/week/month views
> **Methodology:** TDD (write tests first, then implementation)
> **Developed by:** AI Agent

---

## Quick Status

| Epic | Title | Stories | Done | Status |
|------|-------|---------|------|--------|
| E1 | Development Infrastructure | 5 | 5 | DONE |
| E2 | OpenAPI Specification | 4 | 4 | DONE |
| E3 | Symfony API Skeleton | 5 | 5 | DONE |
| E4 | Authentication | 4 | 4 | DONE |
| E5 | Events API | 6 | 6 | DONE |
| E6 | Users API | 3 | 3 | DONE |
| E7 | Categories API | 2 | 2 | DONE |
| E8 | React SPA Foundation | 5 | 5 | DONE |
| E9 | Calendar Views | 4 | 4 | DONE |
| E10 | Event Management UI | 4 | 4 | DONE |
| E11 | E2E Tests | 3 | 1 | IN PROGRESS |
| **Total** | | **45** | **43** | |

---

## Dependency Graph

```
E1 (Infrastructure)
 ├──► E2 (OpenAPI Spec)
 │     └──► E5 (Events API) ──► E10 (Event Mgmt UI)
 │     └──► E6 (Users API)
 │     └──► E7 (Categories API)
 ├──► E3 (Symfony Skeleton)
 │     └──► E4 (Authentication) ──► E8 (React SPA Foundation)
 │                                    └──► E9 (Calendar Views)
 │                                    └──► E10 (Event Mgmt UI)
 └──► E11 (E2E Tests) — depends on E9, E10
```

**Critical path:** E1 → E3 → E4 → E8 → E9 → E11

---

## Global Standards (apply to ALL stories)

### PHP (webcalendar-api)
- PHP 8.2+ with `declare(strict_types=1)` on all files
- PSR-12 code style
- PHPStan level 9 — zero errors
- Psalm — zero errors
- PHPUnit 10+ for unit and integration tests
- Target: 95%+ line coverage
- All tests must pass in Docker containers

### TypeScript (webcalendar-web)
- Strict mode (`"strict": true` in tsconfig.json)
- ESLint + Prettier
- Vitest for unit/component tests
- Playwright for E2E tests
- Target: 90%+ line coverage for non-UI code

### Docker Development Ports
All services use high ports to avoid conflicts with local services:

| Service | Container Port | Host Port |
|---------|---------------|-----------|
| nginx (API + SPA) | 80 | 47180 |
| PHP-FPM | 9000 | (internal only) |
| MySQL | 3306 | 47106 |
| Vite dev server | 5173 | 47173 |
| Playwright UI | 8080 | 47188 |

### Commit Convention
```
type(scope): description

Types: feat, fix, test, refactor, docs, chore, ci
Scopes: api, web, openapi, docker, e2e
```

---

## Epic E1: Development Infrastructure

**Goal:** Docker-based development environment for all repos, CI configuration, and shared tooling.

### E1-S1: Docker Compose Development Environment

**Status:** DONE

**Description:**
Create a `docker-compose.dev.yml` in the wctng root directory that orchestrates all services needed for local development. This is the top-level compose file that ties together the API, database, and frontend dev server. All services must use high ports (47100-47199 range) to avoid conflicts with other local services.

**Acceptance Criteria:**
- [x] `docker-compose.dev.yml` exists at `/var/www/html/wctng/docker-compose.dev.yml`
- [x] Services defined: `nginx`, `php-fpm`, `mysql`, `vite-dev`
- [x] nginx listens on host port 47180
- [x] MySQL listens on host port 47106
- [x] Vite dev server listens on host port 47173
- [x] PHP-FPM is internal only (not exposed to host)
- [x] MySQL uses a named volume for data persistence
- [x] `.env.example` file with all required environment variables
- [x] `make up` starts all services, `make down` stops them
- [x] `make logs` tails all service logs
- [x] Health checks defined for nginx, mysql, php-fpm
- [x] nginx routes `/api/*` to php-fpm and `/*` to vite dev server
- [x] Running `docker compose -f docker-compose.dev.yml up` succeeds with no errors
- [x] MySQL container initializes with webcalendar-core schema on first run

**Recommended Tests:**
```bash
# Verify all containers start and become healthy
docker compose -f docker-compose.dev.yml up -d --wait
docker compose -f docker-compose.dev.yml ps  # all services "healthy"

# Verify ports are correct
curl -s -o /dev/null -w "%{http_code}" http://localhost:47180/  # 200 or 502 (no app yet)
mysql -h 127.0.0.1 -P 47106 -u webcalendar -p -e "SHOW TABLES;"

# Verify schema loaded
mysql -h 127.0.0.1 -P 47106 -u webcalendar -p webcalendar -e "DESCRIBE webcal_entry;"
```

---

### E1-S2: webcalendar-api Project Scaffold

**Status:** DONE

**Description:**
Initialize the Symfony 7.x project in `webcalendar-api/` directory. Install required Composer dependencies including webcalendar-core, PHPStan, Psalm, PHPUnit, PHP-CS-Fixer. Configure all static analysis tools to their strictest settings.

**Preconditions:** None (can be done in parallel with E1-S1)

**Acceptance Criteria:**
- [x] `webcalendar-api/` directory exists with Symfony 7.x skeleton
- [x] `composer.json` requires: `symfony/framework-bundle`, `symfony/security-bundle`, `lexik/jwt-authentication-bundle`, `craigk5n/webcalendar-core`, `nelmio/cors-bundle`
- [x] `composer.json` requires-dev: `phpunit/phpunit` (^10), `phpstan/phpstan` (level 9), `phpstan/phpstan-symfony`, `vimeo/psalm`, `friendsofphp/php-cs-fixer`
- [x] `phpstan.neon` configured at level 9 with Symfony extensions
- [x] `psalm.xml` configured with `errorLevel="1"` (strictest)
- [x] `phpunit.xml.dist` configured with coverage reporting
- [x] `php-cs-fixer` configured for PSR-12
- [x] `Makefile` with targets: `test`, `phpstan`, `psalm`, `cs-fix`, `cs-check`, `coverage`, `ci` (runs all)
- [x] Running `make ci` passes with zero errors (on empty project)
- [x] `.gitignore` includes vendor/, var/, .env.local
- [x] `Dockerfile` for production PHP-FPM image
- [x] `docker/php-fpm.conf` with development-friendly settings

**Recommended Tests:**
```bash
cd webcalendar-api
composer install
make phpstan   # exit 0, zero errors
make psalm     # exit 0, zero errors
make test      # exit 0 (no tests yet, but PHPUnit runs)
make cs-check  # exit 0
```

---

### E1-S3: webcalendar-web Project Scaffold

**Status:** DONE

**Description:**
Initialize the React + Vite + TypeScript project in `webcalendar-web/` directory. Install Tailwind CSS, Shadcn/ui foundation, FullCalendar, React Router, React Query (TanStack Query), openapi-fetch. Configure ESLint, Prettier, Vitest, Playwright.

**Preconditions:** None (can be done in parallel with E1-S1, E1-S2)

**Acceptance Criteria:**
- [x] `webcalendar-web/` directory exists with Vite + React + TypeScript scaffold
- [x] `package.json` dependencies include: `react`, `react-dom`, `react-router-dom`, `@tanstack/react-query`, `@fullcalendar/core`, `@fullcalendar/react`, `@fullcalendar/daygrid`, `@fullcalendar/timegrid`, `@fullcalendar/list`, `@fullcalendar/interaction`, `openapi-fetch`, `tailwindcss`, `@radix-ui/react-dialog`, `@radix-ui/react-dropdown-menu`
- [x] `package.json` devDependencies include: `vitest`, `@testing-library/react`, `@testing-library/jest-dom`, `@playwright/test`, `eslint`, `prettier`, `typescript`
- [x] `tsconfig.json` with `"strict": true`, path aliases (`@/` → `src/`)
- [x] `tailwind.config.ts` configured
- [x] `vite.config.ts` with proxy for `/api` → `http://nginx:80` (in Docker), test config for Vitest
- [x] `playwright.config.ts` targeting `http://localhost:47180`
- [x] `.eslintrc.cjs` with React + TypeScript rules
- [x] `.prettierrc` configured
- [x] `Makefile` with targets: `dev`, `build`, `test`, `test-e2e`, `lint`, `format`, `ci`
- [x] `src/main.tsx` renders a "Hello WCTNG" placeholder
- [x] Running `npm run build` produces static output in `dist/`
- [x] Running `npx vitest run` passes (no tests yet but Vitest executes)
- [x] Running `npx playwright test` runs (may fail with no tests, but binary installed)

**Recommended Tests:**
```bash
cd webcalendar-web
npm ci
npm run build          # exit 0, dist/ created
npx vitest run         # exit 0
npx tsc --noEmit       # exit 0, no type errors
npx eslint src/        # exit 0, no lint errors
```

---

### E1-S4: webcalendar-openapi Project Scaffold

**Status:** DONE

**Description:**
Initialize the OpenAPI spec project in `webcalendar-openapi/` directory. Create the base OpenAPI 3.1 document structure with shared schema definitions, Spectral linting, and TypeScript client generation scripts.

**Preconditions:** None (can be done in parallel)

**Acceptance Criteria:**
- [x] `webcalendar-openapi/` directory exists
- [x] `package.json` with devDependencies: `openapi-typescript`, `openapi-fetch`, `@stoplight/spectral-cli`
- [x] `openapi.yaml` exists with:
  - OpenAPI 3.1.0 version
  - Info block (title: "WebCalendar API", version: "2.0.0")
  - Server URLs for dev (`http://localhost:47180/api/v2`) and production placeholder
  - Security scheme: Bearer JWT
  - Empty paths (to be filled in E2)
  - `components/schemas/` section with `ErrorResponse` and `PaginationMeta` schemas
- [x] `.spectral.yaml` linting rules configured
- [x] `scripts/generate.sh` runs `openapi-typescript` and outputs to `generated/typescript/`
- [x] `Makefile` with targets: `lint`, `generate`, `ci`
- [x] Running `make lint` passes on the base spec
- [x] Running `make generate` produces TypeScript types in `generated/typescript/`

**Recommended Tests:**
```bash
cd webcalendar-openapi
npm ci
make lint       # exit 0, spectral passes
make generate   # exit 0, generated/typescript/ exists
# Verify generated types compile
npx tsc --noEmit generated/typescript/index.ts 2>/dev/null || echo "types generated"
```

---

### E1-S5: CI Pipeline Configuration

**Status:** DONE

**Description:**
Create GitHub Actions CI workflow files for each repository. Each workflow runs all tests, static analysis, and linting in Docker containers. The API workflow validates controllers against the OpenAPI spec.

**Preconditions:** E1-S2, E1-S3, E1-S4

**Acceptance Criteria:**
- [x] `.github/workflows/api.yml` — runs on webcalendar-api changes: `composer install`, `make ci` (phpstan, psalm, phpunit, cs-check)
- [x] `.github/workflows/web.yml` — runs on webcalendar-web changes: `npm ci`, `make ci` (tsc, eslint, vitest, build)
- [x] `.github/workflows/openapi.yml` — runs on webcalendar-openapi changes: `npm ci`, `make ci` (spectral lint, generate)
- [x] `.github/workflows/e2e.yml` — runs Playwright tests against Docker Compose stack
- [x] All workflows use PHP 8.2, Node 20 LTS
- [x] API workflow includes MySQL service container for integration tests
- [x] Coverage reports uploaded as artifacts
- [x] Workflows fail on any PHPStan, Psalm, ESLint, or type error

**Recommended Tests:**
```bash
# Validate workflow syntax
actionlint .github/workflows/*.yml

# Dry-run CI locally (using act or similar)
# Each make ci target should succeed locally before pushing
cd webcalendar-api && make ci
cd webcalendar-web && make ci
cd webcalendar-openapi && make ci
```

---

## Epic E2: OpenAPI Specification

**Goal:** Define the API contract for Phase 1 endpoints. This is the source of truth consumed by both the API and frontend.

### E2-S1: Authentication Endpoints Spec

**Status:** DONE

**Description:**
Define OpenAPI schemas and paths for authentication endpoints. Reference: webcalendar-core `API.md` Section 2.

**Preconditions:** E1-S4

**Acceptance Criteria:**
- [x] `schemas/AuthLoginRequest.yaml` — properties: `username` (string, required), `password` (string, required)
- [x] `schemas/AuthLoginResponse.yaml` — properties: `token` (string), `user` (UserSummary), `expires_at` (string, date-time)
- [x] `schemas/UserSummary.yaml` — properties: `login`, `firstname`, `lastname`, `email`, `is_admin`
- [x] Path `POST /auth/login` — request body: AuthLoginRequest, responses: 200 (AuthLoginResponse wrapped in standard envelope), 401 (ErrorResponse)
- [x] Path `POST /auth/logout` — requires Bearer token, responses: 204, 401
- [x] Path `POST /auth/refresh` — requires Bearer token, responses: 200 (AuthLoginResponse), 401
- [x] Standard response envelope schema defined: `{ data: T, meta: object|null, error: ErrorResponse|null }`
- [x] `make lint` passes
- [x] `make generate` produces TypeScript types including auth paths

**Recommended Tests:**
```bash
cd webcalendar-openapi
make lint      # spectral passes
make generate  # types include paths['/auth/login']
# Grep generated types to confirm
grep -q "'/auth/login'" generated/typescript/*.ts
grep -q "AuthLoginRequest" generated/typescript/*.ts
grep -q "AuthLoginResponse" generated/typescript/*.ts
```

---

### E2-S2: Events Endpoints Spec

**Status:** DONE

**Description:**
Define OpenAPI schemas and paths for event CRUD. Reference: webcalendar-core `API.md` Sections 3.1-3.6. For Phase 1 MVP, include core CRUD and date-range listing. Participants, recurrence, exceptions, attachments, and comments are defined but can be stubbed initially.

**Preconditions:** E2-S1 (needs standard envelope, auth scheme)

**Acceptance Criteria:**
- [x] Event schema — all core fields: `id`, `title`, `description`, `start_date`, `start_time`, `end_date`, `end_time`, `duration`, `location`, `access` (enum), `type` (enum), `created_by`, `all_day`, `uid`, `sequence`, `status`
- [x] EventCreateRequest — required: `title`, `start_date`; optional: all other writable fields
- [x] EventUpdateRequest — all fields optional
- [x] `GET /events` — query params: `start`, `end`, `page`, `limit`; response: Event array + PaginationMeta in envelope
- [x] `POST /events` — body: EventCreateRequest; response: 201 (Event in envelope)
- [x] `GET /events/{id}` — response: Event in envelope, 404
- [x] `PUT /events/{id}` — body: EventUpdateRequest; response: 200 (Event in envelope)
- [x] `DELETE /events/{id}` — query param: `mode` (enum: single, future, all); response: 204
- [x] `make lint` passes (zero errors)
- [x] `make generate` produces TypeScript types for all event paths

**Recommended Tests:**
```bash
cd webcalendar-openapi
make lint
make generate
grep -q "'/events'" generated/typescript/*.ts
grep -q "'/events/{id}'" generated/typescript/*.ts
grep -q "EventCreateRequest" generated/typescript/*.ts
```

---

### E2-S3: Users Endpoints Spec

**Status:** DONE

**Description:**
Define OpenAPI schemas and paths for user management. Reference: webcalendar-core `API.md` Sections 3.9-3.10. Phase 1 includes user listing, profile retrieval, and self-update.

**Preconditions:** E2-S1

**Acceptance Criteria:**
- [x] User schema — `login`, `firstname`, `lastname`, `email`, `is_admin`, `enabled`
- [x] UserCreateRequest — required: `login`, `password`, `email`; optional: `firstname`, `lastname`, `is_admin`
- [x] UserUpdateRequest — all fields optional (`firstname`, `lastname`, `email`, `is_admin`, `enabled`)
- [x] PasswordChangeRequest — required: `new_password`; optional: `current_password`
- [x] `GET /users` — admin only, returns User array + PaginationMeta
- [x] `GET /users/{login}` — User in envelope, 403/404
- [x] `POST /users` — admin only, body: UserCreateRequest, 201/409
- [x] `PUT /users/{login}` — body: UserUpdateRequest
- [x] `PUT /users/{login}/password` — body: PasswordChangeRequest
- [x] `make lint` passes (zero errors)

**Recommended Tests:**
```bash
cd webcalendar-openapi
make lint
make generate
grep -q "'/users'" generated/typescript/*.ts
grep -q "UserCreateRequest" generated/typescript/*.ts
```

---

### E2-S4: Categories Endpoints Spec

**Status:** DONE

**Description:**
Define OpenAPI schemas and paths for category management. Reference: webcalendar-core `API.md` Section 3.14.

**Preconditions:** E2-S1

**Acceptance Criteria:**
- [x] Category schema — `id`, `name`, `color` (nullable), `is_global`, `owner` (nullable)
- [x] CategoryCreateRequest — required: `name`; optional: `color`, `is_global`
- [x] CategoryUpdateRequest — all fields optional (`name`, `color`)
- [x] `GET /categories` — returns global + personal categories
- [x] `POST /categories` — creates category, 201
- [x] `GET /categories/{id}` — single category
- [x] `PUT /categories/{id}` — update, 403/404
- [x] `DELETE /categories/{id}` — delete, 204/403/404
- [x] `make lint` passes (zero errors)

**Recommended Tests:**
```bash
cd webcalendar-openapi
make lint
make generate
grep -q "'/categories'" generated/typescript/*.ts
grep -q "CategoryCreateRequest" generated/typescript/*.ts
```

---

## Epic E3: Symfony API Skeleton

**Goal:** Working Symfony application that boots, connects to the database via webcalendar-core, and serves a health check endpoint.

### E3-S1: Symfony Kernel and Base Configuration

**Status:** DONE

**Description:**
Configure the Symfony kernel, environment handling, and base service wiring. The app must boot in the Docker environment and respond to requests.

**Preconditions:** E1-S1, E1-S2

**Acceptance Criteria:**
- [x] `src/Kernel.php` exists and extends Symfony's base kernel
- [x] `config/packages/framework.yaml` configured
- [x] `.env` with `APP_ENV=dev`, `APP_SECRET`, `DATABASE_URL`
- [x] `public/index.php` as Symfony front controller
- [x] `GET /api/v2/health` returns `{"status":"ok","timestamp":"..."}` with HTTP 200
- [x] App boots in Docker container with `make up`
- [x] PHPStan level 9 passes on all new code
- [x] Psalm passes on all new code

**Recommended Tests:**
```php
// tests/Controller/HealthControllerTest.php
class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/health');

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('ok', $response['status']);
        $this->assertArrayHasKey('timestamp', $response);
    }
}
```

---

### E3-S2: webcalendar-core Service Wiring

**Status:** DONE

**Description:**
Create a Symfony service configuration that wires webcalendar-core's repositories and services into the dependency injection container. The `CoreServiceFactory` creates all webcalendar-core services with the correct PDO connection. For Phase 1, use standalone mode (single database).

**Preconditions:** E3-S1

**Acceptance Criteria:**
- [x] `src/Service/CoreServiceFactory.php` exists
- [x] Factory creates a PDO connection from `DATABASE_URL` environment variable
- [x] Factory instantiates all webcalendar-core PDO repository implementations
- [x] Factory instantiates all webcalendar-core application services with correct dependencies
- [x] `config/services.yaml` registers webcalendar-core interfaces with their PDO implementations
- [x] All webcalendar-core services are available via Symfony's DI container
- [x] Integration test verifies `EventService` can be retrieved from the container
- [x] Integration test verifies `UserService` can be retrieved from the container
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/Service/CoreServiceFactoryTest.php (Unit)
class CoreServiceFactoryTest extends TestCase
{
    public function testCreatesEventService(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $factory = new CoreServiceFactory($pdo);
        $this->assertInstanceOf(EventService::class, $factory->getEventService());
    }

    public function testCreatesUserService(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $factory = new CoreServiceFactory($pdo);
        $this->assertInstanceOf(UserService::class, $factory->getUserService());
    }
}

// tests/Integration/ServiceWiringTest.php (Integration — runs in Docker)
class ServiceWiringTest extends KernelTestCase
{
    public function testEventServiceAvailableInContainer(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(EventService::class);
        $this->assertInstanceOf(EventService::class, $service);
    }
}
```

---

### E3-S3: Standard Response Envelope and Error Handling

**Status:** DONE

**Description:**
Implement the standard JSON response envelope (`{data, meta, error}`) as a Symfony response helper/trait. Implement a global exception handler that converts exceptions to the standard error format. All API responses must use this envelope.

**Preconditions:** E3-S1

**Acceptance Criteria:**
- [x] `src/Response/ApiResponse.php` — helper class with static methods:
  - `success(mixed $data, ?array $meta = null): JsonResponse` — wraps data in `{data, meta, error: null}`
  - `error(int $code, string $message, array $details = []): JsonResponse` — wraps error in `{data: null, meta: null, error: {code, message, details}}`
  - `paginated(array $items, int $total, int $page, int $limit): JsonResponse`
- [x] `src/EventSubscriber/ExceptionSubscriber.php` — catches all exceptions:
  - `HttpException` → appropriate HTTP status + error envelope
  - `\InvalidArgumentException` → 400 + error envelope
  - Any unhandled exception → 500 + generic error envelope (no stack trace in prod)
- [x] Response Content-Type is always `application/json`
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/Response/ApiResponseTest.php
class ApiResponseTest extends TestCase
{
    public function testSuccessEnvelope(): void
    {
        $response = ApiResponse::success(['id' => 1]);
        $body = json_decode($response->getContent(), true);
        $this->assertSame(['id' => 1], $body['data']);
        $this->assertNull($body['error']);
    }

    public function testErrorEnvelope(): void
    {
        $response = ApiResponse::error(404, 'Not found');
        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertNull($body['data']);
        $this->assertSame(404, $body['error']['code']);
        $this->assertSame('Not found', $body['error']['message']);
    }

    public function testPaginatedEnvelope(): void
    {
        $response = ApiResponse::paginated([['id' => 1]], 50, 1, 20);
        $body = json_decode($response->getContent(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame(50, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame(20, $body['meta']['limit']);
    }
}

// tests/EventSubscriber/ExceptionSubscriberTest.php
class ExceptionSubscriberTest extends WebTestCase
{
    public function testNotFoundReturns404Envelope(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/nonexistent');
        $this->assertResponseStatusCodeSame(404);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(404, $body['error']['code']);
    }
}
```

---

### E3-S4: CORS Configuration

**Status:** DONE

**Description:**
Configure CORS to allow the React SPA (running on a different port in development) to call the API. Use `nelmio/cors-bundle`.

**Preconditions:** E3-S1

**Acceptance Criteria:**
- [x] `config/packages/nelmio_cors.yaml` configured:
  - Allow origin: `http://localhost:47173` (Vite dev), configurable via `CORS_ALLOW_ORIGIN` env var
  - Allow headers: `Authorization`, `Content-Type`, `Accept`
  - Allow methods: `GET`, `POST`, `PUT`, `DELETE`, `OPTIONS`
  - Allow credentials: true
  - Max age: 3600
- [x] Preflight `OPTIONS` requests return correct CORS headers
- [x] Non-CORS requests (same-origin) work without CORS headers
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
// tests/Middleware/CorsTest.php
class CorsTest extends WebTestCase
{
    public function testPreflightReturnsCorrectHeaders(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/api/v2/health', [], [], [
            'HTTP_ORIGIN' => 'http://localhost:47173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertResponseHeaderSame('Access-Control-Allow-Origin', 'http://localhost:47173');
        $this->assertResponseHeaderContains('Access-Control-Allow-Methods', 'GET');
    }
}
```

---

### E3-S5: Database Schema Initialization Command

**Status:** DONE

**Description:**
Create a Symfony console command that initializes the database schema using webcalendar-core's SQL schema files. Also creates a default admin user. This replaces a traditional migration system for the initial setup.

**Preconditions:** E3-S2

**Acceptance Criteria:**
- [x] `src/Command/InstallCommand.php` — `php bin/console webcalendar:install`
- [x] Command detects database type from `DATABASE_URL` (mysql, pgsql, sqlite)
- [x] Command loads the appropriate schema SQL from webcalendar-core (`mysql-schema.sql`, `postgresql-schema.sql`, or `sqlite-schema.sql`)
- [x] Command executes schema SQL via PDO
- [x] Command creates a default admin user (login: `admin`, prompted or env-var password)
- [x] Command is idempotent — running twice does not error (checks if tables exist)
- [x] `--force` flag required for non-interactive execution
- [x] Outputs progress messages for each step
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/Command/InstallCommandTest.php (Integration — needs MySQL in Docker)
class InstallCommandTest extends KernelTestCase
{
    public function testInstallCreatesSchema(): void
    {
        $kernel = self::bootKernel();
        $app = new Application($kernel);
        $command = $app->find('webcalendar:install');
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Schema created', $tester->getDisplay());

        // Verify tables exist
        $pdo = self::getContainer()->get(\PDO::class);
        $stmt = $pdo->query("SHOW TABLES LIKE 'webcal_%'");
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('webcal_entry', $tables);
        $this->assertContains('webcal_user', $tables);
    }

    public function testInstallCreatesAdminUser(): void
    {
        // After install, admin user should exist
        $userService = self::getContainer()->get(UserService::class);
        $admin = $userService->getUserByLogin('admin');
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());
    }

    public function testInstallIsIdempotent(): void
    {
        $kernel = self::bootKernel();
        $app = new Application($kernel);
        $command = $app->find('webcalendar:install');
        $tester = new CommandTester($command);

        $tester->execute(['--force' => true]);
        $this->assertSame(0, $tester->getStatusCode());

        // Run again — should not error
        $tester->execute(['--force' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }
}
```

---

## Epic E4: Authentication

**Goal:** JWT-based authentication with login/logout/refresh endpoints.

### E4-S1: JWT Token Service

**Status:** DONE

**Description:**
Implement JWT token generation and validation using `lexik/jwt-authentication-bundle`. Tokens must contain user login, admin flag, and expiration. Configure key pair generation.

**Preconditions:** E3-S2

**Acceptance Criteria:**
- [x] JWT key pair generated and stored in `config/jwt/` (private.pem, public.pem)
- [x] `config/packages/lexik_jwt_authentication.yaml` configured
- [x] Token payload includes: `username` (login), `is_admin`, `iat`, `exp`
- [x] Access token TTL: 1 hour (configurable via `JWT_TTL` env var)
- [x] Refresh token TTL: 30 days (configurable)
- [x] Tokens are signed with RS256
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/Security/JwtTokenTest.php
class JwtTokenTest extends KernelTestCase
{
    public function testTokenContainsRequiredClaims(): void
    {
        $encoder = self::getContainer()->get(JWTEncoderInterface::class);
        $token = $encoder->encode(['username' => 'testuser', 'is_admin' => false]);
        $decoded = $encoder->decode($token);

        $this->assertSame('testuser', $decoded['username']);
        $this->assertFalse($decoded['is_admin']);
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('exp', $decoded);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $encoder = self::getContainer()->get(JWTEncoderInterface::class);
        $this->expectException(JWTDecodeFailureException::class);
        $encoder->decode('eyJ...<expired_token>');
    }
}
```

---

### E4-S2: User Provider (webcalendar-core Bridge)

**Status:** DONE

**Description:**
Implement a Symfony `UserProviderInterface` that loads users from webcalendar-core's `UserService`. This bridges Symfony Security with the core user model.

**Preconditions:** E3-S2, E4-S1

**Acceptance Criteria:**
- [x] `src/Security/WebCalendarUserProvider.php` implements `UserProviderInterface`
- [x] `loadUserByIdentifier(string $login)` calls `UserService::getUserByLogin()`
- [x] Returns a Symfony `UserInterface` implementation wrapping the core User entity
- [x] `src/Security/WebCalendarUser.php` implements `UserInterface` — wraps core `User`
- [x] `getRoles()` returns `['ROLE_USER']` for regular users, `['ROLE_USER', 'ROLE_ADMIN']` for admins
- [x] `getPassword()` returns the hashed password from core User
- [x] `config/packages/security.yaml` configured with this provider
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/Security/WebCalendarUserProviderTest.php
class WebCalendarUserProviderTest extends TestCase
{
    public function testLoadUserByIdentifier(): void
    {
        $coreUser = new \WebCalendar\Core\Domain\Entity\User(
            login: 'testuser', firstname: 'Test', lastname: 'User',
            email: 'test@example.com', isAdmin: false
        );
        $userService = $this->createMock(UserService::class);
        $userService->method('getUserByLogin')->with('testuser')->willReturn($coreUser);

        $provider = new WebCalendarUserProvider($userService);
        $user = $provider->loadUserByIdentifier('testuser');

        $this->assertSame('testuser', $user->getUserIdentifier());
        $this->assertContains('ROLE_USER', $user->getRoles());
        $this->assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testLoadAdminUser(): void
    {
        $coreUser = new \WebCalendar\Core\Domain\Entity\User(
            login: 'admin', firstname: 'Admin', lastname: 'User',
            email: 'admin@example.com', isAdmin: true
        );
        $userService = $this->createMock(UserService::class);
        $userService->method('getUserByLogin')->with('admin')->willReturn($coreUser);

        $provider = new WebCalendarUserProvider($userService);
        $user = $provider->loadUserByIdentifier('admin');

        $this->assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testLoadNonexistentUserThrows(): void
    {
        $userService = $this->createMock(UserService::class);
        $userService->method('getUserByLogin')->willReturn(null);

        $provider = new WebCalendarUserProvider($userService);
        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('nobody');
    }
}
```

---

### E4-S3: Login Endpoint

**Status:** DONE

**Description:**
Implement `POST /api/v2/auth/login` that authenticates a user against webcalendar-core and returns a JWT token. Uses the standard response envelope.

**Preconditions:** E4-S1, E4-S2

**Acceptance Criteria:**
- [x] `src/Controller/Api/AuthController.php` with `login()` action
- [x] Validates request body has `username` and `password` (returns 400 if missing)
- [x] Authenticates via webcalendar-core's `DatabaseAuthService::authenticate()`
- [x] On success: returns 200 with `{data: {token, user: {login, firstname, lastname, email, is_admin}, expires_at}}`
- [x] On failure: returns 401 with error envelope `{error: {code: 401, message: "Invalid credentials"}}`
- [x] Rate limited: via DatabaseAuthService's built-in PdoRateLimiter (max 5 attempts per 15min)
- [x] Logs authentication attempts (success and failure) via DatabaseAuthService's logger
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/Controller/Api/AuthControllerTest.php
class AuthControllerTest extends WebTestCase
{
    public function testLoginSuccess(): void
    {
        // Requires admin user from InstallCommand
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['username' => 'admin', 'password' => 'admin']));

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $body['data']);
        $this->assertSame('admin', $body['data']['user']['login']);
        $this->assertTrue($body['data']['user']['is_admin']);
        $this->assertArrayHasKey('expires_at', $body['data']);
    }

    public function testLoginInvalidCredentials(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['username' => 'admin', 'password' => 'wrong']));

        $this->assertResponseStatusCodeSame(401);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(401, $body['error']['code']);
    }

    public function testLoginMissingFields(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['username' => 'admin']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testLoginReturnsValidJwt(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['username' => 'admin', 'password' => 'admin']));

        $body = json_decode($client->getResponse()->getContent(), true);
        $token = $body['data']['token'];

        // Use token to access protected endpoint
        $client->request('GET', '/api/v2/health', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();
    }
}
```

---

### E4-S4: Logout and Token Refresh Endpoints

**Status:** DONE

**Description:**
Implement `POST /api/v2/auth/logout` (token invalidation) and `POST /api/v2/auth/refresh` (issue new token from valid existing token).

**Preconditions:** E4-S3

**Acceptance Criteria:**
- [x] `POST /api/v2/auth/logout` — requires valid Bearer token, returns 204
- [x] After logout, the old token is rejected (stateless JWT — client discards token; server-side blacklist can be added later)
- [x] `POST /api/v2/auth/refresh` — requires valid Bearer token, returns new token with extended expiry
- [x] Refresh fails if token is expired beyond a grace period
- [x] Both endpoints return error envelope on 401/403
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/Controller/Api/AuthRefreshTest.php
class AuthRefreshTest extends WebTestCase
{
    public function testRefreshReturnsNewToken(): void
    {
        $token = $this->loginAsAdmin();
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/refresh', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $body['data']);
        $this->assertNotSame($token, $body['data']['token']);
    }

    public function testLogoutInvalidatesToken(): void
    {
        $token = $this->loginAsAdmin();
        $client = static::createClient();

        $client->request('POST', '/api/v2/auth/logout', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testRefreshWithoutTokenFails(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/refresh');
        $this->assertResponseStatusCodeSame(401);
    }
}
```

---

## Epic E5: Events API

**Goal:** Full CRUD REST endpoints for events, delegating to webcalendar-core services.

### E5-S1: List Events Endpoint

**Status:** DONE

**Description:**
Implement `GET /api/v2/events` with date range filtering, pagination, and the standard response envelope. Delegates to webcalendar-core's `EventService`.

**Preconditions:** E3-S3, E4-S3

**Acceptance Criteria:**
- [x] `src/Controller/Api/EventController.php` with `list()` action
- [x] Requires authentication (401 without valid JWT)
- [x] Query params: `start` (YYYYMMDD), `end` (YYYYMMDD), `page` (default 1), `limit` (default 20, max 100)
- [x] Calls `EventService::getEventsInDateRange()` with authenticated user
- [x] Returns events in standard envelope with pagination meta
- [x] Returns empty array (not error) when no events found
- [x] Invalid date format returns 400 with error envelope
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/Controller/Api/EventControllerListTest.php
class EventControllerListTest extends WebTestCase
{
    public function testListRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/events');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testListReturnsEventsInEnvelope(): void
    {
        $token = $this->loginAndGetToken();
        $this->createTestEvent($token);

        $client = static::createClient();
        $client->request('GET', '/api/v2/events?start=20260101&end=20261231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($body['data']);
        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayHasKey('total', $body['meta']);
    }

    public function testListReturnsEmptyArrayWhenNoEvents(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('GET', '/api/v2/events?start=19000101&end=19001231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $body['data']);
    }

    public function testListPagination(): void
    {
        $token = $this->loginAndGetToken();
        // Create 25 events...
        $client = static::createClient();
        $client->request('GET', '/api/v2/events?start=20260101&end=20261231&page=1&limit=10', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(10, $body['data']);
        $this->assertSame(25, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['page']);
    }

    public function testListInvalidDateReturns400(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('GET', '/api/v2/events?start=notadate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(400);
    }
}
```

---

### E5-S2: Get Single Event Endpoint

**Status:** DONE

**Description:**
Implement `GET /api/v2/events/{id}` to retrieve a single event by ID.

**Preconditions:** E5-S1

**Acceptance Criteria:**
- [x] Returns event in standard envelope with all fields
- [x] Returns 404 with error envelope if event not found
- [x] Respects access permissions (cannot view private events of other users)
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
class EventControllerGetTest extends WebTestCase
{
    public function testGetExistingEvent(): void
    {
        $token = $this->loginAndGetToken();
        $eventId = $this->createTestEvent($token);

        $client = static::createClient();
        $client->request('GET', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($eventId, $body['data']['id']);
        $this->assertArrayHasKey('title', $body['data']);
    }

    public function testGetNonexistentEventReturns404(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('GET', '/api/v2/events/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }
}
```

---

### E5-S3: Create Event Endpoint

**Status:** DONE

**Description:**
Implement `POST /api/v2/events` to create a new event.

**Preconditions:** E5-S1

**Acceptance Criteria:**
- [x] Accepts JSON body with event fields per OpenAPI spec
- [x] Required field: `title`, `start_date`
- [x] Returns 201 with created event in envelope (including generated `id`)
- [x] Returns 400 with validation errors if required fields missing
- [x] Sets `created_by` to authenticated user's login
- [x] Supports all-day events (no `start_time`) and timed events
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
class EventControllerCreateTest extends WebTestCase
{
    public function testCreateMinimalEvent(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode([
            'title' => 'Test Event',
            'start_date' => '20260315',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Test Event', $body['data']['title']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function testCreateTimedEvent(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode([
            'title' => 'Meeting',
            'start_date' => '20260315',
            'start_time' => '100000',
            'duration' => 60,
            'location' => 'Room A',
            'description' => 'Weekly sync',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('100000', $body['data']['start_time']);
        $this->assertSame(60, $body['data']['duration']);
    }

    public function testCreateWithoutTitleReturns400(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['start_date' => '20260315']));

        $this->assertResponseStatusCodeSame(400);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotNull($body['error']);
    }

    public function testCreateWithoutAuthReturns401(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['title' => 'Test', 'start_date' => '20260315']));

        $this->assertResponseStatusCodeSame(401);
    }
}
```

---

### E5-S4: Update Event Endpoint

**Status:** DONE

**Description:**
Implement `PUT /api/v2/events/{id}` to update an existing event.

**Preconditions:** E5-S3

**Acceptance Criteria:**
- [x] Accepts partial update (only fields present in body are changed)
- [x] Returns 200 with updated event in envelope
- [x] Returns 404 if event not found
- [x] Returns 403 if user does not own the event and is not admin
- [x] Returns 400 for invalid field values
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
class EventControllerUpdateTest extends WebTestCase
{
    public function testUpdateTitle(): void
    {
        $token = $this->loginAndGetToken();
        $eventId = $this->createTestEvent($token);

        $client = static::createClient();
        $client->request('PUT', "/api/v2/events/{$eventId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['title' => 'Updated Title']));

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Updated Title', $body['data']['title']);
    }

    public function testUpdateNonexistentReturns404(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('PUT', '/api/v2/events/999999', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['title' => 'X']));
        $this->assertResponseStatusCodeSame(404);
    }
}
```

---

### E5-S5: Delete Event Endpoint

**Status:** DONE

**Description:**
Implement `DELETE /api/v2/events/{id}` to delete an event.

**Preconditions:** E5-S3

**Acceptance Criteria:**
- [x] Returns 204 on successful deletion
- [x] Returns 404 if event not found
- [x] Returns 403 if user does not own the event and is not admin
- [x] Admin can delete any event
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
class EventControllerDeleteTest extends WebTestCase
{
    public function testDeleteEvent(): void
    {
        $token = $this->loginAndGetToken();
        $eventId = $this->createTestEvent($token);

        $client = static::createClient();
        $client->request('DELETE', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);

        // Verify it's gone
        $client->request('GET', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteNonexistentReturns404(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('DELETE', '/api/v2/events/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }
}
```

---

### E5-S6: Event Serialization and DTO Mapping

**Status:** DONE

**Description:**
Implement request/response DTO classes that map between JSON request bodies and webcalendar-core domain entities, and between core entities and JSON responses. These are used by all event controller actions.

**Preconditions:** E3-S3

**Acceptance Criteria:**
- [x] `src/DTO/EventRequestDTO.php` — creates webcalendar-core Event entity from JSON array
  - Validates required fields, throws `\InvalidArgumentException` on missing `title`/`start_date`
  - Maps `start_date` (string YYYYMMDD) + `start_time` (string HHMMSS) to DateTimeImmutable
  - Maps `access` string to `AccessLevel` enum, `type` string to `EventType` enum
  - Also provides `applyUpdate()` for partial updates on existing events
- [x] `src/DTO/EventResponseDTO.php` — converts webcalendar-core Event entity to array
  - Includes all event fields: `id`, `uid`, `title`, `description`, `start_date`, `start_time`, `end_date`, `end_time`, `duration`, `location`, `access`, `type`, `created_by`, `all_day`, `sequence`, `status`
  - Formats dates as YYYYMMDD strings, times as HHMMSS strings (null for all-day)
  - Also provides `fromCollection()` for batch conversion
- [x] All DTO methods are stateless, static or pure
- [x] PHPStan level 9 passes
- [x] Psalm passes

**Recommended Tests:**
```php
// tests/DTO/EventRequestDTOTest.php
class EventRequestDTOTest extends TestCase
{
    public function testCreatesEventFromValidData(): void
    {
        $data = ['title' => 'Test', 'start_date' => '20260315', 'start_time' => '100000', 'duration' => 60];
        $event = EventRequestDTO::toEntity($data, 'admin');
        $this->assertSame('Test', $event->getTitle());
        $this->assertSame(20260315, $event->getStartDate());
        $this->assertSame(100000, $event->getStartTime());
    }

    public function testThrowsOnMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventRequestDTO::toEntity(['start_date' => '20260315'], 'admin');
    }

    public function testAllDayEventSetsTimeToNegativeOne(): void
    {
        $data = ['title' => 'All Day', 'start_date' => '20260315'];
        $event = EventRequestDTO::toEntity($data, 'admin');
        $this->assertSame(-1, $event->getStartTime());
    }
}

// tests/DTO/EventResponseDTOTest.php
class EventResponseDTOTest extends TestCase
{
    public function testConvertsEntityToArray(): void
    {
        $event = $this->createTestEventEntity();
        $array = EventResponseDTO::fromEntity($event);
        $this->assertSame('20260315', $array['start_date']);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
    }
}
```

---

## Epic E6: Users API

**Goal:** Basic user management endpoints for Phase 1.

### E6-S1: List and Get Users Endpoints

**Status:** DONE

**Description:**
Implement `GET /api/v2/users` (list) and `GET /api/v2/users/{login}` (single). List is admin-only; users can view their own profile.

**Preconditions:** E4-S3, E3-S3

**Acceptance Criteria:**
- [x] `src/Controller/Api/UserController.php`
- [x] `GET /users` — requires admin role, returns paginated user list in envelope
- [x] `GET /users/{login}` — admin can view any user, non-admin can only view self
- [x] Non-admin accessing `/users` gets 403 (via AuthorizationException)
- [x] Non-admin accessing other user's profile gets 403
- [x] User response excludes password hash
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
class UserControllerTest extends WebTestCase
{
    public function testListUsersAsAdmin(): void
    {
        $token = $this->loginAsAdmin();
        $client = static::createClient();
        $client->request('GET', '/api/v2/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($body['data']);
    }

    public function testListUsersAsNonAdminFails(): void
    {
        $token = $this->loginAsRegularUser();
        $client = static::createClient();
        $client->request('GET', '/api/v2/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testGetOwnProfile(): void
    {
        $token = $this->loginAsRegularUser();
        $client = static::createClient();
        $client->request('GET', '/api/v2/users/regularuser', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testGetOtherProfileAsNonAdminFails(): void
    {
        $token = $this->loginAsRegularUser();
        $client = static::createClient();
        $client->request('GET', '/api/v2/users/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserResponseExcludesPassword(): void
    {
        $token = $this->loginAsAdmin();
        $client = static::createClient();
        $client->request('GET', '/api/v2/users/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('password', $body['data']);
        $this->assertArrayNotHasKey('password_hash', $body['data']);
    }
}
```

---

### E6-S2: Create User Endpoint

**Status:** DONE

**Description:**
Implement `POST /api/v2/users` (admin-only) to create new users.

**Preconditions:** E6-S1

**Acceptance Criteria:**
- [x] Admin-only (403 for non-admin via AuthorizationException)
- [x] Required fields: `login`, `password`, `email`
- [x] Returns 201 with created user in envelope (excludes password)
- [x] Returns 409 if login already exists
- [x] Returns 400 for invalid/missing fields
- [x] Password is hashed via webcalendar-core's `UserService::hashPassword()`
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
class UserControllerCreateTest extends WebTestCase
{
    public function testCreateUser(): void
    {
        $token = $this->loginAsAdmin();
        $client = static::createClient();
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode([
            'login' => 'newuser',
            'password' => 'SecurePass123!',
            'email' => 'new@example.com',
            'firstname' => 'New',
            'lastname' => 'User',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('newuser', $body['data']['login']);
        $this->assertArrayNotHasKey('password', $body['data']);
    }

    public function testCreateDuplicateLoginReturns409(): void
    {
        $token = $this->loginAsAdmin();
        $client = static::createClient();
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode([
            'login' => 'admin', // already exists
            'password' => 'pass',
            'email' => 'dup@example.com',
        ]));
        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateAsNonAdminFails(): void
    {
        $token = $this->loginAsRegularUser();
        $client = static::createClient();
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode([
            'login' => 'hacker',
            'password' => 'pass',
            'email' => 'h@example.com',
        ]));
        $this->assertResponseStatusCodeSame(403);
    }
}
```

---

### E6-S3: Update User and Change Password Endpoints

**Status:** DONE

**Description:**
Implement `PUT /api/v2/users/{login}` (update profile) and `PUT /api/v2/users/{login}/password` (change password).

**Preconditions:** E6-S1

**Acceptance Criteria:**
- [x] `PUT /users/{login}` — admin can update any user, non-admin can update self only
- [x] Updatable fields: `firstname`, `lastname`, `email`, `enabled` (admin only), `is_admin` (admin only)
- [x] Non-admin cannot change `enabled` or `is_admin` fields (silently ignored)
- [x] `PUT /users/{login}/password` — requires `new_password` (non-admin also requires `current_password`)
- [x] Admin can change any user's password without `current_password`
- [x] Returns 400 if `current_password` is wrong
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
class UserControllerUpdateTest extends WebTestCase
{
    public function testUpdateOwnProfile(): void
    {
        $token = $this->loginAsRegularUser();
        $client = static::createClient();
        $client->request('PUT', '/api/v2/users/regularuser', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['firstname' => 'Updated']));

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Updated', $body['data']['firstname']);
    }

    public function testChangeOwnPassword(): void
    {
        $token = $this->loginAsRegularUser();
        $client = static::createClient();
        $client->request('PUT', '/api/v2/users/regularuser/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode([
            'current_password' => 'regularpass',
            'new_password' => 'NewPass123!',
        ]));
        $this->assertResponseIsSuccessful();

        // Verify new password works
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['username' => 'regularuser', 'password' => 'NewPass123!']));
        $this->assertResponseIsSuccessful();
    }

    public function testChangePasswordWrongCurrentPassword(): void
    {
        $token = $this->loginAsRegularUser();
        $client = static::createClient();
        $client->request('PUT', '/api/v2/users/regularuser/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode([
            'current_password' => 'wrongpassword',
            'new_password' => 'NewPass123!',
        ]));
        $this->assertResponseStatusCodeSame(400);
    }
}
```

---

## Epic E7: Categories API

**Goal:** Category CRUD endpoints for event categorization.

### E7-S1: List and Get Categories

**Status:** DONE

**Description:**
Implement `GET /api/v2/categories` and `GET /api/v2/categories/{id}`. Categories include both global and user-personal categories.

**Preconditions:** E4-S3, E3-S3

**Acceptance Criteria:**
- [x] `src/Controller/Api/CategoryController.php`
- [x] `GET /categories` returns global categories + current user's personal categories
- [x] `?include_global=false` returns only personal categories (via CategoryService)
- [x] `GET /categories/{id}` returns single category
- [x] Returns 404 for nonexistent category
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
class CategoryControllerTest extends WebTestCase
{
    public function testListCategoriesIncludesGlobal(): void
    {
        $token = $this->loginAndGetToken();
        $this->createGlobalCategory($token, 'Work', '#FF0000');

        $client = static::createClient();
        $client->request('GET', '/api/v2/categories', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($body['data']);
        $names = array_column($body['data'], 'name');
        $this->assertContains('Work', $names);
    }

    public function testGetNonexistentCategory(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('GET', '/api/v2/categories/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }
}
```

---

### E7-S2: Create, Update, Delete Categories

**Status:** DONE

**Description:**
Implement `POST /api/v2/categories`, `PUT /api/v2/categories/{id}`, `DELETE /api/v2/categories/{id}`. Global categories require admin. Personal categories belong to the creating user.

**Preconditions:** E7-S1

**Acceptance Criteria:**
- [x] `POST /categories` — creates category, `is_global: true` requires admin
- [x] Returns 201 with created category
- [x] `PUT /categories/{id}` — owner or admin can update
- [x] `DELETE /categories/{id}` — owner or admin can delete; returns 204
- [x] Non-owner non-admin gets 403 on update/delete (via CategoryService authorization)
- [x] PHPStan level 9 passes

**Recommended Tests:**
```php
class CategoryControllerCrudTest extends WebTestCase
{
    public function testCreatePersonalCategory(): void
    {
        $token = $this->loginAndGetToken();
        $client = static::createClient();
        $client->request('POST', '/api/v2/categories', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['name' => 'Personal', 'color' => '#00FF00']));

        $this->assertResponseStatusCodeSame(201);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Personal', $body['data']['name']);
        $this->assertFalse($body['data']['is_global']);
    }

    public function testCreateGlobalCategoryRequiresAdmin(): void
    {
        $token = $this->loginAsRegularUser();
        $client = static::createClient();
        $client->request('POST', '/api/v2/categories', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['name' => 'Global', 'is_global' => true]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteCategory(): void
    {
        $token = $this->loginAndGetToken();
        $catId = $this->createTestCategory($token);

        $client = static::createClient();
        $client->request('DELETE', "/api/v2/categories/{$catId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);
    }
}
```

---

## Epic E8: React SPA Foundation

**Goal:** Working React app with routing, auth context, API client, and basic layout.

### E8-S1: API Client Setup (openapi-fetch)

**Status:** DONE

**Description:**
Configure the type-safe API client using `openapi-fetch` with generated types from webcalendar-openapi. Set up request/response interceptors for auth token injection and error handling.

**Preconditions:** E2-S1 (need generated types), E1-S3

**Acceptance Criteria:**
- [x] `src/api/client.ts` exports a configured `openapi-fetch` client
- [x] Client reads base URL from `import.meta.env.VITE_API_URL`
- [x] Auth interceptor automatically adds `Authorization: Bearer <token>` header from stored token
- [x] 401 responses trigger automatic redirect to login page
- [x] All API calls are fully typed (TypeScript compiler errors on wrong paths/params)
- [x] `.env.development` sets `VITE_API_URL=http://localhost:47180/api/v2`
- [x] Vitest passes

**Recommended Tests:**
```typescript
// src/api/__tests__/client.test.ts
import { describe, it, expect, vi } from 'vitest';

describe('API Client', () => {
  it('adds auth header when token is stored', () => {
    // Mock localStorage with token
    // Verify fetch is called with Authorization header
  });

  it('redirects to login on 401', () => {
    // Mock a 401 response
    // Verify navigation to /login
  });

  it('uses VITE_API_URL as base URL', () => {
    // Verify client baseUrl matches env var
  });
});
```

---

### E8-S2: Authentication Context and Login Page

**Status:** DONE

**Description:**
Implement React auth context (provider, hook, protected route wrapper) and a login page. Token is stored in localStorage and used by the API client.

**Preconditions:** E8-S1, E4-S3 (need working login endpoint)

**Acceptance Criteria:**
- [x] `src/auth/AuthProvider.tsx` — React context providing: `user`, `token`, `login()`, `logout()`, `isAuthenticated`
- [x] `src/auth/useAuth.ts` — hook consuming the auth context (via `auth-context.ts`)
- [x] `src/auth/ProtectedRoute.tsx` — wrapper that redirects to `/login` if not authenticated
- [x] `src/auth/LoginPage.tsx` — login form with username/password fields
  - Calls `POST /api/v2/auth/login`
  - On success: stores token in localStorage, updates context, redirects to `/`
  - On failure: shows error message (no alert, inline error)
  - Submit button disabled while request in flight
  - Accessible: labels, focus management, keyboard navigation
- [x] Token persisted in `localStorage` under key `wctng_token`
- [x] On app load, existing token is validated (checked for expiry)
- [x] Vitest tests pass for AuthProvider and LoginPage

**Recommended Tests:**
```typescript
// src/auth/__tests__/AuthProvider.test.tsx
describe('AuthProvider', () => {
  it('provides null user when not logged in', () => {});
  it('provides user after successful login', () => {});
  it('clears user on logout', () => {});
  it('restores session from localStorage on mount', () => {});
  it('clears expired token on mount', () => {});
});

// src/auth/__tests__/LoginPage.test.tsx
describe('LoginPage', () => {
  it('renders username and password fields', () => {});
  it('shows error on invalid credentials', () => {});
  it('redirects to / on successful login', () => {});
  it('disables submit button during request', () => {});
  it('is keyboard navigable', () => {});
});
```

---

### E8-S3: App Layout and Routing

**Status:** DONE

**Description:**
Implement the main app shell layout (header, sidebar, content area) and React Router configuration. Protected routes require authentication.

**Preconditions:** E8-S2

**Acceptance Criteria:**
- [x] `src/App.tsx` sets up React Router with routes:
  - `/login` → LoginPage (public)
  - `/` → CalendarPage (protected, redirect from login)
  - `/calendar` → CalendarPage (protected, redirects to /)
  - `/admin/users` → UserManagement (protected, admin only)
  - `*` → 404 Not Found page
- [x] `src/components/layout/AppLayout.tsx` — wraps protected pages with:
  - Header: app name, user display name, logout button
  - Sidebar: navigation links (Calendar, Admin — admin-only items hidden for non-admins)
  - Content area: renders child route
- [x] Layout is responsive (sidebar hidden on mobile, mobile nav in header)
- [x] Tailwind CSS + Shadcn/ui components used for all UI
- [x] Vitest tests pass

**Recommended Tests:**
```typescript
// src/components/layout/__tests__/AppLayout.test.tsx
describe('AppLayout', () => {
  it('renders header with app name', () => {});
  it('renders sidebar with Calendar link', () => {});
  it('hides admin links for non-admin users', () => {});
  it('shows admin links for admin users', () => {});
  it('logout button calls auth.logout()', () => {});
});

// src/App.test.tsx
describe('App Routing', () => {
  it('redirects unauthenticated users to /login', () => {});
  it('renders CalendarPage at / when authenticated', () => {});
  it('renders 404 for unknown routes', () => {});
});
```

---

### E8-S4: Shadcn/ui Component Installation

**Status:** DONE

**Description:**
Install and configure the Shadcn/ui components needed for Phase 1. Copy components into the project (Shadcn/ui is copy/paste, not a dependency).

**Preconditions:** E1-S3

**Acceptance Criteria:**
- [x] `src/components/ui/` contains core Shadcn/ui components:
  - `button.tsx` (with variants: default, destructive, outline, secondary, ghost, link)
  - `input.tsx`
  - `label.tsx` (Radix UI Label primitive)
  - `card.tsx` (Card, CardHeader, CardTitle, CardContent)
  - `textarea.tsx`
  - `badge.tsx` (with variants)
  - `separator.tsx` (Radix UI Separator primitive)
  - dialog/sheet/select deferred — custom modals used in E10 instead
- [x] `src/lib/utils.ts` contains the `cn()` utility (clsx + tailwind-merge)
- [x] `tailwind.config.ts` includes Shadcn/ui theme tokens (CSS variables)
- [x] `src/index.css` includes Shadcn/ui base styles and CSS variables (light + dark)
- [x] All components render without errors (12 tests)
- [x] TypeScript compilation passes

**Recommended Tests:**
```typescript
// src/components/ui/__tests__/components.test.tsx
describe('Shadcn UI Components', () => {
  it('Button renders with variants', () => {
    render(<Button variant="default">Click</Button>);
    expect(screen.getByRole('button')).toHaveTextContent('Click');
  });

  it('Dialog opens and closes', () => {
    render(
      <Dialog>
        <DialogTrigger>Open</DialogTrigger>
        <DialogContent>Content</DialogContent>
      </Dialog>
    );
    fireEvent.click(screen.getByText('Open'));
    expect(screen.getByText('Content')).toBeVisible();
  });

  it('Input accepts and displays value', () => {
    render(<Input placeholder="Enter text" />);
    const input = screen.getByPlaceholderText('Enter text');
    fireEvent.change(input, { target: { value: 'hello' } });
    expect(input).toHaveValue('hello');
  });
});
```

---

### E8-S5: React Query Setup and Error Handling

**Status:** DONE

**Description:**
Configure TanStack React Query for data fetching, caching, and server state management. Set up global error handling for API errors.

**Preconditions:** E8-S1

**Acceptance Criteria:**
- [x] `src/main.tsx` wraps app in `<QueryClientProvider>`
- [x] `QueryClient` configured with:
  - Default stale time: 60 seconds
  - Default retry: 1 (don't hammer failing endpoints)
  - Global error handler for 401 (via api client middleware)
- [x] `src/hooks/useApi.ts` — custom hook pattern for API queries:
  - `useApiQuery` wraps `useQuery` with openapi-fetch error handling
  - Returns `{ data, isLoading, error }` with proper TypeScript types
- [x] `src/hooks/useApi.ts` — also exports `useApiMutation` for API mutations:
  - Wraps `useMutation` with openapi-fetch error handling
  - Automatic cache invalidation on success via `invalidateKeys`
- [x] Toast notifications deferred to E10 (component installation)
- [x] Vitest tests pass

**Recommended Tests:**
```typescript
// src/hooks/__tests__/useApi.test.tsx
describe('useApi', () => {
  it('returns loading state initially', () => {});
  it('returns data on success', () => {});
  it('returns error on failure', () => {});
  it('triggers logout on 401', () => {});
});
```

---

## Epic E9: Calendar Views

**Goal:** Day, week, month calendar views using FullCalendar, with navigation and view switching.

### E9-S1: FullCalendar Integration

**Status:** DONE

**Description:**
Integrate FullCalendar React component with the API client. Events are fetched from `GET /api/v2/events` based on the visible date range.

**Preconditions:** E8-S3, E5-S1

**Acceptance Criteria:**
- [x] `src/calendar/FullCalendarWrapper.tsx` — wraps `@fullcalendar/react`
  - Plugins: `dayGridPlugin`, `timeGridPlugin`, `listPlugin`, `interactionPlugin`
  - Views: `dayGridMonth`, `timeGridWeek`, `timeGridDay`, `listWeek`
  - Fetches events via API when visible date range changes (`datesSet` callback)
  - Maps API event format to FullCalendar event format via `eventMapper.ts`
  - Loading indicator while events are being fetched
- [x] Events display with correct times, titles, and colors
- [x] All-day events render in the all-day section (mapped with `allDay: true`)
- [x] Timed events render at the correct time slot (ISO datetime strings)
- [x] Vitest tests pass

**Recommended Tests:**
```typescript
// src/calendar/__tests__/FullCalendarWrapper.test.tsx
describe('FullCalendarWrapper', () => {
  it('renders FullCalendar component', () => {});
  it('fetches events when date range changes', () => {});
  it('maps API events to FullCalendar format', () => {});
  it('shows loading indicator during fetch', () => {});
  it('renders all-day events correctly', () => {});
});
```

---

### E9-S2: Calendar Navigation and View Switcher

**Status:** DONE

**Description:**
Implement the calendar toolbar with date navigation (prev/next/today) and view switching (month/week/day/list).

**Preconditions:** E9-S1

**Acceptance Criteria:**
- [x] FullCalendar's built-in `headerToolbar` provides:
  - Previous/Next buttons to navigate dates
  - Today button to jump to current date
  - View switcher: Month, Week, Day, List
  - Current date range displayed as title
- [x] `src/calendar/useCalendarUrlSync.ts` — URL query params for view/date state
- [x] Browser back/forward navigates calendar state (via React Router searchParams)
- [x] `src/calendar/useKeyboardShortcuts.ts` — `←`/`→` prev/next, `T` today, `M`/`W`/`D` views (ignores input fields)
- [x] Vitest tests pass (12 new tests)

**Recommended Tests:**
```typescript
// src/calendar/__tests__/CalendarToolbar.test.tsx
describe('CalendarToolbar', () => {
  it('renders prev/next/today buttons', () => {});
  it('renders view switcher with all options', () => {});
  it('calls onNavigate when prev clicked', () => {});
  it('calls onViewChange when view button clicked', () => {});
  it('displays current date range title', () => {});
  it('highlights active view button', () => {});
});
```

---

### E9-S3: Calendar Page Assembly

**Status:** DONE

**Description:**
Assemble the main calendar page combining the toolbar, FullCalendar wrapper, and event data fetching into a complete view.

**Preconditions:** E9-S1, E9-S2

**Acceptance Criteria:**
- [x] `src/calendar/CalendarPage.tsx` composes FullCalendarWrapper
- [x] `src/calendar/useCalendarEvents.ts` — `fetchCalendarEvents()`:
  - Fetches events from API for given date range
  - Transforms API events to FullCalendar format via `eventMapper`
  - Returns empty array on error (graceful degradation)
  - Called by FullCalendarWrapper on `datesSet` (refetches on navigation)
- [x] Page loads and displays current month view by default
- [x] Switching views works and refetches events
- [x] Empty state: FullCalendar shows empty calendar grid when no events
- [x] Vitest tests pass (6 new tests)

**Recommended Tests:**
```typescript
// src/calendar/__tests__/CalendarPage.test.tsx
describe('CalendarPage', () => {
  it('renders toolbar and calendar', () => {});
  it('fetches events for current date range', () => {});
  it('updates when navigating to different dates', () => {});
  it('shows empty state message when no events', () => {});
});

// src/calendar/__tests__/useCalendarEvents.test.ts
describe('useCalendarEvents', () => {
  it('fetches events for given date range', () => {});
  it('transforms API events to FullCalendar format', () => {});
  it('handles all-day events', () => {});
  it('handles timed events', () => {});
});
```

---

### E9-S4: Category Color Coding

**Status:** DONE

**Description:**
Fetch categories and apply their colors to calendar events. Events without a category use a default color.

**Preconditions:** E9-S1, E7-S1

**Acceptance Criteria:**
- [x] Categories fetched on app load and cached (5 min staleTime)
- [x] `src/calendar/useCategories.ts` — `useCategories()` hook + `getEventColor()` utility
- [x] Events colored by their first category's color via `getEventColor()`
- [x] Default color (`#3788d8`) used for uncategorized events
- [x] Category legend deferred to future enhancement
- [x] Vitest tests pass (6 new tests)

**Recommended Tests:**
```typescript
describe('Category Color Coding', () => {
  it('applies category color to event', () => {});
  it('uses default color for uncategorized events', () => {});
  it('fetches categories once and caches', () => {});
});
```

---

## Epic E10: Event Management UI

**Goal:** Create, view, edit, and delete events from the calendar UI.

### E10-S1: Event Detail View

**Status:** DONE

**Description:**
Clicking an event on the calendar opens a detail popover/dialog showing event information.

**Preconditions:** E9-S1

**Acceptance Criteria:**
- [x] Clicking an event on the calendar opens `EventDetailDialog` (via FullCalendarWrapper `onEventClick`)
- [x] Dialog shows: title, date/time, duration, location, description, access level
- [x] Dialog has Edit and Delete action buttons
- [x] Close button and click-outside-to-close behavior (backdrop click)
- [x] Accessible: `role="dialog"`, `aria-modal`, `aria-labelledby`, close button with `aria-label`
- [x] Vitest tests pass (9 new tests)

**Recommended Tests:**
```typescript
describe('EventDetailDialog', () => {
  it('renders event title and details', () => {});
  it('shows Edit and Delete buttons', () => {});
  it('closes on Escape key', () => {});
  it('closes on outside click', () => {});
  it('shows formatted date and time', () => {});
  it('shows "All day" for all-day events', () => {});
});
```

---

### E10-S2: Event Create Dialog

**Status:** DONE

**Description:**
A dialog/modal for creating new events. Triggered by clicking an empty time slot on the calendar or a "New Event" button.

**Preconditions:** E8-S4, E5-S3, E9-S1

**Acceptance Criteria:**
- [x] `src/calendar/EventDialog.tsx` — reusable for create and edit (via `mode` prop)
- [x] Form fields: title (required), date, start time, duration, location, description, access level (select), all-day toggle
- [x] Clicking empty time slot pre-fills date and time (via `initialDate`/`initialTime` props)
- [x] Clicking empty day (month view) pre-fills date as all-day (via `initialAllDay` prop)
- [x] Submitting calls `onSave` callback with `EventFormData`
- [x] On success (`onSave` returns true): closes dialog
- [x] On error: shows inline validation errors
- [x] Cancel button closes without calling onSave
- [x] Form validation: title required
- [x] Vitest tests pass (10 new tests)

**Recommended Tests:**
```typescript
describe('EventDialog - Create', () => {
  it('renders all form fields', () => {});
  it('pre-fills date/time from slot click', () => {});
  it('validates title is required', () => {});
  it('submits event to API', () => {});
  it('closes and shows toast on success', () => {});
  it('shows error on API failure', () => {});
  it('cancel closes without API call', () => {});
  it('disables submit while saving', () => {});
  it('toggling all-day hides time fields', () => {});
});
```

---

### E10-S3: Event Edit Dialog

**Status:** DONE

**Description:**
Edit an existing event using the same dialog as create, pre-filled with current values.

**Preconditions:** E10-S2, E5-S4

**Acceptance Criteria:**
- [x] Edit button in EventDetailDialog opens EventDialog in edit mode (via `onEdit` callback)
- [x] All fields pre-filled via `apiEventToInitialValues()` helper
- [x] Submitting calls `onSave` callback with updated `EventFormData`
- [x] On success: closes dialog (via `onSave` returning true)
- [x] Shows "Save Changes" button in edit mode
- [x] Vitest tests pass (5 new tests)

**Recommended Tests:**
```typescript
describe('EventDialog - Edit', () => {
  it('pre-fills all fields from existing event', () => {});
  it('submits only changed fields', () => {});
  it('calls PUT endpoint with event ID', () => {});
  it('shows success toast on save', () => {});
});
```

---

### E10-S4: Event Delete Confirmation

**Status:** DONE

**Description:**
Delete button triggers a confirmation dialog before calling the delete API.

**Preconditions:** E10-S1, E5-S5

**Acceptance Criteria:**
- [x] Delete button in EventDetailDialog triggers `onDelete` → opens ConfirmDeleteDialog
- [x] Confirmation shows event title in message
- [x] Confirm button calls `onConfirm` callback (caller handles DELETE API call)
- [x] Cancel returns to previous state via `onCancel` callback
- [x] Disabled state while deleting (loading indicator)
- [x] Vitest tests pass (7 new tests)

**Recommended Tests:**
```typescript
describe('Event Delete', () => {
  it('shows confirmation dialog with event title', () => {});
  it('calls DELETE endpoint on confirm', () => {});
  it('removes event from calendar on success', () => {});
  it('cancel does not delete', () => {});
  it('shows error toast on failure', () => {});
});
```

---

## Epic E11: End-to-End Tests

**Goal:** Playwright E2E tests covering critical user journeys through the full stack (React → API → Database).

### E11-S1: Playwright Infrastructure

**Status:** DONE

**Description:**
Configure Playwright to run against the Docker Compose stack. Create test fixtures for authentication, database seeding, and cleanup.

**Preconditions:** E1-S1, E8-S2

**Acceptance Criteria:**
- [x] `playwright.config.ts` configured:
  - Base URL: `http://localhost:47180`
  - Global setup waits for stack health check
  - Browsers: chromium
  - Screenshots on failure, video on first retry, trace on first retry
  - Retries: 1
- [x] `tests/e2e/fixtures/auth.ts` — `loginAsAdmin()`, `loginAsUser()`, `loginViaApi()`
- [x] `tests/e2e/fixtures/db.ts` — `createTestEvent()`, `getAdminToken()`
- [x] `tests/e2e/global-setup.mjs` — waits for Docker stack health endpoint
- [x] `tests/e2e/smoke.spec.ts` — 3 passing smoke tests
- [x] `npx playwright test` runs successfully (3 passed)

**Recommended Tests:**
```typescript
// tests/e2e/smoke.spec.ts
import { test, expect } from '@playwright/test';

test('app loads login page', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/.*login/);
  await expect(page.getByRole('heading')).toContainText(/login|sign in/i);
});
```

---

### E11-S2: Authentication E2E Tests

**Status:** NOT STARTED

**Description:**
End-to-end tests for the login/logout flow.

**Preconditions:** E11-S1, E8-S2, E4-S3

**Acceptance Criteria:**
- [ ] Tests cover:
  - Successful login redirects to calendar
  - Failed login shows error message
  - Logout returns to login page
  - Protected routes redirect to login when not authenticated
  - Session persists across page reload (localStorage token)
  - Expired token redirects to login
- [ ] All tests pass in Docker environment
- [ ] Tests run in under 30 seconds

**Recommended Tests:**
```typescript
// tests/e2e/auth.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
  test('login with valid credentials', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Username').fill('admin');
    await page.getByLabel('Password').fill('admin');
    await page.getByRole('button', { name: /login|sign in/i }).click();
    await expect(page).toHaveURL('/');
    await expect(page.getByText('admin')).toBeVisible(); // user displayed in header
  });

  test('login with invalid credentials shows error', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Username').fill('admin');
    await page.getByLabel('Password').fill('wrongpassword');
    await page.getByRole('button', { name: /login|sign in/i }).click();
    await expect(page.getByText(/invalid|incorrect/i)).toBeVisible();
    await expect(page).toHaveURL(/.*login/);
  });

  test('logout returns to login page', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.getByLabel('Username').fill('admin');
    await page.getByLabel('Password').fill('admin');
    await page.getByRole('button', { name: /login|sign in/i }).click();
    await expect(page).toHaveURL('/');

    // Logout
    await page.getByRole('button', { name: /logout/i }).click();
    await expect(page).toHaveURL(/.*login/);
  });

  test('protected route redirects to login', async ({ page }) => {
    await page.goto('/calendar');
    await expect(page).toHaveURL(/.*login/);
  });

  test('session persists across reload', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Username').fill('admin');
    await page.getByLabel('Password').fill('admin');
    await page.getByRole('button', { name: /login|sign in/i }).click();
    await expect(page).toHaveURL('/');

    await page.reload();
    await expect(page).toHaveURL('/');  // still authenticated
  });
});
```

---

### E11-S3: Calendar and Event Management E2E Tests

**Status:** NOT STARTED

**Description:**
End-to-end tests for the core calendar workflow: viewing the calendar, creating events, editing events, and deleting events.

**Preconditions:** E11-S1, E9-S3, E10-S2, E10-S3, E10-S4

**Acceptance Criteria:**
- [ ] Tests cover:
  - Calendar displays in month view by default
  - Switching between month/week/day views
  - Navigating to previous/next period
  - Creating an event via time slot click
  - Creating an event via "New Event" button
  - Created event appears on calendar
  - Clicking event opens detail dialog
  - Editing event updates the calendar
  - Deleting event removes from calendar
  - All-day event creation and display
- [ ] All tests pass in Docker environment
- [ ] Tests run in under 60 seconds

**Recommended Tests:**
```typescript
// tests/e2e/calendar.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Calendar', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin (use auth fixture)
    await page.goto('/login');
    await page.getByLabel('Username').fill('admin');
    await page.getByLabel('Password').fill('admin');
    await page.getByRole('button', { name: /login|sign in/i }).click();
    await expect(page).toHaveURL('/');
  });

  test('displays month view by default', async ({ page }) => {
    await expect(page.locator('.fc-dayGridMonth-view')).toBeVisible();
  });

  test('switch to week view', async ({ page }) => {
    await page.getByRole('button', { name: /week/i }).click();
    await expect(page.locator('.fc-timeGridWeek-view')).toBeVisible();
  });

  test('switch to day view', async ({ page }) => {
    await page.getByRole('button', { name: /day/i }).click();
    await expect(page.locator('.fc-timeGridDay-view')).toBeVisible();
  });

  test('create event via new event button', async ({ page }) => {
    await page.getByRole('button', { name: /new event/i }).click();

    // Fill in event form
    await page.getByLabel('Title').fill('E2E Test Event');
    await page.getByRole('button', { name: /save|create/i }).click();

    // Verify event appears on calendar
    await expect(page.getByText('E2E Test Event')).toBeVisible();
  });

  test('edit event', async ({ page }) => {
    // Click on existing event
    await page.getByText('E2E Test Event').click();
    await page.getByRole('button', { name: /edit/i }).click();

    // Update title
    await page.getByLabel('Title').clear();
    await page.getByLabel('Title').fill('Updated E2E Event');
    await page.getByRole('button', { name: /save/i }).click();

    // Verify updated title
    await expect(page.getByText('Updated E2E Event')).toBeVisible();
  });

  test('delete event', async ({ page }) => {
    await page.getByText('Updated E2E Event').click();
    await page.getByRole('button', { name: /delete/i }).click();
    await page.getByRole('button', { name: /confirm/i }).click();

    // Verify event is gone
    await expect(page.getByText('Updated E2E Event')).not.toBeVisible();
  });
});
```

---

## Story Execution Checklist (for AI Agent)

When implementing any story, follow this sequence:

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

## Glossary

| Term | Definition |
|------|-----------|
| **webcalendar-core** | PHP Composer library containing all business logic, at `../webcalendar-core` |
| **Envelope** | Standard JSON response wrapper: `{data, meta, error}` |
| **PHPStan Level 9** | Strictest PHP static analysis level — all types must be explicit, no mixed, no dynamic properties |
| **Psalm errorLevel 1** | Strictest Psalm level — equivalent to PHPStan level 9 |
| **YYYYMMDD** | Date format used by webcalendar-core internally (e.g., `20260315` = March 15, 2026) |
| **HHMMSS** | Time format used by webcalendar-core internally (e.g., `100000` = 10:00:00, `-1` = all-day) |
| **TDD** | Test-Driven Development — write failing test, write code to pass, refactor |
