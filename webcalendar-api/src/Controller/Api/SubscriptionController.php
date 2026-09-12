<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\OutboundUrlValidator;
use App\Security\WebCalendarUser;
use App\Subscription\IcsFetcher;
use App\Subscription\SubscriptionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SubscriptionController
{
    private const DEFAULT_REFRESH_SECONDS = 3600;
    private const MIN_REFRESH_SECONDS = 300;

    public function __construct(
        private readonly SubscriptionRepository $repo,
        private readonly IcsFetcher $fetcher,
        private readonly OutboundUrlValidator $urlValidator,
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

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        $url = isset($data['url']) && \is_string($data['url']) ? $data['url'] : '';
        $name = isset($data['name']) && \is_string($data['name']) ? $data['name'] : '';

        if ($url === '' || $name === '') {
            return ApiResponse::error(400, 'Missing required fields: url, name');
        }

        // The stored URL is fetched by the server, so it gets the same
        // outbound checks a webhook target does. CurlIcsFetcher re-checks
        // before each fetch; rejecting here turns a subscription that could
        // never load into an immediate 400.
        try {
            $this->urlValidator->validate($url);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }

        $color = isset($data['color']) && \is_string($data['color']) ? $data['color'] : '#3788d8';
        $interval = isset($data['refresh_interval']) && \is_int($data['refresh_interval'])
            ? $data['refresh_interval']
            : self::DEFAULT_REFRESH_SECONDS;

        // Unbounded, a single subscription is a standing instruction to fetch
        // a chosen URL as fast as the refresh job runs.
        if ($interval < self::MIN_REFRESH_SECONDS) {
            return ApiResponse::error(400, sprintf(
                'refresh_interval must be at least %d seconds.',
                self::MIN_REFRESH_SECONDS,
            ));
        }

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

        try {
            $fetched = $this->fetcher->fetch($sub->url(), $sub->etag());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }

        // 304 Not Modified, or the feed could not be read
        if ($fetched === null) {
            return ApiResponse::success([
                'subscription_id' => $id,
                'events' => [],
                'cached' => true,
            ]);
        }

        $this->repo->updateFetchStatus($id, $fetched['etag']);

        // Parse ICS
        $events = $this->parseIcs($fetched['body'], $sub->name(), $sub->color());

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
