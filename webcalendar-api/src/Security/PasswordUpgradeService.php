<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\CoreServiceFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Transparently upgrades legacy password hashes to Argon2id on successful
 * login. Call AFTER the caller has verified the password — this service
 * trusts the caller and does not re-verify.
 *
 * Best-effort: storage failures are logged but never thrown, so a flaky
 * write never blocks a successful authentication.
 */
final readonly class PasswordUpgradeService
{
    public function __construct(
        private PasswordHasher $hasher,
        private CoreServiceFactory $factory,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function upgradeIfNeeded(string $login, #[\SensitiveParameter] string $password): void
    {
        try {
            $repo = $this->factory->getUserRepository();
            $currentHash = $repo->getPasswordHash($login);

            if ($currentHash === null) {
                return;
            }

            if (!$this->hasher->needsRehash($currentHash)) {
                return;
            }

            $newHash = $this->hasher->hash($password);
            $repo->setPassword($login, $newHash);

            $this->logger->info('Upgraded password hash to pinned Argon2id parameters', [
                'login' => $login,
            ]);
        } catch (\Throwable $e) {
            // Best-effort: do not block login on storage failure.
            $this->logger->warning('Password rehash-on-login failed', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
