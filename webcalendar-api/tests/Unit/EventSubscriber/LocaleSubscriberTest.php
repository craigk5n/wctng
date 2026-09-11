<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
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
        // The method and the priority as well as the event: with only the key
        // checked, the subscriber could have been left listening with no
        // handler named, or moved below Symfony's own locale listener at 16,
        // which applies a route's _locale and would then be overridden here.
        $events = LocaleSubscriber::getSubscribedEvents();

        $this->assertSame(['onKernelRequest', 100], $events[KernelEvents::REQUEST] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function supportedLanguages(): iterable
    {
        // One per entry in SUPPORTED_LOCALES. Dropping a language from that
        // list does not fail to compile or to run -- requests for it quietly
        // become English -- so each is asked for by name here.
        yield 'english' => ['en', 'en'];
        yield 'french' => ['fr', 'fr'];
        yield 'german' => ['de', 'de'];
        yield 'spanish' => ['es', 'es'];
        yield 'spanish as spoken in mexico' => ['es-MX', 'es'];
        yield 'german with a region and a quality' => ['de-AT;q=0.8', 'de'];
    }

    #[DataProvider('supportedLanguages')]
    public function testEachSupportedLanguageIsHonoured(string $header, string $expected): void
    {
        $subscriber = new LocaleSubscriber();

        $request = Request::create('/api/v2/events');
        $request->headers->set('Accept-Language', $header);
        $event = new RequestEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequest($event);

        $this->assertSame($expected, $request->getLocale());
    }

    public function testTheHeadersOrderOfPreferenceIsObeyedRatherThanItsOrderOfAppearance(): void
    {
        // German is listed second and wanted most. Reading the header in
        // written order instead would answer in French.
        $subscriber = new LocaleSubscriber();

        $request = Request::create('/api/v2/events');
        $request->headers->set('Accept-Language', 'fr;q=0.1,de;q=0.9');
        $event = new RequestEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequest($event);

        $this->assertSame('de', $request->getLocale());
    }
}
