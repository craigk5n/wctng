<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\CoreServiceFactory;
use App\Tenant\TenantJwtValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
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

    /**
     * The JWT tenant check has to run after the firewall, or it checks nothing.
     *
     * TenantJwtValidator compares the JWT's tenant claim against the resolved
     * tenant, reading the claim from the _jwt_tenant request attribute. That
     * attribute is set by JwtTenantExtractor from the on_jwt_decoded event,
     * which the security firewall dispatches while it authenticates. Registered
     * above the firewall -- as it was, at priority 10 against the firewall's 8
     * -- the listener runs first, finds no attribute, and returns having
     * compared nothing, so a token minted for one tenant is accepted on
     * another's host.
     *
     * The unit tests for the validator set _jwt_tenant on the request
     * themselves, so they pass whatever the priority is. This asserts the
     * ordering the framework actually dispatches.
     */
    public function testTheJwtTenantCheckRunsAfterTheFirewallAuthenticates(): void
    {
        self::bootKernel();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $order = [];
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $order[] = \is_array($listener) ? \get_debug_type($listener[0]) : \get_debug_type($listener);
        }

        $validator = array_search(TenantJwtValidator::class, $order, true);
        self::assertIsInt($validator, 'TenantJwtValidator is not listening on kernel.request at all');

        $firewall = null;
        foreach ($order as $position => $class) {
            if (str_contains($class, 'FirewallListener')) {
                $firewall = $position;
                break;
            }
        }

        self::assertIsInt($firewall, 'no firewall listener on kernel.request to order against');
        self::assertGreaterThan(
            $firewall,
            $validator,
            'TenantJwtValidator must be dispatched after the firewall, or _jwt_tenant is never set when it looks',
        );
    }
}
