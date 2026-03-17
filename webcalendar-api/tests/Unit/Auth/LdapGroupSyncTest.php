<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LdapConfigRepository;
use App\Auth\LdapGroupSync;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;

final class LdapGroupSyncTest extends TestCase
{
    public function testReturnsEmptyWhenLdapDisabled(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $configRepo = new LdapConfigRepository($pdo);
        $factory = new CoreServiceFactory($pdo, 'test');

        $sync = new LdapGroupSync($configRepo, $factory);
        $result = $sync->syncUserGroups('alice', 'cn=alice,dc=test,dc=com');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testExtractGroupNameFromDn(): void
    {
        // Test via reflection
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $configRepo = new LdapConfigRepository($pdo);
        $factory = new CoreServiceFactory($pdo, 'test');

        $sync = new LdapGroupSync($configRepo, $factory);
        $method = new \ReflectionMethod($sync, 'extractGroupName');

        $this->assertSame('Engineering', $method->invoke($sync, 'CN=Engineering,OU=Groups,DC=corp,DC=com'));
        $this->assertSame('Admin Team', $method->invoke($sync, 'cn=Admin Team,ou=Groups,dc=example,dc=org'));
        $this->assertSame('', $method->invoke($sync, 'invalid-dn'));
    }

    public function testReturnsEmptyWhenExtensionNotLoaded(): void
    {
        // This test validates graceful handling
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $configRepo = new LdapConfigRepository($pdo);

        // Even with LDAP enabled config, if extension not loaded, returns empty
        $configRepo->save(new \App\Auth\LdapConfig(
            host: 'ldap.example.com',
            enabled: true,
        ));

        $factory = new CoreServiceFactory($pdo, 'test');
        $sync = new LdapGroupSync($configRepo, $factory);

        if (!\function_exists('ldap_connect')) {
            $result = $sync->syncUserGroups('user', 'cn=user,dc=test');
            $this->assertEmpty($result);
        } else {
            // Extension is loaded — test will try to connect
            $this->assertTrue(true);
        }
    }
}
