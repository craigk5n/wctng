<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Services\BlockedTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Coordinates JWT revocation across Lexik's blocklist and our per-user
 * `jti` index (PBP-S6). Lexik handles the single-token case; this
 * service layers on `logout-all` / admin force-logout / password-change
 * triggers by iterating every live jti for a login.
 *
 * Callers that need to revoke a *single* token (the logout endpoint)
 * can still talk to `BlockedTokenManagerInterface` directly — they
 * already have the payload. This service is specifically for the
 * "revoke every live session" cases where we don't have payloads, only
 * a login.
 */
final readonly class TokenRevocationService
{
    private LoggerInterface $logger;

    public function __construct(
        private BlockedTokenManagerInterface $blockedTokens,
        private UserTokenIndex $tokenIndex,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Revokes every live JWT for `$login` and returns the number of
     * tokens blocked. Idempotent: calling twice returns 0 the second
     * time.
     */
    public function revokeAllFor(string $login): int
    {
        $tokens = $this->tokenIndex->liveTokensFor($login);
        $count = 0;
        foreach ($tokens as $entry) {
            try {
                $this->blockedTokens->add([
                    'jti' => $entry['jti'],
                    'exp' => $entry['exp'],
                ]);
                $count++;
            } catch (\Throwable $e) {
                $this->logger->warning('JWT revocation failed for jti', [
                    'login' => $login,
                    'jti' => $entry['jti'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->tokenIndex->forgetAllFor($login);

        return $count;
    }
}
