<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LegacyDsn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The --dsn string, which is the only input webcalendar:import-legacy takes.
 *
 * It was parsed inside a private method that went straight on to open the
 * connection, so nothing could reach the decisions in it, and two of them were
 * wrong: the sqlite spelling the command documents was rejected, and
 * credentials were used with their percent-encoding still on.
 */
final class LegacyDsnTest extends TestCase
{
    // --------------------------------------------------------------- sqlite

    /** @return iterable<string, array{string, string}> */
    public static function sqliteSpellings(): iterable
    {
        // The one the command's own error message tells people to use, and
        // the one parse_url rejects outright.
        yield 'three slashes, as documented' => ['sqlite:///tmp/legacy.db', 'sqlite:/tmp/legacy.db'];
        yield 'one slash' => ['sqlite:/tmp/legacy.db', 'sqlite:/tmp/legacy.db'];
        yield 'relative' => ['sqlite://legacy.db', 'sqlite:legacy.db'];
        // Both halves present: joined the other way round this reads
        // "/legacy.dbtmp", which is why the order is asserted and not assumed.
        yield 'relative, with a directory' => ['sqlite://tmp/legacy.db', 'sqlite:tmp/legacy.db'];
    }

    #[DataProvider('sqliteSpellings')]
    public function testSqlitePathsBecomeAPdoDsn(string $given, string $expected): void
    {
        $parsed = LegacyDsn::parse($given);

        self::assertSame($expected, $parsed['dsn']);
        self::assertNull($parsed['user'], 'sqlite has no credentials to pass');
        self::assertNull($parsed['password']);
    }

    public function testSqliteWithNoPathAtAllIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LegacyDsn::parse('sqlite://');
    }

    // ---------------------------------------------------------------- mysql

    public function testMysqlCarriesHostPortDatabaseAndCharset(): void
    {
        $parsed = LegacyDsn::parse('mysql://bob:secret@db.internal:3307/webcalendar');

        self::assertSame('mysql:host=db.internal;port=3307;dbname=webcalendar;charset=utf8mb4', $parsed['dsn']);
        self::assertSame('bob', $parsed['user']);
        self::assertSame('secret', $parsed['password']);
    }

    public function testMysqlFallsBackToTheUsualHostPortAndUser(): void
    {
        $parsed = LegacyDsn::parse('mysql://localhost/webcalendar');

        self::assertSame('mysql:host=localhost;port=3306;dbname=webcalendar;charset=utf8mb4', $parsed['dsn']);
        self::assertSame('root', $parsed['user']);
        self::assertSame('', $parsed['password']);
    }

    /**
     * A URI's userinfo has to be percent-encoded -- a password containing @ or
     * / cannot be written raw, because the authority would not parse. So the
     * encoded spelling is the correct one, and it was the one that failed:
     * parse_url returns what it found without decoding, and p%40ss was handed
     * to the server as a password spelled p%40ss.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function encodedPasswords(): iterable
    {
        yield 'an at sign' => ['p%40ss', 'p@ss'];
        yield 'a slash' => ['pa%2Fss', 'pa/ss'];
        yield 'a hash' => ['pass%23word', 'pass#word'];
        yield 'a colon' => ['pa%3Ass', 'pa:ss'];
        yield 'a space' => ['pass%20word', 'pass word'];
        yield 'nothing to decode' => ['plainpass', 'plainpass'];
    }

    #[DataProvider('encodedPasswords')]
    public function testAPasswordIsDecodedBeforeItReachesTheServer(string $written, string $sent): void
    {
        $parsed = LegacyDsn::parse("mysql://bob:{$written}@db.internal/webcalendar");

        self::assertSame($sent, $parsed['password']);
    }

    public function testAUsernameIsDecodedTheSameWay(): void
    {
        $parsed = LegacyDsn::parse('mysql://real%20name:secret@db.internal/webcalendar');

        self::assertSame('real name', $parsed['user']);
    }

    // --------------------------------------------------------------- pgsql

    /** @return iterable<string, array{string}> */
    public static function postgresSchemes(): iterable
    {
        yield 'pgsql' => ['pgsql'];
        yield 'postgresql' => ['postgresql'];
    }

    #[DataProvider('postgresSchemes')]
    public function testPostgresIsAcceptedUnderBothItsNames(string $scheme): void
    {
        $parsed = LegacyDsn::parse("{$scheme}://alice:pw@pg.internal/webcalendar");

        self::assertSame('pgsql:host=pg.internal;port=5432;dbname=webcalendar', $parsed['dsn']);
        self::assertSame('alice', $parsed['user']);
        self::assertSame('pw', $parsed['password']);
    }

    public function testPostgresFallsBackToItsOwnDefaultUser(): void
    {
        self::assertSame('postgres', LegacyDsn::parse('pgsql://pg.internal/webcalendar')['user']);
    }

    // -------------------------------------------------------------- refused

    /** @return iterable<string, array{string}> */
    public static function stringsThatAreNotDsns(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['just-a-name'];
        yield 'a path' => ['/var/lib/webcalendar.db'];
        yield 'a scheme nobody supports' => ['oracle://user:pw@host/db'];
        yield 'a scheme that looks close' => ['mysqli://user:pw@host/db'];
        // The sqlite fallback is anchored; without the anchor this matches
        // from wherever "sqlite://" happens to appear.
        yield 'junk in front of a good scheme' => ['nonsense sqlite:///tmp/legacy.db'];
        yield 'an upper-case scheme' => ['SQLITE:///tmp/legacy.db'];
    }

    #[DataProvider('stringsThatAreNotDsns')]
    public function testAnythingItCannotOpenIsRefused(string $dsn): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LegacyDsn::parse($dsn);
    }

    public function testTheRefusalNamesTheSchemeItDidNotKnow(): void
    {
        $this->expectExceptionMessage('oracle');

        LegacyDsn::parse('oracle://user:pw@host/db');
    }
}
