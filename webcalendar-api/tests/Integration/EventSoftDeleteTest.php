<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;

/**
 * Integration tests for soft-delete, restore, and recurring scope features.
 *
 * These tests exercise the SQL operations that EventController performs
 * directly against an in-memory SQLite database, using CoreServiceFactory
 * to create events and repositories to verify state changes.
 */
final class EventSoftDeleteTest extends IntegrationTestCase
{
    /**
     * Helper: create a simple non-recurring event owned by admin.
     */
    private function createSimpleEvent(string $uid = 'soft-del-test@example.com'): EventId
    {
        $event = new Event(
            id: new EventId(0),
            uid: $uid,
            name: 'Soft Delete Test Event',
            description: 'Event for soft-delete testing',
            location: 'Room A',
            start: new \DateTimeImmutable('2026-06-15 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );

        $this->factory->getEventService()->createEvent($event, $this->adminUser);

        $found = $this->factory->getEventRepository()->findByUid($uid);
        $this->assertNotNull($found, 'Event should exist after creation');

        return $found->id();
    }

    /**
     * Helper: create a weekly recurring event owned by admin.
     */
    private function createRecurringEvent(string $uid = 'recurring-del-test@example.com'): EventId
    {
        $rrule = new RecurrenceRule('FREQ=WEEKLY;BYDAY=MO,WE,FR');
        $event = new Event(
            id: new EventId(0),
            uid: $uid,
            name: 'Recurring Soft Delete Test',
            description: 'Recurring event for soft-delete testing',
            location: 'Room B',
            start: new \DateTimeImmutable('2026-06-01 09:00:00'),
            duration: 30,
            createdBy: 'admin',
            type: EventType::REPEATING_EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence($rrule),
        );

        $this->factory->getEventService()->createEvent($event, $this->adminUser);

        $found = $this->factory->getEventRepository()->findByUid($uid);
        $this->assertNotNull($found, 'Recurring event should exist after creation');

        return $found->id();
    }

    /**
     * Helper: add a participant to an event via direct SQL insert.
     */
    private function addParticipant(int $eventId, string $login, string $status = 'A'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (:id, :login, :status)'
        );
        $stmt->execute(['id' => $eventId, 'login' => $login, 'status' => $status]);
    }

    /**
     * Helper: fetch cal_status from webcal_entry for a given event ID.
     */
    private function getEventStatus(int $eventId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT cal_status FROM webcal_entry WHERE cal_id = :id');
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $row['cal_status'];
    }

    /**
     * Helper: fetch cal_sequence from webcal_entry for a given event ID.
     */
    private function getEventSequence(int $eventId): int
    {
        $stmt = $this->pdo->prepare('SELECT cal_sequence FROM webcal_entry WHERE cal_id = :id');
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? (int) $row['cal_sequence'] : -1;
    }

    /**
     * Helper: fetch participant status from webcal_entry_user.
     */
    private function getParticipantStatus(int $eventId, string $login): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT cal_status FROM webcal_entry_user WHERE cal_id = :id AND cal_login = :login'
        );
        $stmt->execute(['id' => $eventId, 'login' => $login]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row['cal_status'] : null;
    }

    // ---------------------------------------------------------------
    // Test 1: Organizer soft-delete sets cal_status to 'cancelled'
    // ---------------------------------------------------------------

    public function testOrganizerSoftDeleteSetsStatusToCancelled(): void
    {
        $eventId = $this->createSimpleEvent();
        $id = $eventId->value();

        // Verify initial state: status is null, sequence is 0
        $this->assertNull($this->getEventStatus($id));
        $initialSeq = $this->getEventSequence($id);

        // Simulate what EventController::delete() does for the organizer:
        // UPDATE webcal_entry SET cal_status = 'cancelled', cal_sequence = cal_sequence + 1
        $stmt = $this->pdo->prepare(
            "UPDATE webcal_entry SET cal_status = 'cancelled', cal_sequence = cal_sequence + 1 WHERE cal_id = :id"
        );
        $stmt->execute(['id' => $id]);

        // Verify: status is now 'cancelled'
        $this->assertSame('cancelled', $this->getEventStatus($id));

        // Verify: sequence was bumped
        $this->assertSame($initialSeq + 1, $this->getEventSequence($id));

        // Verify: the event is NOT hard-deleted — it still exists in the DB
        $event = $this->factory->getEventService()->getEventById($eventId);
        $this->assertNotNull($event, 'Soft-deleted event should still exist in the database');
    }

    // ---------------------------------------------------------------
    // Test 2: Participant decline sets webcal_entry_user.cal_status to 'R'
    // ---------------------------------------------------------------

    public function testParticipantDeclineSetsUserStatusToRejected(): void
    {
        $eventId = $this->createSimpleEvent('participant-decline@example.com');
        $id = $eventId->value();

        // Add alice as participant with 'A' (accepted) status
        $this->addParticipant($id, 'alice', 'A');

        // Verify initial participant status
        $this->assertSame('A', $this->getParticipantStatus($id, 'alice'));

        // Simulate what EventController::delete() does for a non-organizer participant:
        // updateParticipantStatus sets cal_status to 'R'
        $this->factory->getEventRepository()
            ->updateParticipantStatus($eventId, 'alice', 'R');

        // Verify: participant status is now 'R' (rejected/declined)
        $this->assertSame('R', $this->getParticipantStatus($id, 'alice'));

        // Verify: the event itself is NOT cancelled — only the participant's status changed
        $this->assertNull($this->getEventStatus($id));
    }

    // ---------------------------------------------------------------
    // Test 3: Restore after cancel resets cal_status to null
    // ---------------------------------------------------------------

    public function testRestoreAfterCancelResetsStatusToNull(): void
    {
        $eventId = $this->createSimpleEvent('restore-test@example.com');
        $id = $eventId->value();

        // Cancel the event first
        $this->pdo->prepare(
            "UPDATE webcal_entry SET cal_status = 'cancelled', cal_sequence = cal_sequence + 1 WHERE cal_id = :id"
        )->execute(['id' => $id]);

        $this->assertSame('cancelled', $this->getEventStatus($id));

        // Simulate what EventController::restore() does for the organizer:
        // Restore to null (the default when previous_status is null or 'cancelled')
        $restoreTo = null;
        $stmt = $this->pdo->prepare(
            'UPDATE webcal_entry SET cal_status = :status WHERE cal_id = :id'
        );
        $stmt->execute(['status' => $restoreTo, 'id' => $id]);

        // Verify: status is restored to null
        $this->assertNull($this->getEventStatus($id));
    }

    public function testRestoreAfterCancelWithPreviousStatusPreservesIt(): void
    {
        $eventId = $this->createSimpleEvent('restore-prev-test@example.com');
        $id = $eventId->value();

        // Set an initial status before cancellation
        $this->pdo->prepare(
            "UPDATE webcal_entry SET cal_status = 'tentative' WHERE cal_id = :id"
        )->execute(['id' => $id]);

        // Cancel the event
        $this->pdo->prepare(
            "UPDATE webcal_entry SET cal_status = 'cancelled', cal_sequence = cal_sequence + 1 WHERE cal_id = :id"
        )->execute(['id' => $id]);

        $this->assertSame('cancelled', $this->getEventStatus($id));

        // Restore with previous status = 'tentative'
        $restoreTo = 'tentative';
        $stmt = $this->pdo->prepare(
            'UPDATE webcal_entry SET cal_status = :status WHERE cal_id = :id'
        );
        $stmt->execute(['status' => $restoreTo, 'id' => $id]);

        // Verify: status is restored to the previous value
        $this->assertSame('tentative', $this->getEventStatus($id));
    }

    // ---------------------------------------------------------------
    // Test 4: scope=occurrence adds EXDATE to webcal_entry_repeats_not
    // ---------------------------------------------------------------

    public function testCancelOccurrenceAddsExdate(): void
    {
        $eventId = $this->createRecurringEvent('exdate-test@example.com');
        $id = $eventId->value();
        $dateInt = 20260603; // a Wednesday occurrence

        // Verify no EXDATE rows exist yet
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM webcal_entry_repeats_not WHERE cal_id = :id'
        );
        $stmt->execute(['id' => $id]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        $initialSeq = $this->getEventSequence($id);

        // Simulate what EventController::cancelOccurrence() does:
        // INSERT OR IGNORE is SQLite equivalent of INSERT IGNORE (MySQL)
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES (:id, :date, 1)'
        )->execute(['id' => $id, 'date' => $dateInt]);

        // Bump sequence
        $this->pdo->prepare(
            'UPDATE webcal_entry SET cal_sequence = cal_sequence + 1 WHERE cal_id = :id'
        )->execute(['id' => $id]);

        // Verify: EXDATE row exists
        $stmt = $this->pdo->prepare(
            'SELECT cal_date, cal_exdate FROM webcal_entry_repeats_not WHERE cal_id = :id AND cal_date = :date'
        );
        $stmt->execute(['id' => $id, 'date' => $dateInt]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'EXDATE row should exist after cancelling occurrence');
        $this->assertSame($dateInt, (int) $row['cal_date']);
        $this->assertSame(1, (int) $row['cal_exdate']);

        // Verify: sequence was bumped
        $this->assertSame($initialSeq + 1, $this->getEventSequence($id));

        // Verify: event itself is NOT cancelled
        $this->assertNull($this->getEventStatus($id));
    }

    public function testCancelOccurrenceDuplicateExdateIsIdempotent(): void
    {
        $eventId = $this->createRecurringEvent('exdate-dup-test@example.com');
        $id = $eventId->value();
        $dateInt = 20260605;

        // Insert EXDATE twice — should not throw
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES (:id, :date, 1)'
        )->execute(['id' => $id, 'date' => $dateInt]);

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES (:id, :date, 1)'
        )->execute(['id' => $id, 'date' => $dateInt]);

        // Verify: only one row
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM webcal_entry_repeats_not WHERE cal_id = :id AND cal_date = :date'
        );
        $stmt->execute(['id' => $id, 'date' => $dateInt]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // ---------------------------------------------------------------
    // Test 5: scope=future sets webcal_entry_repeats.cal_end to date-1
    // ---------------------------------------------------------------

    public function testCancelFutureOccurrencesTruncatesSeries(): void
    {
        $eventId = $this->createRecurringEvent('future-test@example.com');
        $id = $eventId->value();

        // Verify the recurrence rule exists
        $stmt = $this->pdo->prepare(
            'SELECT cal_end FROM webcal_entry_repeats WHERE cal_id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'Recurrence rule row should exist');

        $initialSeq = $this->getEventSequence($id);

        // Simulate cancelling future occurrences from 2026-06-15:
        // EventController::cancelFutureOccurrences() sets cal_end = date - 1 day
        $fromDate = new \DateTimeImmutable('2026-06-15');
        $untilDateInt = (int) $fromDate->modify('-1 day')->format('Ymd');
        $this->assertSame(20260614, $untilDateInt);

        $this->pdo->prepare(
            'UPDATE webcal_entry_repeats SET cal_end = :until WHERE cal_id = :id'
        )->execute(['until' => $untilDateInt, 'id' => $id]);

        // Bump sequence
        $this->pdo->prepare(
            'UPDATE webcal_entry SET cal_sequence = cal_sequence + 1 WHERE cal_id = :id'
        )->execute(['id' => $id]);

        // Verify: cal_end is set to the day before fromDate
        $stmt = $this->pdo->prepare(
            'SELECT cal_end FROM webcal_entry_repeats WHERE cal_id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame(20260614, (int) $row['cal_end']);

        // Verify: sequence was bumped
        $this->assertSame($initialSeq + 1, $this->getEventSequence($id));

        // Verify: event itself is NOT cancelled (status unchanged)
        $this->assertNull($this->getEventStatus($id));
    }

    // ---------------------------------------------------------------
    // Additional edge case tests
    // ---------------------------------------------------------------

    public function testParticipantRestoreResetsStatusToAccepted(): void
    {
        $eventId = $this->createSimpleEvent('participant-restore@example.com');
        $id = $eventId->value();

        // Add alice as participant
        $this->addParticipant($id, 'alice', 'A');

        // Decline
        $this->factory->getEventRepository()
            ->updateParticipantStatus($eventId, 'alice', 'R');
        $this->assertSame('R', $this->getParticipantStatus($id, 'alice'));

        // Restore to accepted (default restore behavior)
        $this->factory->getEventRepository()
            ->updateParticipantStatus($eventId, 'alice', 'A');
        $this->assertSame('A', $this->getParticipantStatus($id, 'alice'));
    }

    public function testSoftDeleteDoesNotRemoveParticipantRows(): void
    {
        $eventId = $this->createSimpleEvent('preserve-participants@example.com');
        $id = $eventId->value();

        // Add participants
        $this->addParticipant($id, 'alice', 'A');

        // Soft-delete the event
        $this->pdo->prepare(
            "UPDATE webcal_entry SET cal_status = 'cancelled', cal_sequence = cal_sequence + 1 WHERE cal_id = :id"
        )->execute(['id' => $id]);

        // Verify: participant rows still exist
        $participants = $this->factory->getEventRepository()
            ->getParticipantsWithStatus($eventId);
        $this->assertArrayHasKey('alice', $participants);
        $this->assertSame('A', $participants['alice']);
    }

    public function testCancelledEventSequenceIncrements(): void
    {
        $eventId = $this->createSimpleEvent('seq-bump@example.com');
        $id = $eventId->value();

        $seq0 = $this->getEventSequence($id);

        // Cancel
        $this->pdo->prepare(
            "UPDATE webcal_entry SET cal_status = 'cancelled', cal_sequence = cal_sequence + 1 WHERE cal_id = :id"
        )->execute(['id' => $id]);

        $seq1 = $this->getEventSequence($id);
        $this->assertSame($seq0 + 1, $seq1);

        // Restore and cancel again — sequence should bump again
        $this->pdo->prepare(
            'UPDATE webcal_entry SET cal_status = NULL WHERE cal_id = :id'
        )->execute(['id' => $id]);

        $this->pdo->prepare(
            "UPDATE webcal_entry SET cal_status = 'cancelled', cal_sequence = cal_sequence + 1 WHERE cal_id = :id"
        )->execute(['id' => $id]);

        $seq2 = $this->getEventSequence($id);
        $this->assertSame($seq1 + 1, $seq2);
    }
}
