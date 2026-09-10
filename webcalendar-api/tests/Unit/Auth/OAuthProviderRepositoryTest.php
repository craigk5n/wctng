<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\OAuthProvider;
use App\Auth\OAuthProviderRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OAuthProviderRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private OAuthProviderRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new OAuthProviderRepository($this->pdo);
    }

    public function testSaveAndFindById(): void
    {
        $provider = new OAuthProvider(
            0,
            'Google',
            'oidc',
            'google-client-id',
            'google-secret',
            'https://accounts.google.com/o/oauth2/auth',
            'https://oauth2.googleapis.com/token',
            'https://openidconnect.googleapis.com/v1/userinfo',
            'openid email profile',
            true,
        );

        $id = $this->repo->save($provider);
        $this->assertGreaterThan(0, $id);

        $found = $this->repo->findById($id);
        $this->assertNotNull($found);
        $this->assertSame('Google', $found->name());
        $this->assertSame('oidc', $found->type());
        $this->assertSame('google-client-id', $found->clientId());
    }

    public function testFindAll(): void
    {
        $this->repo->save(new OAuthProvider(0, 'Google', 'oidc', 'g-id', 'g-secret', '', '', '', '', true));
        $this->repo->save(new OAuthProvider(0, 'GitHub', 'oauth2', 'gh-id', 'gh-secret', '', '', '', '', true));

        $all = $this->repo->findAll();
        $this->assertCount(2, $all);
    }

    public function testUpdate(): void
    {
        $id = $this->repo->save(new OAuthProvider(0, 'Before', 'oauth2', 'id', 'secret', '', '', '', '', true));

        $updated = new OAuthProvider($id, 'After', 'oidc', 'new-id', 'new-secret', '', '', '', '', false);
        $this->repo->save($updated);

        $found = $this->repo->findById($id);
        $this->assertNotNull($found);
        $this->assertSame('After', $found->name());
        $this->assertSame('oidc', $found->type());
        $this->assertFalse($found->isEnabled());
    }

    public function testDelete(): void
    {
        $id = $this->repo->save(new OAuthProvider(0, 'ToDelete', 'oauth2', 'id', 's', '', '', '', '', true));
        $this->assertNotNull($this->repo->findById($id));

        $this->repo->delete($id);
        $this->assertNull($this->repo->findById($id));
    }

    public function testToArrayExcludesSecret(): void
    {
        $provider = new OAuthProvider(1, 'Test', 'oauth2', 'cid', 'secret', 'auth', 'token', 'user', 'scope', true);
        $array = $provider->toArray();

        $this->assertArrayHasKey('client_id', $array);
        $this->assertArrayNotHasKey('client_secret', $array); // Secret not exposed in toArray
    }

    // --------------------------------------------------- every field, read back

    private const SECRET = 'g0cspx-not-a-real-secret';

    private function fullyPopulated(int $id = 0, bool $enabled = true): OAuthProvider
    {
        return new OAuthProvider(
            $id,
            'Google Workspace',
            'oidc',
            'client-id-12345.apps.googleusercontent.com',
            self::SECRET,
            'https://accounts.google.com/o/oauth2/v2/auth',
            'https://oauth2.googleapis.com/token',
            'https://openidconnect.googleapis.com/v1/userinfo',
            'openid email profile',
            $enabled,
        );
    }

    public function testEveryColumnSurvivesTheRoundTrip(): void
    {
        // The older cases only read back name, type and client_id, and left
        // every other string empty -- so each of those narrowing ternaries
        // could have its arms swapped and still "pass": the fallback it
        // returned was the empty string the fixture had put there anyway.
        // A provider with no secret, no token URL and no scopes cannot
        // complete a single login.
        $id = $this->repo->save($this->fullyPopulated());

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertSame($id, $found->id());
        self::assertSame('Google Workspace', $found->name());
        self::assertSame('oidc', $found->type());
        self::assertSame('client-id-12345.apps.googleusercontent.com', $found->clientId());
        self::assertSame(self::SECRET, $found->clientSecret());
        self::assertSame('https://accounts.google.com/o/oauth2/v2/auth', $found->authUrl());
        self::assertSame('https://oauth2.googleapis.com/token', $found->tokenUrl());
        self::assertSame('https://openidconnect.googleapis.com/v1/userinfo', $found->userinfoUrl());
        self::assertSame('openid email profile', $found->scopes());
        self::assertTrue($found->isEnabled());
    }

    public function testFindAllReadsTheSameColumnsAsFindById(): void
    {
        $id = $this->repo->save($this->fullyPopulated());

        $all = $this->repo->findAll();

        self::assertCount(1, $all);
        self::assertSame($this->repo->findById($id)?->toArray(), $all[0]->toArray());
        self::assertSame(self::SECRET, $all[0]->clientSecret());
    }

    public function testTheRowIsReadAsIntsAndBoolsOnAStringifyingDriver(): void
    {
        // MySQL's PDO hands back every column as a string, which is what the
        // casts on id and enabled are for. On SQLite they look like no-ops, so
        // dropping them would leave callers comparing "1" with 1.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $repo = new OAuthProviderRepository($pdo);

        $id = $repo->save($this->fullyPopulated());
        $found = $repo->findById($id);

        self::assertNotNull($found);
        self::assertSame($id, $found->id());
        self::assertTrue($found->isEnabled());
    }

    // ------------------------------------------------- insert versus update

    public function testUpdatingAProviderDoesNotAlsoInsertACopyOfIt(): void
    {
        // save() branches on whether the provider carries an id, and the
        // update branch has to return there rather than fall through to the
        // INSERT below it. Without that return every edit leaves a duplicate,
        // and the provider list grows one row per save.
        $id = $this->repo->save($this->fullyPopulated());

        $returned = $this->repo->save(new OAuthProvider(
            $id,
            'Renamed',
            'oidc',
            'client-id-12345.apps.googleusercontent.com',
            self::SECRET,
            'https://accounts.google.com/o/oauth2/v2/auth',
            'https://oauth2.googleapis.com/token',
            'https://openidconnect.googleapis.com/v1/userinfo',
            'openid email profile',
            true,
        ));

        self::assertSame($id, $returned, 'an update reports the id it edited');
        self::assertCount(1, $this->repo->findAll());
        self::assertSame('Renamed', $this->repo->findById($id)?->name());
    }

    /** @return iterable<string, array{bool}> */
    public static function enabledStates(): iterable
    {
        yield 'enabled' => [true];
        yield 'disabled' => [false];
    }

    #[DataProvider('enabledStates')]
    public function testTheEnabledFlagSurvivesAnInsert(bool $enabled): void
    {
        $id = $this->repo->save($this->fullyPopulated(enabled: $enabled));

        self::assertSame($enabled, $this->repo->findById($id)?->isEnabled());
    }

    #[DataProvider('enabledStates')]
    public function testTheEnabledFlagSurvivesAnUpdate(bool $enabled): void
    {
        // Only the disabling direction was covered, so the flag could have
        // been written as a constant 0 on update -- which switches off every
        // provider the moment anyone edits it.
        $id = $this->repo->save($this->fullyPopulated(enabled: !$enabled));

        $this->repo->save($this->fullyPopulated($id, $enabled));

        self::assertSame($enabled, $this->repo->findById($id)?->isEnabled());
    }

    // ------------------------------------------------------- the table itself

    /** @return iterable<string, array{\Closure(OAuthProviderRepository): mixed}> */
    public static function readsOnAnEmptyDatabase(): iterable
    {
        // Each entry point creates the table before touching it. Without that
        // the first request after a deploy fails with "no such table" instead
        // of reporting that no providers are configured.
        yield 'findAll' => [static fn(OAuthProviderRepository $r): mixed => $r->findAll()];
        yield 'findById' => [static fn(OAuthProviderRepository $r): mixed => $r->findById(1)];
    }

    /** @param \Closure(OAuthProviderRepository): mixed $read */
    #[DataProvider('readsOnAnEmptyDatabase')]
    public function testAReadOnAFreshDatabaseCreatesTheTableFirst(\Closure $read): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $result = $read(new OAuthProviderRepository($pdo));

        self::assertTrue($result === null || $result === []);
    }
}
