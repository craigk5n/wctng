<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class LocationController
{
    /**
     * Joined to the date to make the preference key. cal_setting is a
     * VARCHAR(60) and part of the primary key, so the pair has to fit: the
     * prefix and a YYYY-MM-DD date come to nineteen characters, and an
     * unchecked date was free to overflow the column and take the write down
     * with a PDOException.
     */
    private const PREFIX = 'location_';

    private const LOCATIONS = ['office', 'remote', 'traveling'];
    private const DEFAULT_LOCATION = 'office';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    #[Route('/api/v2/users/{login}/location', name: 'api_user_location_get', methods: ['GET'])]
    public function getLocation(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $date = $request->query->getString('date', '');
        if ($date === '') {
            $date = $this->clock->now()->format('Y-m-d');
        }

        if (!self::isDate($date)) {
            return ApiResponse::error(400, 'Invalid date format. Expected YYYY-MM-DD.');
        }

        $prefKey = self::PREFIX . $date;

        $prefs = $this->userRepository->getPreferences($login);
        $location = 'office'; // default
        foreach ($prefs as $pref) {
            if ($pref->key() === $prefKey) {
                $location = $pref->value();
                break;
            }
        }

        return ApiResponse::success([
            'user' => $login,
            'date' => $date,
            'location' => $location,
        ]);
    }

    #[Route('/api/v2/users/{login}/location', name: 'api_user_location_set', methods: ['PUT'])]
    public function setLocation(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        if ($login !== $user->getUserIdentifier() && !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Can only set your own location');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        // Not defaulted the way an optional field is: the date chooses which
        // day is being written, so one that arrived wrong has to be refused
        // rather than quietly turned into today.
        $date = $data['date'] ?? '';
        if ($date === '') {
            $date = $this->clock->now()->format('Y-m-d');
        }

        if (!\is_string($date) || !self::isDate($date)) {
            return ApiResponse::error(400, 'Invalid date format. Expected YYYY-MM-DD.');
        }

        $location = $data['location'] ?? self::DEFAULT_LOCATION;

        if (!\in_array($location, self::LOCATIONS, true)) {
            return ApiResponse::error(400, 'Location must be: ' . implode(', ', self::LOCATIONS));
        }

        $prefKey = self::PREFIX . $date;
        $this->userRepository->savePreference(
            $login,
            new UserPreference($prefKey, $location),
        );

        return ApiResponse::success(['user' => $login, 'date' => $date, 'location' => $location]);
    }

    /**
     * createFromFormat rolls an impossible date forward rather than refusing
     * it, and here the string itself becomes half of a storage key -- so a
     * date-shaped string is not enough, it has to be the date it says it is.
     */
    private static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
