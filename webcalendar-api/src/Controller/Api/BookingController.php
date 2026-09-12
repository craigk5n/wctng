<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Application\Service\BookingService;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * Public scheduling: when a user is free, and asking for one of those slots.
 *
 * Both routes sit under `^/api/v2/public/`, which security.yaml declares
 * `security: false` -- there is no caller to check, so everything standing
 * between the open internet and a write to somebody's calendar is here.
 * PublicCalendarController and FeedController share that firewall and set the
 * shape: a per-address rate limit, an opt-in preference, and one 404 that
 * cannot tell "no such user" from "that user has not opted in".
 */
final class BookingController
{
    /** Reads, matching PublicCalendarController. */
    private const AVAILABILITY_RATE_LIMIT = 30;
    private const AVAILABILITY_RATE_WINDOW = 60;

    /** Writes get their own, tighter, budget: each one lands on a calendar. */
    private const BOOKING_RATE_LIMIT = 5;
    private const BOOKING_RATE_WINDOW = 300;

    /**
     * The only per-user opt-in the public surface has. Accepting bookings and
     * publishing a calendar are not the same consent, but a user who has not
     * published theirs has certainly not agreed to strangers writing to it.
     */
    private const PUBLIC_PREFERENCE = 'public_calendar_enabled';

    private const DEFAULT_DURATION = 30;
    private const MIN_DURATION = 1;

    /** A working day. Longer, and one request blocks out a week. */
    private const MAX_DURATION = 480;

    /** 'Booking: ' plus this still fits webcal_entry.cal_name, a VARCHAR(80). */
    private const MAX_NAME_LENGTH = 60;
    private const MAX_EMAIL_LENGTH = 75;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly UserService $userService,
        private readonly BookingService $bookingService,
        private readonly UserRepositoryInterface $userRepo,
        private readonly RateLimiterInterface $rateLimiter,
        // Defaulted so the container autowires the real logger while code that
        // constructs this directly keeps working.
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[Route('/api/v2/public/availability/{username}', name: 'api_public_availability', methods: ['GET'])]
    public function availability(string $username, Request $request): JsonResponse
    {
        if (!$this->withinRateLimit($request, self::AVAILABILITY_RATE_LIMIT, self::AVAILABILITY_RATE_WINDOW)) {
            return ApiResponse::error(429, 'Too many requests');
        }

        $user = $this->bookableUser($username);
        if ($user === null) {
            return ApiResponse::error(404, 'User not found');
        }

        $dateStr = $request->query->getString('date', '');
        if ($dateStr === '') {
            return ApiResponse::error(400, 'Missing required query param: date (YYYY-MM-DD)');
        }

        $date = self::readDate('Y-m-d', $dateStr);
        if ($date === null) {
            return ApiResponse::error(400, 'Invalid date format. Expected YYYY-MM-DD.');
        }
        $date = $date->setTime(0, 0);

        $slots = $this->bookingService->getAvailability($user, $date);

        $formatted = [];
        foreach ($slots as $slot) {
            $formatted[] = [
                'start' => $slot->startDate()->format('H:i'),
                'end' => $slot->endDate()->format('H:i'),
            ];
        }

        return ApiResponse::success([
            'date' => $dateStr,
            'username' => $username,
            'slots' => $formatted,
        ]);
    }

    #[Route('/api/v2/public/book/{username}', name: 'api_public_book', methods: ['POST'])]
    public function book(string $username, Request $request): JsonResponse
    {
        if (!$this->withinRateLimit($request, self::BOOKING_RATE_LIMIT, self::BOOKING_RATE_WINDOW)) {
            return ApiResponse::error(429, 'Too many requests');
        }

        $user = $this->bookableUser($username);
        if ($user === null) {
            return ApiResponse::error(404, 'User not found');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        $name = self::readString($data, 'name');
        $email = self::readString($data, 'email');
        $dateStr = self::readString($data, 'date');
        $timeStr = self::readString($data, 'time');

        if ($name === '' || $email === '' || $dateStr === '' || $timeStr === '') {
            return ApiResponse::error(400, 'Missing required fields: name, email, date, time');
        }

        // Both go into an event on someone else's calendar, and the name into
        // a VARCHAR(80) behind a nine-character prefix.
        if (mb_strlen($name) > self::MAX_NAME_LENGTH || mb_strlen($email) > self::MAX_EMAIL_LENGTH) {
            return ApiResponse::error(400, 'Name or email is too long');
        }

        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            return ApiResponse::error(400, 'Invalid email address');
        }

        // BookingService builds the event's description itself -- "Booked by
        // {name} ({email})" -- and, unlike every controller write path, does
        // not run it through DescriptionSanitizer. The SEO event page emits
        // the event's name into its <title> and <h1>. A booking name has no
        // reason to carry markup, so it does not get to.
        if (str_contains($name, '<') || str_contains($name, '>')) {
            return ApiResponse::error(400, 'Name must not contain markup');
        }

        $stamp = "{$dateStr} {$timeStr}";
        $start = self::readDate('Y-m-d H:i', $stamp);
        if ($start === null) {
            return ApiResponse::error(400, 'Invalid date/time format');
        }

        $duration = isset($data['duration']) && \is_int($data['duration'])
            ? $data['duration']
            : self::DEFAULT_DURATION;

        if ($duration < self::MIN_DURATION || $duration > self::MAX_DURATION) {
            return ApiResponse::error(400, sprintf(
                'Duration must be between %d and %d minutes.',
                self::MIN_DURATION,
                self::MAX_DURATION,
            ));
        }

        try {
            $this->bookingService->book($user, $name, $email, $start, $duration);
        } catch (\Throwable $e) {
            // The caller is anonymous, so anything the storage layer says
            // about itself would go straight to the open internet.
            $this->logger->error('Public booking failed', [
                'user' => $username,
                'exception' => $e->getMessage(),
            ]);

            return ApiResponse::error(400, 'Booking could not be completed');
        }

        return ApiResponse::success([
            'message' => 'Booking confirmed',
            'date' => $dateStr,
            'time' => $timeStr,
            'duration' => $duration,
        ], null, 201);
    }

    /**
     * The user this request may act on, or null -- which both routes report as
     * "User not found" whatever the reason, so the route cannot be walked as a
     * list of who has an account here.
     */
    private function bookableUser(string $username): ?User
    {
        $user = $this->userService->getUserByLogin($username);

        if ($user === null || !$user->isEnabled() || !$this->acceptsPublicBookings($username)) {
            return null;
        }

        return $user;
    }

    private function acceptsPublicBookings(string $login): bool
    {
        foreach ($this->userRepo->getPreferences($login) as $preference) {
            if ($preference->key() === self::PUBLIC_PREFERENCE && $preference->value() === 'Y') {
                return true;
            }
        }

        return false;
    }

    private function withinRateLimit(Request $request, int $maxAttempts, int $window): bool
    {
        $identifier = 'public_booking:' . ($request->getClientIp() ?? 'unknown');

        if (!$this->rateLimiter->isAllowed($identifier, 'public_booking', $maxAttempts, $window)) {
            return false;
        }

        $this->rateLimiter->recordAttempt($identifier, 'public_booking', $window);

        return true;
    }

    /**
     * createFromFormat rolls an impossible date forward rather than refusing
     * it -- 2026-02-31 comes back as the 3rd of March, and 25:00 as the next
     * morning. The round trip is what separates a date from a date-shaped
     * string.
     */
    private static function readDate(string $format, string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat($format, $value);

        if ($parsed === false || $parsed->format($format) !== $value) {
            return null;
        }

        return $parsed;
    }

    /** @param array<string, mixed> $data */
    private static function readString(array $data, string $key): string
    {
        return isset($data[$key]) && \is_string($data[$key]) ? trim($data[$key]) : '';
    }
}
