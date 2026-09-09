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

    // --- the bounds, which are the reason this class exists ---

    public function testASqliteProbeOpensThePathWithNoCredentialsAndNoTimeout(): void
    {
        $connector = new RecordingProbeConnector();

        (new ReadinessProbe('sqlite:///:memory:', $connector))->pingDatabase();

        $connection = $connector->onlyConnection();
        self::assertSame('sqlite::memory:', $connection['dsn']);
        self::assertSame('', $connection['user']);
        self::assertSame('', $connection['password']);
        self::assertSame([\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION], $connection['options']);
    }

    public function testAMysqlProbeIsBoundedByAConnectTimeout(): void
    {
        // One second. Without it a database that accepts the TCP connection
        // but never completes the handshake hangs the readiness endpoint,
        // which is the exact failure this class was written to avoid.
        $connector = new RecordingProbeConnector();

        (new ReadinessProbe('mysql://u:p@db/appdb', $connector))->pingDatabase();

        $connection = $connector->onlyConnection();
        self::assertSame('mysql:host=db;port=3306;dbname=appdb;charset=utf8mb4', $connection['dsn']);
        self::assertSame('u', $connection['user']);
        self::assertSame('p', $connection['password']);
        self::assertSame([
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 1,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ], $connection['options']);
    }

    public function testAMysqlProbeCapsHowLongTheQueryMayRun(): void
    {
        $connector = new RecordingProbeConnector();

        (new ReadinessProbe('mysql://u:p@db/appdb', $connector))->pingDatabase();

        self::assertNotNull($connector->pdo);
        self::assertSame(['SET SESSION MAX_EXECUTION_TIME=100'], $connector->pdo->statements);
    }

    public function testOnlyMysqlGetsTheExecutionTimeCap(): void
    {
        // MAX_EXECUTION_TIME is MySQL syntax; sending it to PostgreSQL would
        // turn a readiness check into a syntax error.
        $connector = new RecordingProbeConnector();

        (new ReadinessProbe('pgsql://u:p@db/appdb', $connector))->pingDatabase();

        self::assertNotNull($connector->pdo);
        self::assertSame([], $connector->pdo->statements);
    }

    public function testTheProbeActuallyQueries(): void
    {
        // Connecting is not the check. A server can accept connections while
        // being unable to answer anything.
        $connector = new RecordingProbeConnector();

        $result = (new ReadinessProbe('sqlite:///:memory:', $connector))->pingDatabase();

        self::assertTrue($result->ok);
        self::assertNotNull($connector->pdo);
        self::assertSame(['SELECT 1'], $connector->pdo->queries);
    }

    public function testAConnectionThatCannotAnswerIsNotReady(): void
    {
        $connector = new RecordingProbeConnector(queryFails: true);

        $result = (new ReadinessProbe('sqlite:///:memory:', $connector))->pingDatabase();

        self::assertFalse($result->ok);
        self::assertNull($result->latencyMs);
    }

    public function testAFailureToConnectIsNotReady(): void
    {
        $connector = new RecordingProbeConnector(connectFailure: new \PDOException('connection refused'));

        $result = (new ReadinessProbe('mysql://u:p@db/appdb', $connector))->pingDatabase();

        self::assertFalse($result->ok);
        self::assertNull($result->latencyMs);
    }

    public function testTheLatencyIsAnElapsedTimeRatherThanAClockReading(): void
    {
        // Adding the two instants instead of subtracting them yields something
        // around 1.8e12 milliseconds, which is a plausible-looking integer
        // until you notice it is fifty-odd years.
        $connector = new RecordingProbeConnector();

        $result = (new ReadinessProbe('sqlite:///:memory:', $connector))->pingDatabase();

        self::assertNotNull($result->latencyMs);
        self::assertGreaterThanOrEqual(0, $result->latencyMs);
        self::assertLessThan(60_000, $result->latencyMs, 'an in-memory ping does not take a minute');
    }
}
