<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ReadinessProbe;
use PHPUnit\Framework\TestCase;

final class ReadinessProbeTest extends TestCase
{
    public function testReturnsOkWhenSqlitePingSucceeds(): void
    {
        // sqlite://:memory: creates a fresh in-memory DB per connection.
        $probe = new ReadinessProbe('sqlite:///:memory:');
        $result = $probe->pingDatabase();

        self::assertTrue($result->ok);
        self::assertNotNull($result->latencyMs);
        self::assertGreaterThanOrEqual(0, $result->latencyMs);
    }

    public function testReturnsErrorAndNullLatencyWhenConnectFails(): void
    {
        // Port 1 is always reserved/closed, so a connect attempt blows up fast.
        $probe = new ReadinessProbe('mysql://user:pass@127.0.0.1:1/missing_db');
        $result = $probe->pingDatabase();

        self::assertFalse($result->ok);
        self::assertNull($result->latencyMs);
    }
}
