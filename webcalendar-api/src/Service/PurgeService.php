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
    ) {
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
        $count = \count($targetIds);

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

        if ($count > 0) {
            $this->deleteEventIds($targetIds);
        }

        $this->writeAuditLog($actor, $beforeDate, $userLogin, $includeRepeating, $count);

        // Fire a single bulk webhook for non-empty purges. Per-event delete
        // webhooks are not emitted because the raw SQL delete bypasses
        // EventService (no suppression plumbing required — it's by design).
        if ($count > 0 && $this->webhookDispatcher !== null) {
            try {
                $this->webhookDispatcher->dispatch('events.purged', [
                    'count' => $count,
                    'before_date' => $beforeDate->format('Y-m-d'),
                    'user_login' => $userLogin,
                    'include_repeating' => $includeRepeating,
                    'actor' => $actor,
                ]);
            } catch (\Throwable) {
                // Webhook dispatch is best-effort — never unwind a successful purge.
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
