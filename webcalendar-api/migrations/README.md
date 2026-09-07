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
try to re-run them — re-running errors on the duplicate index and column.

Only `execute`, `list`, `migrate`, `status`, `sync-metadata-storage` and
`up-to-date` are registered (there is no DoctrineMigrationsBundle here,
just the library behind `MigrationDependencyFactoryProvider`). So there
is no `migrations:version --add` and no `sync-metadata-storage
--add-missing`; baselining means creating the metadata table and
inserting the rows:

```bash
bin/console migrations:sync-metadata-storage

# then, against the same database:
INSERT IGNORE INTO api_doctrine_migration_versions
    (version, executed_at, execution_time)
VALUES ('App\Migrations\Version20260415120000', NOW(), 0),
       ('App\Migrations\Version20260415120100', NOW(), 0);
```

Confirm with `bin/console migrations:up-to-date`.

Fresh installs just run `migrations:migrate`.

## webcalendar-core's schema

Core owns its own tables and ships them as a flat `mysql-schema.sql`
with no migrations and no schema version, so bumping the package
silently changes what the app expects. v4.3.0 -> v4.10.0 added five
tables and four columns; the first symptom was a 500 on `/api/v2/events`
("Unknown column 'cat_is_tag'"). Fresh installs are fine — Docker mounts
the vendor schema as an init script — but existing databases pick up
nothing.

`bin/check-schema-drift.php` makes that loud instead of silent:

```bash
composer check-schema-drift              # vendor schema vs the snapshot
php bin/check-schema-drift.php --db      # a live database vs vendor schema
php bin/check-schema-drift.php --update-snapshot   # re-baseline
```

The default check compares the installed core's schema against
`schema/core-schema-snapshot.json` and needs no database, which is why
CI runs it: a CI check against a live database could never fail, since
CI builds its database from that same vendor file. It fires the moment
someone bumps core.

So when a bump trips the guard: read the diff, write the DDL (a
migration under `migrations/api/`, since core has nowhere to put it),
apply it to existing databases, then re-baseline the snapshot in the
same commit. `--db` is the operator-side check for whether a given
deployment has had that DDL applied yet.

This is detection only. It does not generate or apply DDL, and it
compares table and column presence — not types, indexes or constraints.
