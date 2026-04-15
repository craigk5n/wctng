<?php

declare(strict_types=1);

namespace App\Share;

final readonly class ShareTokenRepository
{
    public const SCHEMA_SQL = <<<'SQL'
            CREATE TABLE IF NOT EXISTS share_tokens (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                token VARCHAR(36) NOT NULL UNIQUE,
                owner_login VARCHAR(60) NOT NULL,
                expires_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
            CREATE TABLE IF NOT EXISTS share_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token VARCHAR(36) NOT NULL UNIQUE,
                owner_login VARCHAR(60) NOT NULL,
                expires_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL;

    public function __construct(
        private \PDO $pdo,
    ) {}

    /**
     * @return ShareToken[]
     */
    public function findByOwner(string $login): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM share_tokens WHERE owner_login = :login ORDER BY created_at DESC');
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

    public function findByToken(#[\SensitiveParameter] string $token): ?ShareToken
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM share_tokens WHERE token = :token');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->mapRow($row);
    }

    public function create(#[\SensitiveParameter] string $token, string $ownerLogin, ?string $expiresAt): ShareToken
    {
        $this->ensureTable();

        $this->pdo->prepare(
            'INSERT INTO share_tokens (token, owner_login, expires_at) VALUES (:token, :owner, :expires)',
        )->execute([
            'token' => $token,
            'owner' => $ownerLogin,
            'expires' => $expiresAt,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $created = $this->findByToken($token);
        assert($created !== null);
        return $created;
    }

    public function delete(#[\SensitiveParameter] string $token, string $ownerLogin): bool
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('DELETE FROM share_tokens WHERE token = :token AND owner_login = :login');
        $stmt->execute(['token' => $token, 'login' => $ownerLogin]);
        return $stmt->rowCount() > 0;
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
    private function mapRow(array $row): ShareToken
    {
        return new ShareToken(
            id: \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            token: \is_string($row['token'] ?? null) ? $row['token'] : '',
            ownerLogin: \is_string($row['owner_login'] ?? null) ? $row['owner_login'] : '',
            expiresAt: \is_string($row['expires_at'] ?? null) ? $row['expires_at'] : null,
            createdAt: \is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
        );
    }
}
