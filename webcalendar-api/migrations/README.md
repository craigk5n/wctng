# API Schema Migrations

**Active path:** `migrations/api/Version*.php` — run via
`bin/console migrations:migrate` (PBP-S12).

**Legacy path:** `migrations/003_*.sql`, `migrations/004_*.sql` —
**do not add new files here.** These are kept for audit; the same
changes are re-expressed as Doctrine migration classes under
`migrations/api/`. An already-migrated DB will be noticed by the
Doctrine runner when `migrations:sync-metadata-storage --add-missing`
is run, which marks existing versions as executed without re-running
them.

## Tenant-scoped migrations

`migrations/tenant/*.sql` is a separate path owned by the in-repo
`TenantMigrator`. Tenant migrations run per tenant against each tenant's
own DB connection; they are unrelated to the API-layer Doctrine
migrations here.

## Workflow

```bash
# Generate a new migration scaffold:
bin/console migrations:generate

# See pending migrations:
bin/console migrations:status

# Apply all pending migrations:
bin/console migrations:migrate --no-interaction

# Roll back to a specific version:
bin/console migrations:execute 'App\Migrations\Version20260415120000' --down
```

## Version table

Doctrine records applied versions in `api_doctrine_migration_versions`
(namespaced so it can never collide with anything webcalendar-core's
legacy init-script schema manager writes).

## Existing installs

If you already applied `003_*.sql` / `004_*.sql` before this story
landed, mark those versions as executed so the Doctrine runner doesn't
try to re-run them:

```bash
bin/console migrations:sync-metadata-storage
bin/console migrations:version 'App\Migrations\Version20260415120000' --add
bin/console migrations:version 'App\Migrations\Version20260415120100' --add
```

Fresh installs just run `migrations:migrate`.

## Out of scope

Migrating webcalendar-core's own schema; that stays on the init-script
model by design (`doc/database/*.sql` in the core repo).
