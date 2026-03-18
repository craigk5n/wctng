<?php

declare(strict_types=1);

namespace App\Subscription;

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
    ) {
    }

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

    /** @return CalendarSubscription[] */
    public function findDueForRefresh(): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->query(
            "SELECT * FROM calendar_subscriptions WHERE last_fetched IS NULL OR last_fetched < datetime('now', '-' || refresh_interval || ' seconds')"
        );
        if ($stmt === false) return [];

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
            "UPDATE calendar_subscriptions SET last_fetched = datetime('now'), etag = :etag WHERE id = :id"
        )->execute(['id' => $id, 'etag' => $etag]);
    }

    public function delete(int $id, string $userLogin): bool
    {
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
