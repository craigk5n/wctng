<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class LocationController
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    #[Route('/api/v2/users/{login}/location', name: 'api_user_location_get', methods: ['GET'])]
    public function getLocation(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $date = $request->query->getString('date', date('Y-m-d'));
        $prefKey = 'location_' . $date;

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

        /** @var array{date?: string, location?: string} $data */
        $data = json_decode($request->getContent(), true) ?? [];

        $date = $data['date'] ?? date('Y-m-d');
        $location = $data['location'] ?? 'office';

        if (!\in_array($location, ['office', 'remote', 'traveling'], true)) {
            return ApiResponse::error(400, 'Location must be: office, remote, or traveling');
        }

        $prefKey = 'location_' . $date;
        $this->userRepository->savePreference(
            $login,
            new UserPreference($prefKey, $location),
        );

        return ApiResponse::success(['user' => $login, 'date' => $date, 'location' => $location]);
    }
}
