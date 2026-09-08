<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

/**
 * Force the suite onto a _test database.
 *
 * Dotenv never overrides a real environment variable, so .env.test's
 * DATABASE_URL loses to the one docker-compose.dev.yml exports into the
 * php-fpm container -- and that one points at the *development* database.
 * Running the suite there writes fixtures into real data; it has already
 * cost us a restore-from-backup once.
 *
 * Only the database name is rewritten, so the host and credentials keep
 * working unchanged in every environment: CI already ends in _test and is
 * left alone, the dev container gets redirected off `webcalendar`.
 */
$databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;

if (\is_string($databaseUrl) && $databaseUrl !== '' && str_contains($databaseUrl, 'mysql')) {
    $parts = parse_url($databaseUrl);

    if ($parts === false || !isset($parts['scheme'], $parts['path'])) {
        throw new RuntimeException(
            'DATABASE_URL is not a parseable DSN; refusing to run the suite against an unknown database.'
        );
    }

    $name = ltrim($parts['path'], '/');

    if ($name === '') {
        throw new RuntimeException(
            'DATABASE_URL names no database; refusing to run the suite against an unknown database.'
        );
    }

    if (!str_ends_with($name, '_test')) {
        // parse_url does not decode, so credentials containing reserved
        // characters survive the round trip intact.
        $rebuilt = $parts['scheme'] . '://';

        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'];

            if (isset($parts['pass'])) {
                $rebuilt .= ':' . $parts['pass'];
            }

            $rebuilt .= '@';
        }

        $rebuilt .= $parts['host'] ?? '';

        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }

        $rebuilt .= '/' . $name . '_test';

        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        $_ENV['DATABASE_URL'] = $rebuilt;
        $_SERVER['DATABASE_URL'] = $rebuilt;
        putenv('DATABASE_URL=' . $rebuilt);
    }
}

if ($_SERVER['APP_DEBUG']) {
    umask(0o000);
}
