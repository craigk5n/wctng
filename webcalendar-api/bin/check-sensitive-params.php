#!/usr/bin/env php
<?php

/**
 * CI guard for PBP-S1: every function parameter named $password, $token,
 * $secret, $apiKey, $plaintext, $clientSecret, $bindPassword, $accessToken,
 * $refreshToken, $jwtSecret, or $appSecret must carry #[\SensitiveParameter].
 *
 * Exits non-zero with a list of offenders when any are found.
 *
 * Scope: src/ only. Known-safe exemptions: column names like `password_hash`
 * in SQL, getter method bodies, and short-circuit checks against the
 * parameter are OK — this script only looks at parameter declarations.
 */

declare(strict_types=1);

$srcDir = __DIR__ . '/../src';
if (!is_dir($srcDir)) {
    fwrite(STDERR, "src/ not found at {$srcDir}\n");
    exit(2);
}

$secretNames = [
    'password', 'token', 'secret', 'apiKey', 'plaintext',
    'clientSecret', 'bindPassword', 'accessToken', 'refreshToken',
    'jwtSecret', 'appSecret', 'adminPassword', 'dbPassword',
    'userPassword', 'signingKey', 'webhookSecret', 'encrypted',
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

    // Strip comments so we don't get false positives from docblocks.
    $stripped = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $code);
    if ($stripped === null) {
        continue;
    }

    // Match parameters: optional visibility/readonly, optional type, $name
    // We require a type hint or visibility to keep the regex from matching
    // every local variable.
    $pattern = sprintf(
        '/(?<!#\[\\\\SensitiveParameter\]\s)(?<!#\[SensitiveParameter\]\s)(?:public|private|protected|readonly|\?\w+|\\\\?\w+|\s)+\$(%s)\b\s*[=,)]/i',
        implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $secretNames)),
    );

    $matchCount = preg_match_all($pattern, $stripped, $matches, PREG_OFFSET_CAPTURE);
    if ($matchCount === false || $matchCount === 0) {
        continue;
    }

    foreach ($matches[1] as $i => [$paramName, $offset]) {
        // Look back ~120 chars for the SensitiveParameter attribute on this param.
        $start = max(0, $offset - 120);
        $window = substr($stripped, $start, $offset - $start);
        if (str_contains($window, 'SensitiveParameter')) {
            continue;
        }

        $line = substr_count(substr($stripped, 0, $offset), "\n") + 1;
        $relPath = substr($path, strlen(dirname(__DIR__)) + 1);
        $offenders[] = "{$relPath}:{$line} — \${$paramName} lacks #[\\SensitiveParameter]";
    }
}

if ($offenders === []) {
    echo "OK: all secret-carrying parameters carry #[\\SensitiveParameter]\n";
    exit(0);
}

fwrite(STDERR, "FAIL: " . count($offenders) . " parameter(s) missing #[\\SensitiveParameter]:\n\n");
foreach ($offenders as $line) {
    fwrite(STDERR, "  {$line}\n");
}
fwrite(STDERR, "\nAdd #[\\SensitiveParameter] before each listed parameter, or rename it if it's not actually sensitive.\n");
exit(1);
