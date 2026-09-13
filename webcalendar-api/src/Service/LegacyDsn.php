<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The --dsn an operator hands webcalendar:import-legacy, turned into the three
 * arguments PDO wants.
 *
 * Separate from the command because it is the part with decisions in it, and a
 * private method that opens a database connection is a part nobody can test.
 * Both of the things it gets right below were wrong while it was private.
 */
final class LegacyDsn
{
    /**
     * @return array{dsn: string, user: ?string, password: ?string}
     *
     * @throws \InvalidArgumentException when the string is not a DSN this
     *                                   command knows how to open
     */
    public static function parse(string $dsn): array
    {
        $parsed = parse_url($dsn);

        // parse_url refuses scheme:///path outright -- an empty authority
        // followed by an absolute path -- and that is precisely the sqlite
        // form this command's own error message tells people to use. Reading
        // the scheme off the front first means the documented spelling works.
        if ($parsed === false && preg_match('#^([a-z][a-z0-9+.-]*)://(/.*)$#', $dsn, $m) === 1) {
            $parsed = ['scheme' => $m[1], 'path' => $m[2]];
        }

        if (!\is_array($parsed) || !isset($parsed['scheme'])) {
            throw new \InvalidArgumentException(
                'Invalid DSN format. Expected: mysql://user:pass@host/dbname or sqlite:///path/to/db',
            );
        }

        /** @var string $scheme */
        $scheme = $parsed['scheme'];

        if ($scheme === 'sqlite') {
            $path = self::str($parsed, 'host') . self::str($parsed, 'path');

            if ($path === '') {
                throw new \InvalidArgumentException('Invalid DSN format. sqlite needs a path: sqlite:///path/to/db');
            }

            return ['dsn' => "sqlite:{$path}", 'user' => null, 'password' => null];
        }

        // Userinfo in a URI is percent-encoded, which is not optional: a
        // password containing @ or / or # has to be written p%40ss or the
        // authority cannot be parsed at all. parse_url hands back what it
        // found without decoding, so the encoded form was being used verbatim
        // as the password and the connection was refused for looking wrong.
        $user = isset($parsed['user']) ? rawurldecode(self::str($parsed, 'user')) : null;
        $password = isset($parsed['pass']) ? rawurldecode(self::str($parsed, 'pass')) : null;
        $host = self::str($parsed, 'host');
        $database = ltrim(self::str($parsed, 'path'), '/');

        if ($scheme === 'mysql') {
            $port = self::int($parsed, 'port', 3306);

            return [
                'dsn' => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $host === '' ? '127.0.0.1' : $host,
                    $port,
                    $database,
                ),
                'user' => $user ?? 'root',
                'password' => $password ?? '',
            ];
        }

        if ($scheme === 'pgsql' || $scheme === 'postgresql') {
            $port = self::int($parsed, 'port', 5432);

            return [
                'dsn' => sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $host === '' ? '127.0.0.1' : $host,
                    $port,
                    $database,
                ),
                'user' => $user ?? 'postgres',
                'password' => $password ?? '',
            ];
        }

        throw new \InvalidArgumentException("Unsupported database scheme: {$scheme}");
    }

    /** @param array<string, mixed> $parsed */
    private static function str(array $parsed, string $key): string
    {
        return isset($parsed[$key]) && \is_string($parsed[$key]) ? $parsed[$key] : '';
    }

    /** @param array<string, mixed> $parsed */
    private static function int(array $parsed, string $key, int $default): int
    {
        return isset($parsed[$key]) && \is_int($parsed[$key]) ? $parsed[$key] : $default;
    }
}
