<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\EventInputParser;
use App\Service\PublishedEvents;
use App\Share\ShareTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventScope;

final class ShareController
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepo,
        private readonly ShareTokenRepository $tokenRepo,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    /**
     * Test-only factory — kept so existing tests can inject a stubbed
     * token repo without going through full DI.
     */
    public static function createForTest(
        ShareTokenRepository $tokenRepo,
        EventRepositoryInterface $eventRepo,
    ): self {
        return new self($eventRepo, $tokenRepo, new NativeClock());
    }

    #[Route('/api/v2/calendars/share', name: 'api_share_create', methods: ['POST'])]
    public function createShareToken(
        Request $request,
        #[CurrentUser]
        WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $login = $this->getLogin($actorOrUser);
        if ($login === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        /** @var array{expires_at?: string} $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $expiresAt = $body['expires_at'] ?? null;

        $uuid = Uuid::v4()->toRfc4122();
        $token = $this->tokenRepo->create($uuid, $login, $expiresAt);

        return ApiResponse::success($token->toArray(), null, 201);
    }

    #[Route('/api/v2/calendars/share', name: 'api_share_list', methods: ['GET'])]
    public function listShareTokens(
        Request $request,
        #[CurrentUser]
        WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $login = $this->getLogin($actorOrUser);
        if ($login === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $tokens = $this->tokenRepo->findByOwner($login);
        $items = array_map(
            static fn($t) => $t->toArray(),
            $tokens,
        );

        return ApiResponse::success($items);
    }

    #[Route('/api/v2/calendars/share/{token}', name: 'api_share_delete', methods: ['DELETE'])]
    public function deleteShareToken(
        #[\SensitiveParameter]
        string $token,
        Request $request,
        #[CurrentUser]
        WebCalendarUser|User|null $actorOrUser = null,
    ): Response {
        $login = $this->getLogin($actorOrUser);
        if ($login === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $deleted = $this->tokenRepo->delete($token, $login);
        if (!$deleted) {
            return ApiResponse::error(404, 'Share token not found');
        }

        return ApiResponse::noContent();
    }

    #[Route('/api/v2/public/shared/{token}/events', name: 'api_shared_events', methods: ['GET'])]
    public function sharedEvents(#[\SensitiveParameter] string $token, Request $request): JsonResponse
    {
        $shareToken = $this->tokenRepo->findByToken($token);
        if ($shareToken === null) {
            return ApiResponse::error(404, 'Share link not found');
        }

        if ($shareToken->isExpired($this->clock->now())) {
            return ApiResponse::error(410, 'Share link has expired');
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
        // A share link is a public read path like the others: access 'P' is
        // all the query filters on, so an entry waiting for approval, one that
        // was refused, and one its owner deleted all came back through it.
        $events = PublishedEvents::only(
            $this->eventRepo->findByDateRange($dateRange, EventScope::publicOnly()->limitedToUsers([$shareToken->ownerLogin()])),
        );

        $total = \count($events);
        $offset = ($page - 1) * $limit;
        $pageItems = \array_slice($events, $offset, $limit);

        $items = EventResponseDTO::fromCollection($pageItems);

        return ApiResponse::paginated($items, $total, $page, $limit);
    }

    private function getLogin(WebCalendarUser|User|null $actorOrUser): ?string
    {
        if ($actorOrUser instanceof WebCalendarUser) {
            return $actorOrUser->getUserIdentifier();
        }
        if ($actorOrUser instanceof User) {
            return $actorOrUser->login();
        }
        return null;
    }
}
