<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Share\ShareTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class ShareController
{
    private readonly ShareTokenRepository $tokenRepo;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepo,
        \PDO $pdo,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        $this->tokenRepo = new ShareTokenRepository($pdo);
    }

    /**
     * Test-only factory.
     */
    public static function createForTest(
        ShareTokenRepository $tokenRepo,
        EventRepositoryInterface $eventRepo,
    ): self {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(self::class, 'tokenRepo');
        $ref->setValue($instance, $tokenRepo);
        $ref = new \ReflectionProperty(self::class, 'eventRepo');
        $ref->setValue($instance, $eventRepo);
        $ref = new \ReflectionProperty(self::class, 'clock');
        $ref->setValue($instance, new NativeClock());
        return $instance;
    }

    #[Route('/api/v2/calendars/share', name: 'api_share_create', methods: ['POST'])]
    public function createShareToken(
        Request $request,
        #[CurrentUser] WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $login = $this->getLogin($actorOrUser);
        if ($login === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        /** @var array{expires_at?: string} $body */
        $body = json_decode((string) $request->getContent(), true) ?? [];
        $expiresAt = $body['expires_at'] ?? null;

        $uuid = $this->generateUuid();
        $token = $this->tokenRepo->create($uuid, $login, $expiresAt);

        return ApiResponse::success($token->toArray(), null, 201);
    }

    #[Route('/api/v2/calendars/share', name: 'api_share_list', methods: ['GET'])]
    public function listShareTokens(
        Request $request,
        #[CurrentUser] WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $login = $this->getLogin($actorOrUser);
        if ($login === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $tokens = $this->tokenRepo->findByOwner($login);
        $items = array_map(
            static fn ($t) => $t->toArray(),
            $tokens,
        );

        return ApiResponse::success($items);
    }

    #[Route('/api/v2/calendars/share/{token}', name: 'api_share_delete', methods: ['DELETE'])]
    public function deleteShareToken(
        #[\SensitiveParameter] string $token,
        Request $request,
        #[CurrentUser] WebCalendarUser|User|null $actorOrUser = null,
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

        $start = $this->parseDateParam($startStr);
        $end = $this->parseDateParam($endStr);

        if ($start === null || $end === null) {
            return ApiResponse::error(400, 'Invalid date format. Expected YYYYMMDD.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));

        $dateRange = new DateRange($start, $end);
        $events = $this->eventRepo->findByDateRange($dateRange, null, 'P', [$shareToken->ownerLogin()]);

        $total = \count($events);
        $offset = ($page - 1) * $limit;
        $pageItems = array_values(\array_slice($events, $offset, $limit));

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

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }

    private function parseDateParam(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('Ymd', $value);
        if ($date === false) {
            return null;
        }
        return $date->setTime(0, 0);
    }
}
