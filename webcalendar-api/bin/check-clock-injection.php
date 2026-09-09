#!/usr/bin/env php
<?php

/**
 * CI guard for PBP-S5: business logic must read wall-clock time via an
 * injected Psr\Clock\ClockInterface, not by asking the runtime for it.
 *
 * Banned:
 *   - `new \DateTimeImmutable()` and its now-relative arguments ('now',
 *     'today', 'yesterday', 'tomorrow', '+...', '-...')
 *   - `date($format)` / `gmdate($format)` with no timestamp, which format
 *     the current time
 *   - `strtotime('+...')`, `strtotime('-...')`, `strtotime('now')` and the
 *     other now-relative literals
 *
 * Still legal, because none of these read the clock:
 *   - `new \DateTimeImmutable($parsedUserString)`
 *   - `date($format, $timestamp)` — formatting a timestamp you already have
 *   - `strtotime($userSuppliedString)` — parsing input
 */

declare(strict_types=1);

// Defaults to src/; an explicit directory argument lets the test suite run
// this against fixtures.
$srcDir = realpath($argv[1] ?? (__DIR__ . '/../src'));
if ($srcDir === false || !is_dir($srcDir)) {
    fwrite(STDERR, "src/ not found at {$srcDir}\n");
    exit(2);
}

// Patterns that read the current wall-clock time, mapped to what to say
// about them. The lookbehind on the function names keeps method calls such
// as `$entry->date()` and `Blob::date()` out of it.
$patterns = [
    '/new\s+\\\\?DateTimeImmutable\s*\(\s*\)/' => 'constructs the current moment',
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]now['\"]/" => 'constructs the current moment',
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]today['\"]/" => 'constructs the current moment',
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]yesterday['\"]/" => 'constructs the current moment',
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]tomorrow['\"]/" => 'constructs the current moment',
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]\s*[+\-]/" => 'constructs a moment relative to now',
    // date($format) with no timestamp argument formats the current time.
    '/(?<![>:$\w])(?:date|gmdate)\s*\(\s*(?:\'[^\']*\'|"[^"]*"|\$[A-Za-z_]\w*)\s*\)/'
        => 'formats the current time; pass a timestamp, or use the injected clock',
    "/(?<![>:\$\w])strtotime\s*\(\s*(?:'\s*[+\-][^']*'|\"\s*[+\-][^\"]*\")\s*\)/"
        => 'resolves a relative string against now',
    "/(?<![>:\$\w])strtotime\s*\(\s*(?:'(?:now|today|yesterday|tomorrow|midnight)[^']*'|\"(?:now|today|yesterday|tomorrow|midnight)[^\"]*\")\s*\)/"
        => 'resolves a relative string against now',
];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
$offenders = [];
$root = \dirname(__DIR__) . '/';

/** @var SplFileInfo $file */
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }

    // Blank out comments so docblock examples don't trigger false positives,
    // keeping their newlines so reported line numbers stay true.
    $stripped = preg_replace_callback(
        '~/\*.*?\*/|//[^\n]*~s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $code,
    );
    if ($stripped === null) {
        continue;
    }

    foreach ($patterns as $pattern => $reason) {
        if (preg_match_all($pattern, $stripped, $matches, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($matches[0] ?? [] as [$match, $offset]) {
            $line = substr_count(substr($stripped, 0, $offset), "\n") + 1;
            $rel = str_starts_with($path, $root) ? substr($path, \strlen($root)) : $path;
            $offenders[] = "{$rel}:{$line} — {$match} ({$reason})";
        }
    }
}

if ($offenders === []) {
    echo "OK: no unguarded wall-clock reads in {$srcDir}\n";
    exit(0);
}

fwrite(STDERR, 'FAIL: ' . count($offenders) . " site(s) reading wall-clock time without an injected ClockInterface:\n\n");
foreach (array_unique($offenders) as $line) {
    fwrite(STDERR, "  {$line}\n");
}
fwrite(STDERR, "\nInject Psr\\Clock\\ClockInterface and call \$this->clock->now() instead.\n");
fwrite(STDERR, "Parsing a user-supplied string, or formatting a timestamp you already hold, is still legal:\n");
fwrite(STDERR, "  new \\DateTimeImmutable(\$someString), date(\$format, \$timestamp), strtotime(\$input)\n");
exit(1);
