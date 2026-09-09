<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LdapAuthenticator;
use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;

/**
 * The parts of LDAP authentication that used to need a live server.
 *
 * Everything here runs against FakeLdapClient, so the assertions are about the
 * decisions the class makes -- which URI it dials, whether it binds as the
 * service account first, how it treats zero or several matches, whether it
 * closes what it opened -- rather than about ext-ldap behaving.
 */
final class LdapAuthenticatorFlowTest extends TestCase
{
    private \PDO $pdo;
    private LdapConfigRepository $configRepo;
    private CoreServiceFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->configRepo = new LdapConfigRepository($this->pdo);
        $this->loadCoreSchema();
        $this->factory = new CoreServiceFactory($this->pdo, 'test');
    }

    /**
     * Provisioning writes a real user, so the core tables have to exist.
     */
    private function loadCoreSchema(): void
    {
        $path = __DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql';
        $schema = file_get_contents($path);

        if ($schema === false) {
            self::fail('cannot read the webcalendar-core sqlite schema');
        }

        $clean = (string) preg_replace('/--[^\n]*/', '', $schema);

        foreach (preg_split('/;\s*\n/', $clean) ?: [] as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException) {
                    // Statements this build of SQLite rejects are not needed here.
                }
            }
        }
    }

    private function configure(LdapConfig $config): void
    {
        $this->configRepo->save($config);
    }

    private function authenticator(FakeLdapClient $ldap): LdapAuthenticator
    {
        return new LdapAuthenticator(
            $this->configRepo,
            $this->factory->getUserService(),
            $this->factory->getUserRepository(),
            $ldap,
        );
    }

    private function enabledConfig(bool $useTls = false, string $bindDn = ''): LdapConfig
    {
        return new LdapConfig(
            host: 'ldap.example.com',
            port: 389,
            baseDn: 'dc=example,dc=com',
            bindDn: $bindDn,
            bindPassword: $bindDn === '' ? '' : 'service-secret',
            userFilter: '(uid=%s)',
            useTls: $useTls,
            enabled: true,
        );
    }

    /** @return array<array-key, mixed> one matching entry */
    private static function oneMatch(string $dn = 'uid=alice,dc=example,dc=com'): array
    {
        return ['count' => 1, 0 => ['dn' => $dn]];
    }

    public function testPlainConnectionUsesLdapScheme(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();

        $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertSame(['ldap://ldap.example.com:389'], $ldap->connectedUris);
    }

    public function testTlsConnectionUsesLdapsScheme(): void
    {
        // The scheme is a ternary: swapping its arms would silently downgrade
        // every configured-for-TLS deployment to plaintext.
        $this->configure($this->enabledConfig(useTls: true));
        $ldap = new FakeLdapClient();

        $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertSame(['ldaps://ldap.example.com:389'], $ldap->connectedUris);
    }

    public function testWithoutBindDnTheSearchIsAnonymous(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();

        $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertNotNull($ldap->connection);
        self::assertSame([], $ldap->connection->binds, 'no service bind when no bindDn is configured');
    }

    public function testServiceAccountIsBoundBeforeSearching(): void
    {
        $this->configure($this->enabledConfig(bindDn: 'cn=svc,dc=example,dc=com'));
        $ldap = new FakeLdapClient();

        $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertNotNull($ldap->connection);
        self::assertSame('cn=svc,dc=example,dc=com', $ldap->connection->binds[0]['dn']);
        self::assertSame('service-secret', $ldap->connection->binds[0]['password']);
    }

    public function testFailedServiceBindStopsAndClosesTheConnection(): void
    {
        $this->configure($this->enabledConfig(bindDn: 'cn=svc,dc=example,dc=com'));
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->bindResults['cn=svc,dc=example,dc=com'] = false;

        $result = $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertNull($result);
        self::assertSame([], $ldap->connection->searches, 'no search on an unbound connection');
        self::assertSame(1, $ldap->connection->closes, 'the connection is not leaked');
    }

    public function testUsernameIsEscapedIntoTheSearchFilter(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();

        // A filter metacharacter must not reach the filter unescaped.
        $this->authenticator($ldap)->authenticate('ali*ce', 'pw');

        self::assertNotNull($ldap->connection);
        self::assertSame('(uid=ali\\2ace)', $ldap->connection->searches[0]['filter']);
        self::assertSame('dc=example,dc=com', $ldap->connection->searches[0]['base']);
    }

    public function testNoMatchingUserFails(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = ['count' => 0];

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
    }

    public function testAmbiguousFilterMatchingSeveralUsersFails(): void
    {
        // Two matches means the filter cannot identify one person; binding as
        // either would be a guess.
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = [
            'count' => 2,
            0 => ['dn' => 'uid=alice,dc=example,dc=com'],
            1 => ['dn' => 'uid=alice2,dc=example,dc=com'],
        ];

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
    }

    public function testFailedSearchFails(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = null;

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
    }

    public function testWrongPasswordFailsTheUserBind(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = self::oneMatch();
        $ldap->connection->bindResults['uid=alice,dc=example,dc=com'] = false;

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'wrong'));
    }

    public function testUnusableConnectionFails(): void
    {
        $this->configure($this->enabledConfig());

        self::assertNull(
            $this->authenticator(new FakeLdapClient(connectSucceeds: false))->authenticate('alice', 'pw'),
        );
    }

    public function testMissingExtensionFailsBeforeConnecting(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient(supported: false);

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
        self::assertSame([], $ldap->connectedUris, 'nothing is dialled without the extension');
    }

    public function testSuccessfulAuthenticationProvisionsTheUserFromLdapAttributes(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = self::oneMatch();
        $ldap->connection->readEntries = [
            'count' => 1,
            0 => [
                'givenname' => ['count' => 1, 0 => 'Alice'],
                'sn' => ['count' => 1, 0 => 'Smith'],
                'mail' => ['count' => 1, 0 => 'alice@example.com'],
            ],
        ];

        $user = $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertNotNull($user);
        self::assertSame('alice', $user->login());
        self::assertSame('Alice', $user->firstName());
        self::assertSame('Smith', $user->lastName());
        self::assertSame('alice@example.com', $user->email());
    }

    public function testDisplayNameIsUsedWhenGivenNameIsMissing(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = self::oneMatch();
        $ldap->connection->readEntries = [
            'count' => 1,
            0 => [
                'displayname' => ['count' => 1, 0 => 'Alice Smith'],
                'mail' => ['count' => 1, 0 => 'alice@example.com'],
            ],
        ];

        $user = $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertNotNull($user);
        self::assertSame('Alice Smith', $user->firstName());
    }
    public function testDirectoryEntryWithoutAnEmailDeniesRatherThanErroring(): void
    {
        // The User entity rejects an empty email. Constructing it outside the
        // try turned that into an uncaught exception on the login path.
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = self::oneMatch();
        $ldap->connection->readEntries = [
            'count' => 1,
            0 => ['givenname' => ['count' => 1, 0 => 'Alice']],
        ];

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
    }
}
