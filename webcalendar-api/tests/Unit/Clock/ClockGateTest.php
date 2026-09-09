<?php

declare(strict_types=1);

namespace App\Tests\Unit\Clock;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PBP-S5 gate itself.
 *
 * bin/check-clock-injection.php is a pile of regexes whose only failure mode
 * that matters is a false negative: it prints OK and nobody looks again. It
 * missed date() and strtotime() entirely until a wall-clock read in
 * ReportService went untested because of it, so the patterns are pinned here
 * from both sides -- what must be caught, and what must not be.
 */
final class ClockGateTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $dir = tempnam(sys_get_temp_dir(), 'clockgate');
        self::assertIsString($dir);
        unlink($dir);
        mkdir($dir);
        $this->dir = $dir;
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @return array{int, string} exit code and combined output */
    private function gateOn(string $body): array
    {
        file_put_contents(
            $this->dir . '/Sample.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Sample\n{\n" . $body . "\n}\n",
        );

        $script = \dirname(__DIR__, 3) . '/bin/check-clock-injection.php';
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' '
                . escapeshellarg($this->dir) . ' 2>&1',
            $output,
            $exitCode,
        );

        return [$exitCode, implode("\n", $output)];
    }

    /** @return iterable<string, array{string}> */
    public static function wallClockReads(): iterable
    {
        yield 'date() with only a format' => ["    public function f(): string { return date('c'); }"];
        yield 'gmdate() with only a format' => ["    public function f(): string { return gmdate('Y-m-d'); }"];
        yield 'date() with a format variable' => ['    public function f(string $x): string { return date($x); }'];
        yield 'strtotime() with a relative literal' => ["    public function f(): int|false { return strtotime('-30 days'); }"];
        yield 'strtotime() with an interpolated relative literal' => ['    public function f(int $i): int|false { return strtotime("-{$i} days"); }'];
        yield 'strtotime(now)' => ["    public function f(): int|false { return strtotime('now'); }"];
        yield 'time()' => ['    public function f(): int { return time(); }'];
        yield 'DateTimeImmutable with no argument' => ['    public function f(): \DateTimeImmutable { return new \DateTimeImmutable(); }'];
        yield 'DateTimeImmutable relative to now' => ["    public function f(): \\DateTimeImmutable { return new \\DateTimeImmutable('+1 day'); }"];
    }

    #[DataProvider('wallClockReads')]
    public function testAWallClockReadIsRejected(string $body): void
    {
        [$exitCode, $output] = $this->gateOn($body);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('Sample.php', $output);
    }

    /** @return iterable<string, array{string}> */
    public static function legitimateUses(): iterable
    {
        yield 'date() formatting a timestamp it was given' => ['    public function f(int $ts): string { return date(\'Ymd\', $ts); }'];
        yield 'strtotime() parsing input' => ['    public function f(string $in): int|false { return strtotime($in); }'];
        yield 'strtotime() with an explicit base' => ['    public function f(int $base): int|false { return strtotime(\'+1 day\', $base); }'];
        yield 'a date() method call on an object' => ['    public function f(object $e): string { return $e->date()->format(\'c\'); }'];
        yield 'a static date() call' => ['    public function f(): string { return \Sample::date(\'c\'); }'];
        yield 'parsing a supplied string into DateTimeImmutable' => ['    public function f(string $s): \DateTimeImmutable { return new \DateTimeImmutable($s); }'];
        yield 'microtime() for elapsed time' => ['    public function f(): float { return microtime(true); }'];
        yield 'hrtime() for elapsed time' => ['    public function f(): int|float { return hrtime(true); }'];
        yield 'mktime() with explicit parts' => ['    public function f(): int|false { return mktime(0, 0, 0, 1, 1, 2026); }'];
        yield 'a time() method call on an object' => ['    public function f(object $e): int { return $e->time(); }'];
        yield 'the clock itself' => ['    public function f(): string { return $this->clock->now()->format(\'c\'); }'];
    }

    #[DataProvider('legitimateUses')]
    public function testCodeThatDoesNotReadTheClockIsAccepted(string $body): void
    {
        [$exitCode, $output] = $this->gateOn($body);

        self::assertSame(0, $exitCode, $output);
    }

    public function testAWallClockReadInsideACommentIsIgnored(): void
    {
        [$exitCode, $output] = $this->gateOn(
            "    /** Formerly date('c'); see the clock. */\n    public function f(): int { return 1; }",
        );

        self::assertSame(0, $exitCode, $output);
    }

    public function testTheReportedLineNumberSurvivesCommentStripping(): void
    {
        // Blanked comments have to keep their newlines, or every line number
        // after a docblock is reported short and nobody can find the offender.
        [$exitCode, $output] = $this->gateOn(
            "    /**\n     * A docblock\n     * spanning several lines.\n     */\n"
            . "    public function f(): string { return date('c'); }",
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Sample.php:11', $output);
    }
}
