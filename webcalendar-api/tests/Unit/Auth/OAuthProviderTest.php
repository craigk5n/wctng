<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\OAuthProvider;
use PHPUnit\Framework\TestCase;

/**
 * The OAuth provider value object.
 *
 * toArray() is what the admin API renders and what AuthProviderRegistry hands
 * the chain as a provider's config, so every key in it is somebody's contract.
 * The only assertion it had was that client_id is present and client_secret is
 * not, which leaves the other seven free to be renamed, dropped, or filled
 * with the wrong field.
 */
final class OAuthProviderTest extends TestCase
{
    private const SECRET = 'GOCSPX-not-a-real-secret';

    private static function google(bool $enabled = true): OAuthProvider
    {
        return new OAuthProvider(
            7,
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

    public function testEveryAccessorReportsWhatItWasBuiltWith(): void
    {
        $provider = self::google();

        self::assertSame(7, $provider->id());
        self::assertSame('Google Workspace', $provider->name());
        self::assertSame('oidc', $provider->type());
        self::assertSame('client-id-12345.apps.googleusercontent.com', $provider->clientId());
        self::assertSame(self::SECRET, $provider->clientSecret());
        self::assertSame('https://accounts.google.com/o/oauth2/v2/auth', $provider->authUrl());
        self::assertSame('https://oauth2.googleapis.com/token', $provider->tokenUrl());
        self::assertSame('https://openidconnect.googleapis.com/v1/userinfo', $provider->userinfoUrl());
        self::assertSame('openid email profile', $provider->scopes());
        self::assertTrue($provider->isEnabled());
    }

    public function testAProviderIsEnabledUnlessSaidOtherwise(): void
    {
        // Every caller that omits the flag relies on this; a default of
        // disabled means a newly configured provider never appears on the
        // login page and never authenticates anyone. The helper above passes
        // the flag explicitly, so this builds one the long way with the
        // argument left off -- otherwise the constructor's default is never
        // the value under test.
        $defaulted = new OAuthProvider(
            7,
            'Google Workspace',
            'oidc',
            'client-id-12345.apps.googleusercontent.com',
            self::SECRET,
            'https://accounts.google.com/o/oauth2/v2/auth',
            'https://oauth2.googleapis.com/token',
            'https://openidconnect.googleapis.com/v1/userinfo',
            'openid email profile',
        );

        self::assertTrue($defaulted->isEnabled());
        self::assertTrue($defaulted->toArray()['enabled']);
        self::assertFalse(self::google(false)->isEnabled());
    }

    public function testToArrayCarriesEveryFieldTheAdminApiAndTheChainRead(): void
    {
        // Compared whole: a key that goes missing takes a provider's
        // authorize or token endpoint with it, and a provider with no token
        // URL fails at the point a user is already mid-login.
        self::assertSame(
            [
                'id' => 7,
                'name' => 'Google Workspace',
                'type' => 'oidc',
                'client_id' => 'client-id-12345.apps.googleusercontent.com',
                'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
                'scopes' => 'openid email profile',
                'enabled' => true,
            ],
            self::google()->toArray(),
        );
    }

    public function testToArrayNeverCarriesTheClientSecret(): void
    {
        $encoded = json_encode(self::google()->toArray(), \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('client_secret', self::google()->toArray());
        self::assertStringNotContainsString(self::SECRET, $encoded);
    }

    public function testTheDisabledFlagTravelsInTheArrayToo(): void
    {
        self::assertFalse(self::google(false)->toArray()['enabled']);
    }
}
