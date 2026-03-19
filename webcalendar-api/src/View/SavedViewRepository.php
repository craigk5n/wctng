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
            user_logins TEXT NOT NULL,
            is_global CHAR(1) NOT NULL DEFAULT 'N'
        )
    SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
        CREATE TABLE IF NOT EXISTS saved_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_login VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            user_logins TEXT NOT NULL DEFAULT '[]',
            is_global CHAR(1) NOT NULL DEFAULT 'N',
            category_ids TEXT NOT NULL DEFAULT '[]'
        )
    SQL;

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array{id: int, name: string, user_logins: list<string>, is_global: bool, owner: string, category_ids: list<int>}> */
    public function findByOwner(string $login): array
    {
        $this->ensureTable();
        // Return user's own views + all global views
        $stmt = $this->pdo->prepare(
            'SELECT * FROM saved_views WHERE owner_login = :login OR is_global = :global ORDER BY is_global DESC, name',
        );
        $stmt->execute(['login' => $login, 'global' => 'Y']);

        return $this->mapRows($stmt);
    }

    /** @return list<array{id: int, name: string, user_logins: list<string>, is_global: bool, owner: string, category_ids: list<int>}> */
    public function findGlobal(): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM saved_views WHERE is_global = :global ORDER BY name');
        $stmt->execute(['global' => 'Y']);

        return $this->mapRows($stmt);
    }

    /**
     * @param list<string> $userLogins
     * @param list<int>    $categoryIds
     */
    public function create(string $owner, string $name, array $userLogins, bool $isGlobal = false, array $categoryIds = []): int
    {
        $this->ensureTable();
        $this->pdo->prepare(
            'INSERT INTO saved_views (owner_login, name, user_logins, is_global, category_ids) VALUES (:owner, :name, :logins, :global, :cats)',
        )->execute([
            'owner' => $owner,
            'name' => $name,
            'logins' => json_encode($userLogins, \JSON_THROW_ON_ERROR),
            'global' => $isGlobal ? 'Y' : 'N',
            'cats' => json_encode($categoryIds, \JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id, string $owner): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM saved_views WHERE id = :id AND owner_login = :owner');
        $stmt->execute(['id' => $id, 'owner' => $owner]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array{id: int, name: string, user_logins: list<string>, is_global: bool, owner: string, category_ids: list<int>}>
     */
    private function mapRows(\PDOStatement $stmt): array
    {
        $views = [];
        /** @var array<string, string|int|null>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $views[] = [
                'id' => \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'name' => \is_string($row['name'] ?? null) ? $row['name'] : '',
                'user_logins' => $this->decodeLogins($row['user_logins'] ?? '[]'),
                'is_global' => ($row['is_global'] ?? 'N') === 'Y',
                'owner' => \is_string($row['owner_login'] ?? null) ? $row['owner_login'] : '',
                'category_ids' => $this->decodeCategoryIds($row['category_ids'] ?? '[]'),
            ];
            /** @var array<string, string|int|null>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        return $views;
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->pdo->exec($driver === 'sqlite' ? self::SCHEMA_SQL_SQLITE : self::SCHEMA_SQL);

        // Add is_global column if it doesn't exist (upgrade from older schema)
        try {
            $this->pdo->query('SELECT is_global FROM saved_views LIMIT 1');
        } catch (\PDOException) {
            try {
                $this->pdo->exec("ALTER TABLE saved_views ADD COLUMN is_global CHAR(1) DEFAULT 'N'");
            } catch (\PDOException) {
            }
        }

        // Add category_ids column if it doesn't exist
        try {
            $this->pdo->query('SELECT category_ids FROM saved_views LIMIT 1');
        } catch (\PDOException) {
            try {
                $this->pdo->exec("ALTER TABLE saved_views ADD COLUMN category_ids TEXT DEFAULT '[]'");
            } catch (\PDOException) {
            }
        }
    }

    /** @return list<string> */
    private function decodeLogins(string|int|null $value): array
    {
        if (!\is_string($value)) return [];
        /** @var mixed $decoded */
        $decoded = json_decode($value, true);
        return \is_array($decoded) ? array_values(array_filter($decoded, '\is_string')) : [];
    }

    /** @return list<int> */
    private function decodeCategoryIds(string|int|null $value): array
    {
        if (!\is_string($value)) return [];
        /** @var mixed $decoded */
        $decoded = json_decode($value, true);
        if (!\is_array($decoded)) return [];
        $result = [];
        foreach ($decoded as $v) {
            if (is_numeric($v)) {
                $result[] = (int) $v;
            }
        }
        return $result;
    }
}
