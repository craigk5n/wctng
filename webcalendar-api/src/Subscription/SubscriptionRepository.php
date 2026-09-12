<?php

declare(strict_types=1);

namespace App\Subscription;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final readonly class SubscriptionRepository
{
    public const SCHEMA_SQL = <<<'SQL'
            CREATE TABLE IF NOT EXISTS calendar_subscriptions (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                user_login VARCHAR(60) NOT NULL,
                url VARCHAR(500) NOT NULL,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(16) NOT NULL DEFAULT '#3788d8',
                refresh_interval INTEGER NOT NULL DEFAULT 3600,
                last_fetched DATETIME DEFAULT NULL,
                etag VARCHAR(255) DEFAULT NULL
            )
        SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
            CREATE TABLE IF NOT EXISTS calendar_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_login VARCHAR(60) NOT NULL,
                url VARCHAR(500) NOT NULL,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(16) NOT NULL DEFAULT '#3788d8',
                refresh_interval INTEGER NOT NULL DEFAULT 3600,
                last_fetched DATETIME DEFAULT NULL,
                etag VARCHAR(255) DEFAULT NULL
            )
        SQL;

    public function __construct(
        private \PDO $pdo,
        private ClockInterface $clock = new NativeClock(),
    ) {}

    /** @return CalendarSubscription[] */
    public function findByUser(string $login): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_subscriptions WHERE user_login = :login ORDER BY name');
        $stmt->execute(['login' => $login]);

        $items = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $items[] = $this->mapRow($row);
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        return $items;
    }

    public function findById(int $id): ?CalendarSubscription
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return \is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * The interval arithmetic is done here rather than in SQL because the
     * expression that did it was SQLite's -- `datetime('now', '-' || ... )`
     * -- and MySQL, which every deployment actually runs, rejects it outright.
     * There is no one expression both accept, and the table holds one row per
     * subscribed calendar, so the comparison is cheap in PHP.
     *
     * @return CalendarSubscription[]
     */
    public function findDueForRefresh(): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->query('SELECT * FROM calendar_subscriptions');
        if ($stmt === false) {
            return [];
        }

        $now = $this->clock->now()->getTimestamp();

        $items = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $sub = $this->mapRow($row);
            if (self::isDue($sub, $now)) {
                $items[] = $sub;
            }
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        return $items;
    }

    private static function isDue(CalendarSubscription $sub, int $now): bool
    {
        $lastFetched = $sub->lastFetched();

        if ($lastFetched === null) {
            return true;
        }

        $fetchedAt = strtotime($lastFetched . ' UTC');

        // An unparseable stamp is not evidence the feed is fresh. Infection
        // reports removing this return as a surviving mutant; falling through
        // compares false against the interval, which PHP reads as 0, and any
        // clock past 1970 answers "due" either way. Kept explicit because the
        // agreement is a coincidence of type juggling, not a decision.
        if ($fetchedAt === false) {
            return true;
        }

        return $fetchedAt + $sub->refreshInterval() <= $now;
    }

    public function create(string $userLogin, string $url, string $name, string $color, int $refreshInterval = 3600): CalendarSubscription
    {
        $this->ensureTable();
        $this->pdo->prepare(
            'INSERT INTO calendar_subscriptions (user_login, url, name, color, refresh_interval) VALUES (:login, :url, :name, :color, :interval)'
        )->execute([
            'login' => $userLogin,
            'url' => $url,
            'name' => $name,
            'color' => $color,
            'interval' => $refreshInterval,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $found = $this->findById($id);
        assert($found !== null);
        return $found;
    }

    public function updateFetchStatus(int $id, ?string $etag): void
    {
        $this->pdo->prepare(
            'UPDATE calendar_subscriptions SET last_fetched = :fetched, etag = :etag WHERE id = :id'
        )->execute([
            'id' => $id,
            'etag' => $etag,
            // datetime('now') here was SQLite-only and threw on MySQL, which
            // took the whole fetch down after the feed had already been read.
            // UTC, matching what that expression used to store.
            'fetched' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id, string $userLogin): bool
    {
        // Every other entry point creates the table first; without it here a
        // delete on an install where nobody has listed a subscription yet
        // throws instead of reporting the row missing.
        $this->ensureTable();
        $stmt = $this->pdo->prepare('DELETE FROM calendar_subscriptions WHERE id = :id AND user_login = :login');
        $stmt->execute(['id' => $id, 'login' => $userLogin]);
        return $stmt->rowCount() > 0;
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite' ? self::SCHEMA_SQL_SQLITE : self::SCHEMA_SQL;
        $this->pdo->exec($sql);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): CalendarSubscription
    {
        return new CalendarSubscription(
            id: \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            userLogin: \is_string($row['user_login'] ?? null) ? $row['user_login'] : '',
            url: \is_string($row['url'] ?? null) ? $row['url'] : '',
            name: \is_string($row['name'] ?? null) ? $row['name'] : '',
            color: \is_string($row['color'] ?? null) ? $row['color'] : '#3788d8',
            refreshInterval: \is_numeric($row['refresh_interval'] ?? null) ? (int) $row['refresh_interval'] : 3600,
            lastFetched: \is_string($row['last_fetched'] ?? null) ? $row['last_fetched'] : null,
            etag: \is_string($row['etag'] ?? null) ? $row['etag'] : null,
        );
    }
}
