<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\SecurityService;
use WebCalendar\Core\Application\Service\UserService;

final class CoreServiceFactoryTest extends TestCase
{
    private CoreServiceFactory $factory;

    protected function setUp(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $this->factory = new CoreServiceFactory($pdo, 'test_secret');
    }

    public function testCreatesEventService(): void
    {
        $this->assertInstanceOf(EventService::class, $this->factory->getEventService());
    }

    public function testCreatesUserService(): void
    {
        $this->assertInstanceOf(UserService::class, $this->factory->getUserService());
    }

    public function testCreatesCategoryService(): void
    {
        $this->assertInstanceOf(CategoryService::class, $this->factory->getCategoryService());
    }

    public function testCreatesSecurityService(): void
    {
        $this->assertInstanceOf(SecurityService::class, $this->factory->getSecurityService());
    }

    public function testReturnsSameInstanceOnMultipleCalls(): void
    {
        $first = $this->factory->getEventService();
        $second = $this->factory->getEventService();
        $this->assertSame($first, $second);
    }
}
