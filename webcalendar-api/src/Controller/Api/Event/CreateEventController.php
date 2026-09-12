<?php

declare(strict_types=1);

namespace App\Controller\Api\Event;

use App\DTO\EventRequestDTO;
use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ConflictDetectionService;
use App\Service\DescriptionSanitizer;
use App\Service\EventInputParser;
use App\Service\EventNotificationService;
use App\Service\EventUserPolicy;
use App\Service\ExtParticipantRepository;
use App\Service\ExtParticipantValidator;
use App\Service\GeocodingService;
use App\Service\GeoRepository;
use App\Service\MercurePublisher;
use App\Webhook\WebhookDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventScope;

final class CreateEventController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly CategoryService $categoryService,
        private readonly DescriptionSanitizer $descriptionSanitizer,
        private readonly ConflictDetectionService $conflictService,
        private readonly EventUserPolicy $userPolicy,
        private readonly ExtParticipantRepository $extParticipants,
        private readonly ExtParticipantValidator $extParticipantValidator,
        private readonly ConfigService $configService,
        private readonly GeoRepository $geoRepository,
        private readonly ?GeocodingService $geocodingService,
        private readonly EventNotificationService $notifications,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly MercurePublisher $mercure,
        private readonly ActivityLogService $activityLogService,
        // Defaulted so the container autowires the real logger while code
        // that constructs this directly keeps working.
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Route('/api/v2/events', name: 'api_events_create', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $decoded = json_decode($request->getContent(), true);

        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        if (isset($data['description']) && \is_string($data['description'])) {
            $data['description'] = $this->descriptionSanitizer->sanitize($data['description']);
        }

        try {
            $event = EventRequestDTO::toEntity($data, $user->getUserIdentifier());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }

        try {
            $extParticipants = $this->extParticipantValidator->parse($data['ext_participants'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }
        $isExtParticipantsDisabled = false;
        try {
            $value = $this->configService->getSetting('DISABLE_EXT_PARTICIPANTS_FIELD', 'N');
            $isExtParticipantsDisabled = $value === 'Y';
        } catch (\Throwable $e) {
            $this->logger->debug('configService->getSetting() failed', ['exception' => $e->getMessage()]);
        }
        if ($extParticipants !== [] && $isExtParticipantsDisabled) {
            return ApiResponse::error(403, 'External participants are disabled by the administrator');
        }

        $coreUser = $user->getCoreUser();

        // Check for conflicts
        $conflictMode = $this->userPolicy->getConflictMode($user->getUserIdentifier());
        $conflictList = [];
        if ($conflictMode !== 'off') {
            $dateRange = new DateRange($event->start()->modify('-1 day'), $event->end()->modify('+1 day'));
            $existing = $this->eventService
                ->getEventsInDateRange($dateRange, EventScope::forUser($coreUser))->all();
            $conflicts = $this->conflictService->findConflicts($event, $existing);
            $conflictList = $this->conflictService->formatConflicts($conflicts);

            if (\count($conflictList) > 0 && $conflictMode === 'block') {
                return ApiResponse::error(409, 'Event conflicts with existing events', array_map(
                    static fn(array $c): string => sprintf('%s (%s)', $c['title'], $c['start']),
                    $conflictList,
                ));
            }
        }

        // Check if user requires event approval
        if ($this->userPolicy->requiresApproval($user->getUserIdentifier())) {
            $event = new \WebCalendar\Core\Domain\Entity\Event(
                id: $event->id(),
                uid: $event->uid(),
                name: $event->name(),
                description: $event->description(),
                location: $event->location(),
                start: $event->start(),
                duration: $event->duration(),
                createdBy: $event->createdBy(),
                type: $event->type(),
                access: $event->access(),
                status: 'needs_approval',
                allDay: $event->isAllDay(),
            );
        }

        $this->eventService->createEvent($event, $coreUser);

        // Retrieve the created event to get the assigned ID
        // The core service saves the event; we need the generated ID.
        // Since Event is immutable with id=0, we search for it by UID.
        $created = $this->eventRepository->findByUid($event->uid());

        if ($created === null) {
            return ApiResponse::error(500, 'Event created but could not be retrieved');
        }

        // Assign categories if provided
        $categoryIds = EventInputParser::parseCategoryIds($data);
        if (\count($categoryIds) > 0) {
            $this->categoryService->assignToEvent(
                $created->id(),
                $user->getUserIdentifier(),
                $categoryIds,
                $coreUser,
            );
        }

        // Persist external (email-only) participants if provided
        if ($extParticipants !== []) {
            $this->extParticipants->saveForEvent($created->id()->value(), $extParticipants);
        }

        // Geocode location in background
        $geo = null;
        try {
            if ($this->geocodingService !== null) {
                $this->geocodingService->geocodeEvent($created->id()->value(), $created->location());
                $geo = $this->geoRepository->getCoordinates($created->id()->value());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('geocodingService->geocodeEvent() failed', ['exception' => $e->getMessage()]);
        }

        $responseData = EventResponseDTO::fromEntity($created, $categoryIds, $geo);
        $responseData['ext_participants'] = $extParticipants;

        try {
            $this->mercure->publishEventCreated($created->id()->value(), $responseData);
        } catch (\Throwable $e) {
            $this->logger->warning('mercure->publishEventCreated() failed', ['exception' => $e->getMessage()]);
        }

        try {
            $this->webhookDispatcher->dispatch('event.created', $responseData);
        } catch (\Throwable $e) {
            $this->logger->warning('webhookDispatcher->dispatch() failed', ['exception' => $e->getMessage()]);
        }

        // Send invitations to external (email-only) participants
        try {
            $this->notifications->notifyExtParticipantsAdded($responseData, $extParticipants);
        } catch (\Throwable $e) {
            $this->logger->warning('notifications->notifyExtParticipantsAdded() failed', ['exception' => $e->getMessage()]);
        }

        // Log activity
        try {
            $this->activityLogService->log(
                $created->id()->value(),
                $user->getUserIdentifier(),
                null,
                ActivityLogType::CREATE,
                'Created event: ' . $created->name(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('activityLogService->log() failed', ['exception' => $e->getMessage()]);
        }

        $meta = \count($conflictList) > 0 ? ['conflicts' => $conflictList] : null;
        return ApiResponse::success($responseData, $meta, Response::HTTP_CREATED);
    }
}
