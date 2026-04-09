<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Stores per-user CalDAV sync-token override values.
 *
 * Why this exists: `CoreCalendarBackend::getSyncToken()` derives its token
 * from `MAX(cal_mod_date * 1e6 + cal_mod_time)` across the user's events.
 * Bulk operations like the admin purge remove rows rather than touching
 * mod_date, so the computed token either stays the same (if the newest
 * event survives — clients sync nothing new and re-upload the deleted
 * rows on their next push) or moves *backward* (if the newest event was
 * deleted — clients see a token mismatch *and* a stale past token,
 * which some clients reject outright).
 *
 * The fix: after a purge, write an override value strictly greater than
 * any existing computed token. `getSyncToken()` then returns
 * `max(computed, override)` wrapped in the legacy "sync-" prefix, so
 * every CalDAV client observes a forward-moving token and performs a
 * full reconciliation on next sync.
 *
 * Token values use the same integer format as the computed token:
 * `YYYYMMDD * 1e6 + HHMMSS` (a YmdHis integer), which makes them
 * directly comparable to the computed values.
 */
final class CalDavSyncTokenRepository
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS webcal_caldav_sync_overrides ('
            . 'cal_login VARCHAR(60) NOT NULL, '
            . 'override_token BIGINT NOT NULL, '
            . 'PRIMARY KEY (cal_login)'
            . ')'
        );
    }

    public function getOverride(string $login): ?int
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT override_token FROM webcal_caldav_sync_overrides WHERE cal_login = :login'
        );
        $stmt->execute(['login' => $login]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return \is_numeric($value) ? (int) $value : null;
    }

    /**
     * Bumps the override for each listed login to a value strictly greater
     * than both the prior override and the current wall-clock YmdHis.
     *
     * Uses delete-then-insert so the implementation stays portable across
     * MySQL / SQLite / PostgreSQL without vendor-specific UPSERT syntax.
     *
     * @param list<string> $logins
     */
    public function bumpForUsers(array $logins): void
    {
        if ($logins === []) {
            return;
        }
        $this->ensureSchema();

        $nowYmdHis = (int) (new \DateTimeImmutable())->format('YmdHis');

        foreach (array_unique($logins) as $login) {
            $existing = $this->getOverride($login) ?? 0;
            $new = max($existing, $nowYmdHis) + 1;

            $this->pdo
                ->prepare('DELETE FROM webcal_caldav_sync_overrides WHERE cal_login = :login')
                ->execute(['login' => $login]);
            $this->pdo
                ->prepare('INSERT INTO webcal_caldav_sync_overrides (cal_login, override_token) VALUES (:login, :token)')
                ->execute(['login' => $login, 'token' => $new]);
        }
    }
}
