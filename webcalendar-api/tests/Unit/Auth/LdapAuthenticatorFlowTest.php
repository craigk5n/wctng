<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LdapAuthenticator;
use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\User;

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

    // --------------------------------------------------- the enabling guard

    public function testAConfiguredHostWithLdapDisabledIsNotDialled(): void
    {
        // `!isEnabled() || host() === ''`: with an `&&` a disabled directory
        // that still has a host recorded would be dialled anyway.
        $this->configure(new LdapConfig(host: 'ldap.example.com', port: 389, enabled: false));
        $ldap = new FakeLdapClient();

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
        self::assertSame([], $ldap->connectedUris);
    }

    public function testAnEnabledDirectoryWithNoHostIsNotDialled(): void
    {
        $this->configure(new LdapConfig(host: '', port: 389, enabled: true));
        $ldap = new FakeLdapClient();

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
        self::assertSame([], $ldap->connectedUris);
    }

    // ------------------------------------------------- the password actually

    /** Scripts a directory that would provision successfully if it were reached. */
    private function directoryThatWouldSucceed(): FakeLdapClient
    {
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

        return $ldap;
    }

    public function testAWrongPasswordIsRefusedEvenWhenEverythingElseWouldSucceed(): void
    {
        // The earlier wrong-password test used a directory with no mail
        // attribute, so provisioning failed for its own reasons and the
        // result was null whether or not the bind was checked. Here the
        // directory would provision cleanly, so the only thing standing
        // between a wrong password and a session is that check.
        $this->configure($this->enabledConfig());
        $ldap = $this->directoryThatWouldSucceed();
        $ldap->connection->bindResults['uid=alice,dc=example,dc=com'] = false;

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'wrong'));
    }

    public function testTheSameDirectoryDoesAuthenticateWithTheRightPassword(): void
    {
        // The control for the test above: without it, that one passes even if
        // the fixture is broken in some unrelated way.
        $this->configure($this->enabledConfig());

        $user = $this->authenticator($this->directoryThatWouldSucceed())->authenticate('alice', 'right');

        self::assertNotNull($user);
        self::assertSame('alice', $user->login());
    }

    // ------------------------------------------------- what is asked for

    public function testTheSearchAsksOnlyForTheDistinguishedName(): void
    {
        $this->configure($this->enabledConfig());
        $ldap = $this->directoryThatWouldSucceed();

        $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertSame(['dn'], $ldap->connection->searches[0]['attributes']);
    }

    public function testTheAttributeReadAsksForEveryNameItMightUse(): void
    {
        // Dropping one from the list does not fail: the value simply comes
        // back missing and the user is provisioned with a blank field.
        $this->configure($this->enabledConfig());
        $ldap = $this->directoryThatWouldSucceed();

        $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertSame(
            ['cn', 'mail', 'givenName', 'sn', 'displayName'],
            $ldap->connection->searches[1]['attributes'],
        );
        self::assertSame('(objectClass=*)', $ldap->connection->searches[1]['filter']);
    }

    public function testEveryConnectionItOpensIsClosed(): void
    {
        // Three are opened on a successful login -- the search, the user
        // bind, and the attribute read -- and a directory server has a
        // finite number of them.
        $this->configure($this->enabledConfig());
        $ldap = $this->directoryThatWouldSucceed();

        $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertSame(3, $ldap->connection->closes);
    }

    // ----------------------------------------------- the provisioned account

    public function testAProvisionedUserIsNotAnAdminAndIsEnabled(): void
    {
        $this->configure($this->enabledConfig());

        $this->authenticator($this->directoryThatWouldSucceed())->authenticate('alice', 'pw');

        $saved = $this->factory->getUserRepository()->findByLogin('alice');
        self::assertNotNull($saved);
        self::assertFalse($saved->isAdmin(), 'a directory login does not confer admin');
        self::assertTrue($saved->isEnabled());
    }

    public function testAProvisionedUserGetsAPasswordItCannotBeLoggedInWith(): void
    {
        // The account authenticates through the directory, so the local
        // password is random. Without it the row keeps whatever the column
        // defaults to, which is a password nobody chose and everybody shares.
        $this->configure($this->enabledConfig());

        $this->authenticator($this->directoryThatWouldSucceed())->authenticate('alice', 'pw');

        $stored = $this->pdo
            ->query("SELECT cal_passwd FROM webcal_user WHERE cal_login = 'alice'")
            ?->fetchColumn();

        self::assertIsString($stored);
        self::assertNotSame('', $stored);
    }

    public function testAnExistingUserIsNotReProvisioned(): void
    {
        $this->configure($this->enabledConfig());
        $authenticator = $this->authenticator($this->directoryThatWouldSucceed());

        $first = $authenticator->authenticate('alice', 'pw');
        $second = $this->authenticator($this->directoryThatWouldSucceed())->authenticate('alice', 'pw');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->login(), $second->login());
    }

    // ------------------------------------------------ the anonymous bind path

    public function testWithoutABindDnTheDirectoryIsStillUsable(): void
    {
        // bindServiceAccount() returns true early when no service account is
        // configured. Returning false instead would make every anonymous
        // deployment fail to authenticate anyone, while still recording no
        // bind -- which is all the older test checked.
        $this->configure($this->enabledConfig());

        $user = $this->authenticator($this->directoryThatWouldSucceed())->authenticate('alice', 'pw');

        self::assertNotNull($user);
    }

    // ------------------------------------------- a directory answering oddly

    /** @param array<array-key, mixed> $searchEntries */
    private function directoryReturningSearch(array $searchEntries): FakeLdapClient
    {
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = $searchEntries;

        return $ldap;
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function malformedSearchResults(): iterable
    {
        // ext-ldap hands back an untyped array. These are the shapes the
        // guards exist for; each must deny the login rather than reach into
        // something that is not there.
        yield 'one match but the entry is not an array' => [['count' => 1, 0 => 'not-an-array']];
        yield 'one match but the entry is missing' => [['count' => 1]];
        yield 'entry with no dn' => [['count' => 1, 0 => ['cn' => 'alice']]];
        yield 'dn that is not a string' => [['count' => 1, 0 => ['dn' => ['uid=alice']]]];
    }

    /** @param array<array-key, mixed> $entries */
    #[DataProvider('malformedSearchResults')]
    public function testADirectoryAnsweringOddlyDeniesTheLogin(array $entries): void
    {
        $this->configure($this->enabledConfig());

        self::assertNull($this->authenticator($this->directoryReturningSearch($entries))->authenticate('alice', 'pw'));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function malformedAttributeReads(): iterable
    {
        yield 'no entries at all' => [['count' => 0]];
        yield 'entry is not an array' => [['count' => 1, 0 => 'not-an-array']];
        yield 'entry is missing' => [['count' => 1]];
        yield 'mail is not an array of values' => [['count' => 1, 0 => ['mail' => 'alice@example.com']]];
        yield 'mail has no first value' => [['count' => 1, 0 => ['mail' => ['count' => 0]]]];
        yield 'mail value is not a string' => [['count' => 1, 0 => ['mail' => ['count' => 1, 0 => ['nested']]]]];
    }

    /**
     * @param array<array-key, mixed> $readEntries
     */
    #[DataProvider('malformedAttributeReads')]
    public function testAnAttributeReadThatYieldsNoEmailDeniesTheLogin(array $readEntries): void
    {
        // Every one of these leaves the email blank, and the User entity
        // refuses that -- so the login is denied rather than provisioning an
        // account with no address. Which is the behaviour, but it depends on
        // each guard returning the defaults rather than reaching further in.
        $this->configure($this->enabledConfig());
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = self::oneMatch();
        $ldap->connection->readEntries = $readEntries;

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
    }

    // -------------------------------------------- an account already present

    public function testAnExistingAccountIsUpdatedFromTheDirectory(): void
    {
        $this->configure($this->enabledConfig());
        $this->factory->getUserRepository()->save(
            new User('alice', 'Old', 'Name', 'old@example.com', false, true),
        );

        $user = $this->authenticator($this->directoryThatWouldSucceed())->authenticate('alice', 'pw');

        self::assertNotNull($user);
        self::assertSame('Alice', $user->firstName(), 'the directory is the source of truth');
        self::assertSame('Smith', $user->lastName());
        self::assertSame('alice@example.com', $user->email());
    }

    public function testAnExistingAccountKeepsItsEmailWhenTheDirectoryHasNone(): void
    {
        // `$email !== '' ? $email : $existing->email()`. Swapping those arms
        // blanks a working address whenever the directory omits mail, and the
        // User entity then refuses the update.
        $this->configure($this->enabledConfig());
        $this->factory->getUserRepository()->save(
            new User('alice', 'Old', 'Name', 'kept@example.com', false, true),
        );

        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->searchEntries = self::oneMatch();
        $ldap->connection->readEntries = [
            'count' => 1,
            0 => ['givenname' => ['count' => 1, 0 => 'Alice']],
        ];

        $user = $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertNotNull($user, 'an existing account survives a directory entry with no mail');
        self::assertSame('kept@example.com', $user->email());
    }

    public function testAnExistingAdminStaysAnAdmin(): void
    {
        $this->configure($this->enabledConfig());
        $this->factory->getUserRepository()->save(
            new User('alice', 'Alice', 'Smith', 'alice@example.com', true, true),
        );

        $user = $this->authenticator($this->directoryThatWouldSucceed())->authenticate('alice', 'pw');

        self::assertNotNull($user);
        self::assertTrue($user->isAdmin(), 'a directory login does not demote an admin');
    }

    // ------------------------------------- guards that a fallthrough defeats

    public function testAnAmbiguousFilterIsNotSilentlyResolvedToTheFirstMatch(): void
    {
        // The earlier ambiguity test used a directory that could not have
        // provisioned anyone, so dropping the guard's `return null` looked
        // harmless: the login failed further down for want of an email.
        // Here both matches would authenticate cleanly, so the only thing
        // stopping `alice` from being logged in as whichever entry the
        // directory happened to list first is that return.
        $this->configure($this->enabledConfig());
        $ldap = $this->directoryThatWouldSucceed();
        $ldap->connection->searchEntries = [
            'count' => 2,
            0 => ['dn' => 'uid=alice,dc=example,dc=com'],
            1 => ['dn' => 'uid=alice,ou=contractors,dc=example,dc=com'],
        ];

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
    }

    public function testAnEntryCountOfZeroIsBelievedOverAnEntryThatIsStillThere(): void
    {
        // A malformed read -- count says nothing was found, but element 0 is
        // populated anyway. The count is what the code trusts, and it should:
        // an entry the directory did not count is not an entry. Reaching into
        // it instead would provision an account out of unvouched-for data.
        $this->configure($this->enabledConfig());
        $ldap = $this->directoryThatWouldSucceed();
        $ldap->connection->readEntries = [
            'count' => 0,
            0 => [
                'givenname' => ['count' => 1, 0 => 'Mallory'],
                'sn' => ['count' => 1, 0 => 'Smith'],
                'mail' => ['count' => 1, 0 => 'mallory@example.com'],
            ],
        ];

        self::assertNull($this->authenticator($ldap)->authenticate('alice', 'pw'));
        self::assertFalse(
            $this->pdo->query("SELECT 1 FROM webcal_user WHERE cal_login = 'alice'")?->fetchColumn(),
            'nothing was provisioned from the uncounted entry',
        );
    }

    public function testAServiceAccountThatBindsCleanlyLetsTheLoginThrough(): void
    {
        // Every other successful test here leaves bindDn empty, so they all
        // take bindServiceAccount()'s anonymous early return and never reach
        // the branch that reports a successful service bind. With that branch
        // unexercised, breaking it would break every deployment that actually
        // configures a service account while the suite stayed green.
        $this->configure($this->enabledConfig(bindDn: 'cn=svc,dc=example,dc=com'));
        $ldap = $this->directoryThatWouldSucceed();

        $user = $this->authenticator($ldap)->authenticate('alice', 'pw');

        self::assertNotNull($user);
        self::assertSame('alice@example.com', $user->email());
        self::assertSame(
            ['cn=svc,dc=example,dc=com', 'service-secret'],
            [$ldap->connection->binds[0]['dn'], $ldap->connection->binds[0]['password']],
            'the search is preceded by a bind as the configured service account',
        );
    }
}
