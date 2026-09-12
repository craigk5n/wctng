<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Api\DashboardController;
use App\Security\WebCalendarUser;
use App\Service\DatabaseDsn;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * The dashboard's database-size figure, on MySQL.
 *
 * getSystemInfo() branches on the driver, and only the SQLite half is
 * reachable from the unit suite -- the MySQL half is a query against
 * information_schema that no test has ever run. Mutating it survives for that
 * reason rather than because it is harmless: a column name or a divisor wrong
 * there shows up as a plausible number on an admin page, or as an exception
 * swallowed into a null.
 *
 * Skipped unless DATABASE_URL names MySQL.
 */
final class DashboardStatsOnMySqlTest extends TestCase
{
    private const string NOW = '2026-09-11T12:00:00+00:00';

    private \PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $target = DatabaseDsn::fromUrl($url);
        if ($target->driver !== 'mysql') {
            self::markTestSkipped('DATABASE_URL does not name MySQL; the information_schema branch needs one.');
        }

        try {
            $this->pdo = new \PDO($target->dsn, $target->user, $target->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped('cannot reach MySQL: ' . $e->getMessage());
        }
    }

    private function controller(): DashboardController
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findAll')->willReturn([]);

        return new DashboardController($users, $this->pdo, null, new MockClock(self::NOW));
    }

    /** @return array<string, mixed> */
    private function system(): array
    {
        $response = ($this->controller())(
            new WebCalendarUser(new User('admin', 'Ad', 'Min', 'admin@example.com', true, true), null),
        );
        self::assertSame(200, $response->getStatusCode());

        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array{data: array{system: array<string, mixed>}} $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded['data']['system'];
    }

    public function testTheDatabaseSizeIsReadFromInformationSchema(): void
    {
        $system = $this->system();

        self::assertSame('mysql', $system['db_driver']);
        self::assertNotNull($system['db_size_mb'], 'the information_schema query returned nothing usable');

        // Against the same figure MySQL reports for this schema, so a wrong
        // column or a dropped divisor cannot pass as a plausible number.
        $stmt = $this->pdo->query(
            'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) FROM information_schema.TABLES WHERE table_schema = DATABASE()',
        );
        self::assertNotFalse($stmt);
        $expected = (float) $stmt->fetchColumn();

        self::assertSame($expected, (float) $system['db_size_mb']);
        self::assertGreaterThan(0, $expected, 'the test schema should not be empty');
    }

    public function testTheEventFiguresSurviveTheRealSchema(): void
    {
        // Unit coverage builds webcal_entry by hand; this asserts the same
        // three COUNTs run against the column types the schema actually
        // ships, rather than only the ones a fixture happens to declare.
        $response = ($this->controller())(
            new WebCalendarUser(new User('admin', 'Ad', 'Min', 'admin@example.com', true, true), null),
        );
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array{data: array{events: array<string, int>, users: array<string, int>}} $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        foreach (['total', 'created_7d', 'upcoming_7d'] as $key) {
            self::assertGreaterThanOrEqual(0, $decoded['data']['events'][$key], "events.{$key}");
        }
        self::assertGreaterThanOrEqual(0, $decoded['data']['users']['active_7d']);
    }
}
