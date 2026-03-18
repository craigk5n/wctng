<?php

declare(strict_types=1);

namespace App\Controller\Seo;

use App\Service\CoreServiceFactory;
use App\Service\CustomHtmlProvider;
use App\Service\SeoEligibilityService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Server-side rendered event index/archive pages for search engine crawlers.
 */
final class EventIndexController
{
    private const PER_PAGE = 20;

    private readonly SeoEligibilityService $seoService;

    public function __construct(
        private readonly CoreServiceFactory $factory,
    ) {
        $this->seoService = new SeoEligibilityService($factory);
    }

    #[Route('/public/{username}/events', name: 'seo_event_index', methods: ['GET'])]
    public function index(string $username, Request $request): Response
    {
        $seoStatus = $this->seoService->getUserSeoStatus($username);
        if (!$seoStatus['eligible']) {
            return new Response('Not Found', 404);
        }

        $user = $this->factory->getUserService()->getUserByLogin($username);
        if ($user === null) {
            return new Response('Not Found', 404);
        }

        $displayName = $user->fullName();
        $noindex = $seoStatus['noindex'] ? '<meta name="robots" content="noindex">' : '';

        // Determine date range
        $monthParam = $request->query->getString('month', '');
        $page = max(1, $request->query->getInt('page', 1));

        if ($monthParam !== '' && preg_match('/^(\d{4})-(\d{2})$/', $monthParam, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $start = new \DateTimeImmutable("{$year}-{$month}-01");
            $end = $start->modify('last day of this month')->setTime(23, 59, 59);
            $pageTitle = $start->format('F Y');
            $isMonthView = true;
        } else {
            $start = new \DateTimeImmutable('today');
            $end = $start->modify('+1 year');
            $pageTitle = 'Upcoming Events';
            $isMonthView = false;
        }

        $range = new DateRange($start, $end);
        $allEvents = $this->factory->getEventRepository()->findByDateRange($range, null, 'P', [$username]);

        // Sort by date
        usort($allEvents, fn ($a, $b) => $a->start() <=> $b->start());

        $total = \count($allEvents);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $offset = ($page - 1) * self::PER_PAGE;
        $pageEvents = \array_slice($allEvents, $offset, self::PER_PAGE);

        // Build event list HTML
        $eventsHtml = '';
        foreach ($pageEvents as $event) {
            if ($event->access() !== AccessLevel::PUBLIC) {
                continue;
            }
            $id = $event->id()->value();
            $name = htmlspecialchars($event->name(), \ENT_QUOTES, 'UTF-8');
            $date = $event->start()->format('l, F j, Y');
            $time = $event->isAllDay() ? 'All day' : $event->start()->format('g:i A');
            $loc = htmlspecialchars($event->location(), \ENT_QUOTES, 'UTF-8');
            $locHtml = $loc !== '' ? "<address>📍 {$loc}</address>" : '';

            $eventsHtml .= <<<ITEM
            <article class="event-item">
                <a href="/public/{$username}/event/{$id}">
                    <h2>{$name}</h2>
                    <time datetime="{$event->start()->format('c')}">{$date} · {$time}</time>
                    {$locHtml}
                </a>
            </article>
ITEM;
        }

        if ($eventsHtml === '') {
            $eventsHtml = '<p class="empty">No events found for this period.</p>';
        }

        // Pagination
        $basePath = "/public/{$username}/events" . ($isMonthView ? "?month={$monthParam}" : '');
        $sep = $isMonthView ? '&' : '?';
        $paginationHtml = '';
        $linkTags = '';

        if ($totalPages > 1) {
            $prevLink = $page > 1 ? "{$basePath}{$sep}page=" . ($page - 1) : '';
            $nextLink = $page < $totalPages ? "{$basePath}{$sep}page=" . ($page + 1) : '';

            if ($prevLink !== '') {
                $linkTags .= "<link rel=\"prev\" href=\"{$prevLink}\">\n";
            }
            if ($nextLink !== '') {
                $linkTags .= "<link rel=\"next\" href=\"{$nextLink}\">\n";
            }

            $paginationHtml = '<nav class="pagination">';
            if ($prevLink !== '') {
                $paginationHtml .= "<a href=\"{$prevLink}\">← Previous</a>";
            }
            $paginationHtml .= " <span>Page {$page} of {$totalPages}</span> ";
            if ($nextLink !== '') {
                $paginationHtml .= "<a href=\"{$nextLink}\">Next →</a>";
            }
            $paginationHtml .= '</nav>';
        }

        $canonical = "/public/{$username}/events" . ($isMonthView ? "?month={$monthParam}" : '') . ($page > 1 ? "{$sep}page={$page}" : '');
        $metaDesc = htmlspecialchars("{$displayName}'s {$pageTitle} — {$total} events", \ENT_QUOTES, 'UTF-8');

        // Custom HTML/CSS
        $customHtml = new CustomHtmlProvider($this->factory);
        $customCssTag = $customHtml->getCssStyleTag();
        $customHeader = $customHtml->getHeaderHtml();
        $customTrailer = $customHtml->getTrailerHtml();

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$pageTitle} — {$displayName}'s Calendar</title>
    <meta name="description" content="{$metaDesc}">
    <meta property="og:title" content="{$pageTitle} — {$displayName}'s Calendar">
    <meta property="og:description" content="{$metaDesc}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{$canonical}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{$pageTitle} — {$displayName}'s Calendar">
    <meta name="twitter:description" content="{$metaDesc}">
    <link rel="canonical" href="{$canonical}">
    {$linkTags}
    {$noindex}
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1a1a1a; background: #f8f9fa; line-height: 1.6; }
        .container { max-width: 680px; margin: 0 auto; padding: 2rem 1rem; }
        .breadcrumb { font-size: 0.875rem; color: #666; margin-bottom: 1.5rem; }
        .breadcrumb a { color: #3788d8; text-decoration: none; }
        h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
        .subtitle { color: #666; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .event-item { background: white; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
        .event-item a { text-decoration: none; color: inherit; display: block; }
        .event-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .event-item h2 { font-size: 1.1rem; color: #3788d8; margin-bottom: 0.25rem; }
        .event-item time { font-size: 0.85rem; color: #555; }
        .event-item address { font-size: 0.85rem; color: #777; font-style: normal; margin-top: 0.25rem; }
        .pagination { text-align: center; margin-top: 2rem; font-size: 0.9rem; }
        .pagination a { color: #3788d8; text-decoration: none; margin: 0 0.5rem; }
        .pagination span { color: #999; }
        .empty { text-align: center; color: #999; padding: 2rem; }
        .footer { margin-top: 2rem; text-align: center; font-size: 0.8rem; color: #999; }
        .footer a { color: #3788d8; text-decoration: none; }
        @media (max-width: 640px) { .container { padding: 1rem; } h1 { font-size: 1.4rem; } }
    </style>
    {$customCssTag}
</head>
<body>
    {$customHeader}
    <div class="container">
        <nav class="breadcrumb">
            <a href="/public/{$username}">{$displayName}'s Calendar</a> › Events
        </nav>
        <h1>{$pageTitle}</h1>
        <p class="subtitle">{$displayName}'s Calendar — {$total} events</p>
        {$eventsHtml}
        {$paginationHtml}
        <div class="footer">
            <a href="/public/{$username}">← Back to Calendar</a>
        </div>
    </div>
    {$customTrailer}
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
