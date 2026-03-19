<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ReportService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ReportExportController
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {
    }

    #[Route('/api/v2/reports/export/{type}', name: 'api_reports_export', methods: ['GET'])]
    public function export(string $type, Request $request, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $start = $request->query->getString('start', date('Ymd', time() - 86400 * 30));
        $end = $request->query->getString('end', date('Ymd'));
        $login = $user->getUserIdentifier();

        $csv = match ($type) {
            'activity' => $this->activityCsv($login, $start, $end),
            'categories' => $this->categoriesCsv($login, $start, $end),
            'upcoming' => $this->upcomingCsv($login),
            default => null,
        };

        if ($csv === null) {
            return ApiResponse::error(400, 'Invalid report type. Valid: activity, categories, upcoming');
        }

        $filename = "report-{$type}-{$start}-to-{$end}.csv";

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        return $response;
    }

    private function activityCsv(string $login, string $start, string $end): string
    {
        $data = $this->reportService->activityReport($login, $start, $end);
        $lines = ['Date,Event Count'];
        foreach ($data as $row) {
            $lines[] = "{$row['date']},{$row['count']}";
        }

        return implode("\r\n", $lines);
    }

    private function categoriesCsv(string $login, string $start, string $end): string
    {
        $data = $this->reportService->categoriesReport($login, $start, $end);
        $lines = ['Category,Event Count'];
        foreach ($data as $row) {
            $name = str_replace('"', '""', $row['category_name']);
            $lines[] = "\"{$name}\",{$row['count']}";
        }

        return implode("\r\n", $lines);
    }

    private function upcomingCsv(string $login): string
    {
        $data = $this->reportService->upcomingReport($login, 30);
        $lines = ['ID,Title,Date,Type'];
        foreach ($data as $row) {
            $title = str_replace('"', '""', $row['title']);
            $lines[] = "{$row['id']},\"{$title}\",{$row['start_date']},{$row['type']}";
        }

        return implode("\r\n", $lines);
    }
}
