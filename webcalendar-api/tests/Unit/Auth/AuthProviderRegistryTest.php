<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\AuthProviderRegistry;
use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use App\Auth\OAuthProvider;
use App\Auth\OAuthProviderRepository;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ------------------------------------------------- the order they are tried

    private function registry(): AuthProviderRegistry
    {
        return new AuthProviderRegistry($this->oauthRepo, $this->ldapRepo);
    }

    private function saveOauth(string $name, string $type = 'oidc', bool $enabled = true): void
    {
        $this->oauthRepo->save(new OAuthProvider(
            0,
            $name,
            $type,
            'client-id',
            'secret',
            'https://' . strtolower($name) . '.example/auth',
            'https://' . strtolower($name) . '.example/token',
            'https://' . strtolower($name) . '.example/userinfo',
            'openid email',
            $enabled,
        ));
    }

    public function testSeveralOauthProvidersKeepTheOrderTheRepositoryHandsThemOver(): void
    {
        // findAll() sorts by name, and each provider then takes the next
        // priority as it is read, which carries that order through the sort
        // below. Without the increment they all share one priority and the
        // ordering becomes whatever usort does with ties -- so these are
        // registered out of alphabetical order deliberately, to show the name
        // ordering is what survives rather than the insertion sequence.
        $this->saveOauth('Gamma');
        $this->saveOauth('Alpha');
        $this->saveOauth('Beta');

        self::assertSame(
            ['Alpha', 'Beta', 'Gamma', 'Password'],
            array_column($this->registry()->getEnabledProviders(), 'name'),
        );
    }

    public function testTheWholeChainIsOrderedOauthThenLdapThenPassword(): void
    {
        // The existing case checks pairwise "before" relations, which several
        // orderings satisfy. This pins the sequence.
        $this->saveOauth('Google');
        $this->saveOauth('Entra');
        $this->ldapRepo->save(new LdapConfig(host: 'ldap.test', enabled: true));

        self::assertSame(
            ['Entra', 'Google', 'LDAP', 'Password'],
            array_column($this->registry()->getEnabledProviders(), 'name'),
        );
    }

    // ------------------------------------------------ when LDAP counts as on

    /** @return iterable<string, array{bool, string, bool}> */
    public static function ldapConfigurations(): iterable
    {
        // Enabled *and* configured. An enabled directory with no host cannot
        // be dialled, so offering it puts a login method on the page that can
        // only ever fail.
        yield 'enabled with a host' => [true, 'ldap.test', true];
        yield 'enabled with no host' => [true, '', false];
        yield 'disabled with a host' => [false, 'ldap.test', false];
        yield 'disabled with no host' => [false, '', false];
    }

    #[DataProvider('ldapConfigurations')]
    public function testLdapIsOfferedOnlyWhenItIsBothEnabledAndConfigured(
        bool $enabled,
        string $host,
        bool $offered,
    ): void {
        $this->ldapRepo->save(new LdapConfig(host: $host, enabled: $enabled));

        $types = array_column($this->registry()->getEnabledProviders(), 'type');

        self::assertSame($offered, \in_array('ldap', $types, true));
    }

    public function testADisabledOauthProviderIsNotOffered(): void
    {
        $this->saveOauth('Switched off', 'oidc', false);

        self::assertSame(['password'], array_column($this->registry()->getEnabledProviders(), 'type'));
    }

    // ------------------------------------------------------- what each carries

    public function testEachProviderCarriesTheConfigTheChainNeeds(): void
    {
        // getLoginMethods() strips config for the login page, but
        // getEnabledProviders() is what the authenticator reads, and the
        // config is the only place the provider's own settings travel.
        $this->saveOauth('Google');
        $this->ldapRepo->save(new LdapConfig(host: 'ldap.test', port: 636, enabled: true));

        $byType = [];
        foreach ($this->registry()->getEnabledProviders() as $provider) {
            $byType[$provider['type']] = $provider;
        }

        self::assertArrayHasKey('config', $byType['ldap']);
        self::assertSame('ldap.test', $byType['ldap']['config']['host'] ?? null);
        self::assertArrayHasKey('config', $byType['oidc']);
        self::assertSame('Google', $byType['oidc']['config']['name'] ?? null);
        self::assertSame([], $byType['password']['config']);
    }

    public function testTheLoginPageNeverSeesAProvidersSecrets(): void
    {
        // getLoginMethods() is what reaches an unauthenticated visitor.
        $this->saveOauth('Google');
        $this->ldapRepo->save(new LdapConfig(host: 'ldap.test', bindPassword: 'service-secret', enabled: true));

        $encoded = json_encode($this->registry()->getLoginMethods(), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('secret', $encoded);
        self::assertStringNotContainsString('client-id', $encoded);
        self::assertSame(
            ['Google', 'LDAP', 'Password'],
            array_column($this->registry()->getLoginMethods(), 'name'),
        );
    }
}
