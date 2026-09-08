<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class SavedViewRepository
{
    public const SCHEMA_SQL = <<<'SQL'
            CREATE TABLE IF NOT EXISTS saved_views (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                owner_login VARCHAR(60) NOT NULL,
                name VARCHAR(100) NOT NULL,
                user_logins TEXT NOT NULL,
                is_global CHAR(1) NOT NULL DEFAULT 'N',
                -- No DEFAULT: MySQL rejects one on TEXT (error 1101). Reads
                -- coalesce a missing value to '[]'.
                category_ids TEXT
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

    public function __construct(
        private \PDO $pdo,
        // Defaulted so the container autowires the real logger while tests that
        // build this by hand keep working.
        private LoggerInterface $logger = new NullLogger(),
    ) {}

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

    /**
     * @param list<string> $userLogins
     * @param list<int>    $categoryIds
     */
    public function update(int $id, string $owner, string $name, array $userLogins, bool $isGlobal = false, array $categoryIds = []): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE saved_views SET name = :name, user_logins = :logins, is_global = :global, category_ids = :cats WHERE id = :id AND owner_login = :owner',
        );
        $stmt->execute([
            'id' => $id,
            'owner' => $owner,
            'name' => $name,
            'logins' => json_encode($userLogins, \JSON_THROW_ON_ERROR),
            'global' => $isGlobal ? 'Y' : 'N',
            'cats' => json_encode($categoryIds, \JSON_THROW_ON_ERROR),
        ]);
        return $stmt->rowCount() > 0;
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

        // Add is_global column if it doesn't exist (upgrade from older schema).
        // The probe throwing is the normal "column absent" signal, not a fault.
        try {
            $this->pdo->query('SELECT is_global FROM saved_views LIMIT 1');
        } catch (\PDOException) {
            try {
                $this->pdo->exec("ALTER TABLE saved_views ADD COLUMN is_global CHAR(1) DEFAULT 'N'");
            } catch (\PDOException $e) {
                $this->logger->warning('saved_views: could not add is_global column', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // Add category_ids column if it doesn't exist
        try {
            $this->pdo->query('SELECT category_ids FROM saved_views LIMIT 1');
        } catch (\PDOException) {
            try {
                // Same restriction as above: no DEFAULT on a TEXT column.
                // With one, MySQL raised 1101 and the empty catch below hid it,
                // leaving every saved-view write failing on "Unknown column".
                $this->pdo->exec('ALTER TABLE saved_views ADD COLUMN category_ids TEXT');
            } catch (\PDOException $e) {
                // Swallowing this is what hid MySQL error 1101 and left every
                // saved-view write failing on "Unknown column 'category_ids'".
                $this->logger->warning('saved_views: could not add category_ids column', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return list<string> */
    private function decodeLogins(string|int|null $value): array
    {
        if (!\is_string($value)) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($value, true);
        return \is_array($decoded) ? array_values(array_filter($decoded, '\is_string')) : [];
    }

    /** @return list<int> */
    private function decodeCategoryIds(string|int|null $value): array
    {
        if (!\is_string($value)) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($value, true);
        if (!\is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $v) {
            if (is_numeric($v)) {
                $result[] = (int) $v;
            }
        }
        return $result;
    }
}
