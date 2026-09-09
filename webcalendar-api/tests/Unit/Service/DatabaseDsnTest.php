<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DatabaseDsn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Taking DATABASE_URL apart.
 *
 * This logic existed twice, in PdoFactory and ReadinessProbe, and could only
 * be exercised by opening a real connection -- so neither copy was tested and
 * they had drifted apart. Both bugs below were found by comparing them.
 */
final class DatabaseDsnTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function urls(): iterable
    {
        yield 'a full mysql url' => [
            'mysql://webcalendar:secret@db.example.com:3307/appdb',
            'mysql', 'mysql:host=db.example.com;port=3307;dbname=appdb;charset=utf8mb4', 'webcalendar', 'secret',
        ];
        yield 'mysql without a port' => [
            'mysql://u:p@db/appdb',
            'mysql', 'mysql:host=db;port=3306;dbname=appdb;charset=utf8mb4', 'u', 'p',
        ];
        yield 'mysql without credentials' => [
            'mysql://db/appdb',
            'mysql', 'mysql:host=db;port=3306;dbname=appdb;charset=utf8mb4', 'root', '',
        ];
        yield 'mysql without a database' => [
            'mysql://db',
            'mysql', 'mysql:host=db;port=3306;dbname=webcalendar;charset=utf8mb4', 'root', '',
        ];
        yield 'postgres' => [
            'postgres://u:p@db:5432/appdb',
            'pgsql', 'pgsql:host=db;port=5432;dbname=appdb;charset=utf8mb4', 'u', 'p',
        ];
        yield 'postgresql' => [
            'postgresql://u:p@db/appdb',
            'pgsql', 'pgsql:host=db;port=3306;dbname=appdb;charset=utf8mb4', 'u', 'p',
        ];
        yield 'pgsql' => [
            'pgsql://u:p@db/appdb',
            'pgsql', 'pgsql:host=db;port=3306;dbname=appdb;charset=utf8mb4', 'u', 'p',
        ];
        yield 'an unknown scheme is treated as mysql' => [
            'somethingelse://db/appdb',
            'mysql', 'mysql:host=db;port=3306;dbname=appdb;charset=utf8mb4', 'root', '',
        ];
        yield 'sqlite in memory' => ['sqlite:///:memory:', 'sqlite', 'sqlite::memory:', '', ''];
        yield 'sqlite on disk' => ['sqlite:///var/data/app.sqlite', 'sqlite', 'sqlite:var/data/app.sqlite', '', ''];
        yield 'sqlite3' => ['sqlite3:///var/data/app.sqlite', 'sqlite', 'sqlite:var/data/app.sqlite', '', ''];
        yield 'pdo-sqlite' => ['pdo-sqlite:///var/data/app.sqlite', 'sqlite', 'sqlite:var/data/app.sqlite', '', ''];
        yield 'pdo-sqlite3' => ['pdo-sqlite3:///var/data/app.sqlite', 'sqlite', 'sqlite:var/data/app.sqlite', '', ''];
        yield 'sqlite written with a host' => ['sqlite://localhost/:memory:', 'sqlite', 'sqlite::memory:', '', ''];
    }

    #[DataProvider('urls')]
    public function testTheUrlIsTakenApart(
        string $url,
        string $driver,
        string $dsn,
        string $user,
        string $password,
    ): void {
        $config = DatabaseDsn::fromUrl($url);

        self::assertSame($driver, $config->driver);
        self::assertSame($dsn, $config->dsn);
        self::assertSame($user, $config->user);
        self::assertSame($password, $config->password);
    }

    public function testASqliteUrlIsNotSilentlyTreatedAsMysql(): void
    {
        // parse_url() returns false for sqlite:///path -- there is no host
        // after the third slash -- so a parser that does not patch a host in,
        // and does not check for false, falls through to every default and
        // builds a connection to mysql://root@localhost/webcalendar instead.
        // That was PdoFactory's behaviour, which is what builds the
        // application's own connection.
        $config = DatabaseDsn::fromUrl('sqlite:///var/data/app.sqlite');

        self::assertSame('sqlite', $config->driver);
        self::assertStringStartsNotWith('mysql:', $config->dsn);
    }

    public function testAUrlThatCannotBeParsedIsRejectedRatherThanDefaulted(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Malformed DATABASE_URL');

        DatabaseDsn::fromUrl('http://:80');
    }

    public function testTheDatabaseNameLosesItsLeadingSlashOnly(): void
    {
        // ltrim, not trim or str_replace: a sqlite path has slashes in it that
        // have to survive.
        self::assertSame(
            'sqlite:var/data/nested/app.sqlite',
            DatabaseDsn::fromUrl('sqlite:///var/data/nested/app.sqlite')->dsn,
        );
    }

    public function testCredentialsSurviveUrlEncoding(): void
    {
        // parse_url does not decode, and neither does this: the value handed
        // to PDO is whatever was configured.
        $config = DatabaseDsn::fromUrl('mysql://user%40host:p%40ss@db/appdb');

        self::assertSame('user%40host', $config->user);
        self::assertSame('p%40ss', $config->password);
    }
}
