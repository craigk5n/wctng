<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\PasswordHasher;
use App\Security\PasswordUpgradeService;
use Psr\Log\NullLogger;

/**
 * PBP-S2: A user whose password was stored with legacy bcrypt must end
 * the login request holding an Argon2id hash. Users already on Argon2id
 * stay put.
 */
final class PasswordRehashOnLoginIntegrationTest extends IntegrationTestCase
{
    public function testBcryptHashUpgradesToArgon2idAfterSuccessfulLogin(): void
    {
        // Seed 'alice' with a bcrypt hash directly (simulates pre-migration state)
        $bcrypt = password_hash('alice-secret', PASSWORD_BCRYPT);
        self::assertNotFalse($bcrypt);
        $this->factory->getUserRepository()->setPassword('alice', $bcrypt);

        $stored = $this->factory->getUserRepository()->getPasswordHash('alice');
        self::assertNotNull($stored);
        self::assertStringStartsWith('$2y$', $stored, 'precondition: seeded hash is bcrypt');

        // Simulate login flow: verify, then upgrade on success
        $authService = $this->factory->getAuthService();
        self::assertTrue($authService->authenticate('alice', 'alice-secret'));

        $service = new PasswordUpgradeService(new PasswordHasher(), $this->factory, new NullLogger());
        $service->upgradeIfNeeded('alice', 'alice-secret');

        $afterLogin = $this->factory->getUserRepository()->getPasswordHash('alice');
        self::assertNotNull($afterLogin);
        self::assertStringStartsWith(
            '$argon2id$',
            $afterLogin,
            'bcrypt hash should have been transparently upgraded to Argon2id'
        );

        // Subsequent logins still succeed with the same plaintext
        self::assertTrue(
            $this->factory->getAuthService()->authenticate('alice', 'alice-secret'),
            'upgraded hash must still verify the original password'
        );
    }

    public function testArgon2idHashWithTargetParamsIsUnchangedAfterLogin(): void
    {
        $hasher = new PasswordHasher();
        $argon = $hasher->hash('alice-secret');
        $this->factory->getUserRepository()->setPassword('alice', $argon);

        $service = new PasswordUpgradeService($hasher, $this->factory, new NullLogger());
        $service->upgradeIfNeeded('alice', 'alice-secret');

        $after = $this->factory->getUserRepository()->getPasswordHash('alice');
        self::assertSame($argon, $after, 'target argon2id hash should not be rehashed');
    }

    public function testCoreDefaultArgon2idIsUpgradedToPinnedParameters(): void
    {
        // webcalendar-core's UserService::hashPassword() uses PHP defaults
        // (memory_cost=65536, time_cost=4) — we need to rehash to our
        // pinned canonical values on next login.
        $core = password_hash('alice-secret', PASSWORD_ARGON2ID);
        self::assertNotFalse($core);
        $this->factory->getUserRepository()->setPassword('alice', $core);

        $service = new PasswordUpgradeService(new PasswordHasher(), $this->factory, new NullLogger());
        $service->upgradeIfNeeded('alice', 'alice-secret');

        $after = $this->factory->getUserRepository()->getPasswordHash('alice');
        self::assertNotNull($after);
        self::assertNotSame($core, $after);

        $info = password_get_info($after);
        self::assertSame('argon2id', $info['algoName']);
        self::assertSame(PasswordHasher::ARGON2ID_OPTIONS, $info['options']);
    }

    public function testUpgradeIsBestEffortAndSwallowsRepositoryFailures(): void
    {
        // No password set for this user — getPasswordHash returns null,
        // upgrade should silently no-op without throwing.
        $service = new PasswordUpgradeService(new PasswordHasher(), $this->factory, new NullLogger());
        $service->upgradeIfNeeded('nobody-home', 'whatever');

        $this->assertTrue(true, 'no exception thrown');
    }
}
