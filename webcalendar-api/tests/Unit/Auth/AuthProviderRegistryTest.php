<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\AuthProviderRegistry;
use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use App\Auth\OAuthProvider;
use App\Auth\OAuthProviderRepository;
use PHPUnit\Framework\TestCase;

final class AuthProviderRegistryTest extends TestCase
{
    private \PDO $pdo;
    private OAuthProviderRepository $oauthRepo;
    private LdapConfigRepository $ldapRepo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->oauthRepo = new OAuthProviderRepository($this->pdo);
        $this->ldapRepo = new LdapConfigRepository($this->pdo);
    }

    public function testPasswordAlwaysAvailable(): void
    {
        $registry = new AuthProviderRegistry($this->oauthRepo, $this->ldapRepo);
        $providers = $registry->getEnabledProviders();

        $types = array_column($providers, 'type');
        $this->assertContains('password', $types);
    }

    public function testOAuthProvidersIncluded(): void
    {
        $this->oauthRepo->save(new OAuthProvider(0, 'Google', 'oidc', 'id', 's', '', '', '', '', true));
        $this->oauthRepo->save(new OAuthProvider(0, 'Disabled', 'oauth2', 'id', 's', '', '', '', '', false));

        $registry = new AuthProviderRegistry($this->oauthRepo, $this->ldapRepo);
        $providers = $registry->getEnabledProviders();

        $names = array_column($providers, 'name');
        $this->assertContains('Google', $names);
        $this->assertNotContains('Disabled', $names);
    }

    public function testLdapIncludedWhenEnabled(): void
    {
        $this->ldapRepo->save(new LdapConfig(host: 'ldap.corp.com', enabled: true));

        $registry = new AuthProviderRegistry($this->oauthRepo, $this->ldapRepo);
        $providers = $registry->getEnabledProviders();

        $types = array_column($providers, 'type');
        $this->assertContains('ldap', $types);
    }

    public function testLdapExcludedWhenDisabled(): void
    {
        $registry = new AuthProviderRegistry($this->oauthRepo, $this->ldapRepo);
        $providers = $registry->getEnabledProviders();

        $types = array_column($providers, 'type');
        $this->assertNotContains('ldap', $types);
    }

    public function testPriorityOrder(): void
    {
        $this->oauthRepo->save(new OAuthProvider(0, 'Google', 'oidc', 'id', 's', '', '', '', '', true));
        $this->ldapRepo->save(new LdapConfig(host: 'ldap.test', enabled: true));

        $registry = new AuthProviderRegistry($this->oauthRepo, $this->ldapRepo);
        $providers = $registry->getEnabledProviders();

        $types = array_column($providers, 'type');
        // OAuth first, then LDAP, then password
        $oauthIdx = array_search('oidc', $types, true);
        $ldapIdx = array_search('ldap', $types, true);
        $pwIdx = array_search('password', $types, true);

        $this->assertLessThan($ldapIdx, $oauthIdx);
        $this->assertLessThan($pwIdx, $ldapIdx);
    }

    public function testGetLoginMethodsExcludesConfig(): void
    {
        $this->oauthRepo->save(new OAuthProvider(0, 'Google', 'oidc', 'id', 's', '', '', '', '', true));

        $registry = new AuthProviderRegistry($this->oauthRepo, $this->ldapRepo);
        $methods = $registry->getLoginMethods();

        foreach ($methods as $m) {
            $this->assertArrayHasKey('type', $m);
            $this->assertArrayHasKey('name', $m);
            $this->assertArrayNotHasKey('config', $m);
        }
    }
}
