<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\OidcDiscovery;
use App\Security\OutboundUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OidcDiscoveryTest extends TestCase
{
    /**
     * standalone: these tests reach 127.0.0.1 to exercise the unreachable path,
     * which hosted mode would refuse to dial at all.
     */
    private function discovery(): OidcDiscovery
    {
        return new OidcDiscovery(new OutboundUrlValidator('standalone'));
    }

    public function testExtractEndpointsFromConfig(): void
    {
        $discovery = $this->discovery();

        $config = [
            'issuer' => 'https://accounts.google.com',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
        ];

        $endpoints = $discovery->extractEndpoints($config);

        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', $endpoints['auth_url']);
        $this->assertSame('https://oauth2.googleapis.com/token', $endpoints['token_url']);
        $this->assertSame('https://openidconnect.googleapis.com/v1/userinfo', $endpoints['userinfo_url']);
        $this->assertSame('https://www.googleapis.com/oauth2/v3/certs', $endpoints['jwks_uri']);
        $this->assertSame('https://accounts.google.com', $endpoints['issuer']);
    }

    public function testExtractEndpointsHandlesMissingFields(): void
    {
        $discovery = $this->discovery();

        $endpoints = $discovery->extractEndpoints([]);

        $this->assertSame('', $endpoints['auth_url']);
        $this->assertSame('', $endpoints['token_url']);
        $this->assertSame('', $endpoints['userinfo_url']);
    }

    public function testValidateIdTokenWithValidClaims(): void
    {
        $discovery = $this->discovery();

        // Create a mock JWT (header.payload.signature)
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'my-client-id',
            'sub' => '1234567890',
            'email' => 'user@gmail.com',
            'name' => 'Test User',
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('fake-signature');

        $idToken = "{$header}.{$payload}.{$signature}";

        $claims = $discovery->validateIdToken($idToken, 'https://accounts.google.com', 'my-client-id');

        $this->assertNotNull($claims);
        $this->assertSame('user@gmail.com', $claims['email']);
        $this->assertSame('Test User', $claims['name']);
    }

    public function testValidateIdTokenRejectsExpired(): void
    {
        $discovery = $this->discovery();

        $header = base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://example.com',
            'aud' => 'client',
            'exp' => time() - 3600, // Expired
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');

        $claims = $discovery->validateIdToken("{$header}.{$payload}.{$signature}", 'https://example.com', 'client');

        $this->assertNull($claims);
    }

    public function testValidateIdTokenRejectsWrongIssuer(): void
    {
        $discovery = $this->discovery();

        $header = base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://evil.com',
            'aud' => 'client',
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');

        $claims = $discovery->validateIdToken("{$header}.{$payload}.{$signature}", 'https://expected.com', 'client');

        $this->assertNull($claims);
    }

    public function testValidateIdTokenRejectsWrongAudience(): void
    {
        $discovery = $this->discovery();

        $header = base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://example.com',
            'aud' => 'wrong-client',
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');

        $claims = $discovery->validateIdToken("{$header}.{$payload}.{$signature}", 'https://example.com', 'my-client');

        $this->assertNull($claims);
    }

    public function testValidateIdTokenRejectsInvalidFormat(): void
    {
        $discovery = $this->discovery();

        $this->assertNull($discovery->validateIdToken('not-a-jwt', '', ''));
        $this->assertNull($discovery->validateIdToken('a.b', '', ''));
    }

    public function testValidateIdTokenAcceptsArrayAudience(): void
    {
        $discovery = $this->discovery();

        $header = base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://example.com',
            'aud' => ['client-a', 'client-b'],
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');

        $claims = $discovery->validateIdToken("{$header}.{$payload}.{$signature}", 'https://example.com', 'client-b');
        $this->assertNotNull($claims);
    }

    public function testDiscoverReturnsNullForUnreachableUrl(): void
    {
        $discovery = $this->discovery();

        $result = $discovery->discover('http://127.0.0.1:19999');
        $this->assertNull($result);
    }
    public function testHostedModeRefusesInternalIssuer(): void
    {
        // The issuer is admin-supplied through the auth-provider API, so in
        // hosted mode discovery must not be usable to probe the network. This
        // is refused before curl is dialled at all.
        $discovery = new OidcDiscovery(new OutboundUrlValidator('hosted'));

        $this->assertNull($discovery->discover('http://169.254.169.254'));
        $this->assertNull($discovery->discover('file:///etc/passwd'));
    }

    // ------------------------------------------------------- claim checking

    private const string NOW = '2026-06-15T12:00:00+00:00';

    /** A discovery whose clock is frozen, so expiry can be tested at the boundary. */
    private function frozenDiscovery(): OidcDiscovery
    {
        return new OidcDiscovery(new OutboundUrlValidator('standalone'), new MockClock(self::NOW));
    }

    /** @param array<string, mixed> $claims */
    private static function token(array $claims): string
    {
        return implode('.', [
            base64_encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'], \JSON_THROW_ON_ERROR)),
            base64_encode((string) json_encode($claims, \JSON_THROW_ON_ERROR)),
            base64_encode('not-a-real-signature'),
        ]);
    }

    private static function nowTimestamp(): int
    {
        return (new \DateTimeImmutable(self::NOW))->getTimestamp();
    }

    public function testAnArrayAudienceWithoutTheExpectedClientIsRejected(): void
    {
        // The accepting half of this was covered; the rejecting half is what
        // makes the check a check. Without it a token minted for somebody
        // else's client id is accepted here.
        $token = self::token(['iss' => 'https://example.com', 'aud' => ['client-a', 'client-b']]);

        self::assertNull($this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client-c'));
    }

    public function testAnArrayAudienceIsMatchedStrictly(): void
    {
        // in_array with strict comparison: a numeric-string audience must not
        // match an integer one through PHP's juggling.
        $token = self::token(['iss' => 'https://example.com', 'aud' => [0]]);

        self::assertNull($this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client'));
    }

    public function testAStringAudienceMustMatchExactly(): void
    {
        $token = self::token(['iss' => 'https://example.com', 'aud' => 'client-a']);

        self::assertNull($this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client-b'));
        self::assertNotNull($this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client-a'));
    }

    public function testNoExpectedAudienceMeansNoAudienceCheck(): void
    {
        // Both branches skip when the caller supplies nothing to compare
        // against, which is what makes the audience optional for callers that
        // do not know their client id.
        $string = self::token(['iss' => 'https://example.com', 'aud' => 'anyone']);
        $array = self::token(['iss' => 'https://example.com', 'aud' => ['anyone', 'else']]);

        self::assertNotNull($this->frozenDiscovery()->validateIdToken($string, 'https://example.com', ''));
        self::assertNotNull($this->frozenDiscovery()->validateIdToken($array, 'https://example.com', ''));
    }

    public function testAnAudienceOfSomeOtherTypeIsNotCheckedAtAll(): void
    {
        // Characterisation. aud is a string or an array of strings per the
        // spec; anything else -- a number here -- matches neither branch and
        // the token passes without its audience being looked at.
        $token = self::token(['iss' => 'https://example.com', 'aud' => 12345]);

        self::assertNotNull($this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client'));
    }

    public function testNoExpectedIssuerMeansNoIssuerCheck(): void
    {
        $token = self::token(['iss' => 'https://somewhere.else', 'aud' => 'client']);

        self::assertNotNull($this->frozenDiscovery()->validateIdToken($token, '', 'client'));
    }

    // -------------------------------------------------------------- expiry

    /** @return iterable<string, array{int, bool}> */
    public static function expiries(): iterable
    {
        yield 'an hour from now' => [3600, true];
        yield 'one second from now' => [1, true];
        yield 'exactly now' => [0, true];
        yield 'one second ago' => [-1, false];
        yield 'an hour ago' => [-3600, false];
    }

    #[DataProvider('expiries')]
    public function testExpiryIsCheckedAgainstTheClockNotTheWallClock(int $offset, bool $accepted): void
    {
        // exp equal to now is still valid -- the comparison is `<`, not `<=`
        // -- and one second earlier is not. Neither boundary is reachable
        // without a frozen clock, which is why they were never covered.
        $token = self::token([
            'iss' => 'https://example.com',
            'aud' => 'client',
            'exp' => self::nowTimestamp() + $offset,
        ]);

        $claims = $this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client');

        $accepted ? self::assertNotNull($claims) : self::assertNull($claims);
    }

    public function testATokenWithNoExpiryIsAccepted(): void
    {
        // Characterisation, and worth knowing before this class is wired to
        // anything: OIDC requires exp on an ID token, but a missing or
        // non-numeric one lands on the `: 0` fallback, and `0 > 0` is false,
        // so the expiry check is skipped entirely rather than failing closed.
        // Not tightened here because the method already declines to verify the
        // signature -- see its docblock -- so it is not a security boundary
        // until that is done.
        self::assertNotNull($this->frozenDiscovery()->validateIdToken(
            self::token(['iss' => 'https://example.com', 'aud' => 'client']),
            'https://example.com',
            'client',
        ));

        self::assertNotNull($this->frozenDiscovery()->validateIdToken(
            self::token(['iss' => 'https://example.com', 'aud' => 'client', 'exp' => 'soon']),
            'https://example.com',
            'client',
        ));
    }

    public function testAnExpiryGivenAsANumericStringIsStillRead(): void
    {
        $token = self::token([
            'iss' => 'https://example.com',
            'aud' => 'client',
            'exp' => (string) (self::nowTimestamp() - 3600),
        ]);

        self::assertNull($this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client'));
    }

    // ------------------------------------------------------- malformed input

    /** @return iterable<string, array{string}> */
    public static function malformedTokens(): iterable
    {
        yield 'no dots at all' => ['not-a-token'];
        yield 'two segments' => ['header.payload'];
        yield 'four segments' => ['a.b.c.d'];
        yield 'payload is not base64' => ['aGVhZGVy.!!!not-base64!!!.c2ln'];
        yield 'payload is not json' => ['aGVhZGVy.' . base64_encode('plain text') . '.c2ln'];
        yield 'payload is a json scalar' => ['aGVhZGVy.' . base64_encode('"just a string"') . '.c2ln'];
    }

    #[DataProvider('malformedTokens')]
    public function testAMalformedTokenIsRejected(string $token): void
    {
        self::assertNull($this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client'));
    }

    public function testBase64UrlAlphabetIsAccepted(): void
    {
        // JWT uses base64url, so - and _ stand in for + and /. The payload is
        // translated before decoding; without that a token whose payload
        // happens to contain either character fails to parse.
        $claims = ['iss' => 'https://example.com', 'aud' => 'client', 'name' => 'ok??>>'];
        $payload = rtrim(strtr(base64_encode((string) json_encode($claims, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $token = base64_encode('header') . '.' . $payload . '.' . base64_encode('sig');

        $decoded = $this->frozenDiscovery()->validateIdToken($token, 'https://example.com', 'client');

        self::assertNotNull($decoded);
        self::assertSame('ok??>>', $decoded['name']);
    }
}
