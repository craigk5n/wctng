<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\WebCalendarUser;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\User;

final class WebCalendarUserTest extends TestCase
{
    public function testRegularUserRoles(): void
    {
        $coreUser = new User('testuser', 'Test', 'User', 'test@example.com', false, true);
        $user = new WebCalendarUser($coreUser, 'hashed_password');

        $this->assertContains('ROLE_USER', $user->getRoles());
        $this->assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testAdminUserRoles(): void
    {
        $coreUser = new User('admin', 'Admin', 'User', 'admin@example.com', true, true);
        $user = new WebCalendarUser($coreUser, 'hashed_password');

        $this->assertContains('ROLE_USER', $user->getRoles());
        $this->assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testGetUserIdentifier(): void
    {
        $coreUser = new User('testuser', 'Test', 'User', 'test@example.com', false, true);
        $user = new WebCalendarUser($coreUser, 'hashed_password');

        $this->assertSame('testuser', $user->getUserIdentifier());
    }

    public function testGetPassword(): void
    {
        $coreUser = new User('testuser', 'Test', 'User', 'test@example.com', false, true);
        $user = new WebCalendarUser($coreUser, '$argon2id$hash');

        $this->assertSame('$argon2id$hash', $user->getPassword());
    }

    public function testGetCoreUser(): void
    {
        $coreUser = new User('testuser', 'Test', 'User', 'test@example.com', false, true);
        $user = new WebCalendarUser($coreUser, 'hashed_password');

        $this->assertSame($coreUser, $user->getCoreUser());
    }

    public function testEraseCredentials(): void
    {
        $coreUser = new User('testuser', 'Test', 'User', 'test@example.com', false, true);
        $user = new WebCalendarUser($coreUser, 'hashed_password');

        // Should not throw
        $user->eraseCredentials();
        $this->assertTrue(true);
    }
}
