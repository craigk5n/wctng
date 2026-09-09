<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\OutboundUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * IP literals throughout, so these stay hermetic: a hostname would put a real
 * DNS lookup in the middle of the assertion.
 */
final class OutboundUrlValidatorTest extends TestCase
{
    private function hosted(): OutboundUrlValidator
    {
        return new OutboundUrlValidator('hosted');
    }

    private function standalone(): OutboundUrlValidator
    {
        return new OutboundUrlValidator('standalone');
    }

    /** @return list<array{string}> */
    public static function blockedSchemes(): array
    {
        return [
            ['file:///etc/passwd'],
            ['gopher://127.0.0.1:11211/'],
            ['ftp://example.com/x'],
            ['dict://127.0.0.1:11211/'],
        ];
    }

    #[DataProvider('blockedSchemes')]
    public function testHostedModeRejectsNonHttpSchemes(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->hosted()->validate($url);
    }

    #[DataProvider('blockedSchemes')]
    public function testStandaloneModeAlsoRejectsNonHttpSchemes(string $url): void
    {
        // No deployment has a reason to let a webhook reach file:// or gopher://.
        $this->expectException(\InvalidArgumentException::class);

        $this->standalone()->validate($url);
    }

    public function testRejectsEmbeddedCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not embed credentials');

        $this->standalone()->validate('https://user:pass@example.com/hook');
    }

    public function testRejectsRelativeUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->standalone()->validate('/not-absolute');
    }

    /** @return list<array{string}> */
    public static function internalAddresses(): array
    {
        return [
            ['http://127.0.0.1/hook'],            // loopback
            ['http://169.254.169.254/latest/'],   // cloud metadata
            ['http://10.0.0.5/hook'],             // RFC 1918
            ['http://192.168.1.10/hook'],
            ['http://172.16.0.9/hook'],
            ['http://100.64.0.1/hook'],           // RFC 6598 shared address space
            ['http://0.0.0.0/hook'],
            ['http://[::1]/hook'],                // IPv6 loopback
            ['http://[fd00::1]/hook'],            // IPv6 unique local
            ['http://[fe80::1]/hook'],            // IPv6 link local
        ];
    }

    #[DataProvider('internalAddresses')]
    public function testHostedModeBlocksInternalAddresses(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->hosted()->validate($url);
    }

    #[DataProvider('internalAddresses')]
    public function testStandaloneModeAllowsInternalAddresses(string $url): void
    {
        // Self-hosted installs legitimately post to services on their own
        // network, so only the scheme and credential rules apply there.
        $result = $this->standalone()->validate($url);

        self::assertNull($result['ip'], 'standalone does not pin, because it does not resolve');
    }

    public function testHostedModeAllowsPublicAddressAndReturnsPin(): void
    {
        $result = $this->hosted()->validate('https://8.8.8.8/hook');

        self::assertSame('8.8.8.8', $result['host']);
        self::assertSame(443, $result['port']);
        self::assertSame('8.8.8.8', $result['ip'], 'delivery pins to the validated address');
    }

    public function testPortDefaultsPerSchemeAndExplicitPortWins(): void
    {
        self::assertSame(80, $this->hosted()->validate('http://8.8.8.8/hook')['port']);
        self::assertSame(443, $this->hosted()->validate('https://8.8.8.8/hook')['port']);
        self::assertSame(8443, $this->hosted()->validate('https://8.8.8.8:8443/hook')['port']);
    }

    public function testHostedModeFailsClosedOnUnresolvableHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not resolve');

        // .invalid is reserved by RFC 2606 and never resolves.
        $this->hosted()->validate('https://webhook-target.invalid/hook');
    }

    public function testEnforcementFlagFollowsAppMode(): void
    {
        self::assertTrue($this->hosted()->enforcesNetworkRules());
        self::assertFalse($this->standalone()->enforcesNetworkRules());
    }
    public function testSchemeComparisonIsCaseInsensitive(): void
    {
        // strtolower() on the scheme was not covered: an uppercase FILE:// has
        // to be rejected and an uppercase HTTPS:// accepted.
        $result = $this->hosted()->validate('HTTPS://8.8.8.8/hook');
        self::assertSame('8.8.8.8', $result['ip']);

        $this->expectException(\InvalidArgumentException::class);
        $this->hosted()->validate('FILE:///etc/passwd');
    }

    /** @return list<array{string, bool}> */
    public static function sharedAddressSpaceBoundaries(): array
    {
        return [
            ['100.63.255.255', true],   // just below 100.64.0.0/10
            ['100.64.0.0', false],      // first address of the range
            ['100.127.255.255', false], // last address of the range
            ['100.128.0.0', true],      // just above
        ];
    }

    #[DataProvider('sharedAddressSpaceBoundaries')]
    public function testSharedAddressSpaceBoundariesAreExact(string $ip, bool $allowed): void
    {
        // The < and > comparisons bounding RFC 6598 were both survivable
        // mutations: nothing pinned the edges of the range.
        if (!$allowed) {
            $this->expectException(\InvalidArgumentException::class);
        }

        $result = $this->hosted()->validate('http://' . $ip . '/hook');

        self::assertSame($ip, $result['ip']);
    }

    public function testBracketedIpv6LiteralIsAccepted(): void
    {
        // trim($host, '[]') had no covering assertion on the accepting side:
        // without it a public IPv6 literal would fail to parse as an address.
        $result = $this->hosted()->validate('http://[2606:4700::1111]:8080/hook');

        self::assertSame('2606:4700::1111', $result['ip']);
        self::assertSame(8080, $result['port']);
    }
    public function testRejectsProtocolRelativeUrl(): void
    {
        // parse_url() happily returns a host for //host/path with no scheme.
        // The scheme check is what catches it, and nothing exercised that
        // branch independently of the missing-host one.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute');

        $this->hosted()->validate('//example.com/hook');
    }

    public function testRejectsUsernameWithoutPassword(): void
    {
        // https://user@host/ is the same host-confusion trick as user:pass@,
        // and only the user half is set.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not embed credentials');

        $this->hosted()->validate('https://user@8.8.8.8/hook');
    }

    public function testStandaloneStillReportsHostAndPort(): void
    {
        $result = $this->standalone()->validate('http://internal.service:9000/hook');

        self::assertSame('internal.service', $result['host']);
        self::assertSame(9000, $result['port']);
        self::assertNull($result['ip']);
    }

    public function testCurlSecurityOptionsRestrictProtocolsAndRedirects(): void
    {
        // These are the actual controls: without them curl would follow a 302
        // anywhere and speak schemes the validator never approved. Asserted
        // directly, because reaching them only through the dispatcher left
        // every one of them mutable without a test noticing.
        $options = OutboundUrlValidator::curlSecurityOptions(
            ['host' => 'example.com', 'port' => 443, 'ip' => null],
        );

        self::assertSame('http,https', $options[\CURLOPT_PROTOCOLS_STR]);
        self::assertSame('http,https', $options[\CURLOPT_REDIR_PROTOCOLS_STR]);
        self::assertFalse($options[\CURLOPT_FOLLOWLOCATION]);
        self::assertArrayNotHasKey(\CURLOPT_RESOLVE, $options, 'nothing to pin when ip is null');
    }

    public function testCurlSecurityOptionsPinTheValidatedAddress(): void
    {
        $options = OutboundUrlValidator::curlSecurityOptions(
            ['host' => 'example.com', 'port' => 8443, 'ip' => '93.184.216.34'],
        );

        self::assertSame(['example.com:8443:93.184.216.34'], $options[\CURLOPT_RESOLVE]);
        // The restrictions still apply alongside the pin.
        self::assertSame('http,https', $options[\CURLOPT_PROTOCOLS_STR]);
        self::assertSame('http,https', $options[\CURLOPT_REDIR_PROTOCOLS_STR]);
        self::assertFalse($options[\CURLOPT_FOLLOWLOCATION]);
    }
}
