<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PdoFactory;
use PHPUnit\Framework\TestCase;

/**
 * The application's own connection.
 *
 * This is what config/services.yaml calls to build pdo.connection, and it had
 * no test at all.
 */
final class PdoFactoryTest extends TestCase
{
    public function testASqliteUrlOpensSqlite(): void
    {
        // It did not. parse_url() returns false for sqlite:///... because
        // there is no host after the third slash, and this factory read the
        // scheme, host, port, user and database straight off that false
        // without checking it -- so every field fell back to its default and
        // the "sqlite" connection was an attempt to reach MySQL as root on
        // localhost. Nothing failed loudly; it just connected somewhere else.
        $pdo = PdoFactory::createFromUrl('sqlite:///:memory:');

        self::assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testTheConnectionThrowsRatherThanReturningFalseRows(): void
    {
        $pdo = PdoFactory::createFromUrl('sqlite:///:memory:');

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));

        $this->expectException(\PDOException::class);
        $pdo->query('SELECT * FROM nothing_here');
    }

    public function testAMalformedUrlIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        PdoFactory::createFromUrl('http://:80');
    }
}
