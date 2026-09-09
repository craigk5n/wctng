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

/**
 * Clear the rows earlier runs left behind.
 *
 * ApiTestTrait only deletes what a test registered with it, and events made
 * through the journal, task and import endpoints never pass through its
 * helper, so every run used to leave rows behind: the database had
 * accumulated 841 events over 29 runs before this was added. That is not just
 * untidy. Reports and searches aggregate over whatever is there, so a test
 * asserting "this window holds two events" silently depends on nobody else
 * having written to that window, and the failure arrives months later in an
 * unrelated test.
 *
 * Three kinds of row go: calendar content, the tenants tests register (all of
 * them :memory:, so nothing outside this database is orphaned), and issued
 * JWT ids, of which every login in every test leaves one -- 1956 of them had
 * built up. Nothing a test could legitimately depend on: no test asserts a
 * tenant count or reuses a token across runs.
 *
 * What stays is what the suite is set up against: the admin fixture, config
 * and migration state. Guarded three ways -- MySQL only, database name must
 * end in _test (the block above guarantees it), and any failure is ignored,
 * since a Unit-only run has no MySQL to talk to.
 */
$purgeUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;

if (\is_string($purgeUrl) && str_starts_with($purgeUrl, 'mysql')) {
    $parts = parse_url($purgeUrl);
    $name = \is_array($parts) ? ltrim($parts['path'] ?? '', '/') : '';

    if ($name !== '' && str_ends_with($name, '_test')) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s', $parts['host'] ?? 'localhost', $parts['port'] ?? 3306, $name),
                $parts['user'] ?? '',
                $parts['pass'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            foreach ([
                'webcal_entry_user',
                'webcal_entry_ext_user',
                'webcal_entry_categories',
                'webcal_entry_repeats',
                'webcal_entry_repeats_not',
                'webcal_site_extras',
                'webcal_reminders',
                'webcal_entry_log',
                'webcal_blob',
                'webcal_entry',
                'webcal_category_icons',
                'webcal_categories',
                'webcal_user_jti',
                'tenants',
            ] as $table) {
                $pdo->exec('DELETE FROM ' . $table);
            }
        } catch (PDOException) {
            // No MySQL here (a Unit-only run), or the schema is not loaded
            // yet. Either way there is nothing to clear.
        }
    }
}

if ($_SERVER['APP_DEBUG']) {
    umask(0o000);
}
