<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Sidecar store for category emoji icons.
 *
 * Rather than modifying the vendor `webcal_categories` schema (which lives in
 * webcalendar-core and still carries the legacy cat_icon_mime/cat_icon_blob
 * columns), this repository stores emojis in a small api-layer table keyed
 * by the composite (cat_id, cat_owner) primary key used by the core schema.
 *
 * Global categories use an empty string owner (NOT NULL to stay compatible
 * with the core primary key semantics); personal categories use the login.
 *
 * See STATUS.md "Plan Change (2026-04-08): Category Emoji Icons".
 */
final class CategoryIconRepository
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS webcal_category_icons ('
            . 'cat_id INT NOT NULL, '
            . "cat_owner VARCHAR(60) NOT NULL DEFAULT '', "
            . 'cat_icon VARCHAR(16) NOT NULL, '
            . 'PRIMARY KEY (cat_id, cat_owner)'
            . ')'
        );
    }

    public function get(int $id, ?string $owner): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT cat_icon FROM webcal_category_icons '
            . 'WHERE cat_id = :id AND cat_owner = :owner'
        );
        $stmt->execute(['id' => $id, 'owner' => $owner ?? '']);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return (string) $value;
    }

    public function set(int $id, ?string $owner, ?string $icon): void
    {
        if ($icon === null || $icon === '') {
            $this->delete($id, $owner);
            return;
        }

        // Upsert: delete then insert — portable across MySQL/SQLite/PostgreSQL
        // without relying on vendor-specific ON CONFLICT / ON DUPLICATE clauses.
        $this->delete($id, $owner);
        $stmt = $this->pdo->prepare(
            'INSERT INTO webcal_category_icons (cat_id, cat_owner, cat_icon) '
            . 'VALUES (:id, :owner, :icon)'
        );
        $stmt->execute([
            'id' => $id,
            'owner' => $owner ?? '',
            'icon' => $icon,
        ]);
    }

    public function delete(int $id, ?string $owner): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM webcal_category_icons '
            . 'WHERE cat_id = :id AND cat_owner = :owner'
        );
        $stmt->execute(['id' => $id, 'owner' => $owner ?? '']);
    }

    /**
     * Batch lookup for a single owner scope.
     *
     * @param list<int> $ids
     * @return array<int,string> map of cat_id → icon (missing ids are omitted)
     */
    public function getBatchForOwner(array $ids, ?string $owner): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT cat_id, cat_icon FROM webcal_category_icons "
            . "WHERE cat_owner = ? AND cat_id IN ({$placeholders})"
        );
        $params = array_merge([$owner ?? ''], $ids);
        $stmt->execute($params);

        $out = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (\is_array($row) && isset($row['cat_id'], $row['cat_icon'])) {
                /** @var int|string $id */
                $id = $row['cat_id'];
                $out[(int) $id] = (string) $row['cat_icon'];
            }
        }
        return $out;
    }
}
