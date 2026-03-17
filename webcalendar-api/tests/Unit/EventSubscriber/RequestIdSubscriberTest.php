<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\RequestIdSubscriber;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class RequestIdSubscriberTest extends TestCase
{
    public function testGeneratesRequestId(): void
    {
        $context = new TenantContext();
        $subscriber = new RequestIdSubscriber(new NullLogger(), $context);

        $request = Request::create('/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onRequest($event);

        $this->assertNotNull($subscriber->getRequestId());
        $this->assertSame(16, \strlen($subscriber->getRequestId() ?? ''));
    }

    public function testUsesProvidedRequestId(): void
    {
        $context = new TenantContext();
        $subscriber = new RequestIdSubscriber(new NullLogger(), $context);

        $request = Request::create('/api/v2/events');
        $request->headers->set('X-Request-Id', 'custom-id-123');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onRequest($event);

        $this->assertSame('custom-id-123', $subscriber->getRequestId());
    }

    public function testAddsRequestIdToResponse(): void
    {
        $context = new TenantContext();
        $subscriber = new RequestIdSubscriber(new NullLogger(), $context);

        $request = Request::create('/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);

        $reqEvent = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($reqEvent);

        $response = new Response();
        $resEvent = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onResponse($resEvent);

        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }

    public function testIncludesTenantInContext(): void
    {
        $context = new TenantContext();
        $context->setTenant(new Tenant(1, 'acme', 'Acme', '', '', '', '', 'pro', 'active'));

        // Use a logger that captures messages
        $logged = [];
        $logger = new class($logged) extends NullLogger {
            /** @param array<mixed> $logged */
            public function __construct(private array &$logged) {}
            /** @param array<mixed> $context */
            public function info(string|\Stringable $message, array $context = []): void {
                $this->logged[] = $context;
            }
        };

        $subscriber = new RequestIdSubscriber($logger, $context);

        $request = Request::create('/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onRequest($event);

        $this->assertNotEmpty($logged);
        $this->assertSame('acme', $logged[0]['tenant'] ?? null);
    }
}
