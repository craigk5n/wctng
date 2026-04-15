<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ReportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ReportController
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    #[Route('/api/v2/reports/activity', name: 'api_reports_activity', methods: ['GET'])]
    public function activity(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $start = $request->query->getString('start', date('Ymd', strtotime('-30 days')));
        $end = $request->query->getString('end', date('Ymd'));

        return ApiResponse::success($this->reportService->activityReport($user->getUserIdentifier(), $start, $end));
    }

    #[Route('/api/v2/reports/busy-hours', name: 'api_reports_busy_hours', methods: ['GET'])]
    public function busyHours(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $start = $request->query->getString('start', date('Ymd', strtotime('-30 days')));
        $end = $request->query->getString('end', date('Ymd'));

        return ApiResponse::success($this->reportService->busyHoursReport($user->getUserIdentifier(), $start, $end));
    }

    #[Route('/api/v2/reports/categories', name: 'api_reports_categories', methods: ['GET'])]
    public function categories(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $start = $request->query->getString('start', date('Ymd', strtotime('-30 days')));
        $end = $request->query->getString('end', date('Ymd'));

        return ApiResponse::success($this->reportService->categoriesReport($user->getUserIdentifier(), $start, $end));
    }

    #[Route('/api/v2/reports/upcoming', name: 'api_reports_upcoming', methods: ['GET'])]
    public function upcoming(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $days = max(1, min(90, $request->query->getInt('days', 7)));

        return ApiResponse::success($this->reportService->upcomingReport($user->getUserIdentifier(), $days));
    }
}
