<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LdapAuthenticator;
use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;

final class LdapAuthenticatorTest extends TestCase
{
    public function testReturnsNullWhenLdapDisabled(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $configRepo = new LdapConfigRepository($pdo);
        // Default config has enabled=false
        $factory = new CoreServiceFactory($pdo, 'test');

        $auth = new LdapAuthenticator($configRepo, $factory->getUserService(), $factory->getUserRepository());
        $result = $auth->authenticate('user', 'pass');

        $this->assertNull($result);
    }

    public function testReturnsNullWhenHostEmpty(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $configRepo = new LdapConfigRepository($pdo);
        $configRepo->save(new LdapConfig(host: '', enabled: true));
        $factory = new CoreServiceFactory($pdo, 'test');

        $auth = new LdapAuthenticator($configRepo, $factory->getUserService(), $factory->getUserRepository());
        $result = $auth->authenticate('user', 'pass');

        $this->assertNull($result);
    }

    public function testIsAvailableReturnsFalseWhenDisabled(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $configRepo = new LdapConfigRepository($pdo);
        $factory = new CoreServiceFactory($pdo, 'test');

        $auth = new LdapAuthenticator($configRepo, $factory->getUserService(), $factory->getUserRepository());
        $this->assertFalse($auth->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenEnabledAndExtensionLoaded(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $configRepo = new LdapConfigRepository($pdo);
        $configRepo->save(new LdapConfig(host: 'ldap.example.com', enabled: true));
        $factory = new CoreServiceFactory($pdo, 'test');

        $auth = new LdapAuthenticator($configRepo, $factory->getUserService(), $factory->getUserRepository());

        // Result depends on whether PHP LDAP extension is loaded
        if (\function_exists('ldap_connect')) {
            $this->assertTrue($auth->isAvailable());
        } else {
            $this->assertFalse($auth->isAvailable());
        }
    }

    public function testReturnsNullForUnreachableLdapServer(): void
    {
        if (!\function_exists('ldap_connect')) {
            $this->markTestSkipped('LDAP extension not available');
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $configRepo = new LdapConfigRepository($pdo);
        $configRepo->save(new LdapConfig(
            host: '127.0.0.1',
            port: 19389, // Non-existent port
            baseDn: 'dc=test,dc=com',
            userFilter: '(uid=%s)',
            enabled: true,
        ));
        $factory = new CoreServiceFactory($pdo, 'test');

        $auth = new LdapAuthenticator($configRepo, $factory->getUserService(), $factory->getUserRepository());
        $result = $auth->authenticate('testuser', 'testpass');

        $this->assertNull($result);
    }
}
