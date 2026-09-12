<?php

declare(strict_types=1);

namespace App\Tests\Unit\Subscription;

use App\Security\OutboundUrlValidator;
use App\Subscription\CurlIcsFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The curl half of the subscription fetch.
 *
 * The request this makes is the whole security boundary of the feature: the
 * URL comes from any authenticated user, so what the transfer will and will
 * not do -- which schemes, which addresses, whether it follows a redirect,
 * how much it will read -- is what stops a subscription reaching the inside of
 * the deployment. A stub server on a loopback port is enough to see all of it.
 */
final class CurlIcsFetcherTest extends TestCase
{
    /** @var resource|null */
    private $server;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $dir = '';

    #[\Override]
    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = [];
            proc_close($this->server);
        }

        // Guarded on the directory having been created: with $this->dir still
        // empty, glob('/*') walks the filesystem root.
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    private function fetcher(string $mode = 'standalone'): CurlIcsFetcher
    {
        return new CurlIcsFetcher(new OutboundUrlValidator($mode));
    }

    /** Starts a feed that records what it was asked for and answers what the path names. */
    private function feed(): string
    {
        $this->dir = sys_get_temp_dir() . '/wctng_ics_' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $router = $this->dir . '/router.php';
        file_put_contents($router, <<<'ROUTER'
            <?php
            file_put_contents(__DIR__ . '/received.json', json_encode([
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'if_none_match' => $_SERVER['HTTP_IF_NONE_MATCH'] ?? null,
            ]));

            $uri = $_SERVER['REQUEST_URI'] ?? '';

            if (preg_match('#/status/(\d+)#', $uri, $m)) {
                http_response_code((int) $m[1]);
                echo 'nothing here';

                return true;
            }

            if (str_contains($uri, '/redirect')) {
                header('Location: /feed');
                http_response_code(302);

                return true;
            }

            if (str_contains($uri, '/tight-etag')) {
                header('Content-Type: text/calendar');
                header('ETag:"no-space"');
                echo "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";

                return true;
            }

            if (str_contains($uri, '/big')) {
                header('Content-Type: text/calendar');
                echo "BEGIN:VCALENDAR\r\n";
                for ($i = 0; $i < 600; $i++) {
                    echo str_repeat('x', 10240);
                    flush();
                }

                return true;
            }

            header('Content-Type: text/calendar');
            header('ETag: "server-v2"');
            echo "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Hi\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

            return true;
            ROUTER);

        $port = self::freePort();
        // Pipes rather than /dev/null handles: under Infection the inherited
        // descriptors are not ones proc_open() can dup.
        $server = proc_open(
            ['php', '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server, 'could not start the stub feed');
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->pipes = $pipes;
        $this->server = $server;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);

                return 'http://127.0.0.1:' . $port;
            }

            usleep(50_000);
        }

        self::fail('the stub feed never accepted a connection');
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock);
        $name = stream_socket_get_name($sock, false);
        self::assertIsString($name);
        fclose($sock);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** @return array<string, string|null> */
    private function received(): array
    {
        $raw = file_get_contents($this->dir . '/received.json');
        self::assertIsString($raw, 'the feed recorded nothing -- no request arrived');
        /** @var array<string, string|null> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // -------------------------------------------------------- what comes back

    public function testTheFeedBodyAndItsValidatorComeBack(): void
    {
        $base = $this->feed();

        $result = $this->fetcher()->fetch($base . '/feed', null);

        self::assertNotNull($result);
        self::assertStringContainsString('SUMMARY:Hi', $result['body']);
        self::assertSame('"server-v2"', $result['etag']);
    }

    /**
     * RFC 9110 leaves the space after the colon optional, and the value is
     * stored and sent back as If-None-Match -- one character off it and every
     * later conditional request misses, so the calendar downloads in full
     * forever.
     */
    public function testAValidatorSentWithoutASpaceIsReadWhole(): void
    {
        $base = $this->feed();

        $result = $this->fetcher()->fetch($base . '/tight-etag', null);

        self::assertNotNull($result);
        self::assertSame('"no-space"', $result['etag']);
    }

    public function testTheStoredValidatorIsSentAsAConditionalGet(): void
    {
        // Without it every refresh downloads the whole calendar again, and the
        // 304 branch this class has is unreachable.
        $base = $this->feed();

        $this->fetcher()->fetch($base . '/feed', '"client-v1"');

        self::assertSame('"client-v1"', $this->received()['if_none_match']);
    }

    public function testNoValidatorIsSentWhenNoneIsStored(): void
    {
        $base = $this->feed();

        $this->fetcher()->fetch($base . '/feed', null);

        self::assertNull($this->received()['if_none_match']);
    }

    #[DataProvider('unusableStatuses')]
    public function testAnythingButA200ReadsAsNothingNew(int $status): void
    {
        $base = $this->feed();

        self::assertNull($this->fetcher()->fetch($base . '/status/' . $status, null));
    }

    /** @return iterable<string, array{int}> */
    public static function unusableStatuses(): iterable
    {
        yield 'not modified' => [304];
        yield 'not found' => [404];
        yield 'forbidden' => [403];
        yield 'server error' => [500];
    }

    public function testTheBodyIsCapturedRatherThanPrinted(): void
    {
        $base = $this->feed();

        ob_start();
        $result = $this->fetcher()->fetch($base . '/feed', null);
        $printed = ob_get_clean();

        self::assertNotNull($result);
        self::assertSame('', $printed, 'the calendar would have been written straight into the response');
    }

    // ----------------------------------------------------- where it will not go

    /**
     * A validated address is only the address that was validated while the
     * transfer stays on it; a redirect walks it somewhere nothing checked.
     */
    public function testARedirectIsNotFollowed(): void
    {
        $base = $this->feed();

        self::assertNull($this->fetcher()->fetch($base . '/redirect', null));
    }

    /**
     * The body is held whole in memory and then scanned, so a feed that never
     * ends would otherwise cost a worker its memory limit.
     */
    public function testAFeedLargerThanTheCapIsAbandoned(): void
    {
        $base = $this->feed();

        self::assertNull($this->fetcher()->fetch($base . '/big', null));
    }

    /**
     * curl speaks far more than HTTP, and every one of these would otherwise
     * be a stored subscription reading the server's own filesystem.
     */
    #[DataProvider('refusedUrls')]
    public function testAUrlTheServerMustNotFetchIsRefusedBeforeTheRequest(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fetcher()->fetch($url, null);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedUrls(): iterable
    {
        yield 'local file' => ['file:///etc/passwd'];
        yield 'php wrapper' => ['php://filter/read=convert.base64-encode/resource=/etc/passwd'];
        yield 'phar' => ['phar:///tmp/x.phar/cal.ics'];
        yield 'ftp' => ['ftp://example.com/cal.ics'];
        yield 'embedded credentials' => ['https://user:pass@example.com/cal.ics'];
        yield 'not a url' => ['/etc/passwd'];
    }

    public function testAHostedDeploymentWillNotFetchFromItsOwnNetwork(): void
    {
        $base = $this->feed();

        $this->expectException(\InvalidArgumentException::class);

        $this->fetcher('hosted')->fetch($base . '/feed', null);
    }
}
