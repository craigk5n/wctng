<?php

declare(strict_types=1);

namespace App\Service;

use App\Tenant\TenantContext;
use App\Tenant\TenantDatabaseManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use WebCalendar\Core\Application\Contract\AuthServiceInterface;
use WebCalendar\Core\Application\Contract\EmailProviderInterface;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Application\Contract\WebhookProviderInterface;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\AssistantService;
use WebCalendar\Core\Application\Service\BlobService;
use WebCalendar\Core\Application\Service\BookingService;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\ExportService;
use WebCalendar\Core\Application\Service\FeedService;
use WebCalendar\Core\Application\Service\GroupService;
use WebCalendar\Core\Application\Service\ImportService;
use WebCalendar\Core\Application\Service\JournalService;
use WebCalendar\Core\Application\Service\LayerService;
use WebCalendar\Core\Application\Service\NotificationService;
use WebCalendar\Core\Application\Service\PermissionService;
use WebCalendar\Core\Application\Service\RecurrenceService;
use WebCalendar\Core\Application\Service\ReportService;
use WebCalendar\Core\Application\Service\ResourceService;
use WebCalendar\Core\Application\Service\SearchService;
use WebCalendar\Core\Application\Service\SecurityService;
use WebCalendar\Core\Application\Service\SiteExtraService;
use WebCalendar\Core\Application\Service\TaskService;
use WebCalendar\Core\Application\Service\TemplateService;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Application\Service\ViewService;
use WebCalendar\Core\Infrastructure\Email\LogEmailProvider;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Infrastructure\Persistence\PdoActivityLogRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoAssistantRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoBlobRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoConfigRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoGroupRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoJournalRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoLayerRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoPermissionRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoReminderRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoReportRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoResourceRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoSiteExtraRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoTaskRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoTemplateRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoTokenRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoUserRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoViewRepository;
use WebCalendar\Core\Infrastructure\Security\DatabaseAuthService;
use WebCalendar\Core\Infrastructure\Security\PdoRateLimiter;
use WebCalendar\Core\Infrastructure\Webhook\LogWebhookProvider;

/**
 * Factory that creates and caches all webcalendar-core services.
 *
 * Bridges Symfony's DI container with webcalendar-core's constructor-injected services.
 * All services are lazily created and cached for the lifetime of the factory.
 *
 * @deprecated since PBP-S4 (2026-04-15). Every `getXxx()` method is now registered
 * in `config/services.yaml` as a Symfony-container service, and every controller
 * has been migrated to inject specific services by type rather than depend on this
 * factory. New code MUST NOT add dependencies on `CoreServiceFactory` — inject the
 * specific service (e.g. `EventService`, `UserService`, `ConfigService`) or
 * repository interface (e.g. `UserRepositoryInterface`) directly. The factory is
 * kept alive as the wiring hub behind those service definitions, and because
 * `LegacyImportService` -- and `ImportLegacyCommand`, which constructs it --
 * still take it directly. Those two are all that remain; once they inject
 * their services this class can be deleted.
 */
final class CoreServiceFactory implements ResetInterface
{
    private LoggerInterface $logger;

    // Cached repository instances
    private ?PdoEventRepository $eventRepository = null;
    private ?PdoUserRepository $userRepository = null;
    private ?PdoCategoryRepository $categoryRepository = null;
    private ?PdoTokenRepository $tokenRepository = null;
    private ?PdoActivityLogRepository $activityLogRepository = null;
    private ?PdoGroupRepository $groupRepository = null;
    private ?PdoLayerRepository $layerRepository = null;
    private ?PdoPermissionRepository $permissionRepository = null;
    private ?PdoConfigRepository $configRepository = null;
    private ?PdoBlobRepository $blobRepository = null;
    private ?PdoResourceRepository $resourceRepository = null;
    private ?PdoTemplateRepository $templateRepository = null;
    private ?PdoViewRepository $viewRepository = null;
    private ?PdoTaskRepository $taskRepository = null;
    private ?PdoJournalRepository $journalRepository = null;
    private ?PdoSiteExtraRepository $siteExtraRepository = null;
    private ?PdoReportRepository $reportRepository = null;
    private ?PdoAssistantRepository $assistantRepository = null;
    private ?PdoReminderRepository $reminderRepository = null;

    // Cached service instances
    private ?EventService $eventService = null;
    private ?UserService $userService = null;
    private ?CategoryService $categoryService = null;
    private ?SecurityService $securityService = null;
    private ?GroupService $groupService = null;
    private ?LayerService $layerService = null;
    private ?PermissionService $permissionService = null;
    private ?ConfigService $configService = null;
    private ?ActivityLogService $activityLogService = null;
    private ?BlobService $blobService = null;
    private ?ResourceService $resourceService = null;
    private ?TemplateService $templateService = null;
    private ?ViewService $viewService = null;
    private ?TaskService $taskService = null;
    private ?JournalService $journalService = null;
    private ?SiteExtraService $siteExtraService = null;
    private ?ReportService $reportService = null;
    private ?SearchService $searchService = null;
    private ?RecurrenceService $recurrenceService = null;
    private ?AssistantService $assistantService = null;
    private ?ImportService $importService = null;
    private ?ExportService $exportService = null;
    private ?FeedService $feedService = null;
    private ?BookingService $bookingService = null;
    private ?NotificationService $notificationService = null;
    private ?DatabaseAuthService $authService = null;

    private ?TenantContext $tenantContext = null;
    private ?TenantDatabaseManager $tenantDbManager = null;

    public function __construct(
        private readonly \PDO $pdo,
        #[\SensitiveParameter]
        private readonly string $appSecret,
        ?LoggerInterface $logger = null,
        ?TenantContext $tenantContext = null,
        ?TenantDatabaseManager $tenantDbManager = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->tenantContext = $tenantContext;
        $this->tenantDbManager = $tenantDbManager;
    }


    /**
     * Drops every cached repository and service.
     *
     * Each one is built once and keeps the connection getPdo() returned at the
     * time, and that connection depends on the tenant then in context. A
     * container that serves more than one request -- any worker runtime -- would
     * otherwise hand the second request the first request's repositories, and a
     * write meant for one tenant would land in another's database. Symfony's
     * service resetter calls this between requests, as it does
     * TenantContext::reset().
     *
     * This covers requests, not arbitrary switching: code that changes the
     * tenant in context part-way through a single process has to call this
     * itself, or build a new factory, because a cached service is returned
     * without consulting getPdo() again.
     */
    #[\Override]
    public function reset(): void
    {
        $this->eventRepository = null;
        $this->userRepository = null;
        $this->categoryRepository = null;
        $this->tokenRepository = null;
        $this->activityLogRepository = null;
        $this->groupRepository = null;
        $this->layerRepository = null;
        $this->permissionRepository = null;
        $this->configRepository = null;
        $this->blobRepository = null;
        $this->resourceRepository = null;
        $this->templateRepository = null;
        $this->viewRepository = null;
        $this->taskRepository = null;
        $this->journalRepository = null;
        $this->siteExtraRepository = null;
        $this->reportRepository = null;
        $this->assistantRepository = null;
        $this->reminderRepository = null;
        $this->eventService = null;
        $this->userService = null;
        $this->categoryService = null;
        $this->securityService = null;
        $this->groupService = null;
        $this->layerService = null;
        $this->permissionService = null;
        $this->configService = null;
        $this->activityLogService = null;
        $this->blobService = null;
        $this->resourceService = null;
        $this->templateService = null;
        $this->viewService = null;
        $this->taskService = null;
        $this->journalService = null;
        $this->siteExtraService = null;
        $this->reportService = null;
        $this->searchService = null;
        $this->recurrenceService = null;
        $this->assistantService = null;
        $this->importService = null;
        $this->exportService = null;
        $this->feedService = null;
        $this->bookingService = null;
        $this->notificationService = null;
        $this->authService = null;
    }

    /**
     * Returns the PDO connection for the current context.
     * Uses tenant DB when a tenant is resolved, default DB otherwise.
     */
    public function getPdo(): \PDO
    {
        if ($this->tenantContext !== null && $this->tenantDbManager !== null) {
            $tenant = $this->tenantContext->getTenant();
            if ($tenant !== null) {
                return $this->tenantDbManager->getConnection($tenant);
            }
        }

        return $this->pdo;
    }

    // --- Repositories (lazily created) ---

    public function getEventRepository(): PdoEventRepository
    {
        return $this->eventRepository ??= new PdoEventRepository($this->getPdo());
    }

    public function getUserRepository(): PdoUserRepository
    {
        return $this->userRepository ??= new PdoUserRepository($this->getPdo());
    }

    public function getCategoryRepository(): PdoCategoryRepository
    {
        return $this->categoryRepository ??= new PdoCategoryRepository($this->getPdo());
    }

    public function getTokenRepository(): PdoTokenRepository
    {
        return $this->tokenRepository ??= new PdoTokenRepository($this->getPdo());
    }

    public function getActivityLogRepository(): PdoActivityLogRepository
    {
        return $this->activityLogRepository ??= new PdoActivityLogRepository($this->getPdo());
    }

    public function getGroupRepository(): PdoGroupRepository
    {
        return $this->groupRepository ??= new PdoGroupRepository($this->getPdo());
    }

    public function getLayerRepository(): PdoLayerRepository
    {
        return $this->layerRepository ??= new PdoLayerRepository($this->getPdo());
    }

    public function getPermissionRepository(): PdoPermissionRepository
    {
        return $this->permissionRepository ??= new PdoPermissionRepository($this->getPdo());
    }

    public function getConfigRepository(): PdoConfigRepository
    {
        return $this->configRepository ??= new PdoConfigRepository($this->getPdo());
    }

    public function getBlobRepository(): PdoBlobRepository
    {
        return $this->blobRepository ??= new PdoBlobRepository($this->getPdo());
    }

    public function getResourceRepository(): PdoResourceRepository
    {
        return $this->resourceRepository ??= new PdoResourceRepository($this->getPdo());
    }

    public function getTemplateRepository(): PdoTemplateRepository
    {
        return $this->templateRepository ??= new PdoTemplateRepository($this->getPdo());
    }

    public function getViewRepository(): PdoViewRepository
    {
        return $this->viewRepository ??= new PdoViewRepository($this->getPdo());
    }

    public function getTaskRepository(): PdoTaskRepository
    {
        return $this->taskRepository ??= new PdoTaskRepository($this->getPdo());
    }

    public function getJournalRepository(): PdoJournalRepository
    {
        return $this->journalRepository ??= new PdoJournalRepository($this->getPdo());
    }

    public function getSiteExtraRepository(): PdoSiteExtraRepository
    {
        return $this->siteExtraRepository ??= new PdoSiteExtraRepository($this->getPdo());
    }

    public function getReportRepository(): PdoReportRepository
    {
        return $this->reportRepository ??= new PdoReportRepository($this->getPdo());
    }

    public function getAssistantRepository(): PdoAssistantRepository
    {
        return $this->assistantRepository ??= new PdoAssistantRepository($this->getPdo());
    }

    public function getReminderRepository(): PdoReminderRepository
    {
        return $this->reminderRepository ??= new PdoReminderRepository($this->getPdo());
    }

    // --- Application Services (lazily created) ---

    public function getEventService(): EventService
    {
        return $this->eventService ??= new EventService(
            $this->getEventRepository(),
            $this->getUserRepository(),
            $this->logger,
        );
    }

    public function getUserService(): UserService
    {
        return $this->userService ??= new UserService(
            $this->getUserRepository(),
            $this->logger,
        );
    }

    public function getCategoryService(): CategoryService
    {
        return $this->categoryService ??= new CategoryService(
            $this->getCategoryRepository(),
            $this->logger,
        );
    }

    public function getSecurityService(): SecurityService
    {
        return $this->securityService ??= new SecurityService(
            $this->appSecret,
            $this->getTokenRepository(),
            logger: $this->logger,
        );
    }

    public function getGroupService(): GroupService
    {
        return $this->groupService ??= new GroupService(
            $this->getGroupRepository(),
        );
    }

    public function getLayerService(): LayerService
    {
        return $this->layerService ??= new LayerService(
            $this->getLayerRepository(),
        );
    }

    public function getPermissionService(): PermissionService
    {
        return $this->permissionService ??= new PermissionService(
            $this->getPermissionRepository(),
        );
    }

    public function getConfigService(): ConfigService
    {
        return $this->configService ??= new ConfigService(
            $this->getConfigRepository(),
        );
    }

    public function getActivityLogService(): ActivityLogService
    {
        return $this->activityLogService ??= new ActivityLogService(
            $this->getActivityLogRepository(),
        );
    }

    public function getBlobService(): BlobService
    {
        return $this->blobService ??= new BlobService(
            $this->getBlobRepository(),
        );
    }

    public function getResourceService(): ResourceService
    {
        return $this->resourceService ??= new ResourceService(
            $this->getResourceRepository(),
        );
    }

    public function getTemplateService(): TemplateService
    {
        return $this->templateService ??= new TemplateService(
            $this->getTemplateRepository(),
        );
    }

    public function getViewService(): ViewService
    {
        return $this->viewService ??= new ViewService(
            $this->getViewRepository(),
            $this->logger,
        );
    }

    public function getTaskService(): TaskService
    {
        return $this->taskService ??= new TaskService(
            $this->getTaskRepository(),
            $this->logger,
        );
    }

    public function getJournalService(): JournalService
    {
        return $this->journalService ??= new JournalService(
            $this->getJournalRepository(),
            $this->logger,
        );
    }

    public function getSiteExtraService(): SiteExtraService
    {
        return $this->siteExtraService ??= new SiteExtraService(
            $this->getSiteExtraRepository(),
        );
    }

    public function getReportService(): ReportService
    {
        return $this->reportService ??= new ReportService(
            $this->getReportRepository(),
            $this->getEventService(),
        );
    }

    public function getSearchService(): SearchService
    {
        return $this->searchService ??= new SearchService(
            $this->getEventRepository(),
            $this->logger,
        );
    }

    public function getRecurrenceService(): RecurrenceService
    {
        return $this->recurrenceService ??= new RecurrenceService();
    }

    public function getAssistantService(): AssistantService
    {
        return $this->assistantService ??= new AssistantService(
            $this->getAssistantRepository(),
        );
    }

    public function getImportService(): ImportService
    {
        return $this->importService ??= new ImportService(
            $this->getEventRepository(),
            new EventMapper(),
            $this->getCategoryRepository(),
            logger: $this->logger,
        );
    }

    public function getExportService(): ExportService
    {
        return $this->exportService ??= new ExportService(
            new EventMapper(),
            $this->logger,
        );
    }

    public function getBookingService(): BookingService
    {
        return $this->bookingService ??= new BookingService(
            $this->getEventService(),
            $this->logger,
        );
    }

    public function getNotificationService(): NotificationService
    {
        return $this->notificationService ??= new NotificationService(
            $this->getEmailProvider(),
            $this->getWebhookProvider(),
            $this->logger,
        );
    }

    public function getRateLimiter(): RateLimiterInterface
    {
        return new PdoRateLimiter($this->getPdo());
    }

    public function getEmailProvider(): EmailProviderInterface
    {
        return new LogEmailProvider($this->logger);
    }

    public function getWebhookProvider(): WebhookProviderInterface
    {
        return new LogWebhookProvider($this->logger);
    }

    public function getAuthService(): AuthServiceInterface
    {
        return $this->authService ??= new DatabaseAuthService(
            $this->getUserRepository(),
            $this->getRateLimiter(),
            logger: $this->logger,
        );
    }

    public function getFeedService(string $baseUrl = ''): FeedService
    {
        return $this->feedService ??= new FeedService(
            $this->getEventService(),
            $baseUrl,
            $this->logger,
        );
    }
}
