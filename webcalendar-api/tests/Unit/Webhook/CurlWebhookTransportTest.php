<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Security\OutboundUrlValidator;
use App\Webhook\CurlWebhookTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The curl half of the webhook transport.
 *
 * It had no test of its own: the dispatcher's tests replace it wholesale with
 * a fake, and the two cases that do reach it point at a dead port, so every
 * outcome was the same zero. That left the whole request unasserted -- whether
 * it is a POST at all, whether the payload is sent, whether the signature
 * header a receiver authenticates with goes out, and whether the status code
 * comes back. A stub server on a loopback port is enough to see all of it.
 */
final class CurlWebhookTransportTest extends TestCase
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

    private function transport(string $mode = 'standalone'): CurlWebhookTransport
    {
        return new CurlWebhookTransport(new OutboundUrlValidator($mode));
    }

    /** Starts a receiver that records what it was sent and returns the status its path asks for. */
    private function receiver(): string
    {
        $this->dir = sys_get_temp_dir() . '/wctng_hook_' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $router = $this->dir . '/router.php';
        file_put_contents($router, <<<'ROUTER'
            <?php
            $record = [
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'body' => file_get_contents('php://input'),
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
                'signature' => $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
            ];
            file_put_contents(__DIR__ . '/received.json', json_encode($record));

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

    public function testTheDeliveryIsAPostCarryingThePayloadAndItsSignature(): void
    {
        // The receiver authenticates a delivery by recomputing the HMAC over
        // the body and comparing it with this header. Sent as a GET, or with
        // the header dropped, or with no body, it is not a delivery any
        // receiver can accept -- and the status code would still come back
        // 200, so the dispatcher would record it as delivered.
        $base = $this->receiver();
        $payload = '{"event":"event.created","data":{"id":7}}';

        $status = $this->transport()->post($base . '/hook', $payload, 'abc123');

        self::assertSame(200, $status);
        $received = $this->received();
        self::assertSame('POST', $received['method']);
        self::assertSame($payload, $received['body']);
        self::assertSame('application/json', $received['content_type']);
        self::assertSame('sha256=abc123', $received['signature']);
    }

    /** @return iterable<string, array{int}> */
    public static function statusCodes(): iterable
    {
        // The dispatcher treats 2xx as delivered and everything else as a
        // failure worth retrying, so the code it gets back is the whole
        // contract of this method.
        yield 'ok' => [200];
        yield 'accepted' => [202];
        yield 'gone' => [410];
        yield 'server error' => [500];
    }

    #[DataProvider('statusCodes')]
    public function testTheReceiversStatusCodeIsWhatComesBack(int $status): void
    {
        $base = $this->receiver();

        self::assertSame($status, $this->transport()->post($base . '/status/' . $status, '{}', 'sig'));
    }

    public function testTheResponseBodyIsCapturedRatherThanPrinted(): void
    {
        // Without CURLOPT_RETURNTRANSFER curl writes the receiver's response
        // straight to stdout. The status code still comes back, so the
        // delivery looks fine -- but in a worker or a console command the
        // body lands in the output stream, and in a web request it is
        // prepended to the response.
        $base = $this->receiver();

        ob_start();
        $status = $this->transport()->post($base . '/hook', '{"a":1}', 'sig');
        $printed = ob_get_clean();

        self::assertSame(200, $status);
        self::assertSame('', $printed, 'the receiver said "ok" and nobody should have seen it');
    }

    public function testTheRequestIsActuallySent(): void
    {
        // Without the exec the handle is configured and then thrown away:
        // curl_getinfo() reports no status, the method answers zero, and the
        // dispatcher retries a delivery that never happened.
        $base = $this->receiver();

        $this->transport()->post($base . '/hook', '{"a":1}', 'sig');

        self::assertFileExists($this->dir . '/received.json', 'the receiver saw a request');
    }

    // --------------------------------------------------- when it cannot send

    public function testAnUnreachableReceiverReportsNoStatus(): void
    {
        // Nothing is listening, so there is no status code to report. Zero
        // rather than an exception is what makes the dispatcher's retry loop
        // work.
        self::assertSame(0, $this->transport()->post('http://127.0.0.1:9/hook', '{}', 'sig'));
    }

    public function testATargetTheOutboundChecksRejectIsRefusedNotDialled(): void
    {
        // Hosted mode must not let a stored webhook URL point at link-local
        // metadata. The refusal is an exception so the dispatcher can log it
        // as blocked rather than as an ordinary failed delivery.
        $this->expectException(\InvalidArgumentException::class);
        $this->transport('hosted')->post('http://169.254.169.254/hook', '{}', 'sig');
    }
}
