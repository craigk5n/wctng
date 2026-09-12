<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\EventInputParser;
use App\Service\ReportService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ReportExportController
{
    /**
     * A spreadsheet reads a cell beginning with one of these as a formula
     * rather than as text. Not every title in a report is the reader's own
     * writing: the public booking route builds one out of an anonymous
     * request, and a category can belong to somebody else.
     */
    private const FORMULA_LEADERS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private readonly ReportService $reportService,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    #[Route('/api/v2/reports/export/{type}', name: 'api_reports_export', methods: ['GET'])]
    public function export(string $type, Request $request, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $now = $this->clock->now();
        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        $start = $startStr === '' ? $now->modify('-30 days')->format('Ymd') : $startStr;
        $end = $endStr === '' ? $now->format('Ymd') : $endStr;

        // Both go into the SQL and into the filename below, which is a quoted
        // value in a response header -- so an unchecked quote ended that
        // string and an unchecked newline was written into the header as it
        // arrived. Checked here, the only things that reach the filename are
        // eight digits and one of three report types.
        if (
            EventInputParser::parseDateParam($start) === null
            || EventInputParser::parseDateParam($end) === null
        ) {
            return ApiResponse::error(400, 'Invalid date format. Expected YYYYMMDD.');
        }

        // Both are YYYYMMDD by now, so they sort as strings.
        if ($start > $end) {
            return ApiResponse::error(400, 'The start date must not be after the end date.');
        }

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
            $lines[] = self::cell($row['category_name']) . ",{$row['count']}";
        }

        return implode("\r\n", $lines);
    }

    private function upcomingCsv(string $login): string
    {
        $data = $this->reportService->upcomingReport($login, 30);
        $lines = ['ID,Title,Date,Type'];
        foreach ($data as $row) {
            $lines[] = "{$row['id']}," . self::cell($row['title']) . ",{$row['start_date']},{$row['type']}";
        }

        return implode("\r\n", $lines);
    }

    /**
     * One CSV field: quoted, with its own quotes doubled, and kept out of the
     * formula parser. The leading apostrophe is how a spreadsheet is told the
     * cell is text; it is not part of the value once the file is open.
     */
    private static function cell(string $value): string
    {
        if ($value !== '' && \in_array($value[0], self::FORMULA_LEADERS, true)) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
