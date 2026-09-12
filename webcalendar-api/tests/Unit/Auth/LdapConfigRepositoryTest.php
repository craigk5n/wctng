<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single-row LDAP configuration, read back whole.
 *
 * This class had no test of its own -- the LDAP flow tests save a config and
 * then assert things about authentication, so the repository was only ever
 * exercised for the fields those cases happened to set. Everything it stores
 * is operator-entered through the admin API and everything it returns decides
 * how the directory is dialled, so a field that does not survive the round
 * trip is a login that quietly stops working.
 */
final class LdapConfigRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private LdapConfigRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new LdapConfigRepository($this->pdo);
    }

    private static function fullyPopulated(bool $useTls = true, bool $enabled = true): LdapConfig
    {
        return new LdapConfig(
            host: 'ldap.corp.example.com',
            port: 636,
            baseDn: 'ou=people,dc=corp,dc=example,dc=com',
            bindDn: 'cn=svc-calendar,ou=services,dc=corp,dc=example,dc=com',
            bindPassword: 'service-account-secret',
            userFilter: '(sAMAccountName=%s)',
            useTls: $useTls,
            enabled: $enabled,
        );
    }

    private function assertMatchesFixture(LdapConfig $config, bool $useTls = true, bool $enabled = true): void
    {
        self::assertSame('ldap.corp.example.com', $config->host());
        self::assertSame(636, $config->port());
        self::assertSame('ou=people,dc=corp,dc=example,dc=com', $config->baseDn());
        self::assertSame('cn=svc-calendar,ou=services,dc=corp,dc=example,dc=com', $config->bindDn());
        self::assertSame('service-account-secret', $config->bindPassword());
        self::assertSame('(sAMAccountName=%s)', $config->userFilter());
        self::assertSame($useTls, $config->useTls());
        self::assertSame($enabled, $config->isEnabled());
    }

    public function testEveryFieldSurvivesTheFirstSave(): void
    {
        $this->repo->save(self::fullyPopulated());

        $this->assertMatchesFixture($this->repo->get());
    }

    public function testEveryFieldSurvivesASecondSaveOverTheFirst(): void
    {
        // save() inserts or updates depending on whether row 1 exists, and the
        // two statements list the columns separately -- so the update path can
        // drift from the insert path without anything noticing.
        $this->repo->save(new LdapConfig(host: 'old.example.com', port: 389, enabled: false));

        $this->repo->save(self::fullyPopulated());

        $this->assertMatchesFixture($this->repo->get());
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM ldap_config')?->fetchColumn(),
            'the configuration is a single row, not one per save',
        );
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function flagCombinations(): iterable
    {
        // Two independent booleans stored as integers. Either one written as a
        // constant is a deployment that silently ignores its own settings:
        // TLS dropped to plaintext, or LDAP left switched off.
        yield 'both on' => [true, true];
        yield 'tls only' => [true, false];
        yield 'enabled only' => [false, true];
        yield 'both off' => [false, false];
    }

    /** @return iterable<string, array{bool, int}> */
    public static function flagsAndTheirStoredValue(): iterable
    {
        yield 'on is stored as one' => [true, 1];
        yield 'off is stored as zero' => [false, 0];
    }

    #[DataProvider('flagsAndTheirStoredValue')]
    public function testAFlagIsStoredAsOneOrZeroAndNothingElse(bool $on, int $stored): void
    {
        // get() only asks whether the column equals 1, so anything that is not
        // 1 reads back as false and the round-trip tests below pass for any of
        // them -- the column could be written as -1 and nothing here would
        // notice. use_tls and enabled are INTEGER NOT NULL DEFAULT 0 standing
        // in for booleans, and what is in them is what anything reading the
        // table directly sees.
        $this->repo->save(self::fullyPopulated($on, $on));
        self::assertSame($stored, $this->storedFlag('use_tls'));
        self::assertSame($stored, $this->storedFlag('enabled'));

        // And again down the update path, which writes the same expressions.
        $this->repo->save(self::fullyPopulated($on, $on));
        self::assertSame($stored, $this->storedFlag('use_tls'));
        self::assertSame($stored, $this->storedFlag('enabled'));
    }

    private function storedFlag(string $column): int
    {
        return (int) $this->pdo->query("SELECT {$column} FROM ldap_config WHERE id = 1")?->fetchColumn();
    }

    #[DataProvider('flagCombinations')]
    public function testTheFlagsSurviveAnInsert(bool $useTls, bool $enabled): void
    {
        $this->repo->save(self::fullyPopulated($useTls, $enabled));

        $config = $this->repo->get();
        self::assertSame($useTls, $config->useTls());
        self::assertSame($enabled, $config->isEnabled());
    }

    #[DataProvider('flagCombinations')]
    public function testTheFlagsSurviveAnUpdate(bool $useTls, bool $enabled): void
    {
        $this->repo->save(self::fullyPopulated(!$useTls, !$enabled));

        $this->repo->save(self::fullyPopulated($useTls, $enabled));

        $config = $this->repo->get();
        self::assertSame($useTls, $config->useTls());
        self::assertSame($enabled, $config->isEnabled());
    }

    public function testItIsReadAsIntsAndBoolsOnAStringifyingDriver(): void
    {
        // MySQL's PDO returns every column as a string. Without the casts the
        // port arrives as "636" -- and LdapConfig declares it int, so the
        // constructor would raise rather than merely misbehave.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $repo = new LdapConfigRepository($pdo);

        $repo->save(self::fullyPopulated());
        $config = $repo->get();

        self::assertSame(636, $config->port());
        self::assertTrue($config->useTls());
        self::assertTrue($config->isEnabled());
    }

    public function testAnUnconfiguredDirectoryReadsBackAsTheDefaults(): void
    {
        // get() on a database that has never been written to has to answer
        // with a disabled configuration rather than raising, because it runs
        // on every login attempt.
        $config = $this->repo->get();

        self::assertSame('', $config->host());
        self::assertSame(389, $config->port());
        self::assertSame('(uid=%s)', $config->userFilter());
        self::assertFalse($config->useTls());
        self::assertFalse($config->isEnabled());
    }
}
