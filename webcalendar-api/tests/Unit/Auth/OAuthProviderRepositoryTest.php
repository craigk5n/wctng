<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\OAuthProvider;
use App\Auth\OAuthProviderRepository;
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
}
