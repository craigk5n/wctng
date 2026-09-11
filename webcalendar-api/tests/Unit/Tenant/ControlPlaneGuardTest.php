<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\ControlPlaneGuard;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Who is let into the control plane.
 *
 * The guard is the whole of the authorisation on /control/v1/*: past it sit
 * the routes that create, suspend, export and delete tenants. It scored 100%
 * MSI on two tests -- standalone answers 404, and no credentials answers 401 --
 * which between them never let anybody in. Nothing asserted that a super admin
 * is admitted, that any other role is refused, or that the exemption carved out
 * for the login endpoint cannot be widened by a path that merely starts with it.
 *
 * String literals are not mutated, so the prefix this matches on and the login
 * path it exempts are invisible to the mutation score as well: both are pinned
 * here by hand.
 */
final class ControlPlaneGuardTest extends TestCase
{
    private function event(string $path, ?string $authorization = null): RequestEvent
    {
        $request = Request::create($path);
        if ($authorization !== null) {
            $request->headers->set('Authorization', $authorization);
        }

        return new RequestEvent($this->createMock(KernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    /** An encoder that returns the given payload for any token. */
    private function encoderReturning(mixed $payload): JWTEncoderInterface
    {
        $encoder = $this->createMock(JWTEncoderInterface::class);
        $encoder->method('decode')->willReturn($payload);

        return $encoder;
    }

    private function encoderRefusing(): JWTEncoderInterface
    {
        $encoder = $this->createMock(JWTEncoderInterface::class);
        $encoder->method('decode')->willThrowException(new JWTDecodeFailureException('expired', 'Expired JWT Token'));

        return $encoder;
    }

    // ------------------------------------------------------------- admitted

    public function testASuperAdminIsLetThrough(): void
    {
        // The half nothing covered: the guard has to admit somebody, or the
        // control plane is unreachable and every other test here still passes.
        $guard = new ControlPlaneGuard($this->encoderReturning(['role' => 'super_admin']), 'hosted');
        $event = $this->event('/control/v1/tenants', 'Bearer any.valid.token');

        $guard->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'a super admin should reach the controller');
    }

    public function testTheLoginEndpointNeedsNoToken(): void
    {
        // Exempt because it is where the token comes from. If this stopped
        // working nobody could obtain one.
        $guard = new ControlPlaneGuard($this->encoderRefusing(), 'hosted');
        $event = $this->event('/control/v1/auth/login');

        $guard->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testRoutesOutsideTheControlPlaneAreLeftAlone(): void
    {
        $guard = new ControlPlaneGuard($this->encoderRefusing(), 'hosted');
        $event = $this->event('/api/v2/events');

        $guard->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'the tenant API is not this guard\'s business');
    }

    public function testSubRequestsAreNotGuarded(): void
    {
        // A forwarded sub-request carries the main request's credentials only
        // by accident; the main request has already been judged.
        $guard = new ControlPlaneGuard($this->encoderRefusing(), 'hosted');
        $request = Request::create('/control/v1/tenants');
        $event = new RequestEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        $guard->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testTheEncoderIsHandedTheTokenAndNotTheSchemeWithIt(): void
    {
        // Everything else here mocks the encoder to answer regardless of what
        // it is given, so the slice that strips "Bearer " is invisible to them:
        // taking a character too many or too few, or forgetting to slice at
        // all, passes every other test in this file while the real encoder
        // rejects every token there is.
        $seen = null;
        $encoder = $this->createMock(JWTEncoderInterface::class);
        $encoder->method('decode')
            ->willReturnCallback(static function (string $token) use (&$seen): array {
                $seen = $token;

                return ['role' => 'super_admin'];
            });

        $guard = new ControlPlaneGuard($encoder, 'hosted');
        $guard->onKernelRequest($this->event('/control/v1/tenants', 'Bearer header.payload.signature'));

        self::assertSame('header.payload.signature', $seen);
    }

    // ------------------------------------------------------------- refused

    /** @return iterable<string, array{mixed}> */
    public static function payloadsThatAreNotASuperAdmin(): iterable
    {
        yield 'another role' => [['role' => 'admin']];
        yield 'a tenant user token, which carries no role at all' => [['username' => 'alice', 'is_admin' => true]];
        yield 'an empty role' => [['role' => '']];
        yield 'the role in the wrong case' => [['role' => 'SUPER_ADMIN']];
        yield 'the role as a list' => [['role' => ['super_admin']]];
        yield 'the role as true' => [['role' => true]];
    }

    #[DataProvider('payloadsThatAreNotASuperAdmin')]
    public function testAnythingOtherThanASuperAdminIsRefused(mixed $payload): void
    {
        $guard = new ControlPlaneGuard($this->encoderReturning($payload), 'hosted');
        $event = $this->event('/control/v1/tenants', 'Bearer any.valid.token');

        $guard->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testATokenTheEncoderRefusesIsAnUnauthenticatedRequest(): void
    {
        $guard = new ControlPlaneGuard($this->encoderRefusing(), 'hosted');
        $event = $this->event('/control/v1/tenants', 'Bearer expired.or.forged');

        $guard->onKernelRequest($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
    }

    /** @return iterable<string, array{?string}> */
    public static function unusableAuthorizationHeaders(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'basic auth' => ['Basic YWRtaW46YWRtaW4='];
        yield 'the scheme in lower case' => ['bearer some.token'];
        yield 'the token with no scheme' => ['some.token'];
        yield 'the scheme with no space' => ['Bearersome.token'];
    }

    #[DataProvider('unusableAuthorizationHeaders')]
    public function testOnlyABearerTokenCountsAsCredentials(?string $header): void
    {
        $guard = new ControlPlaneGuard($this->encoderReturning(['role' => 'super_admin']), 'hosted');
        $event = $this->event('/control/v1/tenants', $header);

        $guard->onKernelRequest($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
    }

    // ------------------------------------------ the shape of the exemption

    /** @return iterable<string, array{string}> */
    public static function pathsThatOnlyLookLikeTheLoginEndpoint(): iterable
    {
        // The exemption is an exact match, and has to stay one: a prefix test
        // would hand out every control route to anybody who appended the login
        // path to it.
        yield 'traversal back up to another route' => ['/control/v1/auth/login/../tenants'];
        yield 'a longer path underneath it' => ['/control/v1/auth/login/tenants'];
        yield 'a trailing slash' => ['/control/v1/auth/login/'];
        yield 'a query-looking suffix' => ['/control/v1/auth/loginx'];
    }

    #[DataProvider('pathsThatOnlyLookLikeTheLoginEndpoint')]
    public function testOnlyTheLoginEndpointItselfIsExempt(string $path): void
    {
        $guard = new ControlPlaneGuard($this->encoderRefusing(), 'hosted');
        $event = $this->event($path);

        $guard->onKernelRequest($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode(), $path . ' should still need credentials');
    }

    // -------------------------------------------------------- standalone

    public function testStandaloneRefusesEvenASuperAdmin(): void
    {
        // 404 rather than 403: in standalone mode the control plane does not
        // exist, and saying "forbidden" would tell an anonymous caller that it
        // does.
        $guard = new ControlPlaneGuard($this->encoderReturning(['role' => 'super_admin']), 'standalone');
        $event = $this->event('/control/v1/tenants', 'Bearer any.valid.token');

        $guard->onKernelRequest($event);

        self::assertSame(404, $event->getResponse()?->getStatusCode());
    }

    public function testStandaloneClosesTheLoginEndpointToo(): void
    {
        $guard = new ControlPlaneGuard($this->encoderReturning(['role' => 'super_admin']), 'standalone');
        $event = $this->event('/control/v1/auth/login');

        $guard->onKernelRequest($event);

        self::assertSame(404, $event->getResponse()?->getStatusCode());
    }
}
