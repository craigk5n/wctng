<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class LocaleSubscriberTest extends TestCase
{
    public function testSetsLocaleFromAcceptLanguage(): void
    {
        $subscriber = new LocaleSubscriber();

        $request = Request::create('/api/v2/events');
        $request->headers->set('Accept-Language', 'fr-FR,fr;q=0.9,en;q=0.8');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        $this->assertSame('fr', $request->getLocale());
    }

    public function testDefaultsToEnglishWhenNoHeader(): void
    {
        $subscriber = new LocaleSubscriber();

        $request = Request::create('/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        $this->assertSame('en', $request->getLocale());
    }

    public function testSetsGermanLocale(): void
    {
        $subscriber = new LocaleSubscriber();

        $request = Request::create('/api/v2/events');
        $request->headers->set('Accept-Language', 'de');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        $this->assertSame('de', $request->getLocale());
    }

    public function testFallsBackForUnsupportedLocale(): void
    {
        $subscriber = new LocaleSubscriber();

        $request = Request::create('/api/v2/events');
        $request->headers->set('Accept-Language', 'ja-JP');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        // Falls back to default (en)
        $this->assertSame('en', $request->getLocale());
    }

    public function testSubscribesToRequestEvent(): void
    {
        $events = LocaleSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey('kernel.request', $events);
    }
}
