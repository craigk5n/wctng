<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
 */
final class CoreServiceFactory
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
    private ?BookingService $bookingService = null;
    private ?NotificationService $notificationService = null;
    private ?DatabaseAuthService $authService = null;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $appSecret,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    // --- Repositories (lazily created) ---

    public function getEventRepository(): PdoEventRepository
    {
        return $this->eventRepository ??= new PdoEventRepository($this->pdo);
    }

    public function getUserRepository(): PdoUserRepository
    {
        return $this->userRepository ??= new PdoUserRepository($this->pdo);
    }

    public function getCategoryRepository(): PdoCategoryRepository
    {
        return $this->categoryRepository ??= new PdoCategoryRepository($this->pdo);
    }

    public function getTokenRepository(): PdoTokenRepository
    {
        return $this->tokenRepository ??= new PdoTokenRepository($this->pdo);
    }

    public function getActivityLogRepository(): PdoActivityLogRepository
    {
        return $this->activityLogRepository ??= new PdoActivityLogRepository($this->pdo);
    }

    public function getGroupRepository(): PdoGroupRepository
    {
        return $this->groupRepository ??= new PdoGroupRepository($this->pdo);
    }

    public function getLayerRepository(): PdoLayerRepository
    {
        return $this->layerRepository ??= new PdoLayerRepository($this->pdo);
    }

    public function getPermissionRepository(): PdoPermissionRepository
    {
        return $this->permissionRepository ??= new PdoPermissionRepository($this->pdo);
    }

    public function getConfigRepository(): PdoConfigRepository
    {
        return $this->configRepository ??= new PdoConfigRepository($this->pdo);
    }

    public function getBlobRepository(): PdoBlobRepository
    {
        return $this->blobRepository ??= new PdoBlobRepository($this->pdo);
    }

    public function getResourceRepository(): PdoResourceRepository
    {
        return $this->resourceRepository ??= new PdoResourceRepository($this->pdo);
    }

    public function getTemplateRepository(): PdoTemplateRepository
    {
        return $this->templateRepository ??= new PdoTemplateRepository($this->pdo);
    }

    public function getViewRepository(): PdoViewRepository
    {
        return $this->viewRepository ??= new PdoViewRepository($this->pdo);
    }

    public function getTaskRepository(): PdoTaskRepository
    {
        return $this->taskRepository ??= new PdoTaskRepository($this->pdo);
    }

    public function getJournalRepository(): PdoJournalRepository
    {
        return $this->journalRepository ??= new PdoJournalRepository($this->pdo);
    }

    public function getSiteExtraRepository(): PdoSiteExtraRepository
    {
        return $this->siteExtraRepository ??= new PdoSiteExtraRepository($this->pdo);
    }

    public function getReportRepository(): PdoReportRepository
    {
        return $this->reportRepository ??= new PdoReportRepository($this->pdo);
    }

    public function getAssistantRepository(): PdoAssistantRepository
    {
        return $this->assistantRepository ??= new PdoAssistantRepository($this->pdo);
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
        return new PdoRateLimiter($this->pdo);
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
}
