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
    /**
     * $redisUrl is nullable because services.yaml passes
     * `%env(default::REDIS_URL)%`, and Symfony's `default:` processor yields
     * null when the variable is unset *or* empty — which is exactly what
     * .env.test asks for (REDIS_URL=), so a non-nullable signature made the
     * container unbuildable in the project's own test configuration.
     */
    public static function fromEnv(?string $redisUrl, string $cacheDir): TenantRateLimitStorage
    {
        if ($redisUrl === null || $redisUrl === '') {
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
