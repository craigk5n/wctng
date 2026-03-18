<?php

declare(strict_types=1);

namespace App\View;

final readonly class SavedViewRepository
{
    public const SCHEMA_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS saved_views (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            owner_login VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            user_logins TEXT NOT NULL DEFAULT '[]'
        )
    SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
        CREATE TABLE IF NOT EXISTS saved_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_login VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            user_logins TEXT NOT NULL DEFAULT '[]'
        )
    SQL;

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array{id: int, name: string, user_logins: list<string>}> */
    public function findByOwner(string $login): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM saved_views WHERE owner_login = :login ORDER BY name');
        $stmt->execute(['login' => $login]);

        /** @var list<array{id: int, name: string, user_logins: list<string>}> $views */
        $views = [];
        /** @var array<string, string|int|null>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $views[] = [
                'id' => \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'name' => \is_string($row['name'] ?? null) ? $row['name'] : '',
                'user_logins' => $this->decodeLogins($row['user_logins'] ?? '[]'),
            ];
            /** @var array<string, string|int|null>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        return $views;
    }

    /** @param list<string> $userLogins */
    public function create(string $owner, string $name, array $userLogins): int
    {
        $this->ensureTable();
        $this->pdo->prepare(
            'INSERT INTO saved_views (owner_login, name, user_logins) VALUES (:owner, :name, :logins)'
        )->execute([
            'owner' => $owner,
            'name' => $name,
            'logins' => json_encode($userLogins, \JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id, string $owner): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM saved_views WHERE id = :id AND owner_login = :owner');
        $stmt->execute(['id' => $id, 'owner' => $owner]);
        return $stmt->rowCount() > 0;
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->pdo->exec($driver === 'sqlite' ? self::SCHEMA_SQL_SQLITE : self::SCHEMA_SQL);
    }

    /** @return list<string> */
    private function decodeLogins(string|int|null $value): array
    {
        if (!\is_string($value)) return [];
        /** @var mixed $decoded */
        $decoded = json_decode($value, true);
        return \is_array($decoded) ? array_values(array_filter($decoded, '\is_string')) : [];
    }
}
