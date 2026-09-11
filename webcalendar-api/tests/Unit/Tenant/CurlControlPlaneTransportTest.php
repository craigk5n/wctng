<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\CurlControlPlaneTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The curl half of the control-plane seam.
 *
 * The two cases that reached it both pointed at somewhere unreachable, so
 * every outcome was the same zero and not one of its eight mutants died: the
 * request could stop being a POST, lose its payload, lose its content type, or
 * never be sent at all, and the test still passed. A stub server on a loopback
 * port shows what actually arrives. curl itself runs in this process, so a
 * mutated line here is the one being exercised -- only the receiver is
 * external.
 */
final class CurlControlPlaneTransportTest extends TestCase
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

    /** Starts a receiver that records the request and answers with the status its path asks for. */
    private function receiver(): string
    {
        $this->dir = sys_get_temp_dir() . '/wctng_cp_' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $router = $this->dir . '/router.php';
        file_put_contents($router, <<<'ROUTER'
            <?php
            file_put_contents(__DIR__ . '/received.json', json_encode([
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'body' => file_get_contents('php://input'),
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            ]));

            $status = 200;
            if (preg_match('#/status/(\d+)#', $_SERVER['REQUEST_URI'] ?? '', $m)) {
                $status = (int) $m[1];
            }
            http_response_code($status);
            echo 'ok';

            return true;
            ROUTER);

        $port = self::freePort();
        // Pipes rather than /dev/null handles: under Infection the inherited
        // descriptors are not ones proc_open() can dup, and it fails with
        // "posix_spawn() failed: Bad file descriptor" -- which failed the test
        // for every mutant and scored the file a meaningless 100%.
        $server = proc_open(
            ['php', '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server, 'could not start the stub receiver');
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

        self::fail('the stub receiver never accepted a connection');
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

    /** @return array<string, string> */
    private function received(): array
    {
        $raw = file_get_contents($this->dir . '/received.json');
        self::assertIsString($raw, 'the receiver recorded nothing -- no request arrived');
        /** @var array<string, string> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // ------------------------------------------------------ what goes out

    public function testTheNotificationIsAPostCarryingItsJsonPayload(): void
    {
        // The control plane reads the body as JSON and routes on the event in
        // it. Sent as a GET, with the body dropped, or without the content
        // type, it is not a notification the far end can act on -- and the
        // status would still come back 200, so this class would report
        // success.
        $base = $this->receiver();
        $payload = '{"event":"tenant.provisioned","slug":"acme"}';

        $status = (new CurlControlPlaneTransport())->post($base . '/hook', $payload);

        self::assertSame(200, $status);
        $received = $this->received();
        self::assertSame('POST', $received['method']);
        self::assertSame($payload, $received['body']);
        self::assertSame('application/json', $received['content_type']);
    }

    /** @return iterable<string, array{int}> */
    public static function statusCodes(): iterable
    {
        // The status is this method's entire return value, so every code the
        // far end can answer with has to survive the trip back as an int.
        yield 'ok' => [200];
        yield 'accepted' => [202];
        yield 'unauthorized' => [401];
        yield 'gone' => [410];
        yield 'server error' => [500];
    }

    #[DataProvider('statusCodes')]
    public function testTheStatusComesBackAsTheCallerSeesIt(int $code): void
    {
        $base = $this->receiver();

        $status = (new CurlControlPlaneTransport())->post($base . '/status/' . $code, '{"event":"x"}');

        self::assertSame($code, $status);
    }

    public function testTheBodyIsReadRatherThanPrintedToOutput(): void
    {
        // Without RETURNTRANSFER the receiver's answer is written straight to
        // stdout, which in a request would land in the middle of the response
        // being built.
        $base = $this->receiver();

        ob_start();
        (new CurlControlPlaneTransport())->post($base . '/hook', '{"event":"x"}');
        $printed = (string) ob_get_clean();

        self::assertSame('', $printed);
    }

    public function testAUrlCurlWillNotAcceptReportsNoResponse(): void
    {
        // curl_init() returns false for this rather than a handle, which is
        // the branch that would otherwise pass null into curl_setopt_array().
        self::assertSame(0, (new CurlControlPlaneTransport())->post('', '{"event":"tenant.deleted"}'));
    }

    public function testAnUnreachableTargetReportsNoResponse(): void
    {
        // Nothing is listening, so the connection is refused and there is no
        // status code to report. Zero rather than an exception is what makes
        // the caller's fire-and-forget contract work.
        $transport = new CurlControlPlaneTransport();

        self::assertSame(0, $transport->post('http://127.0.0.1:9/hook', '{"event":"tenant.provisioned"}'));
    }
}
