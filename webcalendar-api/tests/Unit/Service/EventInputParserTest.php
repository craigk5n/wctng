<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EventInputParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The YYYYMMDD query parameter, shared by five routes.
 *
 * It had no test of its own. The length and digit checks in front of it make
 * it look strict, but createFromFormat behind them rolls an impossible date
 * forward rather than refusing it, so every one of those routes answered for a
 * day nobody asked about.
 */
final class EventInputParserTest extends TestCase
{
    #[DataProvider('realDates')]
    public function testARealDateIsReadAsMidnightThatDay(string $input, string $expected): void
    {
        $parsed = EventInputParser::parseDateParam($input);

        $this->assertNotNull($parsed);
        $this->assertSame($expected, $parsed->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function realDates(): iterable
    {
        yield 'an ordinary day' => ['20260911', '2026-09-11 00:00:00'];
        yield 'the first of the year' => ['20260101', '2026-01-01 00:00:00'];
        yield 'the last of the year' => ['20261231', '2026-12-31 00:00:00'];
        yield 'a leap day' => ['20240229', '2024-02-29 00:00:00'];
    }

    #[DataProvider('notDates')]
    public function testAnythingElseIsRefused(string $input): void
    {
        $this->assertNull(EventInputParser::parseDateParam($input));
    }

    /** @return iterable<string, array{string}> */
    public static function notDates(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['2026091'];
        yield 'too long' => ['202609110'];
        yield 'not digits' => ['2026-9-11'];
        yield 'words' => ['tomorrow'];
        yield 'spaces' => ['2026 911'];
        // Eight digits each, and each one a day that does not exist. Without
        // the round trip these came back as 3 March, 14 February the year
        // after, and the 1st respectively.
        yield 'the 31st of February' => ['20260231'];
        yield 'the 13th month' => ['20261345'];
        yield 'the 32nd' => ['20260132'];
        yield 'the 30th of February in a leap year' => ['20240230'];
        yield 'the zeroth' => ['20260900'];
        yield 'month zero' => ['20260011'];
    }
}
