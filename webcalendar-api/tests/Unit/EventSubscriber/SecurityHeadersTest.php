<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\SecurityHeaderSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class SecurityHeadersTest extends TestCase
{
    public function testAddsSecurityHeaders(): void
    {
        $subscriber = new SecurityHeaderSubscriber();

        $request = Request::create('/api/v2/events');
        $response = new Response();
        $kernel = $this->createMock(KernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber->onResponse($event);

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('1; mode=block', $response->headers->get('X-XSS-Protection'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function testNoHstsOnHttp(): void
    {
        $subscriber = new SecurityHeaderSubscriber();

        $request = Request::create('http://localhost/api/v2/events');
        $response = new Response();
        $kernel = $this->createMock(KernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber->onResponse($event);

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function testSubscribesToResponseEvent(): void
    {
        $events = SecurityHeaderSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey('kernel.response', $events);
    }
}
