<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreAuthBackend;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Auth\Backend\AbstractBasic;

final class CoreAuthBackendTest extends TestCase
{
    public function testExtendsAbstractBasic(): void
    {
        $this->assertTrue(is_subclass_of(CoreAuthBackend::class, AbstractBasic::class));
    }

    public function testValidateUserPassMethodExists(): void
    {
        $method = new \ReflectionMethod(CoreAuthBackend::class, 'validateUserPass');
        $this->assertTrue($method->isProtected());
    }

    public function testRejectsNonStringCredentials(): void
    {
        // Create a minimal CoreServiceFactory with SQLite
        $pdo = new \PDO('sqlite::memory:');
        $factory = new \App\Service\CoreServiceFactory($pdo, 'test_secret');

        $backend = new CoreAuthBackend($factory);
        $method = new \ReflectionMethod($backend, 'validateUserPass');

        // Non-string args should return false
        $this->assertFalse($method->invoke($backend, null, null));
        $this->assertFalse($method->invoke($backend, 123, 'pass'));
    }
}
