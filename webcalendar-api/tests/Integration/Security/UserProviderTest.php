<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Security\WebCalendarUser;
use App\Security\WebCalendarUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class UserProviderTest extends KernelTestCase
{
    private WebCalendarUserProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(WebCalendarUserProvider::class);
        $this->assertInstanceOf(WebCalendarUserProvider::class, $provider);
        $this->provider = $provider;
    }

    public function testLoadAdminUser(): void
    {
        $user = $this->provider->loadUserByIdentifier('admin');

        $this->assertInstanceOf(WebCalendarUser::class, $user);
        $this->assertSame('admin', $user->getUserIdentifier());
        $this->assertContains('ROLE_USER', $user->getRoles());
        $this->assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testAdminUserHasPasswordHash(): void
    {
        $user = $this->provider->loadUserByIdentifier('admin');

        $password = $user->getPassword();
        $this->assertNotNull($password);
        $this->assertNotEmpty($password);
    }

    public function testLoadNonexistentUserThrows(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier('nonexistent_user_xyz');
    }

    public function testRefreshUser(): void
    {
        $user = $this->provider->loadUserByIdentifier('admin');
        $refreshed = $this->provider->refreshUser($user);

        $this->assertInstanceOf(WebCalendarUser::class, $refreshed);
        $this->assertSame('admin', $refreshed->getUserIdentifier());
    }

    public function testGetCoreUser(): void
    {
        $user = $this->provider->loadUserByIdentifier('admin');

        $coreUser = $user->getCoreUser();
        $this->assertSame('admin', $coreUser->login());
        $this->assertTrue($coreUser->isAdmin());
    }
}
