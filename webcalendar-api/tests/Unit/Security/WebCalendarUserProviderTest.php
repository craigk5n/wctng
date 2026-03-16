<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\WebCalendarUser;
use App\Security\WebCalendarUserProvider;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WebCalendarUserProvider.
 *
 * Since CoreServiceFactory and UserService are final, we test the provider
 * as an integration test against a real DB in ServiceWiring tests.
 * These unit tests cover the WebCalendarUser wrapper and provider logic
 * that can be tested without mocking final classes.
 */
final class WebCalendarUserProviderTest extends TestCase
{
    public function testSupportsWebCalendarUserClass(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $factory = new CoreServiceFactory($pdo, 'test_secret');
        $provider = new WebCalendarUserProvider($factory);

        $this->assertTrue($provider->supportsClass(WebCalendarUser::class));
    }

    public function testDoesNotSupportOtherClasses(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $factory = new CoreServiceFactory($pdo, 'test_secret');
        $provider = new WebCalendarUserProvider($factory);

        $this->assertFalse($provider->supportsClass(\stdClass::class));
    }

    public function testRefreshUserWithUnsupportedTypeThrows(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $factory = new CoreServiceFactory($pdo, 'test_secret');
        $provider = new WebCalendarUserProvider($factory);

        $otherUser = $this->createMock(\Symfony\Component\Security\Core\User\UserInterface::class);

        $this->expectException(\Symfony\Component\Security\Core\Exception\UnsupportedUserException::class);
        $provider->refreshUser($otherUser);
    }
}
