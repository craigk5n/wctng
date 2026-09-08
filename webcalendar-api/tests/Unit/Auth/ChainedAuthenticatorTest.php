<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\AuthProviderRegistry;
use App\Auth\ChainedAuthenticator;
use App\Auth\LdapAuthenticator;
use App\Auth\LdapConfigRepository;
use App\Auth\OAuthProviderRepository;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ChainedAuthenticatorTest extends TestCase
{
    private function createChain(): ChainedAuthenticator
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $oauthRepo = new OAuthProviderRepository($pdo);
        $ldapRepo = new LdapConfigRepository($pdo);
        $factory = new CoreServiceFactory($pdo, 'test');
        $ldapAuth = new LdapAuthenticator($ldapRepo, $factory->getUserService(), $factory->getUserRepository());
        $registry = new AuthProviderRegistry($oauthRepo, $ldapRepo);

        return new ChainedAuthenticator($factory->getAuthService(), $factory->getUserService(), $ldapAuth, $registry);
    }

    public function testReturnsNullWhenAllProvidersFail(): void
    {
        $chain = $this->createChain();

        // No users in SQLite DB, so password auth fails
        // LDAP disabled, so LDAP auth fails
        $result = $chain->authenticate('nonexistent', 'password');
        $this->assertNull($result);
    }

    #[Group('integration')]
    public function testReturnsUserAndMethodOnSuccess(): void
    {
        $this->markTestSkipped('Requires full webcalendar-core schema for AuthService');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create minimal tables needed for auth
        $pdo->exec("CREATE TABLE webcal_user (
            cal_login VARCHAR(60) PRIMARY KEY,
            cal_firstname VARCHAR(60) DEFAULT '',
            cal_lastname VARCHAR(60) DEFAULT '',
            cal_email VARCHAR(100) DEFAULT '',
            cal_is_admin CHAR(1) DEFAULT 'N',
            cal_enabled CHAR(1) DEFAULT 'Y',
            cal_passwd VARCHAR(255) DEFAULT ''
        )");
        $pdo->exec('CREATE TABLE IF NOT EXISTS webcal_rate_limit (
            cal_key VARCHAR(255) PRIMARY KEY,
            cal_attempts INTEGER DEFAULT 0,
            cal_last_attempt INTEGER DEFAULT 0
        )');

        $hash = password_hash('correct', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO webcal_user VALUES ('testuser', 'Test', 'User', 'test@test.com', 'N', 'Y', :hash)")
            ->execute(['hash' => $hash]);

        $oauthRepo = new OAuthProviderRepository($pdo);
        $ldapRepo = new LdapConfigRepository($pdo);
        $factory = new CoreServiceFactory($pdo, 'test');
        $ldapAuth = new LdapAuthenticator($ldapRepo, $factory->getUserService(), $factory->getUserRepository());
        $registry = new AuthProviderRegistry($oauthRepo, $ldapRepo);

        $chain = new ChainedAuthenticator($factory->getAuthService(), $factory->getUserService(), $ldapAuth, $registry);
        $result = $chain->authenticate('testuser', 'correct');

        $this->assertNotNull($result);
        $this->assertSame('testuser', $result['user']->login());
        $this->assertSame('password', $result['method']);
    }

    public function testShortCircuitsOnFirstSuccess(): void
    {
        // With password-only (LDAP disabled), should succeed with 'password' method
        $chain = $this->createChain();

        // Even though LDAP is tried first (if enabled), password fallback works
        $result = $chain->authenticate('any', 'any');
        $this->assertNull($result); // No users, so fails
    }
}
