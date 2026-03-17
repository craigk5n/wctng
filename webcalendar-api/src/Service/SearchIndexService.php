<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Full-text search service for calendar entries.
 *
 * Uses SQL LIKE for SQLite compatibility, FULLTEXT for MySQL when available.
 */
final readonly class SearchIndexService
{
    private const TYPE_MAP = [
        'event' => ['E', 'M'],
        'task' => ['T', 'N'],
        'journal' => ['J', 'O'],
    ];

    public function __construct(
        private CoreServiceFactory $coreServiceFactory,
    ) {
    }

    /**
     * Searches calendar entries by keyword with type filtering and pagination.
     *
     * @return array{results: list<array<string, mixed>>, total: int}
     */
    /**
     * @param array<string, string> $filters Optional filters: start, end, category_id, participant
     *
     * @return array{results: list<array<string, mixed>>, total: int}
     */
    public function search(
        string $query,
        string $userLogin,
        ?string $type = null,
        int $limit = 20,
        int $offset = 0,
        array $filters = [],
    ): array {
        $pdo = $this->coreServiceFactory->getPdo();

        $params = [];
        $joins = '';
        $where = ['(e.cal_name LIKE :q1 OR e.cal_description LIKE :q2)'];
        $params['q1'] = '%' . $query . '%';
        $params['q2'] = '%' . $query . '%';

        // User filter
        $where[] = "e.cal_create_by = :user";
        $params['user'] = $userLogin;

        // Type filter
        if ($type !== null && isset(self::TYPE_MAP[$type])) {
            $types = self::TYPE_MAP[$type];
            $typePlaceholders = [];
            foreach ($types as $i => $t) {
                $key = 'type_' . $i;
                $typePlaceholders[] = ':' . $key;
                $params[$key] = $t;
            }
            $where[] = 'e.cal_type IN (' . implode(', ', $typePlaceholders) . ')';
        }

        // Date range filter
        if (isset($filters['start']) && $filters['start'] !== '') {
            $where[] = 'e.cal_date >= :start_date';
            $params['start_date'] = $filters['start'];
        }
        if (isset($filters['end']) && $filters['end'] !== '') {
            $where[] = 'e.cal_date <= :end_date';
            $params['end_date'] = $filters['end'];
        }

        // Category filter
        if (isset($filters['category_id']) && $filters['category_id'] !== '') {
            $joins .= ' INNER JOIN webcal_entry_categories ec ON ec.cal_id = e.cal_id';
            $where[] = 'ec.cat_id = :cat_id';
            $params['cat_id'] = $filters['category_id'];
        }

        // Participant filter
        if (isset($filters['participant']) && $filters['participant'] !== '') {
            $joins .= ' INNER JOIN webcal_entry_user eu ON eu.cal_id = e.cal_id';
            $where[] = 'eu.cal_login = :participant';
            $params['participant'] = $filters['participant'];
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countSql = "SELECT COUNT(*) FROM webcal_entry e{$joins} WHERE {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch results
        $limitInt = (int) $limit;
        $offsetInt = (int) $offset;
        $sql = "SELECT e.cal_id, e.cal_name, e.cal_description, e.cal_date, e.cal_time,
                       e.cal_type, e.cal_create_by, e.cal_duration
                FROM webcal_entry e{$joins}
                WHERE {$whereClause}
                ORDER BY e.cal_date DESC
                LIMIT {$limitInt} OFFSET {$offsetInt}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $results = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            /** @var string $name */
            $name = $row['cal_name'] ?? '';
            /** @var string $desc */
            $desc = $row['cal_description'] ?? '';

            $results[] = [
                'id' => \is_numeric($row['cal_id'] ?? null) ? (int) $row['cal_id'] : 0,
                'title' => $name,
                'description' => $desc,
                'start_date' => \is_string($row['cal_date'] ?? null) ? $row['cal_date'] : '',
                'type' => \is_string($row['cal_type'] ?? null) ? $row['cal_type'] : 'E',
                'created_by' => \is_string($row['cal_create_by'] ?? null) ? $row['cal_create_by'] : '',
                'snippet' => $this->generateSnippet($name, $desc, $query),
            ];

            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return ['results' => $results, 'total' => $total];
    }

    /**
     * Returns top N suggestions matching a prefix, searching titles and locations.
     *
     * @return list<array{id: int, title: string, start_date: string, type: string}>
     */
    public function suggest(string $prefix, string $userLogin, int $limit = 5): array
    {
        $pdo = $this->coreServiceFactory->getPdo();

        $limitInt = (int) $limit;
        $sql = "SELECT e.cal_id, e.cal_name, e.cal_date, e.cal_type
                FROM webcal_entry e
                WHERE (e.cal_name LIKE :q1 OR e.cal_description LIKE :q2)
                AND e.cal_create_by = :user
                ORDER BY e.cal_date DESC
                LIMIT {$limitInt}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'q1' => $prefix . '%',
            'q2' => $prefix . '%',
            'user' => $userLogin,
        ]);

        $results = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $results[] = [
                'id' => \is_numeric($row['cal_id'] ?? null) ? (int) $row['cal_id'] : 0,
                'title' => \is_string($row['cal_name'] ?? null) ? $row['cal_name'] : '',
                'start_date' => \is_string($row['cal_date'] ?? null) ? (string) $row['cal_date'] : '',
                'type' => \is_string($row['cal_type'] ?? null) ? $row['cal_type'] : 'E',
            ];
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $results;
    }

    /**
     * Creates a FULLTEXT index on webcal_entry for MySQL.
     * No-op for SQLite (uses LIKE instead).
     */
    public function ensureIndex(): void
    {
        $pdo = $this->coreServiceFactory->getPdo();

        try {
            $pdo->exec(
                'CREATE FULLTEXT INDEX IF NOT EXISTS idx_entry_fulltext ON webcal_entry (cal_name, cal_description)',
            );
        } catch (\PDOException) {
            // SQLite doesn't support FULLTEXT — graceful fallback
        }
    }

    private function generateSnippet(string $title, string $description, string $query): string
    {
        $text = $description !== '' ? $description : $title;
        $queryLower = mb_strtolower($query);
        $textLower = mb_strtolower($text);

        $pos = mb_strpos($textLower, $queryLower);
        if ($pos === false) {
            return mb_substr($text, 0, 100);
        }

        $snippetStart = max(0, $pos - 30);
        $snippet = mb_substr($text, $snippetStart, 80);

        if ($snippetStart > 0) {
            $snippet = '...' . $snippet;
        }
        if (mb_strlen($text) > $snippetStart + 80) {
            $snippet .= '...';
        }

        // Highlight match
        $highlighted = preg_replace(
            '/(' . preg_quote($query, '/') . ')/i',
            '<mark>$1</mark>',
            $snippet,
        );

        return $highlighted ?? $snippet;
    }
}
