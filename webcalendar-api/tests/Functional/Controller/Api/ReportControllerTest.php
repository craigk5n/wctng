<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The four report endpoints, which had no test of any kind.
 *
 * Three of them are thin: they read start and end from the query string,
 * defaulting to the last thirty days, and hand off to ReportService (covered
 * by ReportServiceTest). The fourth clamps its `days` parameter to 1..90, and
 * that clamp is the only real logic in the class -- it is what stops a caller
 * asking for a ten-year window.
 *
 * The date-window tests use June 2027 on purpose. This suite shares one MySQL
 * database and other functional tests seed events across March to September
 * 2026, so a window of their own is what makes exact assertions safe here.
 */
final class ReportControllerTest extends WebTestCase
{
    use ApiTestTrait;
    private const string WINDOW_START = '20270601';
    private const string WINDOW_END = '20270630';

    /** @var list<int> categories created here; the shared trait does not clean these up */
    private array $createdCategories = [];

    private ?KernelBrowser $cleanupClient = null;
    private string $cleanupToken = '';

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->createdCategories as $id) {
            $this->cleanupClient?->request('DELETE', '/api/v2/categories/' . $id, [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->cleanupToken,
            ]);
        }
        $this->createdCategories = [];

        $this->cleanupTestData();
        parent::tearDown();
    }

    /** @return array{KernelBrowser, string} */
    private function authenticated(): array
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->cleanupClient = $client;
        $this->cleanupToken = $token;

        return [$client, $token];
    }

    /** @param array<string, mixed> $query */
    private function report(KernelBrowser $client, string $token, string $path, array $query = []): mixed
    {
        $client->request('GET', '/api/v2/reports/' . $path, $query, [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseIsSuccessful();

        return $this->decodeResponse($client)['data'];
    }

    /** @param array<string, mixed> $extra */
    private function event(KernelBrowser $client, string $token, string $date, string $time, array $extra = []): int
    {
        return $this->createTestEvent($client, $token, array_merge([
            'title' => 'Report fixture ' . $date . ' ' . $time,
            'start_date' => $date,
            'start_time' => $time,
            'duration' => 60,
        ], $extra));
    }

    private function daysFromToday(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify("+{$days} days")->format('Ymd');
    }

    // ----------------------------------------------------------- the guards

    /** @return iterable<string, array{string}> */
    public static function endpoints(): iterable
    {
        foreach (['activity', 'busy-hours', 'categories', 'upcoming'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('endpoints')]
    public function testReportsAreNotPublic(string $path): void
    {
        // The firewall answers first (^/api/ is IS_AUTHENTICATED_FULLY), so
        // this never reaches the controller's own null check. It is still the
        // assertion that matters: it fails the day one of these paths is
        // added to the PUBLIC_ACCESS list, which would expose one user's
        // calendar activity to anyone.
        $client = static::createClient();
        $client->request('GET', '/api/v2/reports/' . $path);

        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    // ------------------------------------------------------------- activity

    public function testActivityReportCountsEventsPerDayInTheRequestedWindow(): void
    {
        [$client, $token] = $this->authenticated();
        $this->event($client, $token, '20270605', '090000');
        $this->event($client, $token, '20270605', '120000');
        $this->event($client, $token, '20270610', '140000');

        $data = $this->report($client, $token, 'activity', [
            'start' => self::WINDOW_START,
            'end' => self::WINDOW_END,
        ]);

        self::assertSame([
            ['date' => '20270605', 'count' => 2],
            ['date' => '20270610', 'count' => 1],
        ], $data);
    }

    public function testActivityReportHonoursTheRequestedWindowRatherThanTheDefault(): void
    {
        // Without start and end the window is the last thirty days, which this
        // 2027 event is nowhere near. Asserting on its date rather than on the
        // whole result because the shared database holds other tests' events,
        // so the default window is not empty.
        [$client, $token] = $this->authenticated();
        $this->event($client, $token, '20270605', '090000');

        $defaultWindow = array_column((array) $this->report($client, $token, 'activity'), 'date');
        self::assertNotContains('20270605', $defaultWindow, 'the default window is the last thirty days');

        $asked = array_column((array) $this->report($client, $token, 'activity', [
            'start' => self::WINDOW_START,
            'end' => self::WINDOW_END,
        ]), 'date');
        self::assertContains('20270605', $asked, 'start and end from the query string are used');
    }

    // ----------------------------------------------------------- busy hours

    public function testBusyHoursReportBucketsEventsByHourOfDay(): void
    {
        [$client, $token] = $this->authenticated();
        $this->event($client, $token, '20270605', '090000');
        $this->event($client, $token, '20270610', '090000');
        $this->event($client, $token, '20270612', '143000');

        $data = $this->report($client, $token, 'busy-hours', [
            'start' => self::WINDOW_START,
            'end' => self::WINDOW_END,
        ]);

        // 14:30 belongs to the 14:00 bucket: the hour is cal_time / 10000.
        self::assertSame([
            ['hour' => 9, 'count' => 2],
            ['hour' => 14, 'count' => 1],
        ], $data);
    }

    // ----------------------------------------------------------- categories

    public function testCategoriesReportCountsEventsPerCategory(): void
    {
        [$client, $token] = $this->authenticated();

        $name = 'ReportCat_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/categories', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['name' => $name, 'color' => '#123456']));

        $categoryId = $this->decodeResponse($client)['data']['id'];
        self::assertIsInt($categoryId);
        $this->createdCategories[] = $categoryId;

        $this->event($client, $token, '20270605', '090000', ['categories' => [$categoryId]]);
        $this->event($client, $token, '20270610', '090000', ['categories' => [$categoryId]]);
        $this->event($client, $token, '20270612', '090000');

        $data = $this->report($client, $token, 'categories', [
            'start' => self::WINDOW_START,
            'end' => self::WINDOW_END,
        ]);

        // The uncategorised third event contributes nothing: the report is an
        // inner join, not a left one.
        self::assertSame([
            ['category_id' => $categoryId, 'category_name' => $name, 'count' => 2],
        ], $data);
    }

    // ------------------------------------------------------------- upcoming

    public function testUpcomingReportDefaultsToAWeek(): void
    {
        [$client, $token] = $this->authenticated();
        $soon = $this->event($client, $token, $this->daysFromToday(2), '090000');
        $later = $this->event($client, $token, $this->daysFromToday(40), '090000');

        $ids = array_column((array) $this->report($client, $token, 'upcoming'), 'id');

        self::assertContains($soon, $ids);
        self::assertNotContains($later, $ids, 'the default window is seven days, not forty');
    }

    public function testUpcomingReportWindowIsCappedAtNinetyDays(): void
    {
        // max(1, min(90, days)). Without the cap a caller could ask for a
        // ten-year window and walk the whole table 50 rows at a time.
        [$client, $token] = $this->authenticated();
        $inside = $this->event($client, $token, $this->daysFromToday(80), '090000');
        $outside = $this->event($client, $token, $this->daysFromToday(120), '090000');

        $ids = array_column((array) $this->report($client, $token, 'upcoming', ['days' => 1000]), 'id');

        self::assertContains($inside, $ids);
        self::assertNotContains($outside, $ids, 'days is clamped to 90 however large the request');
    }

    public function testUpcomingReportWindowIsAtLeastOneDay(): void
    {
        [$client, $token] = $this->authenticated();
        $twoDaysOut = $this->event($client, $token, $this->daysFromToday(2), '090000');

        foreach ([0, -30] as $days) {
            $ids = array_column((array) $this->report($client, $token, 'upcoming', ['days' => $days]), 'id');

            self::assertNotContains(
                $twoDaysOut,
                $ids,
                "days={$days} clamps to a one day window rather than widening or inverting it",
            );
        }
    }

    public function testUpcomingReportExcludesThePast(): void
    {
        [$client, $token] = $this->authenticated();
        $yesterday = $this->event($client, $token, $this->daysFromToday(-1), '090000');

        $ids = array_column((array) $this->report($client, $token, 'upcoming', ['days' => 90]), 'id');

        self::assertNotContains($yesterday, $ids);
    }
}
