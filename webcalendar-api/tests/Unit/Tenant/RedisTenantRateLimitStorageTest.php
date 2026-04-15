<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\RedisTenantRateLimitStorage;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

/**
 * Tests the Redis backend with an in-memory fake — we don't want the
 * suite to require a running Redis and Predis's `ClientInterface`
 * defines `incr()` / `expire()` via `@method` annotations (handled by
 * `__call`), so PHPUnit's mock builder can't stub them directly.
 */
final class RedisTenantRateLimitStorageTest extends TestCase
{
    public function testIncrementReturnsPostIncrementCount(): void
    {
        $fake = new FakePredisClient();
        $storage = new RedisTenantRateLimitStorage($fake);

        $this->assertSame(1, $storage->incrementAndCount('acme', 1000));
        $this->assertSame(2, $storage->incrementAndCount('acme', 1000));
        $this->assertSame(3, $storage->incrementAndCount('acme', 1000));
    }

    public function testSetsTtlOnlyOnFirstIncrement(): void
    {
        $fake = new FakePredisClient();
        $storage = new RedisTenantRateLimitStorage($fake);

        $storage->incrementAndCount('acme', 1000);
        $storage->incrementAndCount('acme', 1000);
        $storage->incrementAndCount('acme', 1000);

        // EXPIRE runs once, on the first INCR (when the counter goes 0→1).
        $this->assertSame(1, $fake->expireCalls);
    }

    public function testSeparateSlugsAreIndependent(): void
    {
        $fake = new FakePredisClient();
        $storage = new RedisTenantRateLimitStorage($fake);

        $storage->incrementAndCount('acme', 1000);
        $storage->incrementAndCount('acme', 1000);

        $this->assertSame(1, $storage->incrementAndCount('globex', 1000));
    }

    public function testSeparateWindowsAreIndependent(): void
    {
        $fake = new FakePredisClient();
        $storage = new RedisTenantRateLimitStorage($fake);

        $storage->incrementAndCount('acme', 1000);
        $storage->incrementAndCount('acme', 1000);

        $this->assertSame(1, $storage->incrementAndCount('acme', 2000));
    }

    public function testTwoClientsSharingBackendSeeSameCounter(): void
    {
        // The file-based backend would FAIL this test in a real multi-node
        // deploy because each node has a private filesystem. Redis passes
        // because both clients talk to the same backing store — this is
        // the whole point of the Redis backend.
        $fake = new FakePredisClient();

        $nodeA = new RedisTenantRateLimitStorage($fake);
        $nodeB = new RedisTenantRateLimitStorage($fake);

        $nodeA->incrementAndCount('acme', 1000);
        $nodeA->incrementAndCount('acme', 1000);

        $this->assertSame(3, $nodeB->incrementAndCount('acme', 1000));
    }
}

/**
 * Minimal stand-in for Predis's `ClientInterface` that services the two
 * methods the storage class actually calls. Predis returns raw ints
 * from `incr()` and `expire()` (the latter 1/0), so we mirror that.
 *
 * `ClientInterface` declares many members as untyped shims (no return
 * types or param types), so this class matches those signatures literally
 * to stay Liskov-compatible.
 *
 * @internal
 */
final class FakePredisClient implements ClientInterface
{
    /** @var array<string, int> */
    private array $values = [];

    public int $expireCalls = 0;

    public function incr(string $key): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;

        return $this->values[$key];
    }

    public function expire(string $key, int $seconds, string $expireOption = ''): int
    {
        $this->expireCalls++;

        return isset($this->values[$key]) ? 1 : 0;
    }

    public function __call($method, $arguments)
    {
        throw new \BadMethodCallException("FakePredisClient does not implement {$method}()");
    }

    public function getProfile()
    {
        throw new \BadMethodCallException('not implemented');
    }

    public function getOptions()
    {
        throw new \BadMethodCallException('not implemented');
    }

    public function connect()
    {
        throw new \BadMethodCallException('not implemented');
    }

    public function disconnect()
    {
        throw new \BadMethodCallException('not implemented');
    }

    public function getConnection()
    {
        throw new \BadMethodCallException('not implemented');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new \BadMethodCallException('not implemented');
    }

    public function executeCommand(\Predis\Command\CommandInterface $command)
    {
        throw new \BadMethodCallException('not implemented');
    }

    public function getCommandFactory()
    {
        throw new \BadMethodCallException('not implemented');
    }
}
