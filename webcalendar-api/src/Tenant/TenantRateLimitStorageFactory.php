<?php

declare(strict_types=1);

namespace App\Tenant;

use Predis\Client as PredisClient;

/**
 * Selects the rate-limit storage backend based on `REDIS_URL`. If unset
 * (or empty), falls back to the file-based implementation so dev and
 * single-node deploys work without extra infrastructure.
 *
 * Invoked from `config/services.yaml` as a DI factory so consumers only
 * depend on `TenantRateLimitStorage` and never branch on the env var.
 */
final readonly class TenantRateLimitStorageFactory
{
    public static function fromEnv(string $redisUrl, string $cacheDir): TenantRateLimitStorage
    {
        if ($redisUrl === '') {
            return new FileTenantRateLimitStorage($cacheDir);
        }

        $client = new PredisClient($redisUrl, [
            'parameters' => [
                'read_write_timeout' => 2.0, // keep a slow Redis from hanging the request
            ],
        ]);

        return new RedisTenantRateLimitStorage($client);
    }
}
