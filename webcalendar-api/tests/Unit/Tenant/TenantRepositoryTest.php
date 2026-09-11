<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRepository;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;

final class TenantRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private TenantRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(TenantRepository::SCHEMA_SQL);
        $this->repo = new TenantRepository($this->pdo);
    }

    public function testSaveAndFindBySlug(): void
    {
        $tenant = new Tenant(
            id: 0,
            slug: 'acme',
            name: 'Acme Corp',
            dbHost: 'localhost',
            dbName: 'wc_acme',
            dbUser: 'wc_user',
            dbPassword: 'secret',
            plan: TenantPlan::Pro,
            status: TenantStatus::Active,
        );

        $id = $this->repo->save($tenant);
        $this->assertGreaterThan(0, $id);

        $found = $this->repo->findBySlug('acme');
        $this->assertNotNull($found);
        $this->assertSame('acme', $found->slug());
        $this->assertSame('Acme Corp', $found->name());
        $this->assertSame(TenantPlan::Pro, $found->plan());
    }

    public function testFindBySlugReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->findBySlug('nonexistent'));
    }

    public function testFindAllReturnsAllTenants(): void
    {
        $this->repo->save(new Tenant(0, 'alpha', 'Alpha', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));
        $this->repo->save(new Tenant(0, 'beta', 'Beta', 'h', 'd', 'u', 'p', TenantPlan::Pro, TenantStatus::Active));

        $all = $this->repo->findAll();
        $this->assertCount(2, $all);
    }

    public function testUpdateExistingTenant(): void
    {
        $id = $this->repo->save(new Tenant(0, 'gamma', 'Gamma', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));

        $updated = new Tenant($id, 'gamma', 'Gamma Updated', 'h2', 'd2', 'u2', 'p2', TenantPlan::Pro, TenantStatus::Suspended);
        $this->repo->save($updated);

        $found = $this->repo->findBySlug('gamma');
        $this->assertNotNull($found);
        $this->assertSame('Gamma Updated', $found->name());
        $this->assertSame(TenantPlan::Pro, $found->plan());
        $this->assertSame(TenantStatus::Suspended, $found->status());
    }

    public function testDelete(): void
    {
        $id = $this->repo->save(new Tenant(0, 'delta', 'Delta', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));
        $this->assertNotNull($this->repo->findBySlug('delta'));

        $this->repo->delete($id);
        $this->assertNull($this->repo->findBySlug('delta'));
    }

    public function testAnInsertStoresEveryColumnAndNotJustTheOnesUsuallyLookedAt(): void
    {
        // Read the whole row back, not a sample of it. The column lists live in
        // SQL strings, which no mutation reaches: a value bound to the wrong
        // column would pass a test that only checks the slug and the name.
        $id = $this->repo->save(new Tenant(
            id: 0,
            slug: 'whole-row',
            name: 'Whole Row Co',
            dbHost: 'db.example.test',
            dbName: 'wc_tenant_whole_row',
            dbUser: 'wc_whole_row',
            dbPassword: 'encrypted:secret',
            plan: TenantPlan::Pro,
            status: TenantStatus::Suspended,
        ));

        $found = $this->repo->findBySlug('whole-row');
        self::assertNotNull($found);
        self::assertSame($id, $found->id());
        self::assertSame('whole-row', $found->slug());
        self::assertSame('Whole Row Co', $found->name());
        self::assertSame('db.example.test', $found->dbHost());
        self::assertSame('wc_tenant_whole_row', $found->dbName());
        self::assertSame('wc_whole_row', $found->dbUser());
        self::assertSame('encrypted:secret', $found->dbPassword());
        self::assertSame(TenantPlan::Pro, $found->plan());
        self::assertSame(TenantStatus::Suspended, $found->status());
    }

    public function testAnUpdateRewritesEveryColumnItClaimsTo(): void
    {
        $id = $this->repo->save(
            new Tenant(0, 'movable', 'Before', 'h1', 'd1', 'u1', 'p1', TenantPlan::Free, TenantStatus::Pending),
        );

        $this->repo->save(new Tenant(
            id: $id,
            slug: 'movable',
            name: 'After',
            dbHost: 'h2',
            dbName: 'd2',
            dbUser: 'u2',
            dbPassword: 'p2',
            plan: TenantPlan::Enterprise,
            status: TenantStatus::Active,
        ));

        $found = $this->repo->findBySlug('movable');
        self::assertNotNull($found);
        self::assertSame($id, $found->id());
        self::assertSame('After', $found->name());
        self::assertSame('h2', $found->dbHost());
        self::assertSame('d2', $found->dbName());
        self::assertSame('u2', $found->dbUser());
        self::assertSame('p2', $found->dbPassword());
        self::assertSame(TenantPlan::Enterprise, $found->plan());
        self::assertSame(TenantStatus::Active, $found->status());
        self::assertSame(1, $this->rowCount(), 'an update must not insert a second row');
    }

    public function testAnUpdateCannotRenameTheSlug(): void
    {
        // The UPDATE deliberately leaves slug out -- it is the tenant's
        // identity, and the one caller that updates a tenant carries the
        // existing slug through. A rename is silently not performed, so
        // nothing should be built on the assumption that it works.
        $id = $this->repo->save(
            new Tenant(0, 'original', 'Name', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active),
        );

        $this->repo->save(
            new Tenant($id, 'renamed', 'Name', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active),
        );

        self::assertNull($this->repo->findBySlug('renamed'));
        self::assertNotNull($this->repo->findBySlug('original'));
    }

    public function testSavingAnIdThatIsNotThereChangesNothingAndSaysNothing(): void
    {
        // The UPDATE branch reports the id it was handed whether or not a row
        // matched. Pinned as the current behaviour: a caller cannot tell a
        // successful update from one that hit nothing.
        $returned = $this->repo->save(
            new Tenant(4242, 'ghost', 'Ghost', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active),
        );

        self::assertSame(4242, $returned);
        self::assertSame(0, $this->rowCount());
        self::assertNull($this->repo->findBySlug('ghost'));
    }

    public function testFindAllIsOrderedBySlugRatherThanByInsertion(): void
    {
        // Registered out of order on purpose: inserting them alphabetically
        // would pass with no ORDER BY at all.
        $this->repo->save(new Tenant(0, 'charlie', 'C', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));
        $this->repo->save(new Tenant(0, 'alpha', 'A', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));
        $this->repo->save(new Tenant(0, 'bravo', 'B', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));

        $slugs = array_map(static fn(Tenant $t): string => $t->slug(), $this->repo->findAll());

        self::assertSame(['alpha', 'bravo', 'charlie'], $slugs);
    }

    public function testDeleteRemovesTheOneTenantAndLeavesTheRest(): void
    {
        // A DELETE that lost its WHERE clause would empty the registry, and no
        // mutation of this file would show it: the clause is inside a string.
        $doomed = $this->repo->save(
            new Tenant(0, 'doomed', 'Doomed', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active),
        );
        $this->repo->save(new Tenant(0, 'keeper', 'Keeper', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));

        $this->repo->delete($doomed);

        self::assertNull($this->repo->findBySlug('doomed'));
        self::assertNotNull($this->repo->findBySlug('keeper'));
        self::assertSame(1, $this->rowCount());
    }

    public function testTheTableItselfRefusesASecondTenantWithTheSameSlug(): void
    {
        // Provisioning checks findBySlug() first, but the constraint is what
        // makes a race lose rather than duplicate a tenant.
        $this->repo->save(new Tenant(0, 'onlyone', 'First', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));

        $this->expectException(\PDOException::class);
        $this->repo->save(new Tenant(0, 'onlyone', 'Second', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));
    }

    public function testAnUpdateMovesUpdatedAtAndLeavesCreatedAtWhereItWas(): void
    {
        $id = $this->repo->save(
            new Tenant(0, 'stamped', 'Stamped', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active),
        );

        // Backdate both so the change is visible without waiting a second.
        $this->pdo
            ->prepare("UPDATE tenants SET created_at = '2020-01-01 00:00:00', updated_at = '2020-01-01 00:00:00' WHERE id = :id")
            ->execute(['id' => $id]);

        $this->repo->save(
            new Tenant($id, 'stamped', 'Renamed', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active),
        );

        $found = $this->repo->findBySlug('stamped');
        self::assertNotNull($found);
        self::assertSame('2020-01-01 00:00:00', $found->createdAt()?->format('Y-m-d H:i:s'));
        self::assertNotNull($found->updatedAt());
        self::assertGreaterThan(
            new \DateTimeImmutable('2020-01-02 00:00:00'),
            $found->updatedAt(),
            'an update should stamp updated_at',
        );
    }

    public function testBothSchemasDeclareTheSameColumns(): void
    {
        // Tests run on SQLite and production runs on MySQL, so a column added
        // to one constant and not the other would only surface in production.
        self::assertSame(
            self::columnNamesOf(TenantRepository::SCHEMA_SQL),
            self::columnNamesOf(TenantRepository::SCHEMA_SQL_MYSQL),
        );
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')?->fetchColumn();
    }

    /** @return list<string> */
    private static function columnNamesOf(string $schema): array
    {
        $body = substr($schema, (int) strpos($schema, '(') + 1);
        $names = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ')')) {
                continue;
            }

            $names[] = rtrim((string) strtok($line, " \t"), ',');
        }

        return $names;
    }
}
