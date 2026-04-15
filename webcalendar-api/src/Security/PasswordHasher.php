<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Single source of truth for password hashing in the API layer.
 *
 * Hashes are Argon2id with OWASP-2025-minimum parameters (19 MiB memory,
 * 2 iterations, 1 thread). Verification accepts both current Argon2id and
 * legacy bcrypt hashes so existing users can authenticate while we
 * transparently upgrade their hash on next successful login.
 */
final readonly class PasswordHasher
{
    /**
     * OWASP 2025 minimum Argon2id parameters. Pinned here so
     * `password_needs_rehash()` fires when PHP's defaults drift away from
     * this baseline.
     *
     * @var array{memory_cost: int, time_cost: int, threads: int}
     */
    public const ARGON2ID_OPTIONS = [
        'memory_cost' => 19456, // 19 MiB
        'time_cost' => 2,
        'threads' => 1,
    ];

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);
    }

    public function verify(
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        string $hash,
    ): bool {
        return password_verify($password, $hash);
    }

    public function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);
    }
}
