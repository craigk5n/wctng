<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Repository for "external" (email-only) event participants.
 *
 * Legacy WebCalendar has a `webcal_entry_ext_user` table for inviting
 * people to events without requiring them to have a user account
 * (customers, vendors, one-time guests). The schema has shipped since
 * v1.9.x but until this repository the rewrite had no feature code
 * that touched the table.
 *
 * Schema (from webcalendar-core sqlite-schema.sql):
 *   webcal_entry_ext_user (
 *     cal_id INT,
 *     cal_fullname VARCHAR(60) NOT NULL,
 *     cal_email VARCHAR(75) NULL,
 *     PRIMARY KEY (cal_id, cal_fullname)
 *   )
 *
 * The composite PK means you cannot have the same fullname twice on
 * one event — saveForEvent() replaces the full set atomically to
 * avoid dangling rows and to keep the "same list = same state"
 * invariant expected by the controller.
 */
final class ExtParticipantRepository
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    /**
     * @return list<array{name: string, email: ?string}>
     */
    public function findForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cal_fullname, cal_email FROM webcal_entry_ext_user '
            . 'WHERE cal_id = :id ORDER BY cal_fullname'
        );
        $stmt->execute(['id' => $eventId]);

        $result = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (!\is_array($row)) {
                continue;
            }
            $name = isset($row['cal_fullname']) && \is_string($row['cal_fullname'])
                ? $row['cal_fullname']
                : '';
            if ($name === '') {
                continue;
            }
            $email = isset($row['cal_email']) && \is_string($row['cal_email']) && $row['cal_email'] !== ''
                ? $row['cal_email']
                : null;
            $result[] = ['name' => $name, 'email' => $email];
        }
        return $result;
    }

    /**
     * Batch lookup for multiple events in one query.
     *
     * @param list<int> $eventIds
     * @return array<int,list<array{name: string, email: ?string}>>
     */
    public function findForEvents(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, \count($eventIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT cal_id, cal_fullname, cal_email FROM webcal_entry_ext_user '
            . "WHERE cal_id IN ({$placeholders}) ORDER BY cal_id, cal_fullname"
        );
        $stmt->execute($eventIds);

        $result = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (!\is_array($row) || !isset($row['cal_id'], $row['cal_fullname'])) {
                continue;
            }
            $rawId = $row['cal_id'];
            if (!\is_int($rawId) && !\is_string($rawId)) {
                continue;
            }
            $id = (int) $rawId;
            $name = \is_string($row['cal_fullname']) ? $row['cal_fullname'] : '';
            if ($name === '') {
                continue;
            }
            $email = isset($row['cal_email']) && \is_string($row['cal_email']) && $row['cal_email'] !== ''
                ? $row['cal_email']
                : null;
            $result[$id] ??= [];
            $result[$id][] = ['name' => $name, 'email' => $email];
        }
        return $result;
    }

    /**
     * Replace the external participant set for an event. Duplicate names
     * (after trimming) are deduplicated; the first entry wins for email.
     *
     * @param list<array{name: string, email?: ?string}> $participants
     */
    public function saveForEvent(int $eventId, array $participants): void
    {
        $this->deleteForEvent($eventId);

        if ($participants === []) {
            return;
        }

        $seen = [];
        $insert = $this->pdo->prepare(
            'INSERT INTO webcal_entry_ext_user (cal_id, cal_fullname, cal_email) '
            . 'VALUES (:id, :name, :email)'
        );
        foreach ($participants as $p) {
            $name = trim($p['name']);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $emailRaw = $p['email'] ?? null;
            $email = ($emailRaw !== null && trim($emailRaw) !== '')
                ? trim($emailRaw)
                : null;

            $insert->execute([
                'id' => $eventId,
                'name' => $name,
                'email' => $email,
            ]);
        }
    }

    public function deleteForEvent(int $eventId): void
    {
        $this->pdo
            ->prepare('DELETE FROM webcal_entry_ext_user WHERE cal_id = :id')
            ->execute(['id' => $eventId]);
    }
}
