<?php

declare(strict_types=1);

namespace App\Tests\Integration;

final class AuthFlowIntegrationTest extends IntegrationTestCase
{
    public function testAuthServiceValidatesCorrectPassword(): void
    {
        $authService = $this->factory->getAuthService();
        $result = $authService->authenticate('admin', 'admin');
        $this->assertTrue($result);
    }

    public function testAuthServiceRejectsWrongPassword(): void
    {
        $authService = $this->factory->getAuthService();
        $result = $authService->authenticate('admin', 'wrongpassword');
        $this->assertFalse($result);
    }

    public function testAuthServiceRejectsNonexistentUser(): void
    {
        $authService = $this->factory->getAuthService();
        $result = $authService->authenticate('nobody', 'password');
        $this->assertFalse($result);
    }

    public function testUserServiceGetUserByLogin(): void
    {
        $user = $this->factory->getUserService()->getUserByLogin('admin');
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->login());
        $this->assertTrue($user->isAdmin());
    }

    public function testUserServiceGetNonexistentUser(): void
    {
        $user = $this->factory->getUserService()->getUserByLogin('nobody');
        $this->assertNull($user);
    }

    public function testNormalUserIsNotAdmin(): void
    {
        $user = $this->factory->getUserService()->getUserByLogin('alice');
        $this->assertNotNull($user);
        $this->assertFalse($user->isAdmin());
    }
}
