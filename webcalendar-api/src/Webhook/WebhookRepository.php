<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Repository for webhook subscriptions.
 */
final readonly class WebhookRepository
{
    public const SCHEMA_SQL = <<<'SQL'
            CREATE TABLE IF NOT EXISTS webhooks (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                url VARCHAR(500) NOT NULL,
                events VARCHAR(500) NOT NULL DEFAULT '*',
                secret VARCHAR(255) NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
            CREATE TABLE IF NOT EXISTS webhooks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url VARCHAR(500) NOT NULL,
                events VARCHAR(500) NOT NULL DEFAULT '*',
                secret VARCHAR(255) NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL;

    public function __construct(
        private \PDO $pdo,
    ) {}

    /**
     * @return WebhookSubscription[]
     */
    public function findAll(): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->query('SELECT * FROM webhooks ORDER BY id');
        if ($stmt === false) {
            return [];
        }

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

    /**
     * @return WebhookSubscription[]
     */
    public function findEnabled(): array
    {
        return array_filter($this->findAll(), static fn(WebhookSubscription $w): bool => $w->isEnabled());
    }

    public function findById(int $id): ?WebhookSubscription
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM webhooks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->mapRow($row);
    }

    public function save(WebhookSubscription $webhook): int
    {
        $this->ensureTable();

        if ($webhook->id() > 0) {
            $this->pdo->prepare(
                'UPDATE webhooks SET url=:url, events=:events, secret=:secret, enabled=:enabled WHERE id=:id',
            )->execute([
                'id' => $webhook->id(),
                'url' => $webhook->url(),
                'events' => $webhook->events(),
                'secret' => $webhook->secret(),
                'enabled' => $webhook->isEnabled() ? 1 : 0,
            ]);

            return $webhook->id();
        }

        $this->pdo->prepare(
            'INSERT INTO webhooks (url, events, secret, enabled) VALUES (:url, :events, :secret, :enabled)',
        )->execute([
            'url' => $webhook->url(),
            'events' => $webhook->events(),
            'secret' => $webhook->secret(),
            'enabled' => $webhook->isEnabled() ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM webhooks WHERE id = :id')->execute(['id' => $id]);
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite' ? self::SCHEMA_SQL_SQLITE : self::SCHEMA_SQL;
        $this->pdo->exec($sql);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): WebhookSubscription
    {
        return new WebhookSubscription(
            id: \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            url: \is_string($row['url'] ?? null) ? $row['url'] : '',
            events: \is_string($row['events'] ?? null) ? $row['events'] : '*',
            secret: \is_string($row['secret'] ?? null) ? $row['secret'] : '',
            enabled: (\is_numeric($row['enabled'] ?? null) ? (int) $row['enabled'] : 1) === 1,
        );
    }
}
