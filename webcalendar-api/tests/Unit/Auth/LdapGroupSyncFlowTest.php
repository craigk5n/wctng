<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use App\Auth\LdapGroupSync;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Group sync driven through FakeLdapClient.
 *
 * Before the adapter the only reachable assertions were "disabled returns []"
 * and "no extension returns []"; everything past the ldap_connect() call was
 * unreachable without a directory.
 */
final class LdapGroupSyncFlowTest extends TestCase
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

        $this->configRepo->save(new LdapConfig(
            host: 'ldap.example.com',
            port: 389,
            baseDn: 'dc=example,dc=com',
            enabled: true,
        ));
    }

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
                    // Not every statement applies to this SQLite build.
                }
            }
        }
    }

    private function sync(FakeLdapClient $ldap): LdapGroupSync
    {
        return new LdapGroupSync(
            $this->configRepo,
            $this->factory->getGroupService(),
            null,
            $ldap,
        );
    }

    /** @param list<string> $groupDns */
    private function directoryReturning(array $groupDns): FakeLdapClient
    {
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->readEntries = [
            'count' => 1,
            0 => ['memberof' => array_merge(['count' => \count($groupDns)], array_values($groupDns))],
        ];

        return $ldap;
    }

    public function testMemberOfGroupsAreCreatedAndReturned(): void
    {
        $ldap = $this->directoryReturning([
            'CN=Engineering,OU=Groups,DC=example,DC=com',
            'CN=All Staff,OU=Groups,DC=example,DC=com',
        ]);

        $synced = $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com');

        self::assertSame(['Engineering', 'All Staff'], $synced);

        $names = array_map(
            static fn(object $g): string => $g->name(),
            $this->factory->getGroupService()->getAllGroups(),
        );
        self::assertContains('Engineering', $names);
        self::assertContains('All Staff', $names);
    }

    public function testExistingGroupGainsTheMemberRatherThanADuplicate(): void
    {
        $first = $this->directoryReturning(['CN=Engineering,OU=Groups,DC=example,DC=com']);
        $this->sync($first)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com');

        $second = $this->directoryReturning(['CN=Engineering,OU=Groups,DC=example,DC=com']);
        $this->sync($second)->syncUserGroups('bob', 'uid=bob,dc=example,dc=com');

        $engineering = array_values(array_filter(
            $this->factory->getGroupService()->getAllGroups(),
            static fn(object $g): bool => $g->name() === 'Engineering',
        ));

        self::assertCount(1, $engineering, 'the second sync reuses the group');
        self::assertContains('bob', $this->factory->getGroupService()->getGroupMembers($engineering[0]->id()));
    }

    public function testDnsWithoutACommonNameAreSkipped(): void
    {
        $ldap = $this->directoryReturning([
            'OU=NoCommonName,DC=example,DC=com',
            'CN=Engineering,OU=Groups,DC=example,DC=com',
        ]);

        $synced = $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com');

        self::assertSame(['Engineering'], $synced);
    }

    public function testTlsConfigurationDialsLdaps(): void
    {
        $this->configRepo->save(new LdapConfig(
            host: 'ldap.example.com',
            port: 636,
            useTls: true,
            enabled: true,
        ));
        $ldap = $this->directoryReturning(['CN=Engineering,DC=example,DC=com']);

        $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com');

        self::assertSame(['ldaps://ldap.example.com:636'], $ldap->connectedUris);
    }

    public function testFailedServiceBindSyncsNothingAndClosesTheConnection(): void
    {
        $this->configRepo->save(new LdapConfig(
            host: 'ldap.example.com',
            bindDn: 'cn=svc,dc=example,dc=com',
            bindPassword: 'service-secret',
            enabled: true,
        ));
        $ldap = $this->directoryReturning(['CN=Engineering,DC=example,DC=com']);
        $ldap->connection->bindResults['cn=svc,dc=example,dc=com'] = false;

        self::assertSame([], $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com'));
        self::assertSame([], $ldap->connection->searches, 'no read on an unbound connection');
        self::assertSame(1, $ldap->connection->closes);
    }

    public function testUnusableConnectionSyncsNothing(): void
    {
        $ldap = new FakeLdapClient(connectSucceeds: false);

        self::assertSame([], $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com'));
    }

    public function testUserWithNoGroupsSyncsNothing(): void
    {
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->readEntries = ['count' => 0];

        self::assertSame([], $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com'));
    }

    public function testFailedReadSyncsNothing(): void
    {
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->readEntries = null;

        self::assertSame([], $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com'));
    }

    public function testMissingExtensionDialsNothing(): void
    {
        $ldap = new FakeLdapClient(supported: false);

        self::assertSame([], $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com'));
        self::assertSame([], $ldap->connectedUris);
    }

    // ------------------------------------- the group a new member ends up in

    public function testANewlyCreatedGroupActuallyContainsTheUser(): void
    {
        // The existing case checks the group was created and that its name
        // comes back in the result, but never that anybody is in it. A group
        // created empty is worse than no group: the sync reports success and
        // the user gets none of its permissions.
        $ldap = $this->directoryReturning(['CN=Engineering,OU=Groups,DC=example,DC=com']);

        $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com');

        $groups = array_values(array_filter(
            $this->factory->getGroupService()->getAllGroups(),
            static fn(object $g): bool => $g->name() === 'Engineering',
        ));
        self::assertCount(1, $groups);
        self::assertSame(
            ['alice'],
            $this->factory->getGroupService()->getGroupMembers($groups[0]->id()),
        );
    }

    public function testACreatedGroupIsStampedWithTheInjectedClock(): void
    {
        // Nothing passed a clock, so the date stamped on every group this
        // creates was the wall clock and unassertable.
        //
        // Only the date is checked, because only the date is kept:
        // webcal_group.cal_last_update is an INT holding Ymd, and the
        // repository rebuilds it with createFromFormat('Ymd', ...), which
        // fills the time in from whenever the row happened to be read. The
        // time on a Group's lastUpdate() is therefore not information.
        $clock = new MockClock('2026-03-15T10:00:00+00:00');
        $ldap = $this->directoryReturning(['CN=Engineering,DC=example,DC=com']);

        (new LdapGroupSync($this->configRepo, $this->factory->getGroupService(), $clock, $ldap))
            ->syncUserGroups('alice', 'uid=alice,dc=example,dc=com');

        $groups = array_values(array_filter(
            $this->factory->getGroupService()->getAllGroups(),
            static fn(object $g): bool => $g->name() === 'Engineering',
        ));
        self::assertCount(1, $groups);
        self::assertSame('2026-03-15', $groups[0]->lastUpdate()->format('Y-m-d'));
    }

    // ---------------------------------------------- the connection it opens

    public function testAServiceAccountThatBindsCleanlyStillSyncs(): void
    {
        // Only the failing service bind was covered, and the branch reads
        // `bindDn !== '' && !bind(...)`. Turn that into an or and a successful
        // bind aborts the sync -- so every deployment that configures a
        // service account silently syncs no groups at all, while the ones
        // binding anonymously keep working.
        $this->configRepo->save(new LdapConfig(
            host: 'ldap.example.com',
            bindDn: 'cn=svc,dc=example,dc=com',
            bindPassword: 'service-secret',
            enabled: true,
        ));
        $ldap = $this->directoryReturning(['CN=Engineering,DC=example,DC=com']);

        $synced = $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com');

        self::assertSame(['Engineering'], $synced);
        self::assertSame(
            ['cn=svc,dc=example,dc=com', 'service-secret'],
            [$ldap->connection->binds[0]['dn'], $ldap->connection->binds[0]['password']],
        );
    }

    public function testTheReadAsksOnlyForMemberOfAndClosesWhenItIsDone(): void
    {
        // memberOf is the one attribute this class needs; dropping it from the
        // request makes the directory answer with every attribute it holds,
        // which for a large entry is a lot of traffic on every single login.
        $ldap = $this->directoryReturning(['CN=Engineering,DC=example,DC=com']);

        $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com');

        self::assertSame(
            [['base' => 'uid=alice,dc=example,dc=com', 'filter' => '(objectClass=*)', 'attributes' => ['memberOf']]],
            $ldap->connection->searches,
        );
        self::assertSame(1, $ldap->connection->closes, 'the connection it opened is closed');
    }

    public function testAnEnabledDirectoryWithNoHostIsNotDialled(): void
    {
        // Three conditions guard the entry point and only two of them had a
        // case, so the operators joining them were interchangeable.
        $this->configRepo->save(new LdapConfig(host: '', enabled: true));
        $ldap = $this->directoryReturning(['CN=Engineering,DC=example,DC=com']);

        self::assertSame([], $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com'));
        self::assertSame([], $ldap->connectedUris);
    }

    public function testAMemberOfCountOfZeroIsBelievedOverEntriesThatArePresent(): void
    {
        // A malformed attribute set -- count says none, but element 0 is
        // populated anyway. The count is what the loop trusts, and it should:
        // an entry the directory did not count is not one to hand permissions
        // out for.
        $ldap = new FakeLdapClient();
        $ldap->connection = new FakeLdapConnection();
        $ldap->connection->readEntries = [
            'count' => 1,
            0 => ['memberof' => [0 => 'CN=Uncounted,DC=example,DC=com']],
        ];

        self::assertSame([], $this->sync($ldap)->syncUserGroups('alice', 'uid=alice,dc=example,dc=com'));
        self::assertSame([], $this->factory->getGroupService()->getAllGroups());
    }
}
