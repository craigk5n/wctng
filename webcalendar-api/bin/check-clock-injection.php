#!/usr/bin/env php
<?php

/**
 * CI guard for PBP-S5: business logic must read wall-clock time via an
 * injected Psr\Clock\ClockInterface, not via `new \DateTimeImmutable()`
 * / `new \DateTimeImmutable('now')` / `new \DateTimeImmutable('today')`
 * / `new \DateTimeImmutable('+...')` / `new \DateTimeImmutable('-...')`.
 *
 * `new \DateTimeImmutable($parsedUserString)` remains legal — the ban
 * only catches the now-relative forms.
 */

declare(strict_types=1);

$srcDir = __DIR__ . '/../src';
if (!is_dir($srcDir)) {
    fwrite(STDERR, "src/ not found at {$srcDir}\n");
    exit(2);
}

// Patterns that read the current wall-clock time.
$patterns = [
    '/new\s+\\\\?DateTimeImmutable\s*\(\s*\)/',
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]now['\"]/",
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]today['\"]/",
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]yesterday['\"]/",
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]tomorrow['\"]/",
    "/new\s+\\\\?DateTimeImmutable\s*\(\s*['\"]\s*[+\-]/",
];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
$offenders = [];

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

    // Strip comments so docblock examples don't trigger false positives.
    $stripped = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $code);
    if ($stripped === null) {
        continue;
    }

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $stripped, $matches, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($matches[0] ?? [] as [$match, $offset]) {
            $line = substr_count(substr($stripped, 0, $offset), "\n") + 1;
            $rel = substr($path, strlen(dirname(__DIR__)) + 1);
            $offenders[] = "{$rel}:{$line} — {$match}";
        }
    }
}

if ($offenders === []) {
    echo "OK: no now-relative DateTimeImmutable construction in src/\n";
    exit(0);
}

fwrite(STDERR, "FAIL: " . count($offenders) . " site(s) reading wall-clock time without an injected ClockInterface:\n\n");
foreach (array_unique($offenders) as $line) {
    fwrite(STDERR, "  {$line}\n");
}
fwrite(STDERR, "\nInject Psr\\Clock\\ClockInterface and call \$this->clock->now() instead.\n");
fwrite(STDERR, "Parsing a user-supplied date string with `new \\DateTimeImmutable(\$someString)` is still legal.\n");
exit(1);
