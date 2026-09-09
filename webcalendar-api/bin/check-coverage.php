#!/usr/bin/env php
<?php

/**
 * CI guard: lines this change touches must be executed by the test suite.
 *
 * Deliberately not a global coverage floor. On an existing codebase a global
 * number moves by a fraction of a percent when a whole untested file lands,
 * so it cannot catch the thing worth catching, and setting it near the
 * current value makes every unrelated PR fight it. This asks the narrower
 * question instead: of the executable lines this diff adds or changes, how
 * many did the suite run?
 *
 * Complements bin/../infection: the mutation gate asks whether the tests that
 * exist are any good, this one asks whether they exist at all.
 *
 * Usage: check-coverage.php <clover.xml> <base-ref> [min-percent]
 */

declare(strict_types=1);

$cloverPath = $argv[1] ?? '';
$baseRef = $argv[2] ?? '';
$minPercent = isset($argv[3]) ? (float) $argv[3] : 75.0;

if ($cloverPath === '' || $baseRef === '') {
    fwrite(STDERR, "usage: check-coverage.php <clover.xml> <base-ref> [min-percent]\n");
    exit(2);
}

if (!is_file($cloverPath)) {
    fwrite(STDERR, "coverage report not found: {$cloverPath}\n");
    exit(2);
}

$apiDir = \dirname(__DIR__);
$repoRoot = trim((string) shell_exec('git -C ' . escapeshellarg($apiDir) . ' rev-parse --show-toplevel 2>/dev/null'));

if ($repoRoot === '') {
    fwrite(STDERR, "not inside a git repository\n");
    exit(2);
}

// An unresolvable base (a bad ref, or a shallow clone with no history) would
// otherwise produce an empty diff, no changed lines, and a silent pass --
// which is indistinguishable from "this change is fully covered".
exec(
    'git -C ' . escapeshellarg($repoRoot) . ' rev-parse --verify --quiet '
        . escapeshellarg($baseRef . '^{commit}') . ' 2>/dev/null',
    $refOutput,
    $refStatus,
);

if ($refStatus !== 0) {
    fwrite(STDERR, "cannot resolve base ref '{$baseRef}' (a shallow checkout has no history to diff against)\n");
    exit(2);
}

/**
 * Executable lines and their hit counts, keyed by path relative to the api
 * directory. Clover records whatever absolute path the run saw, which is not
 * the path git reports, so both sides are cut down to `src/...`.
 */
function normalise(string $path): ?string
{
    $pos = strpos($path, '/src/');

    return $pos === false ? null : substr($path, $pos + 1);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "cannot parse {$cloverPath}\n");
    exit(2);
}

/** @var array<string, array<int, int>> $executed */
$executed = [];
foreach ($xml->xpath('//file') ?: [] as $file) {
    $name = normalise((string) $file['name']);
    if ($name === null) {
        continue;
    }

    foreach ($file->line ?: [] as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }
        $executed[$name][(int) $line['num']] = (int) $line['count'];
    }
}

/**
 * Line numbers added or changed on the new side of the diff, per file.
 *
 * --unified=0 so each hunk header describes exactly the changed lines; with
 * context lines the ranges would include code this change did not touch.
 *
 * @return array<string, list<int>>
 */
function changedLines(string $repoRoot, string $baseRef, string $apiDirName): array
{
    $cmd = 'git -C ' . escapeshellarg($repoRoot) . ' diff --unified=0 --no-color --diff-filter=d '
        . escapeshellarg($baseRef) . ' -- ' . escapeshellarg($apiDirName . '/src') . ' 2>/dev/null';
    $diff = (string) shell_exec($cmd);

    $changed = [];
    $current = null;

    foreach (explode("\n", $diff) as $line) {
        if (str_starts_with($line, '+++ ')) {
            $path = substr($line, 4);
            // str_starts_with, not ltrim: ltrim takes a character list, so
            // ltrim('b/bin/x', 'b/') yields 'in/x'.
            if (str_starts_with($path, 'b/')) {
                $path = substr($path, 2);
            }
            $current = $path === '/dev/null' ? null : normalise('/' . $path);
            continue;
        }

        if ($current !== null && preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m) === 1) {
            $start = (int) $m[1];
            $count = isset($m[2]) ? (int) $m[2] : 1;
            for ($i = 0; $i < $count; $i++) {
                $changed[$current][] = $start + $i;
            }
        }
    }

    return $changed;
}

$changed = changedLines($repoRoot, $baseRef, basename($apiDir));

$total = 0;
$covered = 0;
$misses = [];

foreach ($changed as $file => $lines) {
    foreach ($lines as $line) {
        // Only executable statements count: blank lines, comments, closing
        // braces and declarations are not something a test can run.
        if (!isset($executed[$file][$line])) {
            continue;
        }

        $total++;
        if ($executed[$file][$line] > 0) {
            $covered++;
        } else {
            $misses[] = "{$file}:{$line}";
        }
    }
}

if ($total === 0) {
    echo "OK: this change adds no executable lines to src/\n";
    exit(0);
}

$percent = round($covered / $total * 100, 1);

if ($percent >= $minPercent) {
    printf("OK: %d of %d changed lines covered (%.1f%%, floor %.1f%%)\n", $covered, $total, $percent, $minPercent);
    exit(0);
}

fwrite(STDERR, sprintf(
    "FAIL: only %d of %d changed lines are covered (%.1f%%, floor %.1f%%)\n\n",
    $covered,
    $total,
    $percent,
    $minPercent,
));
foreach (\array_slice($misses, 0, 40) as $miss) {
    fwrite(STDERR, "  never executed: {$miss}\n");
}
if (\count($misses) > 40) {
    fwrite(STDERR, '  ... and ' . (\count($misses) - 40) . " more\n");
}
fwrite(STDERR, "\nAdd tests that reach these lines, or explain in review why they cannot be reached.\n");
exit(1);
