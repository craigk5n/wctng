<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Subscription\SubscriptionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SubscriptionController
{
    public function __construct(
        private readonly SubscriptionRepository $repo,
    ) {}

    #[Route('/api/v2/calendars/subscriptions', name: 'api_subscriptions_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $subs = $this->repo->findByUser($user->getUserIdentifier());
        $items = array_map(fn($s) => $s->toArray(), $subs);

        return ApiResponse::success($items);
    }

    #[Route('/api/v2/calendars/subscribe', name: 'api_subscriptions_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        /** @var array{url?: string, name?: string, color?: string, refresh_interval?: int} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

        $url = $data['url'] ?? '';
        $name = $data['name'] ?? '';

        if ($url === '' || $name === '') {
            return ApiResponse::error(400, 'Missing required fields: url, name');
        }

        $color = $data['color'] ?? '#3788d8';
        $interval = $data['refresh_interval'] ?? 3600;

        $sub = $this->repo->create($user->getUserIdentifier(), $url, $name, $color, $interval);

        return ApiResponse::success($sub->toArray(), null, 201);
    }

    #[Route('/api/v2/calendars/subscriptions/{id}', name: 'api_subscriptions_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $deleted = $this->repo->delete($id, $user->getUserIdentifier());
        if (!$deleted) {
            return ApiResponse::error(404, 'Subscription not found');
        }

        return ApiResponse::noContent();
    }

    /** Fetch events from a subscription (on-demand) */
    #[Route('/api/v2/calendars/subscriptions/{id}/events', name: 'api_subscriptions_events', methods: ['GET'])]
    public function events(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $sub = $this->repo->findById($id);
        if ($sub === null || $sub->userLogin() !== $user->getUserIdentifier()) {
            return ApiResponse::error(404, 'Subscription not found');
        }

        // Fetch ICS content
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => $sub->etag() !== null
                    ? "If-None-Match: {$sub->etag()}\r\n"
                    : '',
            ],
        ]);

        $content = @file_get_contents($sub->url(), false, $context);

        // Check for 304 Not Modified
        if ($content === false) {
            return ApiResponse::success([
                'subscription_id' => $id,
                'events' => [],
                'cached' => true,
            ]);
        }

        // Extract ETag from response headers (set by file_get_contents)
        $etag = null;
        foreach ($http_response_header as $header) {
            if (stripos($header, 'ETag:') === 0) {
                $etag = trim(substr($header, 5));
            }
        }

        $this->repo->updateFetchStatus($id, $etag);

        // Parse ICS
        $events = $this->parseIcs($content, $sub->name(), $sub->color());

        return ApiResponse::success([
            'subscription_id' => $id,
            'events' => $events,
            'cached' => false,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseIcs(string $icsContent, string $sourceName, string $color): array
    {
        $events = [];

        // Simple VEVENT parser
        if (preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $icsContent, $matches)) {
            foreach ($matches[1] as $veventBlock) {
                $event = [
                    'title' => '',
                    'start' => '',
                    'end' => '',
                    'all_day' => false,
                    'description' => '',
                    'location' => '',
                    'source' => $sourceName,
                    'color' => $color,
                    'read_only' => true,
                ];

                if (preg_match('/SUMMARY[^:]*:(.+)/m', $veventBlock, $m)) {
                    $event['title'] = trim($m[1]);
                }
                if (preg_match('/DESCRIPTION[^:]*:(.+)/m', $veventBlock, $m)) {
                    $event['description'] = trim($m[1]);
                }
                if (preg_match('/LOCATION[^:]*:(.+)/m', $veventBlock, $m)) {
                    $event['location'] = trim($m[1]);
                }
                if (preg_match('/DTSTART;VALUE=DATE:(\d{8})/m', $veventBlock, $m)) {
                    $event['start'] = $m[1];
                    $event['all_day'] = true;
                } elseif (preg_match('/DTSTART[^:]*:(\d{8}T\d{6}Z?)/m', $veventBlock, $m)) {
                    $event['start'] = $m[1];
                }
                if (preg_match('/DTEND;VALUE=DATE:(\d{8})/m', $veventBlock, $m)) {
                    $event['end'] = $m[1];
                } elseif (preg_match('/DTEND[^:]*:(\d{8}T\d{6}Z?)/m', $veventBlock, $m)) {
                    $event['end'] = $m[1];
                }

                if ($event['title'] !== '') {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }
}
