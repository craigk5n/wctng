<?php

declare(strict_types=1);

namespace App\Controller\Seo;

use App\Service\CoreServiceFactory;
use App\Service\DescriptionSanitizer;
use App\Service\JsonLdGenerator;
use App\Service\SeoEligibilityService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Domain\ValueObject\EventId;

/**
 * Server-side rendered event detail pages for search engine crawlers.
 * Returns full HTML — no JavaScript required.
 */
final class EventPageController
{
    private readonly SeoEligibilityService $seoService;
    private readonly DescriptionSanitizer $sanitizer;
    private readonly JsonLdGenerator $jsonLd;

    public function __construct(
        private readonly CoreServiceFactory $factory,
    ) {
        $this->seoService = new SeoEligibilityService($factory);
        $this->sanitizer = new DescriptionSanitizer();
        $this->jsonLd = new JsonLdGenerator();
    }

    #[Route('/public/{username}/event/{id}', name: 'seo_event_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(string $username, int $id): Response
    {
        // Check SEO eligibility
        $seoStatus = $this->seoService->getUserSeoStatus($username);
        if (!$seoStatus['eligible']) {
            return new Response('Not Found', 404);
        }

        // Get user
        $user = $this->factory->getUserService()->getUserByLogin($username);
        if ($user === null) {
            return new Response('Not Found', 404);
        }

        // Get event
        $event = $this->factory->getEventService()->getEventById(new EventId($id));
        if ($event === null || $event->createdBy() !== $username) {
            return new Response('Not Found', 404);
        }

        // Only show public access events
        if ($event->access()->value !== 'P') {
            return new Response('Not Found', 404);
        }

        $displayName = $user->fullName();
        $title = $event->name();
        $description = $this->sanitizer->sanitize($event->description());
        $location = htmlspecialchars($event->location(), \ENT_QUOTES, 'UTF-8');
        $dateFormatted = $event->start()->format('l, F j, Y');
        $timeFormatted = $event->isAllDay() ? 'All day' : $event->start()->format('g:i A') . ' — ' . $event->end()->format('g:i A');
        $metaDescription = htmlspecialchars(
            sprintf('%s — %s%s', $dateFormatted, $timeFormatted, $location ? " at {$event->location()}" : ''),
            \ENT_QUOTES,
            'UTF-8',
        );

        $noindex = $seoStatus['noindex'] ? '<meta name="robots" content="noindex">' : '';
        $rruleHuman = '';
        if ($event->recurrence()->rule() !== null) {
            $rruleHuman = '<p class="meta">🔁 Repeating event</p>';
        }

        $descriptionHtml = $description !== '' ? "<div class=\"description\">{$description}</div>" : '';
        $locationHtml = $location !== '' ? "<p class=\"meta\">📍 {$location}</p>" : '';

        // Generate JSON-LD structured data
        $canonicalUrl = "/public/{$username}/event/{$id}";
        $jsonLdBlock = $seoStatus['noindex'] ? '' : $this->jsonLd->generateEventJsonLd($event, $user, $canonicalUrl);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — {$displayName}'s Calendar</title>
    <meta name="description" content="{$metaDescription}">
    {$noindex}
    {$jsonLdBlock}
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1a1a1a; background: #f8f9fa; line-height: 1.6; }
        .container { max-width: 680px; margin: 0 auto; padding: 2rem 1rem; }
        .breadcrumb { font-size: 0.875rem; color: #666; margin-bottom: 1.5rem; }
        .breadcrumb a { color: #3788d8; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .card { background: white; border-radius: 12px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { font-size: 1.75rem; margin-bottom: 1rem; }
        .meta { color: #555; margin: 0.5rem 0; font-size: 0.95rem; }
        .description { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #eee; }
        .description p { margin: 0.5rem 0; }
        .footer { margin-top: 2rem; text-align: center; font-size: 0.8rem; color: #999; }
        .footer a { color: #3788d8; text-decoration: none; }
        @media (max-width: 640px) { .container { padding: 1rem; } h1 { font-size: 1.4rem; } }
    </style>
</head>
<body>
    <div class="container">
        <nav class="breadcrumb">
            <a href="/public/{$username}">{$displayName}'s Calendar</a> › Event
        </nav>
        <article class="card">
            <h1>{$title}</h1>
            <p class="meta">📅 {$dateFormatted}</p>
            <p class="meta">🕐 {$timeFormatted}</p>
            {$locationHtml}
            {$rruleHuman}
            {$descriptionHtml}
        </article>
        <div class="footer">
            <a href="/public/{$username}">← Back to {$displayName}'s Calendar</a>
        </div>
    </div>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
