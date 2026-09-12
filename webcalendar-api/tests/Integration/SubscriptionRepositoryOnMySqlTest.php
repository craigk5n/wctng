<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\DatabaseDsn;
use App\Subscription\SubscriptionRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The subscription table's SQL, on the database deployments actually run.
 *
 * Every other test of this repository opens SQLite, and that is exactly how
 * two SQLite-only expressions -- `datetime('now')` in updateFetchStatus() and
 * `datetime('now', '-' || refresh_interval || ' seconds')` in
 * findDueForRefresh() -- shipped: MySQL rejects both outright with a syntax
 * error, so every on-demand fetch raised a 500 after the calendar had already
 * been downloaded, and the refresh command never completed a single pass.
 * A dialect difference is invisible to any suite that only speaks one dialect,
 * so the statements are exercised against a real server here.
 *
 * Skipped unless DATABASE_URL names MySQL.
 */
final class SubscriptionRepositoryOnMySqlTest extends TestCase
{
    private \PDO $pdo;
    private string $login = '';

    #[\Override]
    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $target = DatabaseDsn::fromUrl($url);
        if ($target->driver !== 'mysql') {
            self::markTestSkipped('DATABASE_URL does not name MySQL; nothing to check the dialect against.');
        }

        try {
            $this->pdo = new \PDO($target->dsn, $target->user, $target->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped('cannot reach MySQL: ' . $e->getMessage());
        }

        $this->login = 'sub' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->login === '') {
            return;
        }

        $this->pdo->prepare('DELETE FROM calendar_subscriptions WHERE user_login = :login')
            ->execute(['login' => $this->login]);
    }

    private function repository(MockClock $clock): SubscriptionRepository
    {
        return new SubscriptionRepository($this->pdo, $clock);
    }

    public function testTheFetchStampIsWritableOnMySql(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('UTC')));
        $repo = $this->repository($clock);
        $sub = $repo->create($this->login, 'https://example.com/cal.ics', 'MySQL', '#123456', 3600);

        $repo->updateFetchStatus($sub->id(), '"v1"');

        $stored = $repo->findById($sub->id());
        self::assertNotNull($stored);
        self::assertSame('2026-04-01 12:00:00', $stored->lastFetched());
        self::assertSame('"v1"', $stored->etag());
    }

    public function testTheDueQueryRunsOnMySqlAndRespectsTheInterval(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('UTC')));
        $repo = $this->repository($clock);
        $sub = $repo->create($this->login, 'https://example.com/cal.ics', 'MySQL', '#123456', 3600);
        $repo->updateFetchStatus($sub->id(), '"v1"');

        $clock->modify('+59 minutes');
        self::assertSame([], $this->mine($repo->findDueForRefresh()));

        $clock->modify('+2 minutes');
        self::assertSame(['MySQL'], $this->mine($repo->findDueForRefresh()));
    }

    /**
     * The table is shared with whatever else the suite left behind, so the
     * assertions look only at the rows this test created.
     *
     * @param array<int, \App\Subscription\CalendarSubscription> $subs
     *
     * @return list<string>
     */
    private function mine(array $subs): array
    {
        $names = [];
        foreach ($subs as $sub) {
            if ($sub->userLogin() === $this->login) {
                $names[] = $sub->name();
            }
        }

        return $names;
    }
}
