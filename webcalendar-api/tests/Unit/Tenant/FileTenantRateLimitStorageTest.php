<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\FileTenantRateLimitStorage;
use PHPUnit\Framework\TestCase;

final class FileTenantRateLimitStorageTest extends TestCase
{
    private string $tmpDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/wctng_file_storage_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/rate_limits/*');
        if (\is_array($files)) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        @rmdir($this->tmpDir . '/rate_limits');
        @rmdir($this->tmpDir);
    }

    public function testIncrementsFromZeroToOne(): void
    {
        $storage = new FileTenantRateLimitStorage($this->tmpDir);

        $this->assertSame(1, $storage->incrementAndCount('acme', 1000));
    }

    public function testIncrementsMonotonicallyInSameWindow(): void
    {
        $storage = new FileTenantRateLimitStorage($this->tmpDir);

        $this->assertSame(1, $storage->incrementAndCount('acme', 1000));
        $this->assertSame(2, $storage->incrementAndCount('acme', 1000));
        $this->assertSame(3, $storage->incrementAndCount('acme', 1000));
    }

    public function testSeparateSlugsHaveSeparateCounters(): void
    {
        $storage = new FileTenantRateLimitStorage($this->tmpDir);

        $storage->incrementAndCount('acme', 1000);
        $storage->incrementAndCount('acme', 1000);

        $this->assertSame(1, $storage->incrementAndCount('globex', 1000));
    }

    public function testNewWindowStartsFreshAndCleansOldFiles(): void
    {
        $storage = new FileTenantRateLimitStorage($this->tmpDir);

        $storage->incrementAndCount('acme', 1000);
        $storage->incrementAndCount('acme', 1000);

        // Jumping to a newer window: the old file for window 1000 is deleted
        // by the internal cleanup before the new write.
        $this->assertSame(1, $storage->incrementAndCount('acme', 2000));

        $oldFile = $this->tmpDir . '/rate_limits/acme_1000.count';
        $this->assertFileDoesNotExist($oldFile);
    }
}
