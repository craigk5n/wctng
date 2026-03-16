<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\CoreServiceFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\SecurityService;
use WebCalendar\Core\Application\Service\UserService;

final class ServiceWiringTest extends KernelTestCase
{
    public function testCoreServiceFactoryAvailableInContainer(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(CoreServiceFactory::class);
        $this->assertInstanceOf(CoreServiceFactory::class, $factory);
    }

    public function testEventServiceAvailableViaFactory(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(CoreServiceFactory::class);
        $this->assertInstanceOf(CoreServiceFactory::class, $factory);
        $this->assertInstanceOf(EventService::class, $factory->getEventService());
    }

    public function testUserServiceAvailableViaFactory(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(CoreServiceFactory::class);
        $this->assertInstanceOf(CoreServiceFactory::class, $factory);
        $this->assertInstanceOf(UserService::class, $factory->getUserService());
    }

    public function testCategoryServiceAvailableViaFactory(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(CoreServiceFactory::class);
        $this->assertInstanceOf(CoreServiceFactory::class, $factory);
        $this->assertInstanceOf(CategoryService::class, $factory->getCategoryService());
    }

    public function testSecurityServiceAvailableViaFactory(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(CoreServiceFactory::class);
        $this->assertInstanceOf(CoreServiceFactory::class, $factory);
        $this->assertInstanceOf(SecurityService::class, $factory->getSecurityService());
    }
}
