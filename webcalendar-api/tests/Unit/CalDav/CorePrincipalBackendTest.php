<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CorePrincipalBackend;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

final class CorePrincipalBackendTest extends TestCase
{
    public function testImplementsBackendInterface(): void
    {
        $this->assertTrue(
            is_subclass_of(CorePrincipalBackend::class, BackendInterface::class),
        );
    }

    public function testGetPrincipalByPathReturnsUserDetails(): void
    {
        $pdo = $this->createTestPdo();
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        // Create a test user
        $this->createTestUser($pdo, 'alice', 'Alice', 'Wonder', 'alice@example.com');

        $backend = new CorePrincipalBackend($factory);
        $principal = $backend->getPrincipalByPath('principals/alice');

        $this->assertNotEmpty($principal);
        $this->assertSame('principals/alice', $principal['uri']);
        $this->assertSame('Alice Wonder', $principal['{DAV:}displayname']);
        $this->assertSame('alice@example.com', $principal['{http://sabredav.org/ns}email-address']);
        $this->assertSame('calendars/alice/', $principal['{urn:ietf:params:xml:ns:caldav}calendar-home-set']);
    }

    public function testGetPrincipalByPathReturnsEmptyForUnknown(): void
    {
        $pdo = $this->createTestPdo();
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $backend = new CorePrincipalBackend($factory);
        $principal = $backend->getPrincipalByPath('principals/nonexistent');

        $this->assertEmpty($principal);
    }

    public function testGetPrincipalsByPrefixReturnsAllUsers(): void
    {
        $pdo = $this->createTestPdo();
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $this->createTestUser($pdo, 'alice', 'Alice', 'A', 'alice@test.com');
        $this->createTestUser($pdo, 'bob', 'Bob', 'B', 'bob@test.com');

        $backend = new CorePrincipalBackend($factory);
        $principals = $backend->getPrincipalsByPrefix('principals');

        $this->assertCount(2, $principals);
        $uris = array_map(static fn (array $p) => $p['uri'], $principals);
        $this->assertContains('principals/alice', $uris);
        $this->assertContains('principals/bob', $uris);
    }

    public function testGetPrincipalsByPrefixReturnsEmptyForWrongPrefix(): void
    {
        $pdo = $this->createTestPdo();
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $backend = new CorePrincipalBackend($factory);
        $principals = $backend->getPrincipalsByPrefix('users');

        $this->assertEmpty($principals);
    }

    public function testPrincipalIncludesCalendarHomeSet(): void
    {
        $pdo = $this->createTestPdo();
        $factory = new CoreServiceFactory($pdo, 'test_secret');

        $this->createTestUser($pdo, 'charlie', 'Charlie', 'C', 'c@test.com');

        $backend = new CorePrincipalBackend($factory);
        $principal = $backend->getPrincipalByPath('principals/charlie');

        $this->assertArrayHasKey('{urn:ietf:params:xml:ns:caldav}calendar-home-set', $principal);
        $this->assertSame('calendars/charlie/', $principal['{urn:ietf:params:xml:ns:caldav}calendar-home-set']);
    }

    private function createTestPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create minimal webcal_user table
        $pdo->exec("CREATE TABLE webcal_user (
            cal_login VARCHAR(60) PRIMARY KEY,
            cal_firstname VARCHAR(60) DEFAULT '',
            cal_lastname VARCHAR(60) DEFAULT '',
            cal_email VARCHAR(100) DEFAULT '',
            cal_is_admin CHAR(1) DEFAULT 'N',
            cal_enabled CHAR(1) DEFAULT 'Y',
            cal_passwd VARCHAR(255) DEFAULT ''
        )");

        return $pdo;
    }

    private function createTestUser(\PDO $pdo, string $login, string $first, string $last, string $email): void
    {
        $pdo->prepare(
            "INSERT INTO webcal_user (cal_login, cal_firstname, cal_lastname, cal_email, cal_is_admin, cal_enabled)
             VALUES (:login, :first, :last, :email, 'N', 'Y')",
        )->execute(['login' => $login, 'first' => $first, 'last' => $last, 'email' => $email]);
    }
}
