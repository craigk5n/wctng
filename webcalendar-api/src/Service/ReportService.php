<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generates calendar reports and analytics from event data.
 */
final readonly class ReportService
{
    public function __construct(
        private CoreServiceFactory $coreServiceFactory,
    ) {
    }

    /**
     * Event count grouped by date.
     *
     * @return list<array{date: string, count: int}>
     */
    public function activityReport(string $userLogin, string $start, string $end): array
    {
        $pdo = $this->coreServiceFactory->getPdo();
        $stmt = $pdo->prepare(
            'SELECT CAST(cal_date AS CHAR) AS date, COUNT(*) AS cnt FROM webcal_entry
             WHERE cal_create_by = :user AND cal_date >= :s AND cal_date <= :e
             GROUP BY cal_date ORDER BY cal_date',
        );
        $stmt->execute(['user' => $userLogin, 's' => $start, 'e' => $end]);

        $results = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $results[] = [
                'date' => \is_string($row['date'] ?? null) ? (string) $row['date'] : '',
                'count' => \is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0,
            ];
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $results;
    }

    /**
     * Event count by hour of day (0-23) across all days of week.
     *
     * @return list<array{hour: int, count: int}>
     */
    public function busyHoursReport(string $userLogin, string $start, string $end): array
    {
        $pdo = $this->coreServiceFactory->getPdo();

        // cal_time is stored as HHMMSS integer (e.g., 140000 = 14:00:00)
        $stmt = $pdo->prepare(
            "SELECT (cal_time / 10000) AS hour, COUNT(*) AS cnt FROM webcal_entry
             WHERE cal_create_by = :user AND cal_date >= :s AND cal_date <= :e
             AND cal_time > 0
             GROUP BY (cal_time / 10000) ORDER BY hour",
        );
        $stmt->execute(['user' => $userLogin, 's' => $start, 'e' => $end]);

        $results = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $results[] = [
                'hour' => \is_numeric($row['hour'] ?? null) ? (int) $row['hour'] : 0,
                'count' => \is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0,
            ];
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $results;
    }

    /**
     * Event count by category.
     *
     * @return list<array{category_id: int, category_name: string, count: int}>
     */
    public function categoriesReport(string $userLogin, string $start, string $end): array
    {
        $pdo = $this->coreServiceFactory->getPdo();

        $stmt = $pdo->prepare(
            "SELECT c.cat_id, c.cat_name, COUNT(*) AS cnt
             FROM webcal_entry e
             INNER JOIN webcal_entry_categories ec ON ec.cal_id = e.cal_id
             INNER JOIN webcal_categories c ON c.cat_id = ec.cat_id
             WHERE e.cal_create_by = :user AND e.cal_date >= :s AND e.cal_date <= :e
             GROUP BY c.cat_id, c.cat_name ORDER BY cnt DESC",
        );
        $stmt->execute(['user' => $userLogin, 's' => $start, 'e' => $end]);

        $results = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $results[] = [
                'category_id' => \is_numeric($row['cat_id'] ?? null) ? (int) $row['cat_id'] : 0,
                'category_name' => \is_string($row['cat_name'] ?? null) ? $row['cat_name'] : '',
                'count' => \is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0,
            ];
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $results;
    }

    /**
     * Upcoming events in the next N days.
     *
     * @return list<array{id: int, title: string, start_date: string, type: string}>
     */
    public function upcomingReport(string $userLogin, int $days = 7): array
    {
        $pdo = $this->coreServiceFactory->getPdo();
        $today = (int) date('Ymd');
        $endDate = (int) date('Ymd', strtotime("+{$days} days") ?: null);

        $stmt = $pdo->prepare(
            "SELECT cal_id, cal_name, cal_date, cal_type FROM webcal_entry
             WHERE cal_create_by = :user AND cal_date >= :today AND cal_date <= :end
             ORDER BY cal_date, cal_time LIMIT 50",
        );
        $stmt->execute(['user' => $userLogin, 'today' => $today, 'end' => $endDate]);

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
}
