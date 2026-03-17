<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use PHPUnit\Framework\TestCase;

final class LdapConfigTest extends TestCase
{
    public function testDefaultConfig(): void
    {
        $config = new LdapConfig();

        $this->assertSame('', $config->host());
        $this->assertSame(389, $config->port());
        $this->assertSame('', $config->baseDn());
        $this->assertSame('(uid=%s)', $config->userFilter());
        $this->assertFalse($config->useTls());
        $this->assertFalse($config->isEnabled());
    }

    public function testToArrayExcludesPassword(): void
    {
        $config = new LdapConfig(host: 'ldap.example.com', bindPassword: 'secret');
        $array = $config->toArray();

        $this->assertArrayHasKey('host', $array);
        $this->assertArrayNotHasKey('bind_password', $array);
    }

    public function testSaveAndGet(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $repo = new LdapConfigRepository($pdo);

        $config = new LdapConfig(
            host: 'ldap.corp.com',
            port: 636,
            baseDn: 'dc=corp,dc=com',
            bindDn: 'cn=admin,dc=corp,dc=com',
            bindPassword: 'admin_pass',
            userFilter: '(sAMAccountName=%s)',
            useTls: true,
            enabled: true,
        );

        $repo->save($config);

        $loaded = $repo->get();
        $this->assertSame('ldap.corp.com', $loaded->host());
        $this->assertSame(636, $loaded->port());
        $this->assertSame('dc=corp,dc=com', $loaded->baseDn());
        $this->assertSame('(sAMAccountName=%s)', $loaded->userFilter());
        $this->assertTrue($loaded->useTls());
        $this->assertTrue($loaded->isEnabled());
    }

    public function testUpdateConfig(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $repo = new LdapConfigRepository($pdo);

        $repo->save(new LdapConfig(host: 'old.host'));
        $repo->save(new LdapConfig(host: 'new.host'));

        $loaded = $repo->get();
        $this->assertSame('new.host', $loaded->host());
    }

    public function testGetReturnsDefaultWhenEmpty(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $repo = new LdapConfigRepository($pdo);

        $config = $repo->get();
        $this->assertSame('', $config->host());
        $this->assertFalse($config->isEnabled());
    }
}
