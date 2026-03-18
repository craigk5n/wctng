<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Simple PDO query counter for performance auditing.
 * Wraps a PDO instance and counts prepare/exec/query calls.
 */
final class QueryLogger
{
    private int $queryCount = 0;
    private float $totalTime = 0.0;

    /** @var list<array{sql: string, time_ms: float}> */
    private array $queries = [];

    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    public function getTotalTimeMs(): float
    {
        return round($this->totalTime, 2);
    }

    /**
     * @return list<array{sql: string, time_ms: float}>
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Execute a query and track it.
     *
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $start = microtime(true);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $results */
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $elapsed = (microtime(true) - $start) * 1000;
        $this->queryCount++;
        $this->totalTime += $elapsed;
        $this->queries[] = ['sql' => $sql, 'time_ms' => round($elapsed, 2)];

        return $results;
    }

    public function reset(): void
    {
        $this->queryCount = 0;
        $this->totalTime = 0.0;
        $this->queries = [];
    }
}
