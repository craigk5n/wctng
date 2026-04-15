<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Covers PBP-S2 PasswordHasher wrapper: Argon2id with fixed OWASP-2025
 * minimum parameters, verifies both argon2id and legacy bcrypt hashes,
 * and reports rehash-needed for anything other than target argon2id.
 */
final class PasswordHasherTest extends TestCase
{
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasher();
    }

    public function testHashProducesArgon2idWithTargetParameters(): void
    {
        $hash = $this->hasher->hash('correct horse battery staple');

        $this->assertStringStartsWith('$argon2id$', $hash);

        $info = password_get_info($hash);
        $this->assertSame('argon2id', $info['algoName']);
        $this->assertSame(PasswordHasher::ARGON2ID_OPTIONS, $info['options']);
    }

    public function testVerifyAcceptsArgon2idHash(): void
    {
        $hash = $this->hasher->hash('hunter2');
        $this->assertTrue($this->hasher->verify('hunter2', $hash));
        $this->assertFalse($this->hasher->verify('wrong', $hash));
    }

    public function testVerifyAcceptsLegacyBcryptHash(): void
    {
        // Intentional: build a legacy bcrypt hash to simulate an existing user
        // whose password was stored before the Argon2id migration.
        $legacy = password_hash('hunter2', PASSWORD_BCRYPT);
        $this->assertNotFalse($legacy);
        $this->assertStringStartsWith('$2y$', $legacy);

        $this->assertTrue($this->hasher->verify('hunter2', $legacy));
        $this->assertFalse($this->hasher->verify('wrong', $legacy));
    }

    public function testNeedsRehashDetectsBcrypt(): void
    {
        $legacy = password_hash('hunter2', PASSWORD_BCRYPT);
        $this->assertNotFalse($legacy);
        $this->assertTrue($this->hasher->needsRehash($legacy));
    }

    public function testNeedsRehashDetectsCoreDefaultArgon2id(): void
    {
        // Core's UserService::hashPassword currently uses PHP defaults
        // (memory_cost=65536) — stronger than our target but triggers
        // needs_rehash because it differs from our canonical options.
        $withDefaults = password_hash('hunter2', PASSWORD_ARGON2ID);
        $this->assertNotFalse($withDefaults);

        $this->assertTrue(
            $this->hasher->needsRehash($withDefaults),
            'PHP-default Argon2id params should trigger rehash so we pin to canonical values'
        );
    }

    public function testNeedsRehashAcceptsTargetArgon2id(): void
    {
        $hash = $this->hasher->hash('hunter2');
        $this->assertFalse($this->hasher->needsRehash($hash));
    }

    public function testHashParameterCarriesSensitiveParameterAttribute(): void
    {
        $ref = new \ReflectionMethod(PasswordHasher::class, 'hash');
        $params = $ref->getParameters();
        $this->assertNotEmpty($params[0]->getAttributes(\SensitiveParameter::class));
    }

    public function testVerifyParameterCarriesSensitiveParameterAttribute(): void
    {
        $ref = new \ReflectionMethod(PasswordHasher::class, 'verify');
        $params = $ref->getParameters();
        $this->assertNotEmpty(
            $params[0]->getAttributes(\SensitiveParameter::class),
            '$password parameter must be annotated'
        );
        $this->assertNotEmpty(
            $params[1]->getAttributes(\SensitiveParameter::class),
            '$hash parameter must be annotated (it embeds the salt, which is sensitive)'
        );
    }
}
