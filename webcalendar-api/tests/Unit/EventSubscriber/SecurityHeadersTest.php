<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\SecurityHeaderSubscriber;
use App\Security\CspNonceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

final class SecurityHeadersTest extends TestCase
{
    private function buildEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(KernelInterface::class);
        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    private function subscriber(RequestStack $stack): SecurityHeaderSubscriber
    {
        return new SecurityHeaderSubscriber(new CspNonceProvider($stack));
    }

    public function testCoreSecurityHeadersPresent(): void
    {
        $request = Request::create('/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();

        $this->subscriber($stack)->onResponse($this->buildEvent($request, $response));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function testDeprecatedXXssProtectionIsRemoved(): void
    {
        $request = Request::create('/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();
        $response->headers->set('X-XSS-Protection', '1; mode=block'); // simulate a stale upstream setter

        $this->subscriber($stack)->onResponse($this->buildEvent($request, $response));

        $this->assertFalse(
            $response->headers->has('X-XSS-Protection'),
            'X-XSS-Protection is deprecated and must not be emitted'
        );
    }

    public function testContentSecurityPolicyIncludesNonce(): void
    {
        $request = Request::create('/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();

        $this->subscriber($stack)->onResponse($this->buildEvent($request, $response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);

        // Core directives
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);

        // Nonce in script-src, matching the request attribute
        $nonce = $request->attributes->get('csp_nonce');
        $this->assertIsString($nonce);
        $this->assertNotSame('', $nonce);
        $this->assertMatchesRegularExpression(
            "/script-src [^;]*'nonce-" . preg_quote($nonce, '/') . "'/",
            $csp,
            'script-src must include the request nonce'
        );

        // HTTP-friendly connect-src (internal deployments run on plain HTTP)
        $this->assertMatchesRegularExpression(
            '/connect-src [^;]*(?<![a-z])http:/',
            $csp,
            'connect-src must allow http: so internal HTTP deploys keep working'
        );
        $this->assertMatchesRegularExpression(
            '/connect-src [^;]*(?<![a-z])ws:/',
            $csp,
            'connect-src must allow ws: for Mercure / websocket clients on HTTP'
        );
    }

    public function testNonceIsStableAcrossMultipleSubscriberCalls(): void
    {
        $request = Request::create('/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);

        $provider = new CspNonceProvider($stack);
        $first = $provider->getNonce();
        $second = $provider->getNonce();

        $this->assertSame($first, $second, 'nonce must be stable within a single request');
        $this->assertSame(
            $first,
            $request->attributes->get('csp_nonce'),
            'nonce must be exposed on the request for controllers to inline'
        );
    }

    public function testNonceDiffersAcrossRequests(): void
    {
        $stack = new RequestStack();
        $provider = new CspNonceProvider($stack);

        $r1 = Request::create('/a');
        $stack->push($r1);
        $n1 = $provider->getNonce();
        $stack->pop();

        $r2 = Request::create('/b');
        $stack->push($r2);
        $n2 = $provider->getNonce();

        $this->assertNotSame($n1, $n2, 'fresh requests must get fresh nonces');
    }

    public function testReportOnlyHeaderMirrorsPolicyAndPointsAtReportEndpoint(): void
    {
        $request = Request::create('/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();

        $this->subscriber($stack)->onResponse($this->buildEvent($request, $response));

        $enforced = $response->headers->get('Content-Security-Policy');
        $reportOnly = $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotNull($reportOnly);
        $this->assertNotNull($enforced);
        $this->assertNotSame('', $enforced);
        $this->assertStringContainsString('report-uri /api/v2/csp-report', $reportOnly);
        // cast keeps Psalm happy — we've already asserted non-empty above
        /** @var non-empty-string $enforcedPrefix */
        $enforcedPrefix = $enforced;
        $this->assertStringStartsWith($enforcedPrefix, $reportOnly, 'report-only policy must mirror the enforced policy');
    }

    public function testPermissionsPolicyDenySensitiveFeaturesByDefault(): void
    {
        $request = Request::create('/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();

        $this->subscriber($stack)->onResponse($this->buildEvent($request, $response));

        $pp = $response->headers->get('Permissions-Policy');
        $this->assertNotNull($pp);

        foreach (['geolocation', 'microphone', 'camera', 'payment', 'usb'] as $feature) {
            $this->assertMatchesRegularExpression(
                "/\\b{$feature}=\\(\\)/",
                $pp,
                "{$feature} must be denied by default in Permissions-Policy"
            );
        }
    }

    public function testHstsUpgradedWithPreloadOnHttps(): void
    {
        $request = Request::create('https://example.com/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();

        $this->subscriber($stack)->onResponse($this->buildEvent($request, $response));

        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertNotNull($hsts);
        $this->assertStringContainsString('max-age=63072000', $hsts, 'HSTS must use 2-year max-age');
        $this->assertStringContainsString('includeSubDomains', $hsts);
        $this->assertStringContainsString('preload', $hsts);
    }

    public function testNoHstsOnPlainHttp(): void
    {
        $request = Request::create('http://localhost/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();

        $this->subscriber($stack)->onResponse($this->buildEvent($request, $response));

        $this->assertFalse(
            $response->headers->has('Strict-Transport-Security'),
            'HSTS must not be emitted on HTTP — preload would poison the hostname forever'
        );
    }

    public function testSkipsSubRequests(): void
    {
        $request = Request::create('/api/v2/events');
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();
        $kernel = $this->createMock(KernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);

        $this->subscriber($stack)->onResponse($event);

        $this->assertFalse(
            $response->headers->has('Content-Security-Policy'),
            'sub-requests (ESI, fragments) must not re-set headers'
        );
    }

    public function testSubscribesToResponseEvent(): void
    {
        $events = SecurityHeaderSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey('kernel.response', $events);
    }

    // -------------------------------------------------- when it is wired in

    public function testItRunsLateEnoughToSeeTheFinishedResponse(): void
    {
        // The priority is the whole reason this lands after everything else
        // that touches the response; nothing asserted it, nor the handler
        // name, so either could change and the headers would quietly stop
        // being applied to responses built by later listeners.
        self::assertSame(
            [KernelEvents::RESPONSE => ['onResponse', -50]],
            SecurityHeaderSubscriber::getSubscribedEvents(),
        );
    }

    // --------------------------------------- the directives, spelled out

    /** @return array<string, string> directive name => value */
    private function directivesFrom(string $csp): array
    {
        $directives = [];
        foreach (explode('; ', $csp) as $directive) {
            [$name, $value] = array_pad(explode(' ', trim($directive), 2), 2, '');
            $directives[$name] = $value;
        }

        return $directives;
    }

    private function cspFor(Request $request): string
    {
        $stack = new RequestStack();
        $stack->push($request);
        $response = new Response();

        $this->subscriber($stack)->onResponse($this->buildEvent($request, $response));

        $csp = $response->headers->get('Content-Security-Policy');
        self::assertIsString($csp);

        return $csp;
    }

    public function testTheScriptSourceIsSelfTheNonceAndTheAllowedCdnInThatOrder(): void
    {
        // Asserting only that the nonce appears somewhere in script-src left
        // the rest of the value free: the CDN could be dropped, or
        // concatenated the wrong way round into a source expression browsers
        // ignore. Either one blocks every script the app loads, and the
        // existing regex would still match.
        $request = Request::create('https://example.com/');
        $csp = $this->cspFor($request);

        $nonce = $request->attributes->get('csp_nonce');
        self::assertIsString($nonce);
        self::assertNotSame('', $nonce);

        self::assertSame(
            "'self' 'nonce-{$nonce}' https://unpkg.com",
            $this->directivesFrom($csp)['script-src'] ?? null,
        );
    }

    public function testTheStyleSourceKeepsItsAllowedCdn(): void
    {
        self::assertSame(
            "'self' 'unsafe-inline' https://unpkg.com",
            $this->directivesFrom($this->cspFor(Request::create('https://example.com/')))['style-src'] ?? null,
        );
    }

    public function testTheImageSourceKeepsTheMapTileHost(): void
    {
        // The tile host is what makes the map on an event page render; drop it
        // and every tile is blocked, which looks like a broken map rather than
        // a policy change.
        self::assertSame(
            "'self' data: http: https: https://tile.openstreetmap.org",
            $this->directivesFrom($this->cspFor(Request::create('https://example.com/')))['img-src'] ?? null,
        );
    }

    public function testEveryOtherDirectiveIsExactlyWhatItClaimsToBe(): void
    {
        // The ones with no CDN concatenated into them, pinned as whole values
        // rather than by substring, so a directive cannot pick up an extra
        // source without this failing.
        $directives = $this->directivesFrom($this->cspFor(Request::create('https://example.com/')));

        self::assertSame("'self'", $directives['default-src'] ?? null);
        self::assertSame("'self' data:", $directives['font-src'] ?? null);
        self::assertSame("'self' http: https: ws: wss:", $directives['connect-src'] ?? null);
        self::assertSame("'self'", $directives['media-src'] ?? null);
        self::assertSame("'none'", $directives['object-src'] ?? null);
        self::assertSame("'none'", $directives['base-uri'] ?? null);
        self::assertSame("'none'", $directives['frame-ancestors'] ?? null);
        self::assertSame("'self'", $directives['form-action'] ?? null);
    }
}
