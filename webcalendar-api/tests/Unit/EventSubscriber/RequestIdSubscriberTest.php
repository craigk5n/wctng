<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\RequestIdSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

final class RequestIdSubscriberTest extends TestCase
{
    private RecordingLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    private function subscriber(): RequestIdSubscriber
    {
        return new RequestIdSubscriber($this->logger);
    }

    private function requestEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createMock(KernelInterface::class), $request, $type);
    }

    private function responseEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createMock(KernelInterface::class), $request, $type, new Response());
    }

    public function testAnIdIsGeneratedWhenTheClientSuppliesNone(): void
    {
        $subscriber = $this->subscriber();
        $subscriber->onRequest($this->requestEvent(Request::create('/api/v2/events')));

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $subscriber->getRequestId());
    }

    public function testTheClientsOwnRequestIdIsKept(): void
    {
        // Lets a caller correlate its own logs with the API's.
        $request = Request::create('/api/v2/events');
        $request->headers->set('X-Request-Id', 'caller-supplied-id');

        $subscriber = $this->subscriber();
        $subscriber->onRequest($this->requestEvent($request));

        self::assertSame('caller-supplied-id', $subscriber->getRequestId());
    }

    public function testTheIdIsPutOnTheRequestForTheRestOfTheApplication(): void
    {
        // ExceptionSubscriber reads _request_id off the request rather than
        // calling back into this class, so the attribute is load-bearing.
        $request = Request::create('/api/v2/events');

        $subscriber = $this->subscriber();
        $subscriber->onRequest($this->requestEvent($request));

        self::assertSame($subscriber->getRequestId(), $request->attributes->get('_request_id'));
    }

    public function testTheLogLineSaysWhichRequestItIs(): void
    {
        // Nothing checked the context, so any field could have been dropped or
        // pointed at the wrong part of the request.
        $subscriber = $this->subscriber();
        $subscriber->onRequest($this->requestEvent(Request::create('/api/v2/events?q=x', 'POST')));

        self::assertCount(1, $this->logger->records);
        self::assertSame('Request started', $this->logger->records[0]['message']);
        self::assertSame(
            ['request_id' => $subscriber->getRequestId(), 'method' => 'POST', 'path' => '/api/v2/events'],
            $this->logger->records[0]['context'],
        );
    }

    public function testTheStartingLineCarriesNoTenant(): void
    {
        // This subscriber runs at 250 and TenantResolverListener at 200, so no
        // tenant is resolved yet -- deliberately, so that a request the
        // resolver refuses still gets an id and a log line. The slug reaches
        // later records through RequestIdProcessor instead. A test that set a
        // tenant by hand before calling onRequest would say otherwise, and
        // used to.
        $subscriber = $this->subscriber();
        $subscriber->onRequest($this->requestEvent(Request::create('/api/v2/events')));

        self::assertArrayNotHasKey('tenant', $this->logger->records[0]['context']);
    }

    public function testItRunsBeforeTheTenantIsResolved(): void
    {
        // The ordering the paragraph above depends on, asserted rather than
        // described: 250 against the resolver's 200.
        $events = RequestIdSubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', 250], $events[KernelEvents::REQUEST]);
        self::assertSame(['onResponse', -100], $events[KernelEvents::RESPONSE]);
    }

    public function testTheResponseCarriesTheIdBack(): void
    {
        $request = Request::create('/api/v2/events');
        $subscriber = $this->subscriber();
        $subscriber->onRequest($this->requestEvent($request));

        $event = $this->responseEvent($request);
        $subscriber->onResponse($event);

        self::assertSame($subscriber->getRequestId(), $event->getResponse()->headers->get('X-Request-Id'));
    }

    public function testASubRequestNeitherTakesAnIdNorCarriesOneBack(): void
    {
        $request = Request::create('/api/v2/events');
        $subscriber = $this->subscriber();

        $subscriber->onRequest($this->requestEvent($request, HttpKernelInterface::SUB_REQUEST));
        self::assertNull($subscriber->getRequestId());
        self::assertCount(0, $this->logger->records);

        $event = $this->responseEvent($request, HttpKernelInterface::SUB_REQUEST);
        $subscriber->onResponse($event);
        self::assertFalse($event->getResponse()->headers->has('X-Request-Id'));
    }

    public function testASubRequestDoesNotBorrowTheMainRequestsIdOnItsWayOut(): void
    {
        // With an id already taken for the main request, the guard on the way
        // out has to still refuse the sub-request.
        $request = Request::create('/api/v2/events');
        $subscriber = $this->subscriber();
        $subscriber->onRequest($this->requestEvent($request));

        $event = $this->responseEvent($request, HttpKernelInterface::SUB_REQUEST);
        $subscriber->onResponse($event);

        self::assertFalse($event->getResponse()->headers->has('X-Request-Id'));
    }

    public function testAResponseWithNoIdTakenIsLeftAlone(): void
    {
        // onResponse can be reached without onRequest having run.
        $event = $this->responseEvent(Request::create('/api/v2/events'));
        $this->subscriber()->onResponse($event);

        self::assertFalse($event->getResponse()->headers->has('X-Request-Id'));
    }
}
