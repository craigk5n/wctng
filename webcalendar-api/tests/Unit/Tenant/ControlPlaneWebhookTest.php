<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\ControlPlaneWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Tenant lifecycle notifications.
 *
 * What this class does is decide what to send and what to do about the
 * answer, and none of that used to be checked: the previous tests called the
 * four methods against a dead port and asserted true is true, so the event
 * names, the payload, and the "log if it came back 4xx" rule were all
 * changeable without a failure. The curl call is behind a transport now, so
 * the decisions can be observed without a server.
 */
final class ControlPlaneWebhookTest extends TestCase
{
    private const string URL = 'https://control.example.com/hook';
    private const string NOW = '2026-04-15T12:00:00+00:00';

    private function webhook(
        FakeControlPlaneTransport $transport,
        string $url = self::URL,
        ?RecordingLogger $logger = null,
    ): ControlPlaneWebhook {
        return new ControlPlaneWebhook($url, $logger ?? new RecordingLogger(), new MockClock(self::NOW), $transport);
    }

    // ------------------------------------------------------------- the events

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function lifecycleEvents(): iterable
    {
        yield 'provisioned' => ['tenantProvisioned', ['acme', 'Acme Corp'], 'tenant.provisioned'];
        yield 'suspended' => ['tenantSuspended', ['acme'], 'tenant.suspended'];
        yield 'activated' => ['tenantActivated', ['acme'], 'tenant.activated'];
        yield 'deleted' => ['tenantDeleted', ['acme'], 'tenant.deleted'];
    }

    /** @param array<string, string> $args */
    #[DataProvider('lifecycleEvents')]
    public function testEachLifecycleMethodSendsItsOwnEvent(string $method, array $args, string $expected): void
    {
        $transport = new FakeControlPlaneTransport();

        $this->webhook($transport)->{$method}(...$args);

        $payload = $transport->onlyPayload();
        self::assertSame($expected, $payload['event']);
        self::assertSame('acme', $payload['slug']);
        self::assertSame(self::URL, $transport->sent[0]['url']);
    }

    public function testProvisioningCarriesTheTenantName(): void
    {
        // The only event with a second field, and the only one where dropping
        // an array item would leave the rest of the payload intact.
        $transport = new FakeControlPlaneTransport();

        $this->webhook($transport)->tenantProvisioned('acme', 'Acme Corp');

        self::assertSame('Acme Corp', $transport->onlyPayload()['name']);
    }

    public function testTheOtherEventsCarryNoName(): void
    {
        $transport = new FakeControlPlaneTransport();

        $this->webhook($transport)->tenantSuspended('acme');

        self::assertArrayNotHasKey('name', $transport->onlyPayload());
    }

    public function testThePayloadIsStampedWithTheInjectedClock(): void
    {
        $transport = new FakeControlPlaneTransport();

        $this->webhook($transport)->tenantActivated('acme');

        self::assertSame('2026-04-15T12:00:00+00:00', $transport->onlyPayload()['timestamp']);
    }

    public function testTheTenantFieldsCannotOverwriteTheEnvelope(): void
    {
        // The payload spreads $data over the envelope, so a slug is a sibling
        // of event and timestamp rather than nested. Worth pinning: it is what
        // a consumer parses.
        $transport = new FakeControlPlaneTransport();

        $this->webhook($transport)->tenantProvisioned('acme', 'Acme Corp');

        self::assertSame(
            ['event', 'timestamp', 'slug', 'name'],
            array_keys($transport->onlyPayload()),
        );
    }

    // ------------------------------------------------------------ the guards

    public function testNothingIsSentWithoutAConfiguredUrl(): void
    {
        $transport = new FakeControlPlaneTransport();

        $this->webhook($transport, url: '')->tenantProvisioned('acme', 'Acme Corp');

        self::assertSame([], $transport->sent, 'an unconfigured control plane is not a target');
    }

    public function testASuccessfulDeliveryLogsNothing(): void
    {
        $logger = new RecordingLogger();

        $this->webhook(new FakeControlPlaneTransport(204), logger: $logger)->tenantDeleted('acme');

        self::assertSame([], $logger->warnings);
    }

    public function testAnErrorStatusIsLoggedWithItsCode(): void
    {
        $logger = new RecordingLogger();

        $this->webhook(new FakeControlPlaneTransport(500), logger: $logger)->tenantDeleted('acme');

        self::assertCount(1, $logger->warnings);
        self::assertSame('Webhook returned error', $logger->warnings[0]['message']);
        self::assertSame('tenant.deleted', $logger->warnings[0]['context']['event']);
        self::assertSame(500, $logger->warnings[0]['context']['http_code']);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function statusCodes(): iterable
    {
        yield '200 is fine' => [200, false];
        yield '399 is fine' => [399, false];
        yield '400 is an error' => [400, true];
        yield '404 is an error' => [404, true];
        yield 'no response at all is not logged as an error' => [0, false];
    }

    #[DataProvider('statusCodes')]
    public function testFourHundredIsWhereAnErrorStarts(int $status, bool $expectWarning): void
    {
        $logger = new RecordingLogger();

        $this->webhook(new FakeControlPlaneTransport($status), logger: $logger)->tenantSuspended('acme');

        self::assertCount($expectWarning ? 1 : 0, $logger->warnings);
    }

    public function testATransportFailureIsLoggedRatherThanThrown(): void
    {
        // Fire and forget is the contract: provisioning a tenant must not fail
        // because the control plane is unreachable.
        $logger = new RecordingLogger();
        $transport = new FakeControlPlaneTransport(failure: new \RuntimeException('connection refused'));

        $this->webhook($transport, logger: $logger)->tenantProvisioned('acme', 'Acme Corp');

        self::assertCount(1, $logger->warnings);
        self::assertSame('Webhook failed', $logger->warnings[0]['message']);
        self::assertSame('connection refused', $logger->warnings[0]['context']['error']);
    }

    public function testTheLoggerAndClockAreOptional(): void
    {
        $transport = new FakeControlPlaneTransport();

        (new ControlPlaneWebhook(self::URL, null, null, $transport))->tenantActivated('acme');

        $payload = $transport->onlyPayload();
        self::assertSame('tenant.activated', $payload['event']);
        self::assertIsString($payload['timestamp']);
    }

    public function testTheDefaultLoggerSwallowsWarnings(): void
    {
        $webhook = new ControlPlaneWebhook(self::URL, new NullLogger(), new MockClock(self::NOW), new FakeControlPlaneTransport(500));

        $webhook->tenantDeleted('acme');

        // Reaching here is the assertion: a 500 with no logger configured must
        // not become an exception on the provisioning path.
        self::assertTrue(true);
    }
}

/**
 * Captures warnings so the logging decisions can be asserted.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ((string) $level === 'warning') {
            $this->warnings[] = ['message' => (string) $message, 'context' => $context];
        }
    }
}
