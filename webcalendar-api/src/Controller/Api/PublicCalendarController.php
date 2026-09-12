<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\EventInputParser;
use App\Service\PublishedEvents;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class PublicCalendarController
{
    private const PUBLIC_RATE_LIMIT = 30;
    private const PUBLIC_RATE_WINDOW = 60;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepo,
        private readonly UserRepositoryInterface $userRepo,
        private readonly RateLimiterInterface $rateLimiter,
    ) {}

    /**
     * Test-only constructor that accepts individual dependencies.
     */
    public static function createForTest(
        EventRepositoryInterface $eventRepo,
        UserRepositoryInterface $userRepo,
        RateLimiterInterface $rateLimiter,
    ): self {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $setProp = static function (string $name, mixed $value) use ($instance): void {
            $ref = new \ReflectionProperty(self::class, $name);
            $ref->setValue($instance, $value);
        };
        $setProp('eventRepo', $eventRepo);
        $setProp('userRepo', $userRepo);
        $setProp('rateLimiter', $rateLimiter);
        return $instance;
    }

    #[Route('/api/v2/public/calendars', name: 'api_public_calendars_list', methods: ['GET'])]
    public function listPublicCalendars(Request $request): JsonResponse
    {
        if (!$this->checkRateLimit($request)) {
            return ApiResponse::error(429, 'Too many requests');
        }

        $users = $this->userRepo->findAll();
        $publicCalendars = [];

        foreach ($users as $user) {
            if ($this->isPublicCalendarEnabled($user->login())) {
                $publicCalendars[] = [
                    'username' => $user->login(),
                    'display_name' => $user->fullName(),
                ];
            }
        }

        return ApiResponse::success($publicCalendars);
    }

    #[Route('/api/v2/public/calendars/{username}/events', name: 'api_public_calendar_events', methods: ['GET'])]
    public function listPublicEvents(string $username, Request $request): JsonResponse
    {
        if (!$this->checkRateLimit($request)) {
            return ApiResponse::error(429, 'Too many requests');
        }

        $user = $this->userRepo->findByLogin($username);
        if ($user === null || !$this->isPublicCalendarEnabled($username)) {
            return ApiResponse::error(404, 'Public calendar not found');
        }

        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        if ($startStr === '' || $endStr === '') {
            return ApiResponse::error(400, 'Missing required query params: start, end (YYYYMMDD)');
        }

        $start = EventInputParser::parseDateParam($startStr);
        $end = EventInputParser::parseDateParam($endStr);

        if ($start === null || $end === null) {
            return ApiResponse::error(400, 'Invalid date format. Expected YYYYMMDD.');
        }

        // DateRange refuses this pair, and nothing caught it, so a range the
        // wrong way round came back a 500.
        if ($start > $end) {
            return ApiResponse::error(400, 'The start date must not be after the end date.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));

        $dateRange = new DateRange($start, $end);
        $events = PublishedEvents::only(
            $this->eventRepo->findByDateRange($dateRange, EventScope::publicOnly()->limitedToUsers([$username])),
        );

        $total = \count($events);
        $offset = ($page - 1) * $limit;
        $pageItems = \array_slice($events, $offset, $limit);

        $items = EventResponseDTO::fromCollection($pageItems);

        return ApiResponse::paginated($items, $total, $page, $limit);
    }

    /**
     * @param WebCalendarUser|User|null $actorOrUser WebCalendarUser in production, User in tests
     */
    #[Route('/api/v2/admin/users/{login}/public-calendar', name: 'api_admin_toggle_public_calendar', methods: ['PUT'])]
    public function togglePublicCalendar(
        string $login,
        Request $request,
        #[CurrentUser]
        WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $actor = $actorOrUser instanceof WebCalendarUser
            ? $actorOrUser->getCoreUser()
            : $actorOrUser;
        if ($actor === null || !$actor->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $target = $this->userRepo->findByLogin($login);
        if ($target === null) {
            return ApiResponse::error(404, 'User not found');
        }

        /** @var array{enabled?: bool} $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $enabled = ($body['enabled'] ?? false) === true;

        $this->userRepo->savePreference(
            $login,
            new UserPreference('public_calendar_enabled', $enabled ? 'Y' : 'N'),
        );

        return ApiResponse::success([
            'login' => $login,
            'public_calendar_enabled' => $enabled,
        ]);
    }

    private function isPublicCalendarEnabled(string $login): bool
    {
        $prefs = $this->userRepo->getPreferences($login);
        foreach ($prefs as $pref) {
            if ($pref->key() === 'public_calendar_enabled' && $pref->value() === 'Y') {
                return true;
            }
        }
        return false;
    }

    private function checkRateLimit(Request $request): bool
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $identifier = 'public_api:' . $ip;

        if (!$this->rateLimiter->isAllowed($identifier, 'public_api', self::PUBLIC_RATE_LIMIT, self::PUBLIC_RATE_WINDOW)) {
            return false;
        }

        $this->rateLimiter->recordAttempt($identifier, 'public_api', self::PUBLIC_RATE_WINDOW);
        return true;
    }
}
