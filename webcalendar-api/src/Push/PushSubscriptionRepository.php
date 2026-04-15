<?php

declare(strict_types=1);

namespace App\Push;

final readonly class PushSubscriptionRepository
{
    public const SCHEMA_SQL = <<<'SQL'
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                user_login VARCHAR(60) NOT NULL,
                endpoint VARCHAR(500) NOT NULL UNIQUE,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_login VARCHAR(60) NOT NULL,
                endpoint VARCHAR(500) NOT NULL UNIQUE,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL;

    public function __construct(private \PDO $pdo) {}

    public function subscribe(string $login, string $endpoint, string $p256dh, string $auth): void
    {
        $this->ensureTable();

        // Upsert: update if endpoint exists, insert if not
        $stmt = $this->pdo->prepare('SELECT id FROM push_subscriptions WHERE endpoint = :endpoint');
        $stmt->execute(['endpoint' => $endpoint]);
        /** @var array<string, mixed>|false $existing */
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (\is_array($existing)) {
            $this->pdo->prepare(
                'UPDATE push_subscriptions SET user_login = :login, p256dh = :p256dh, auth = :auth WHERE endpoint = :endpoint'
            )->execute(['login' => $login, 'p256dh' => $p256dh, 'auth' => $auth, 'endpoint' => $endpoint]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO push_subscriptions (user_login, endpoint, p256dh, auth) VALUES (:login, :endpoint, :p256dh, :auth)'
            )->execute(['login' => $login, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth]);
        }
    }

    public function unsubscribe(string $endpoint): void
    {
        $this->ensureTable();
        $this->pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = :endpoint')
            ->execute(['endpoint' => $endpoint]);
    }

    /**
     * @return list<array{endpoint: string, p256dh: string, auth: string}>
     */
    public function getSubscriptionsForUser(string $login): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_login = :login');
        $stmt->execute(['login' => $login]);

        /** @var list<array{endpoint: string, p256dh: string, auth: string}> $subs */
        $subs = [];
        /** @var array<string, string>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $subs[] = [
                'endpoint' => $row['endpoint'] ?? '',
                'p256dh' => $row['p256dh'] ?? '',
                'auth' => $row['auth'] ?? '',
            ];
            /** @var array<string, string>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        return $subs;
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite' ? self::SCHEMA_SQL_SQLITE : self::SCHEMA_SQL;
        $this->pdo->exec($sql);
    }
}
