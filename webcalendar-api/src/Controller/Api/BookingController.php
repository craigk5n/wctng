<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Application\Service\BookingService;
use WebCalendar\Core\Application\Service\UserService;

final class BookingController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly BookingService $bookingService,
    ) {}

    #[Route('/api/v2/public/availability/{username}', name: 'api_public_availability', methods: ['GET'])]
    public function availability(string $username, Request $request): JsonResponse
    {
        $user = $this->userService->getUserByLogin($username);
        if ($user === null) {
            return ApiResponse::error(404, 'User not found');
        }

        $dateStr = $request->query->getString('date', '');
        if ($dateStr === '') {
            return ApiResponse::error(400, 'Missing required query param: date (YYYY-MM-DD)');
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if ($date === false) {
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
        $user = $this->userService->getUserByLogin($username);
        if ($user === null) {
            return ApiResponse::error(404, 'User not found');
        }

        /** @var array{name?: string, email?: string, date?: string, time?: string, duration?: int, description?: string} $data */
        $data = json_decode($request->getContent(), true) ?? [];

        $name = $data['name'] ?? '';
        $email = $data['email'] ?? '';
        $dateStr = $data['date'] ?? '';
        $timeStr = $data['time'] ?? '';

        if ($name === '' || $email === '' || $dateStr === '' || $timeStr === '') {
            return ApiResponse::error(400, 'Missing required fields: name, email, date, time');
        }

        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "$dateStr $timeStr");
        if ($start === false) {
            return ApiResponse::error(400, 'Invalid date/time format');
        }

        $duration = $data['duration'] ?? 30;

        try {
            $this->bookingService->book($user, $name, $email, $start, $duration);
        } catch (\Throwable $e) {
            return ApiResponse::error(400, 'Booking failed: ' . $e->getMessage());
        }

        return ApiResponse::success([
            'message' => 'Booking confirmed',
            'date' => $dateStr,
            'time' => $timeStr,
            'duration' => $duration,
        ], null, 201);
    }
}
