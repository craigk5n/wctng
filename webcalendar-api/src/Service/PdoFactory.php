<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Creates a PDO connection from a Symfony-style DATABASE_URL.
 *
 * The URL is taken apart by {@see DatabaseDsn}; this class only decides which
 * PDO options the application's shared connection wants.
 */
final class PdoFactory
{
    public static function createFromUrl(#[\SensitiveParameter] string $databaseUrl): \PDO
    {
        $config = DatabaseDsn::fromUrl($databaseUrl);

        if ($config->driver === 'sqlite') {
            return new \PDO($config->dsn, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        }

        return new \PDO($config->dsn, $config->user, $config->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            // PBP-S13: stop returning numeric columns as strings — API response
            // types silently widened without this. Pairs with emulated prepares
            // being off so the MySQL client returns the native int/float.
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }
}
