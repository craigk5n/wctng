<?php

declare(strict_types=1);

namespace App\Service;

use App\Webhook\WebhookDispatcherInterface;
use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;

/**
 * Admin event purge service.
 *
 * Deletes events older than a cutoff date, optionally scoped to a single user,
 * with dry-run + confirm-count guardrails. Cascades to all child tables tied
 * to the event id (participants, categories, repeats, ext users, reminders, blobs).
 *
 * Recurring series (events with a matching row in webcal_entry_repeats) are
 * skipped by default so long-running series are not silently truncated.
 * Pass includeRepeating=true to delete them along with their repeat rows.
 *
 * Date semantics: events are matched when `webcal_entry.cal_date` (stored as a
 * YYYYMMDD integer per webcalendar-core schema conventions) is strictly less
 * than the given beforeDate.
 */
final class PurgeService
{
    /**
     * Child tables that reference webcal_entry.cal_id via their own cal_id column.
     * Order matters: delete children before the parent row.
     *
     * @var list<string>
     */
    private const CASCADE_TABLES = [
        'webcal_entry_user',
        'webcal_entry_categories',
        'webcal_entry_repeats_not',
        'webcal_entry_repeats',
        'webcal_entry_ext_user',
        'webcal_reminders',
        'webcal_blob',
    ];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ?ActivityLogRepositoryInterface $activityLog = null,
        private readonly ?WebhookDispatcherInterface $webhookDispatcher = null,
        private readonly ?CalendarPublisherInterface $calendarPublisher = null,
        private readonly ?CalDavSyncTokenRepository $syncTokens = null,
    ) {
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private function findOwnersOfEvents(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT cal_create_by FROM webcal_entry WHERE cal_id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $logins = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (\is_array($row) && isset($row['cal_create_by']) && \is_string($row['cal_create_by'])) {
                $logins[] = $row['cal_create_by'];
            }
        }
        return $logins;
    }

    public function purge(
        \DateTimeImmutable $beforeDate,
        ?string $userLogin = null,
        bool $includeRepeating = false,
        bool $dryRun = true,
        ?int $confirmCount = null,
        ?string $actor = null,
    ): PurgeResult {
        $cutoff = (int) $beforeDate->format('Ymd');

        $targetIds = $this->findTargetEventIds($cutoff, $userLogin, $includeRepeating);

        // When include_repeating=true, split the target set into events that
        // should be DELETED (non-recurring or already-ended series) vs
        // TRUNCATED (active series with cal_end NULL or ≥ cutoff). Truncating
        // preserves historical occurrences by capping the series' UNTIL.
        $truncateIds = [];
        $deleteIds = $targetIds;
        if ($includeRepeating && $targetIds !== []) {
            [$deleteIds, $truncateIds] = $this->partitionForTruncation($targetIds, $cutoff);
        }

        $count = \count($deleteIds) + \count($truncateIds);

        if ($dryRun) {
            return new PurgeResult(count: $count, dryRun: true, beforeDate: $beforeDate, userLogin: $userLogin);
        }

        if ($confirmCount === null) {
            throw new \DomainException('confirm_count is required for non-dry-run purge');
        }

        if ($confirmCount !== $count) {
            throw new \DomainException(
                "confirm_count mismatch: expected {$count}, got {$confirmCount}. "
                . 'Run a dry run first and pass the exact count to proceed.'
            );
        }

        // Collect affected users BEFORE deletion so we can bump CalDAV
        // sync tokens after the fact (rows are gone by then).
        $affectedLogins = $count > 0
            ? $this->findOwnersOfEvents(array_merge($deleteIds, $truncateIds))
            : [];

        if ($deleteIds !== []) {
            $this->deleteEventIds($deleteIds);
        }
        if ($truncateIds !== []) {
            $untilYmd = (int) $beforeDate->modify('-1 day')->format('Ymd');
            $this->truncateSeries($truncateIds, $untilYmd);
        }

        // Bump each affected user's CalDAV sync token so clients (Apple
        // Calendar, Thunderbird, DAVx5) observe a forward-moving token
        // and reconcile rather than silently re-uploading the purged
        // events on their next push.
        if ($affectedLogins !== [] && $this->syncTokens !== null) {
            try {
                $this->syncTokens->bumpForUsers($affectedLogins);
            } catch (\Throwable) {
                // Sync-token bump is best-effort — never unwind a successful purge.
            }
        }

        $this->writeAuditLog($actor, $beforeDate, $userLogin, $includeRepeating, $count);

        // Fire a single bulk webhook for non-empty purges. Per-event delete
        // webhooks are not emitted because the raw SQL delete bypasses
        // EventService (no suppression plumbing required — it's by design).
        if ($count > 0) {
            $payload = [
                'count' => $count,
                'before_date' => $beforeDate->format('Y-m-d'),
                'user_login' => $userLogin,
                'include_repeating' => $includeRepeating,
                'actor' => $actor,
            ];

            if ($this->webhookDispatcher !== null) {
                try {
                    $this->webhookDispatcher->dispatch('events.purged', $payload);
                } catch (\Throwable) {
                    // Webhook dispatch is best-effort — never unwind a successful purge.
                }
            }

            if ($this->calendarPublisher !== null) {
                try {
                    $this->calendarPublisher->publishCalendarPurged($payload);
                } catch (\Throwable) {
                    // Mercure publish is best-effort — never unwind a successful purge.
                }
            }
        }

        return new PurgeResult(count: $count, dryRun: false, beforeDate: $beforeDate, userLogin: $userLogin);
    }

    private function writeAuditLog(
        ?string $actor,
        \DateTimeImmutable $beforeDate,
        ?string $userLogin,
        bool $includeRepeating,
        int $count,
    ): void {
        if ($this->activityLog === null || $actor === null || $actor === '') {
            return;
        }

        $text = sprintf(
            'admin event purge: count=%d before=%s user=%s include_repeating=%s',
            $count,
            $beforeDate->format('Y-m-d'),
            $userLogin ?? 'all',
            $includeRepeating ? 'yes' : 'no',
        );

        try {
            $this->activityLog->save(new ActivityLogEntry(
                id: 0,
                entryId: 0,
                login: $actor,
                userCal: $userLogin,
                type: ActivityLogType::EXTRA,
                date: new \DateTimeImmutable(),
                text: $text,
            ));
        } catch (\Throwable) {
            // Audit logging is best-effort — never block the purge on a log failure.
        }
    }

    /**
     * @return list<int>
     */
    private function findTargetEventIds(int $cutoff, ?string $userLogin, bool $includeRepeating): array
    {
        $sql = 'SELECT cal_id FROM webcal_entry WHERE cal_date < :cutoff';
        $params = ['cutoff' => $cutoff];

        if ($userLogin !== null) {
            $sql .= ' AND cal_create_by = :login';
            $params['login'] = $userLogin;
        }

        if (!$includeRepeating) {
            $sql .= ' AND cal_id NOT IN (SELECT cal_id FROM webcal_entry_repeats)';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<int> $ids */
        $ids = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (\is_array($row) && isset($row['cal_id']) && (\is_int($row['cal_id']) || \is_string($row['cal_id']))) {
                $ids[] = (int) $row['cal_id'];
            }
        }
        return $ids;
    }

    /**
     * Splits candidate event ids into those that should be fully deleted vs
     * those whose recurrence should be truncated with an UNTIL at the cutoff.
     *
     * Active series (no cal_end, or cal_end at or after the cutoff) go to the
     * truncate bucket. Non-recurring events and series whose cal_end is
     * already before the cutoff go to the delete bucket.
     *
     * @param list<int> $ids
     * @return array{0: list<int>, 1: list<int>} [deleteIds, truncateIds]
     */
    private function partitionForTruncation(array $ids, int $cutoffYmd): array
    {
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $sql = "SELECT cal_id, cal_end FROM webcal_entry_repeats "
            . "WHERE cal_id IN ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);

        /** @var array<int,?int> $repeatEnds */
        $repeatEnds = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (!\is_array($row) || !isset($row['cal_id'])) {
                continue;
            }
            $rawId = $row['cal_id'];
            if (!\is_int($rawId) && !\is_string($rawId)) {
                continue;
            }
            $rid = (int) $rawId;
            $end = $row['cal_end'] ?? null;
            if ($end === null || $end === '') {
                $repeatEnds[$rid] = null;
            } elseif (\is_int($end) || \is_string($end)) {
                $repeatEnds[$rid] = (int) $end;
            } else {
                $repeatEnds[$rid] = null;
            }
        }

        $deleteIds = [];
        $truncateIds = [];
        foreach ($ids as $id) {
            if (!\array_key_exists($id, $repeatEnds)) {
                // No repeats row — plain event, delete normally.
                $deleteIds[] = $id;
                continue;
            }
            $end = $repeatEnds[$id];
            if ($end === null || $end >= $cutoffYmd) {
                // Active series → truncate
                $truncateIds[] = $id;
            } else {
                // Series already ended before cutoff → delete
                $deleteIds[] = $id;
            }
        }

        return [$deleteIds, $truncateIds];
    }

    /**
     * @param list<int> $ids
     */
    private function truncateSeries(array $ids, int $untilYmd): void
    {
        $chunks = array_chunk($ids, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));
            $stmt = $this->pdo->prepare(
                "UPDATE webcal_entry_repeats SET cal_end = ? WHERE cal_id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$untilYmd], $chunk));
        }
    }

    /**
     * @param list<int> $ids
     */
    private function deleteEventIds(array $ids): void
    {
        // Chunk to avoid giant IN clauses on large purges.
        $chunks = array_chunk($ids, 500);

        $this->pdo->beginTransaction();
        try {
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, \count($chunk), '?'));

                foreach (self::CASCADE_TABLES as $table) {
                    try {
                        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE cal_id IN ({$placeholders})");
                        $stmt->execute($chunk);
                    } catch (\PDOException $e) {
                        // Table may not exist in all deployments (e.g. event_comments
                        // is added by a later migration). Skip missing tables.
                        if (!$this->isMissingTableError($e)) {
                            throw $e;
                        }
                    }
                }

                $stmt = $this->pdo->prepare("DELETE FROM webcal_entry WHERE cal_id IN ({$placeholders})");
                $stmt->execute($chunk);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isMissingTableError(\PDOException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'no such table')      // SQLite
            || str_contains($msg, "doesn't exist")      // MySQL
            || str_contains($msg, 'does not exist');    // PostgreSQL
    }
}
