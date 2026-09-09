<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use PHPUnit\Framework\TestCase;

/**
 * The changed-lines coverage gate.
 *
 * Same reasoning as ClockGateTest: a gate whose failure mode is "prints OK
 * and nobody looks again" has to be pinned from both sides. This one has two
 * moving parts that are easy to get quietly wrong -- reading hit counts out
 * of clover, and turning a diff into a set of line numbers -- and if either
 * silently yields nothing, every change passes.
 */
final class CoverageGateTest extends TestCase
{
    private string $repo;

    #[\Override]
    protected function setUp(): void
    {
        $dir = tempnam(sys_get_temp_dir(), 'covgate');
        self::assertIsString($dir);
        unlink($dir);
        mkdir($dir . '/webcalendar-api/src', 0o777, true);
        $this->repo = $dir;

        $this->git('init -q');
        $this->git('config user.email gate@example.com');
        $this->git('config user.name Gate');
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repo));
    }

    private function git(string $args): void
    {
        exec('git -C ' . escapeshellarg($this->repo) . ' ' . $args . ' 2>&1', $out, $code);
        self::assertSame(0, $code, "git {$args}: " . implode("\n", $out));
    }

    private function writeSource(string $body): void
    {
        file_put_contents($this->repo . '/webcalendar-api/src/Sample.php', $body);
    }

    /**
     * @param array<int, int> $lineCounts line number => times executed
     */
    private function writeClover(array $lineCounts): void
    {
        $lines = '';
        foreach ($lineCounts as $num => $count) {
            $lines .= sprintf('<line num="%d" type="stmt" count="%d"/>', $num, $count);
        }

        file_put_contents(
            $this->repo . '/clover.xml',
            '<?xml version="1.0" encoding="UTF-8"?><coverage><project>'
            // An absolute path from some other machine on purpose: clover
            // records wherever the run happened, never the checkout being
            // examined, so the gate has to match on the src/... tail.
            . '<file name="/somewhere/else/webcalendar-api/src/Sample.php">' . $lines . '</file>'
            . '</project></coverage>',
        );
    }

    /**
     * @return array{int, string} exit code and combined output
     */
    private function runGate(?float $floor = null, ?string $clover = null): array
    {
        // The gate finds the repository from its own location, so it has to
        // live inside the fixture repo rather than be called from outside it.
        $bin = $this->repo . '/webcalendar-api/bin';
        if (!is_dir($bin)) {
            mkdir($bin, 0o777, true);
        }
        copy(\dirname(__DIR__, 3) . '/bin/check-coverage.php', $bin . '/check-coverage.php');

        exec(
            'cd ' . escapeshellarg($this->repo . '/webcalendar-api') . ' && '
                . escapeshellarg(PHP_BINARY) . ' bin/check-coverage.php '
                . escapeshellarg($clover ?? $this->repo . '/clover.xml') . ' HEAD'
                . ($floor === null ? '' : ' ' . $floor) . ' 2>&1',
            $output,
            $exitCode,
        );

        return [$exitCode, implode("\n", $output)];
    }

    private function commitBaseline(): void
    {
        $this->writeSource("<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n\$d = 4;\n");
        $this->git('add -A');
        $this->git('commit -qm baseline');
    }

    public function testChangedLinesThatRanArePassed(): void
    {
        $this->commitBaseline();
        $this->writeSource("<?php\n\$a = 1;\n\$b = 99;\n\$c = 3;\n\$d = 4;\n");
        $this->writeClover([2 => 1, 3 => 5, 4 => 1, 5 => 1]);

        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('1 of 1 changed lines covered', $output);
    }

    public function testChangedLinesThatNeverRanAreRejected(): void
    {
        $this->commitBaseline();
        $this->writeSource("<?php\n\$a = 1;\n\$b = 99;\n\$c = 3;\n\$d = 4;\n");
        $this->writeClover([2 => 1, 3 => 0, 4 => 1, 5 => 1]);

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('never executed: src/Sample.php:3', $output);
    }

    public function testTheFloorIsWhatDecides(): void
    {
        // Three changed lines, one of them never run: 66.7%.
        $this->commitBaseline();
        $this->writeSource("<?php\n\$a = 11;\n\$b = 22;\n\$c = 33;\n\$d = 4;\n");
        $this->writeClover([2 => 1, 3 => 1, 4 => 0, 5 => 1]);

        [$strict, $output] = $this->runGate(75.0);
        self::assertSame(1, $strict, "a floor of 75 rejects 66.7%: {$output}");

        [$loose, $output] = $this->runGate(60.0);
        self::assertSame(0, $loose, "a floor of 60 accepts the same diff: {$output}");
        self::assertStringContainsString('2 of 3 changed lines covered (66.7%', $output);
    }

    public function testLinesTheDiffDidNotTouchAreIgnored(): void
    {
        // Line 5 never ran, but this change did not touch it: not our problem.
        $this->commitBaseline();
        $this->writeSource("<?php\n\$a = 1;\n\$b = 99;\n\$c = 3;\n\$d = 4;\n");
        $this->writeClover([2 => 1, 3 => 1, 4 => 1, 5 => 0]);

        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
    }

    public function testNonExecutableChangesArePassed(): void
    {
        // A comment-only change has no statements in clover, so there is
        // nothing to require coverage of.
        $this->commitBaseline();
        $this->writeSource("<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n\$d = 4;\n// a note\n");
        $this->writeClover([2 => 1, 3 => 1, 4 => 1, 5 => 1]);

        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('no executable lines', $output);
    }

    public function testAnUnresolvableBaseRefIsAnError(): void
    {
        $this->commitBaseline();
        $this->writeClover([2 => 1]);

        $bin = $this->repo . '/webcalendar-api/bin';
        mkdir($bin, 0o777, true);
        copy(\dirname(__DIR__, 3) . '/bin/check-coverage.php', $bin . '/check-coverage.php');

        // A base that does not exist must stop the build, not report an empty
        // diff as a clean one.
        exec(
            'cd ' . escapeshellarg($this->repo . '/webcalendar-api') . ' && '
                . escapeshellarg(PHP_BINARY) . ' bin/check-coverage.php '
                . escapeshellarg($this->repo . '/clover.xml') . ' 0000000000000000000000000000000000000000 60 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(2, $exitCode, implode("\n", $output));
        self::assertStringContainsString('cannot resolve base ref', implode("\n", $output));
    }

    public function testAMissingCoverageReportIsAnError(): void
    {
        $this->commitBaseline();

        // Exit 2, not 1: a missing report means the gate could not run, which
        // must never read as "the diff is covered".
        [$exitCode, $output] = $this->runGate(60.0, '/nonexistent/clover.xml');

        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString('coverage report not found', $output);
    }
}
