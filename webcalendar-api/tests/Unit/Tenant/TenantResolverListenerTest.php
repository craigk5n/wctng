<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRepository;
use App\Tenant\TenantResolverListener;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class TenantResolverListenerTest extends TestCase
{
    private TenantContext $context;
    private \PDO $pdo;
    private TenantRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(TenantRepository::SCHEMA_SQL);
        $this->repo = new TenantRepository($this->pdo);
    }

    private function createEvent(string $host): RequestEvent
    {
        $request = Request::create('http://' . $host . '/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testResolvesSubdomain(): void
    {
        $this->repo->save(new Tenant(0, 'acme', 'Acme', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Active));

        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
        $event = $this->createEvent('acme.webcalendar.com');

        $listener->onKernelRequest($event);

        $this->assertNotNull($this->context->getTenant());
        $this->assertSame('acme', $this->context->getTenant()?->slug());
    }

    public function testReturns404ForUnknownSlug(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
        $event = $this->createEvent('unknown.webcalendar.com');

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSkipsInStandaloneMode(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'standalone');
        $event = $this->createEvent('acme.webcalendar.com');

        $listener->onKernelRequest($event);

        $this->assertNull($this->context->getTenant());
        $this->assertNull($event->getResponse());
    }

    public function testSkipsWhenHostIsBaseDomain(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
        $event = $this->createEvent('webcalendar.com');

        $listener->onKernelRequest($event);

        $this->assertNull($this->context->getTenant());
        $this->assertNull($event->getResponse());
    }

    public function testSkipsSubRequestEvents(): void
    {
        $this->repo->save(new Tenant(0, 'acme', 'Acme', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Active));
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');

        $request = Request::create('http://acme.webcalendar.com/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $listener->onKernelRequest($event);

        $this->assertNull($this->context->getTenant());
    }

    public function testRejects404ForSuspendedTenant(): void
    {
        $this->repo->save(new Tenant(0, 'suspended-co', 'Suspended', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Suspended));

        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
        $event = $this->createEvent('suspended-co.webcalendar.com');

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ------------------------------------------------ the X-Tenant-Id header

    private function createEventWithHeader(string $host, ?string $tenantHeader): RequestEvent
    {
        $server = $tenantHeader === null ? [] : ['HTTP_X_TENANT_ID' => $tenantHeader];
        $request = Request::create('http://' . $host . '/api/v2/events', 'GET', [], [], [], $server);
        $kernel = $this->createMock(KernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function hostedListener(): TenantResolverListener
    {
        return new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
    }

    private function saveTenant(string $slug, TenantStatus $status = TenantStatus::Active): void
    {
        $this->repo->save(new Tenant(0, $slug, ucfirst($slug), '', ':memory:', '', '', TenantPlan::Pro, $status));
    }

    public function testTheHeaderIdentifiesTheTenantWhenThereIsNoSubdomain(): void
    {
        // The header is the second way in and nothing exercised it, so the
        // whole fallback could have been removed without a failure.
        $this->saveTenant('acme');

        $event = $this->createEventWithHeader('webcalendar.com', 'acme');
        $this->hostedListener()->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertSame('acme', $this->context->getTenant()?->slug());
    }

    public function testAnEmptyHeaderIsNotATenantIdentifier(): void
    {
        // `$headerValue !== null && $headerValue !== ''`. With an or there, an
        // empty header is taken as a slug, looked up, and answered with 404 --
        // so a client that sends the header unset breaks instead of reaching
        // the base domain it asked for.
        $this->saveTenant('acme');

        $event = $this->createEventWithHeader('webcalendar.com', '');
        $this->hostedListener()->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'an empty header is no header at all');
        self::assertNull($this->context->getTenant());
    }

    public function testASubdomainWinsOverAHeaderThatDisagrees(): void
    {
        // The header is only consulted when the host yielded nothing, which is
        // what stops a header from redirecting a request away from the tenant
        // whose domain it arrived on.
        $this->saveTenant('acme');
        $this->saveTenant('other');

        $event = $this->createEventWithHeader('acme.webcalendar.com', 'other');
        $this->hostedListener()->onKernelRequest($event);

        self::assertSame('acme', $this->context->getTenant()?->slug());
    }

    // -------------------------------------------- a host that is not ours

    public function testAHostOutsideTheBaseDomainIsNotParsedAsASlug(): void
    {
        // The suffix check is what makes this safe. Without its return, the
        // host is truncated by the suffix *length* instead of matched against
        // it -- and "acme.evil-domain.com" is exactly as long as
        // "acme.webcalendar.com", so it truncates to "acme" and resolves the
        // real tenant. A domain somebody else controls would then serve as
        // that tenant.
        $this->saveTenant('acme');

        $event = $this->createEvent('acme.evil-domain.com');
        $this->hostedListener()->onKernelRequest($event);

        self::assertNull($this->context->getTenant(), 'a foreign host resolves to no tenant');
        self::assertNull($event->getResponse(), 'and is passed through rather than answered');
    }

    // ------------------------------------------------------ what it answers

    public function testAnUnknownTenantIsRefusedInTheProjectsErrorEnvelope(): void
    {
        // Clients parse every error the same way; only the status code was
        // ever checked, so the body could have been anything at all.
        $event = $this->createEvent('unknown.webcalendar.com');
        $this->hostedListener()->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(
            [
                'data' => null,
                'meta' => null,
                'error' => ['code' => 404, 'message' => 'Tenant not found', 'details' => []],
            ],
            json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testASuspendedTenantIsRefusedInTheProjectsErrorEnvelope(): void
    {
        $this->saveTenant('suspended-co', TenantStatus::Suspended);

        $event = $this->createEvent('suspended-co.webcalendar.com');
        $this->hostedListener()->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(
            [
                'data' => null,
                'meta' => null,
                'error' => ['code' => 403, 'message' => 'Tenant is suspended', 'details' => []],
            ],
            json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testASuspendedTenantNeverReachesTheContext(): void
    {
        // Setting the 403 has to be the end of it. Falling through puts the
        // suspended tenant into the context anyway -- and anything later in
        // the request that reads the context, including whatever renders the
        // error, would be doing so as that tenant.
        $this->saveTenant('suspended-co', TenantStatus::Suspended);

        $event = $this->createEvent('suspended-co.webcalendar.com');
        $this->hostedListener()->onKernelRequest($event);

        self::assertNull($this->context->getTenant());
    }

    public function testAnUnknownTenantNeverReachesTheContext(): void
    {
        $event = $this->createEvent('unknown.webcalendar.com');
        $this->hostedListener()->onKernelRequest($event);

        self::assertNull($this->context->getTenant());
    }

    // ------------------------------------------- how the host is normalised

    /** @return iterable<string, array{string}> */
    public static function hostsNamingTheSameTenant(): iterable
    {
        // Every one of these is the same host as far as DNS and the browser
        // are concerned. getHost() already lowercases, strips the port and
        // trims, so those are here to keep it that way rather than because
        // they were ever broken.
        yield 'plain' => ['acme.webcalendar.com'];
        yield 'uppercased' => ['ACME.WEBCALENDAR.COM'];
        yield 'with a port' => ['acme.webcalendar.com:8443'];
        yield 'fully qualified with the root dot' => ['acme.webcalendar.com.'];
    }

    #[DataProvider('hostsNamingTheSameTenant')]
    public function testEverySpellingOfATenantHostResolvesToThatTenant(string $host): void
    {
        // The root-dot form used to resolve no tenant at all: it matched no
        // base domain, so the request was handled as though it had arrived on
        // the base domain -- with no tenant in the context.
        $this->saveTenant('acme');

        $event = $this->createEvent($host);
        $this->hostedListener()->onKernelRequest($event);

        self::assertSame('acme', $this->context->getTenant()?->slug(), "host {$host} resolved no tenant");
        self::assertNull($event->getResponse());
    }

    public function testTheBaseDomainWithARootDotIsStillTheBaseDomain(): void
    {
        // The other side of the same normalisation: "webcalendar.com." must not
        // be read as a subdomain of itself.
        $this->saveTenant('acme');

        $event = $this->createEvent('webcalendar.com.');
        $this->hostedListener()->onKernelRequest($event);

        self::assertNull($this->context->getTenant());
        self::assertNull($event->getResponse(), 'the base domain is passed through, not refused');
    }

    public function testARootDotDoesNotTurnAForeignHostIntoATenant(): void
    {
        // Normalising the host must not widen what counts as the base domain:
        // a domain somebody else controls still has to resolve to nothing.
        $this->saveTenant('acme');

        $event = $this->createEvent('acme.evil-domain.com.');
        $this->hostedListener()->onKernelRequest($event);

        self::assertNull($this->context->getTenant());
    }
}
