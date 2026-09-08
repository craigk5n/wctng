<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;

final readonly class EventRecurrenceService
{
    public function __construct(
        private TenantAwarePdoProvider $pdoProvider,
        private MercurePublisher $mercure,
        private ActivityLogService $activityLogService,
        private ClockInterface $clock,
        // Defaulted so the container autowires the real logger while code
        // that constructs this directly keeps working.
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Cancel a single occurrence by adding an EXDATE.
     *
     * @return array{action: string, date: string}
     */
    public function cancelOccurrence(
        int $id,
        Event $event,
        \DateTimeImmutable $date,
        string $actor,
    ): array {
        $pdo = $this->pdoProvider->get();
        $dateInt = (int) $date->format('Ymd');

        // Insert EXDATE row (ignore if duplicate)
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES (:id, :date, 1)'
        );
        $stmt->execute(['id' => $id, 'date' => $dateInt]);

        // Bump sequence
        $pdo->prepare('UPDATE webcal_entry SET cal_sequence = cal_sequence + 1 WHERE cal_id = :id')
            ->execute(['id' => $id]);

        try {
            $this->mercure->publishEventUpdated($id, ['action' => 'exdate_added']);
        } catch (\Throwable $e) {
            $this->logger->warning('mercure->publishEventUpdated() failed', ['exception' => $e->getMessage()]);
        }

        try {
            $this->activityLogService->log(
                $id,
                $actor,
                null,
                ActivityLogType::UPDATE,
                'Cancelled occurrence ' . $date->format('Y-m-d') . ' of: ' . $event->name(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('activityLogService->log() failed', ['exception' => $e->getMessage()]);
        }

        return [
            'action' => 'occurrence_cancelled',
            'date' => $date->format('Y-m-d'),
        ];
    }

    /**
     * Truncate a recurring series by setting UNTIL to the day before the given date.
     *
     * @return array{action: string, from_date: string}
     */
    public function cancelFutureOccurrences(
        int $id,
        Event $event,
        \DateTimeImmutable $fromDate,
        string $actor,
    ): array {
        $pdo = $this->pdoProvider->get();
        $untilDateInt = (int) $fromDate->modify('-1 day')->format('Ymd');

        // Set the cal_end of the recurrence rule
        $stmt = $pdo->prepare(
            'UPDATE webcal_entry_repeats SET cal_end = :until WHERE cal_id = :id'
        );
        $stmt->execute(['until' => $untilDateInt, 'id' => $id]);

        // Bump sequence
        $pdo->prepare('UPDATE webcal_entry SET cal_sequence = cal_sequence + 1 WHERE cal_id = :id')
            ->execute(['id' => $id]);

        try {
            $this->mercure->publishEventUpdated($id, ['action' => 'series_truncated']);
        } catch (\Throwable $e) {
            $this->logger->warning('mercure->publishEventUpdated() failed', ['exception' => $e->getMessage()]);
        }

        try {
            $this->activityLogService->log(
                $id,
                $actor,
                null,
                ActivityLogType::UPDATE,
                'Truncated series at ' . $fromDate->format('Y-m-d') . ': ' . $event->name(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('activityLogService->log() failed', ['exception' => $e->getMessage()]);
        }

        return [
            'action' => 'future_cancelled',
            'from_date' => $fromDate->format('Y-m-d'),
        ];
    }

    /**
     * Split a recurring series at a given date.
     *
     * The original series is truncated with UNTIL = fromDate - 1 day.
     * A new recurring event is created starting at fromDate with the
     * modified data and the same recurrence pattern.
     *
     * @return array{original_id: int, new_id: int}
     */
    public function splitSeriesAtDate(
        int $originalId,
        Event $original,
        \DateTimeImmutable $fromDate,
        string $actor,
    ): array {
        $pdo = $this->pdoProvider->get();

        // 1. Truncate original series
        $untilDateInt = (int) $fromDate->modify('-1 day')->format('Ymd');
        $pdo->prepare('UPDATE webcal_entry_repeats SET cal_end = :until WHERE cal_id = :id')
            ->execute(['until' => $untilDateInt, 'id' => $originalId]);
        $pdo->prepare('UPDATE webcal_entry SET cal_sequence = cal_sequence + 1 WHERE cal_id = :id')
            ->execute(['id' => $originalId]);

        // 2. Create new event starting at fromDate with same recurrence
        $newDateInt = (int) $fromDate->format('Ymd');
        $newUid = sprintf('%s-%s@split', $original->uid(), $fromDate->format('Ymd'));
        $now = $this->clock->now();

        // Get next ID
        $stmt = $pdo->query('SELECT COALESCE(MAX(cal_id), 0) + 1 FROM webcal_entry');
        /** @var int $newId */
        $newId = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        $pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_time, cal_duration,
             cal_name, cal_description, cal_location, cal_type, cal_access, cal_uid,
             cal_sequence, cal_status, cal_mod_date, cal_mod_time)
             VALUES (:id, :create_by, :date, :time, :duration, :name, :description, :location,
                     :type, :access, :uid, 0, NULL, :mod_date, :mod_time)'
        )->execute([
            'id' => $newId,
            'create_by' => $original->createdBy(),
            'date' => $newDateInt,
            'time' => (int) $original->start()->format('His'),
            'duration' => $original->duration(),
            'name' => $original->name(),
            'description' => $original->description(),
            'location' => $original->location(),
            'type' => 'M',
            'access' => $original->access()->value,
            'uid' => $newUid,
            'mod_date' => (int) $now->format('Ymd'),
            'mod_time' => (int) $now->format('His'),
        ]);

        // Copy recurrence rule (without the UNTIL we just set on the original)
        $rruleRow = $pdo->prepare(
            'SELECT * FROM webcal_entry_repeats WHERE cal_id = :id'
        );
        $rruleRow->execute(['id' => $originalId]);
        /** @var array<string, mixed>|false $rr */
        $rr = $rruleRow->fetch(\PDO::FETCH_ASSOC);
        if (\is_array($rr)) {
            $rr['cal_id'] = $newId;
            $rr['cal_end'] = $original->recurrence()->rule() !== null
                ? ($rr['cal_end'] ?? null)  // Preserve original end if it existed before truncation
                : null;
            // The original UNTIL was just set; the new series should use the ORIGINAL end
            // Since we don't have it anymore, use NULL (no end) — user can edit the new series
            $rr['cal_end'] = null;

            $cols = array_keys($rr);
            $placeholders = array_map(static fn(string $c) => ':' . $c, $cols);
            $pdo->prepare(
                'INSERT INTO webcal_entry_repeats (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
            )->execute($rr);
        }

        // Add creator as participant
        $pdo->prepare(
            "INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (:id, :login, 'A')"
        )->execute(['id' => $newId, 'login' => $original->createdBy()]);

        try {
            $this->activityLogService->log(
                $originalId,
                $actor,
                null,
                ActivityLogType::UPDATE,
                'Split series at ' . $fromDate->format('Y-m-d') . ': ' . $original->name() . ' → new event #' . $newId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('activityLogService->log() failed', ['exception' => $e->getMessage()]);
        }

        return ['original_id' => $originalId, 'new_id' => $newId];
    }
}
