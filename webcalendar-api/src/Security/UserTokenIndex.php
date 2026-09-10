<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Per-user outstanding-JWT index.
 *
 * Lexik's built-in `BlockedTokenManagerInterface` only knows about
 * individual `jti`s — it's perfect for "logout" (block one token) but
 * can't answer "what are all of this user's outstanding tokens?", which
 * `logout-all`, password-change-triggered revocation, and admin
 * force-logout all need.
 *
 * This service maintains a `(user_login, jti, expires_at)` table so
 * those paths can iterate a user's live tokens and hand each one to
 * `BlockedTokenManagerInterface::add()`. On every token issue the caller
 * records the jti here; on every logout the jti is removed. Expired
 * rows are trimmed opportunistically on read/write to keep the table
 * small without needing a cron.
 *
 * Schema: `webcal_user_jti(login, jti, expires_at, issued_at)` — the
 * composite primary key is `(login, jti)` so a user can have many live
 * sessions (web + mobile + CLI).
 */
final class UserTokenIndex
{
    private ClockInterface $clock;

    public function __construct(
        private readonly \PDO $pdo,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    public function record(string $login, string $jti, int $expiresAt): void
    {
        $this->ensureSchema();
        $this->purgeExpired();

        $sql = 'INSERT INTO webcal_user_jti (login, jti, expires_at, issued_at) '
            . 'VALUES (:login, :jti, :exp, :iss)';
        try {
            $this->pdo->prepare($sql)->execute([
                'login' => $login,
                'jti' => $jti,
                'exp' => $expiresAt,
                'iss' => $this->clock->now()->getTimestamp(),
            ]);
        } catch (\PDOException) {
            // Already recorded (primary key collision on replay of a
            // stored token) — the caller doesn't need to know.
        }
    }

    public function forget(string $jti): void
    {
        $this->ensureSchema();
        $this->pdo->prepare('DELETE FROM webcal_user_jti WHERE jti = :jti')->execute(['jti' => $jti]);
    }

    /**
     * Returns all currently-live `jti`s for a login, along with their
     * `exp` so the caller can build a payload for Lexik's blocklist.
     *
     * @return list<array{jti: string, exp: int}>
     */
    public function liveTokensFor(string $login): array
    {
        $this->ensureSchema();
        $this->purgeExpired();

        $stmt = $this->pdo->prepare(
            'SELECT jti, expires_at FROM webcal_user_jti WHERE login = :login AND expires_at > :now'
        );
        $stmt->execute(['login' => $login, 'now' => $this->clock->now()->getTimestamp()]);

        $out = [];
        /** @var array<string, scalar|null>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $jti = $row['jti'] ?? null;
            $exp = $row['expires_at'] ?? null;
            if (\is_string($jti) && \is_numeric($exp)) {
                $out[] = ['jti' => $jti, 'exp' => (int) $exp];
            }
            /** @var array<string, scalar|null>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $out;
    }

    /**
     * Forgets every live jti for a login. Used after `logout-all` once
     * the caller has handed each jti to the blocklist.
     */
    public function forgetAllFor(string $login): void
    {
        $this->ensureSchema();
        $this->pdo->prepare('DELETE FROM webcal_user_jti WHERE login = :login')->execute(['login' => $login]);
    }

    private function ensureSchema(): void
    {
        // No surrogate key, so no driver-specific AUTO_INCREMENT spelling to
        // pick between: the primary key is (login, jti).
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS webcal_user_jti ('
            . 'login VARCHAR(60) NOT NULL, '
            . 'jti VARCHAR(64) NOT NULL, '
            . 'expires_at INTEGER NOT NULL, '
            . 'issued_at INTEGER NOT NULL, '
            . 'PRIMARY KEY (login, jti)'
            . ')'
        );
    }

    private function purgeExpired(): void
    {
        try {
            $this->pdo->prepare('DELETE FROM webcal_user_jti WHERE expires_at <= :now')
                ->execute(['now' => $this->clock->now()->getTimestamp()]);
        } catch (\PDOException) {
            // Best-effort; missing table is handled by ensureSchema().
        }
    }
}
