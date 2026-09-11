<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ExceptionSubscriber;
use App\Service\ErrorMetricsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use WebCalendar\Core\Domain\Exception\AuthorizationException;

/**
 * What a client is told when something throws, and what is written down.
 *
 * The only tests this class had went through the HTTP stack for a 404 and a
 * 405, which reach one of its four branches. Infection never saw it at all --
 * it runs the unit suite, and nothing there executed a line of it -- so every
 * decision here could be changed without a failure: the status an exception
 * maps to, whether an internal message reaches the client in production, which
 * level it is logged at, and whether a failure in the metrics counter takes the
 * error response down with it.
 */
final class ExceptionSubscriberTest extends TestCase
{
    private RecordingLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    private function event(\Throwable $exception, string $path = '/api/v2/events'): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createMock(KernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    /** @return array{code: int, message: string, details: array<mixed>} */
    private static function envelopeOf(ExceptionEvent $event): array
    {
        $response = $event->getResponse();
        self::assertNotNull($response);

        /** @var array{data: null, meta: null, error: array{code: int, message: string, details: array<mixed>}} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertNull($body['data']);
        self::assertNull($body['meta']);

        return $body['error'];
    }

    // --------------------------------------------------- which status it is

    /** @return iterable<string, array{\Throwable, int}> */
    public static function exceptionsAndTheirStatus(): iterable
    {
        yield 'an HTTP exception keeps its own status' => [new ConflictHttpException('already there'), 409];
        yield 'a not found' => [new NotFoundHttpException('no such event'), 404];
        yield 'an access denied' => [new AccessDeniedHttpException('not yours'), 403];
        yield 'a domain authorization failure' => [new AuthorizationException('not allowed'), 403];
        yield 'a bad argument' => [new \InvalidArgumentException('id must be positive'), 400];
        yield 'anything else' => [new \RuntimeException('the database fell over'), 500];
        yield 'an error rather than an exception' => [new \DivisionByZeroError('nope'), 500];
    }

    #[DataProvider('exceptionsAndTheirStatus')]
    public function testTheStatusMatchesWhatWentWrong(\Throwable $exception, int $expected): void
    {
        $subscriber = new ExceptionSubscriber('dev', $this->logger);
        $event = $this->event($exception);

        $subscriber->onKernelException($event);

        self::assertSame($expected, $event->getResponse()?->getStatusCode());
        self::assertSame($expected, self::envelopeOf($event)['code']);
    }

    // ------------------------------------------------ what the client sees

    public function testProductionDoesNotRepeatAnInternalMessageBackToTheClient(): void
    {
        // An uncaught exception's message is written by whatever threw it --
        // a PDO error carries the query, a file error carries a path. In
        // production the client is told nothing but that it failed.
        $subscriber = new ExceptionSubscriber('prod', $this->logger);
        $event = $this->event(new \RuntimeException('SQLSTATE[28000] access denied for user wc_acme@10.0.0.4'));

        $subscriber->onKernelException($event);

        $error = self::envelopeOf($event);
        self::assertSame('Internal server error', $error['message']);
        self::assertStringNotContainsString('SQLSTATE', $error['message']);
        self::assertStringNotContainsString('wc_acme', $error['message']);
    }

    public function testOutsideProductionTheRealMessageIsShownForDebugging(): void
    {
        $subscriber = new ExceptionSubscriber('dev', $this->logger);
        $event = $this->event(new \RuntimeException('the database fell over'));

        $subscriber->onKernelException($event);

        self::assertSame('the database fell over', self::envelopeOf($event)['message']);
    }

    public function testAnExpectedFailureKeepsItsMessageEvenInProduction(): void
    {
        // Only the catch-all branch is withheld. A 404 or a bad argument says
        // what a caller got wrong, which is not an internal detail.
        $subscriber = new ExceptionSubscriber('prod', $this->logger);

        $notFound = $this->event(new NotFoundHttpException('no such event'));
        $subscriber->onKernelException($notFound);
        self::assertSame('no such event', self::envelopeOf($notFound)['message']);

        $bad = $this->event(new \InvalidArgumentException('id must be positive'));
        $subscriber->onKernelException($bad);
        self::assertSame('id must be positive', self::envelopeOf($bad)['message']);
    }

    /** @return iterable<string, array{string}> */
    public static function pathsOutsideTheApi(): iterable
    {
        yield 'the control plane' => ['/control/v1/tenants'];
        yield 'caldav' => ['/dav/calendars/admin/default/'];
        yield 'the root' => ['/'];
        yield 'something merely starting the same way' => ['/apiary'];
    }

    #[DataProvider('pathsOutsideTheApi')]
    public function testOnlyApiRoutesGetTheApiEnvelope(string $path): void
    {
        // Everything else is left to Symfony, which is what keeps this from
        // answering a CalDAV client with JSON it cannot read.
        $subscriber = new ExceptionSubscriber('dev', $this->logger);
        $event = $this->event(new \RuntimeException('boom'), $path);

        $subscriber->onKernelException($event);

        self::assertNull($event->getResponse());
        self::assertCount(0, $this->logger->records, 'and nothing is logged for it either');
    }

    // ------------------------------------------------- what is written down

    public function testServerFailuresAreLoggedAsErrorsAndClientOnesAsWarnings(): void
    {
        $subscriber = new ExceptionSubscriber('dev', $this->logger);

        $subscriber->onKernelException($this->event(new \RuntimeException('the database fell over')));
        $subscriber->onKernelException($this->event(new NotFoundHttpException('no such event')));

        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame('the database fell over', $this->logger->records[0]['message']);
        self::assertSame('warning', $this->logger->records[1]['level']);
        self::assertSame('no such event', $this->logger->records[1]['message']);
    }

    public function testTheLogSaysWhichRequestFailedAndHow(): void
    {
        $subscriber = new ExceptionSubscriber('prod', $this->logger);
        $request = Request::create('/api/v2/events', 'DELETE');
        $request->attributes->set('_request_id', 'req-42');
        $event = new ExceptionEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('SQLSTATE[28000] access denied'),
        );

        $subscriber->onKernelException($event);

        $context = $this->logger->records[0]['context'];
        self::assertSame('req-42', $context['request_id'] ?? null);
        self::assertSame('DELETE', $context['method'] ?? null);
        self::assertSame('/api/v2/events', $context['path'] ?? null);
        self::assertSame(500, $context['status'] ?? null);
        self::assertSame(\RuntimeException::class, $context['exception'] ?? null);
        // The message withheld from the client is still recorded for whoever
        // has to work out what happened.
        self::assertSame('SQLSTATE[28000] access denied', $this->logger->records[0]['message']);
    }

    public function testTheUserIsNamedInTheLogWhenOneIsKnown(): void
    {
        $subscriber = new ExceptionSubscriber('dev', $this->logger);
        $request = Request::create('/api/v2/events');
        $request->attributes->set('_security_token_user', 'alice');
        $event = new ExceptionEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );

        $subscriber->onKernelException($event);

        self::assertSame('alice', $this->logger->records[0]['context']['user'] ?? null);
    }

    public function testNoUserIsNamedWhenNobodyIsSignedIn(): void
    {
        $subscriber = new ExceptionSubscriber('dev', $this->logger);
        $subscriber->onKernelException($this->event(new \RuntimeException('boom')));

        self::assertArrayNotHasKey('user', $this->logger->records[0]['context']);
    }

    public function testWithoutALoggerTheResponseIsStillProduced(): void
    {
        // The logger is optional, and losing it must not cost the client its
        // error envelope.
        $subscriber = new ExceptionSubscriber('dev');
        $event = $this->event(new \RuntimeException('boom'));

        $subscriber->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    public function testTheLogSaysHowLongTheRequestHadBeenRunning(): void
    {
        // Answers "was it slow before it failed?" without having to correlate
        // two log lines.
        $request = Request::create('/api/v2/events');
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true) - 2.0);
        $event = new ExceptionEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );

        (new ExceptionSubscriber('dev', $this->logger))->onKernelException($event);

        $duration = $this->logger->records[0]['context']['duration_ms'] ?? null;
        self::assertIsFloat($duration);
        // Two seconds, in milliseconds. Wide enough not to mind a slow
        // machine, narrow enough that seconds, microseconds, or a subtraction
        // the wrong way round all fall outside it.
        self::assertGreaterThan(1500.0, $duration);
        self::assertLessThan(3000.0, $duration);
    }

    public function testNoDurationIsReportedWhenTheRequestNeverRecordedAStart(): void
    {
        // A request built by hand, or one from a SAPI that does not set it.
        $request = Request::create('/api/v2/events');
        $request->server->remove('REQUEST_TIME_FLOAT');
        $event = new ExceptionEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );

        (new ExceptionSubscriber('dev', $this->logger))->onKernelException($event);

        self::assertArrayNotHasKey('duration_ms', $this->logger->records[0]['context']);
    }

    // ------------------------------------------------------------- metrics

    private static function metrics(\PDO $pdo): ErrorMetricsService
    {
        return new ErrorMetricsService($pdo);
    }

    private static function sqlite(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function testAServerFailureIsCounted(): void
    {
        $pdo = self::sqlite();
        $metrics = self::metrics($pdo);
        $subscriber = new ExceptionSubscriber('prod', $this->logger, $metrics);

        $subscriber->onKernelException($this->event(new \RuntimeException('boom')));

        self::assertSame(1, $metrics->getRecentErrorCount());
    }

    public function testAClientMistakeIsNotCountedAsAnError(): void
    {
        // 4xx is the caller's problem; counting it would bury the 5xx rate the
        // counter exists to watch.
        $pdo = self::sqlite();
        $metrics = self::metrics($pdo);
        $subscriber = new ExceptionSubscriber('prod', $this->logger, $metrics);

        $subscriber->onKernelException($this->event(new NotFoundHttpException('no such event')));

        self::assertSame(0, $metrics->getRecentErrorCount());
    }

    public function testTheCounterFailingDoesNotCostTheClientItsResponse(): void
    {
        // The counter writes to the database, which is a plausible thing to be
        // broken at the moment an exception is being handled -- quite possibly
        // the reason for it.
        $pdo = self::sqlite();
        $metrics = self::metrics($pdo);
        $pdo->exec('DROP TABLE system_metrics');

        $subscriber = new ExceptionSubscriber('prod', $this->logger, $metrics);
        $event = $this->event(new \RuntimeException('boom'));

        $subscriber->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());

        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => str_contains($r['message'], 'recordError'),
        ));
        self::assertCount(1, $warnings, 'the counter failing is itself worth a line');
        // And it has to say why, or the line reports that something failed
        // while withholding the only useful part.
        self::assertArrayHasKey('exception', $warnings[0]['context']);
        self::assertStringContainsString('system_metrics', (string) $warnings[0]['context']['exception']);
    }

    public function testTheCounterFailingIsSurvivableWithNoLoggerToComplainTo(): void
    {
        // Both collaborators are optional. With the counter broken and no
        // logger, the warning has nowhere to go -- and reaching for it anyway
        // would turn a handled 500 into a fatal inside the exception handler.
        $pdo = self::sqlite();
        $metrics = self::metrics($pdo);
        $pdo->exec('DROP TABLE system_metrics');

        $subscriber = new ExceptionSubscriber('prod', null, $metrics);
        $event = $this->event(new \RuntimeException('boom'));

        $subscriber->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    public function testItListensForExceptionsAtTheDocumentedPriority(): void
    {
        self::assertSame(
            ['onKernelException', 0],
            ExceptionSubscriber::getSubscribedEvents()[KernelEvents::EXCEPTION] ?? null,
        );
    }
}
