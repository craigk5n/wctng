<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The lazy service locator.
 *
 * Two properties carry the whole class and neither was asserted anywhere. It
 * memoises: forty-six getters are `$this->x ??= new X(...)`, and dropping the
 * `??` would hand every caller its own instance. And it routes: getPdo()
 * returns the resolved tenant's connection, which is what keeps one tenant
 * out of another's database.
 */
final class CoreServiceFactoryTest extends TestCase
{
    /**
     * The three getters that build a new instance per call. All stateless --
     * two log, one reads and writes its counters in the database -- and none
     * has a backing field. Listed rather than derived so that a fourth one
     * appearing is a decision somebody makes on purpose.
     *
     * @var list<string>
     */
    private const array FRESH_EACH_CALL = [
        'getRateLimiter',
        'getEmailProvider',
        'getWebhookProvider',
    ];

    private \PDO $pdo;
    private NullLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->logger = new NullLogger();
    }

    private function factory(
        ?TenantContext $context = null,
        ?TenantDatabaseManager $manager = null,
    ): CoreServiceFactory {
        return new CoreServiceFactory($this->pdo, 'secret-for-tests', $this->logger, $context, $manager);
    }

    private static function tenant(string $slug): Tenant
    {
        // dbHost '' with dbName ':memory:' is the SQLite path in
        // TenantDatabaseManager::createConnection(), so this yields a real
        // connection that is demonstrably not the default one.
        return new Tenant(1, $slug, 'Tenant', '', ':memory:', '', '', TenantPlan::Free, TenantStatus::Active);
    }

    // ------------------------------------------------------------ memoisation

    /**
     * Every getter this class offers, written out rather than reflected over.
     *
     * The first version of this test derived the list with reflection, which
     * cannot work: the question "is this method public?" is exactly what needs
     * checking, so an enumeration of public methods quietly shrinks by one
     * instead of failing when a getter stops being public. Mutation testing
     * said so plainly -- thirty-seven surviving PublicVisibility mutants that
     * the reflected version could never have caught.
     *
     * @var list<string>
     */
    private const array GETTERS = [
        'getActivityLogRepository',
        'getActivityLogService',
        'getAssistantRepository',
        'getAssistantService',
        'getAuthService',
        'getBlobRepository',
        'getBlobService',
        'getBookingService',
        'getCategoryRepository',
        'getCategoryService',
        'getConfigRepository',
        'getConfigService',
        'getEmailProvider',
        'getEventRepository',
        'getEventService',
        'getExportService',
        'getFeedService',
        'getGroupRepository',
        'getGroupService',
        'getImportService',
        'getJournalRepository',
        'getJournalService',
        'getLayerRepository',
        'getLayerService',
        'getNotificationService',
        'getPdo',
        'getPermissionRepository',
        'getPermissionService',
        'getRateLimiter',
        'getRecurrenceService',
        'getReminderRepository',
        'getReportRepository',
        'getReportService',
        'getResourceRepository',
        'getResourceService',
        'getSearchService',
        'getSecurityService',
        'getSiteExtraRepository',
        'getSiteExtraService',
        'getTaskRepository',
        'getTaskService',
        'getTemplateRepository',
        'getTemplateService',
        'getTokenRepository',
        'getUserRepository',
        'getUserService',
        'getViewRepository',
        'getViewService',
        'getWebhookProvider',
    ];

    /** @return iterable<string, array{string}> */
    public static function getters(): iterable
    {
        foreach (self::GETTERS as $name) {
            yield $name => [$name];
        }
    }

    public function testTheListedGettersAreExactlyWhatTheClassOffers(): void
    {
        $actual = [];

        foreach ((new \ReflectionClass(CoreServiceFactory::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'get') && $method->getNumberOfRequiredParameters() === 0) {
                $actual[] = $method->getName();
            }
        }

        sort($actual);
        $expected = self::GETTERS;
        sort($expected);

        self::assertSame($expected, $actual, 'a getter was added, removed, or stopped being public');
    }

    #[DataProvider('getters')]
    public function testEveryGetterBuildsWhatItPromises(string $getter): void
    {
        // A getter nothing calls reads as dead code to any tool that looks,
        // and seven of these had no caller in the whole suite. The instance is
        // checked against the declared return type, which generalises what
        // this class's first four tests asserted by hand for EventService,
        // UserService, CategoryService and SecurityService.
        $returnType = (new \ReflectionMethod(CoreServiceFactory::class, $getter))->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType, $getter . ' declares no return type');

        self::assertInstanceOf($returnType->getName(), $this->factory()->{$getter}());
    }

    #[DataProvider('getters')]
    public function testGettersHandBackTheSameInstanceEveryTime(string $getter): void
    {
        $factory = $this->factory();

        $first = $factory->{$getter}();
        $second = $factory->{$getter}();

        if (\in_array($getter, self::FRESH_EACH_CALL, true)) {
            self::assertNotSame($first, $second, $getter . ' is documented as building a new instance per call');

            return;
        }

        self::assertSame($first, $second, $getter . ' must memoise: callers share one instance');
    }

    // --------------------------------------------------------------- routing

    public function testWithoutTenancyThePdoIsTheDefaultConnection(): void
    {
        self::assertSame($this->pdo, $this->factory()->getPdo());
    }

    public function testWithNoTenantResolvedThePdoIsTheDefaultConnection(): void
    {
        $factory = $this->factory(new TenantContext(), new TenantDatabaseManager('secret-for-tests'));

        self::assertSame($this->pdo, $factory->getPdo());
    }

    public function testTheResolvedTenantsConnectionIsUsed(): void
    {
        // The isolation property: with a tenant resolved, nothing may reach
        // the default database.
        $context = new TenantContext();
        $context->setTenant(self::tenant('alpha'));
        $factory = $this->factory($context, new TenantDatabaseManager('secret-for-tests'));

        self::assertNotSame($this->pdo, $factory->getPdo());
    }

    public function testTheTenantConnectionIsOpenedOnce(): void
    {
        $context = new TenantContext();
        $context->setTenant(self::tenant('alpha'));
        $factory = $this->factory($context, new TenantDatabaseManager('secret-for-tests'));

        self::assertSame($factory->getPdo(), $factory->getPdo());
    }

    public function testATenantWithoutADatabaseManagerFallsBackRatherThanFailing(): void
    {
        // Both collaborators are optional, so a half-configured factory is
        // reachable. Requiring only one of them would call getConnection() on
        // null the moment a tenant appeared.
        $context = new TenantContext();
        $context->setTenant(self::tenant('alpha'));

        self::assertSame($this->pdo, $this->factory($context, null)->getPdo());
    }

    // ---------------------------------------------------------------- wiring

    public function testTheInjectedLoggerReachesTheServicesThatTakeOne(): void
    {
        $service = $this->factory()->getEventService();

        self::assertSame($this->logger, self::loggerOf($service));
    }

    public function testTheLoggerIsOptional(): void
    {
        $factory = new CoreServiceFactory($this->pdo, 'secret-for-tests');

        self::assertInstanceOf(LoggerInterface::class, self::loggerOf($factory->getEventService()));
    }

    public function testServicesKeepTheConnectionTheyWereFirstBuiltWith(): void
    {
        // Characterisation, not endorsement. Memoised services hold the PDO
        // that was current when they were built, so switching tenant
        // afterwards moves getPdo() but not an already-built service. That is
        // safe only because TenantResolverListener resolves once per request
        // and php-fpm gives each request its own process. It stops being safe
        // under a worker runtime, or the moment anything iterates tenants in
        // one process -- and this test is where that will show up.
        $context = new TenantContext();
        $context->setTenant(self::tenant('alpha'));
        $factory = $this->factory($context, new TenantDatabaseManager('secret-for-tests'));

        $alphaPdo = $factory->getPdo();
        $repository = $factory->getEventRepository();

        $context->reset();
        $context->setTenant(self::tenant('beta'));

        self::assertNotSame($alphaPdo, $factory->getPdo(), 'getPdo() follows the tenant');
        self::assertSame($repository, $factory->getEventRepository(), 'but a built service is not rebuilt');
    }

    private static function loggerOf(object $service): ?LoggerInterface
    {
        foreach ((new \ReflectionClass($service))->getProperties() as $property) {
            $type = $property->getType();

            if ($type instanceof \ReflectionNamedType && is_a($type->getName(), LoggerInterface::class, true)) {
                /** @var LoggerInterface|null $value */
                $value = $property->getValue($service);

                return $value;
            }
        }

        self::fail($service::class . ' holds no logger to check');
    }
}
