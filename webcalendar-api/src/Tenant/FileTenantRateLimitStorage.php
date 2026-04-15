<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Single-node file-based counter using fopen/flock. Fine for dev and
 * single-replica deploys; breaks in multi-node production because each
 * node has its own filesystem so counters don't share. Use
 * {@see RedisTenantRateLimitStorage} instead whenever `REDIS_URL` is
 * configured.
 */
final readonly class FileTenantRateLimitStorage implements TenantRateLimitStorage
{
    public function __construct(
        private string $storageDir,
    ) {}

    #[\Override]
    public function incrementAndCount(string $slug, int $window): int
    {
        $dir = $this->storageDir . '/rate_limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }

        $file = $dir . '/' . $slug . '_' . $window . '.count';

        $this->cleanOldWindows($dir, $slug, $window);

        $fp = fopen($file, 'c+');
        if ($fp === false) {
            return 1;
        }

        flock($fp, LOCK_EX);
        $content = fread($fp, 100);
        $current = $content !== false && $content !== '' ? (int) $content : 0;
        $current++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $current);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $current;
    }

    private function cleanOldWindows(string $dir, string $slug, int $currentWindow): void
    {
        $pattern = $dir . '/' . $slug . '_*.count';
        $files = glob($pattern);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $basename = basename($file, '.count');
            $parts = explode('_', $basename);
            $fileWindow = (int) end($parts);
            if ($fileWindow < $currentWindow) {
                @unlink($file);
            }
        }
    }
}
