<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\AuthProviderRegistry;
use App\Auth\ChainedAuthenticator;
use App\Auth\LdapAuthenticator;
use App\Auth\LdapClient;
use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use App\Auth\LdapConnection;
use App\Auth\OAuthProvider;
use App\Auth\OAuthProviderRepository;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use WebCalendar\Core\Domain\Entity\User;

/**
 * The provider chain, driven end to end.
 *
 * The success path here used to be skipped as "requires full webcalendar-core
 * schema for AuthService", and the short-circuit case asserted null because
 * there were no users to authenticate -- so nothing exercised a successful
 * login at all. Every other Auth test in this suite loads that schema out of
 * vendor, which is all the skip needed.
 */
final class ChainedAuthenticatorTest extends TestCase
{
    private \PDO $pdo;
    private CoreServiceFactory $factory;
    private OAuthProviderRepository $oauthRepo;
    private LdapConfigRepository $ldapRepo;
    private RecordingChainLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->loadCoreSchema();

        $this->factory = new CoreServiceFactory($this->pdo, 'test');
        $this->oauthRepo = new OAuthProviderRepository($this->pdo);
        $this->ldapRepo = new LdapConfigRepository($this->pdo);
        $this->logger = new RecordingChainLogger();
    }

    private function loadCoreSchema(): void
    {
        $path = __DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql';
        $schema = file_get_contents($path);
        self::assertIsString($schema, 'cannot read the webcalendar-core sqlite schema');

        foreach (preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?: [] as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException) {
                    // Not every statement applies to this SQLite build.
                }
            }
        }
    }

    private function chain(?LdapAuthenticator $ldapAuth = null): ChainedAuthenticator
    {
        return new ChainedAuthenticator(
            $this->factory->getAuthService(),
            $this->factory->getUserService(),
            $ldapAuth ?? new LdapAuthenticator($this->ldapRepo, $this->factory->getUserService(), $this->factory->getUserRepository()),
            new AuthProviderRegistry($this->oauthRepo, $this->ldapRepo),
            $this->logger,
        );
    }

    private function createUser(string $login, string $password): void
    {
        $admin = new User('admin', 'Admin', 'User', 'admin@example.com', true, true);
        $this->factory->getUserService()->createUser($admin, $admin);

        $user = new User($login, 'Test', 'User', $login . '@example.com', false, true);
        $this->factory->getUserService()->createUser($user, $admin);
        $this->factory->getUserRepository()->setPassword(
            $login,
            $this->factory->getUserService()->hashPassword($password),
        );
    }

    private function enableLdap(): void
    {
        $this->ldapRepo->save(new LdapConfig(host: 'ldap.example.com', port: 389, enabled: true));
    }

    // ------------------------------------------------------- the happy path

    public function testACorrectPasswordAuthenticatesAndSaysWhichProviderDidIt(): void
    {
        $this->createUser('alice', 'correct-horse');

        $result = $this->chain()->authenticate('alice', 'correct-horse');

        self::assertNotNull($result);
        self::assertSame('alice', $result['user']->login());
        self::assertSame('password', $result['method']);
    }

    public function testAWrongPasswordIsRefused(): void
    {
        // tryPasswordAuth() negates the AuthService result. Drop that negation
        // and every wrong password is accepted for any user that exists.
        $this->createUser('alice', 'correct-horse');

        self::assertNull($this->chain()->authenticate('alice', 'wrong'));
    }

    public function testAnUnknownUserIsRefused(): void
    {
        $this->createUser('alice', 'correct-horse');

        self::assertNull($this->chain()->authenticate('mallory', 'correct-horse'));
    }

    // ------------------------------------------------- which provider runs

    public function testALdapProviderIsTriedAndCanSucceed(): void
    {
        // The match arm for 'ldap' had nothing behind it: with the arm gone,
        // every LDAP deployment falls through to the default and stops
        // authenticating, while password-only deployments carry on working.
        $this->enableLdap();
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = ['count' => 1, 0 => ['dn' => 'uid=bob,dc=example,dc=com']];
        $ldap->connection->readEntries = [
            'count' => 1,
            0 => [
                'givenname' => ['count' => 1, 0 => 'Bob'],
                'sn' => ['count' => 1, 0 => 'Jones'],
                'mail' => ['count' => 1, 0 => 'bob@example.com'],
            ],
        ];

        $result = $this->chain(new LdapAuthenticator(
            $this->ldapRepo,
            $this->factory->getUserService(),
            $this->factory->getUserRepository(),
            $ldap,
        ))->authenticate('bob', 'ldap-password');

        self::assertNotNull($result);
        self::assertSame('ldap', $result['method']);
        self::assertSame('bob', $result['user']->login());
    }

    public function testLdapIsTriedBeforePassword(): void
    {
        // The registry sorts by priority and the chain honours that order, so
        // a user who exists in both places is authenticated by the directory.
        $this->createUser('bob', 'local-password');
        $this->enableLdap();
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = ['count' => 1, 0 => ['dn' => 'uid=bob,dc=example,dc=com']];
        $ldap->connection->readEntries = [
            'count' => 1,
            0 => ['mail' => ['count' => 1, 0 => 'bob@example.com']],
        ];

        $result = $this->chain(new LdapAuthenticator(
            $this->ldapRepo,
            $this->factory->getUserService(),
            $this->factory->getUserRepository(),
            $ldap,
        ))->authenticate('bob', 'local-password');

        self::assertNotNull($result);
        self::assertSame('ldap', $result['method'], 'the directory answers first');
    }

    public function testPasswordStillWorksWhenLdapDeclines(): void
    {
        // The loop has to continue past a provider that returns null rather
        // than stopping at the first one it asks.
        $this->createUser('alice', 'correct-horse');
        $this->enableLdap();
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = ['count' => 0];

        $result = $this->chain(new LdapAuthenticator(
            $this->ldapRepo,
            $this->factory->getUserService(),
            $this->factory->getUserRepository(),
            $ldap,
        ))->authenticate('alice', 'correct-horse');

        self::assertNotNull($result);
        self::assertSame('password', $result['method']);
    }

    public function testAnOauthProviderIsSkippedRatherThanTried(): void
    {
        // OAuth and OIDC are redirect flows; the default arm returns null so
        // the chain moves on. Remove it and a configured OAuth provider makes
        // the match throw, which the catch turns into a failed login for
        // everybody on that deployment.
        $this->oauthRepo->save(new OAuthProvider(
            0,
            'Google',
            'oidc',
            'client-id',
            'secret',
            'https://accounts.google.com/o/oauth2/v2/auth',
            'https://oauth2.googleapis.com/token',
            'https://openidconnect.googleapis.com/v1/userinfo',
            'openid email',
            true,
        ));
        $this->createUser('alice', 'correct-horse');

        $result = $this->chain()->authenticate('alice', 'correct-horse');

        self::assertNotNull($result);
        self::assertSame('password', $result['method'], 'the redirect provider is passed over');
        self::assertSame(
            [],
            $this->logger->matching('Auth provider failed'),
            'passed over quietly -- without the default arm the match raises and is logged as a failure',
        );
    }

    // ----------------------------------------------------- what it records

    public function testASuccessfulLoginIsLoggedWithItsMethod(): void
    {
        // Nothing injected a logger, so the constructor fell back to a null
        // one and every log call in this class was unobservable.
        $this->createUser('alice', 'correct-horse');

        $this->chain()->authenticate('alice', 'correct-horse');

        self::assertSame(
            [['Authentication successful', ['user' => 'alice', 'method' => 'password']]],
            $this->logger->matching('Authentication successful'),
        );
    }

    public function testExhaustingEveryProviderIsLogged(): void
    {
        self::assertNull($this->chain()->authenticate('nobody', 'nothing'));

        self::assertSame(
            [['All auth providers failed', ['user' => 'nobody']]],
            $this->logger->matching('All auth providers failed'),
        );
    }

    public function testAProviderThatThrowsIsLoggedAndDoesNotStopTheChain(): void
    {
        // The catch is what keeps one broken provider from locking everybody
        // out. Without it -- or without the loop continuing afterwards -- an
        // unreachable directory takes local password login down with it.
        $this->createUser('alice', 'correct-horse');
        $this->enableLdap();

        $result = $this->chain(new LdapAuthenticator(
            $this->ldapRepo,
            $this->factory->getUserService(),
            $this->factory->getUserRepository(),
            new ThrowingLdapClient(),
        ))->authenticate('alice', 'correct-horse');

        self::assertNotNull($result, 'password auth still answers');
        self::assertSame('password', $result['method']);
        self::assertSame(
            [['Auth provider failed', ['type' => 'ldap', 'error' => 'directory unreachable']]],
            $this->logger->matching('Auth provider failed'),
        );
    }
}

/** A directory that is having a bad day: dialling it raises rather than returning null. */
final class ThrowingLdapClient implements LdapClient
{
    #[\Override]
    public function isSupported(): bool
    {
        return true;
    }

    #[\Override]
    public function connect(string $uri): ?LdapConnection
    {
        throw new \RuntimeException('directory unreachable');
    }

    #[\Override]
    public function escapeFilterValue(string $value): string
    {
        return $value;
    }
}

final class RecordingChainLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [(string) $message, $context];
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    public function matching(string $message): array
    {
        return array_values(array_filter($this->records, static fn(array $r): bool => $r[0] === $message));
    }
}
