<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\CurlOidcConfigFetcher;
use App\Security\OutboundUrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * The curl half of the discovery seam.
 *
 * Moving the request out of OidcDiscovery made everything around it testable,
 * but not the request itself: with no provider answering, every mutation of the
 * options, the handle check and the status check still ends in the same null.
 * So this stands one up -- `php -S` on a loopback port, serving one stub
 * document -- which is enough to tell "fetched" from "gave up", and to see
 * which headers went out.
 *
 * The `curl_init() === false` guard stays untested on purpose: validation runs
 * first and is stricter than curl about what a URL is, so nothing can reach it.
 */
final class CurlOidcConfigFetcherTest extends TestCase
{
    private const DOCUMENT_PATH = '/.well-known/openid-configuration';

    /** @var resource|null */
    private $server;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $routerPath = '';

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

        if ($this->routerPath !== '' && is_file($this->routerPath)) {
            unlink($this->routerPath);
        }
    }

    private function fetcher(string $mode = 'standalone'): CurlOidcConfigFetcher
    {
        return new CurlOidcConfigFetcher(new OutboundUrlValidator($mode));
    }

    /** Starts a stub provider and returns its base URL. */
    private function stubProvider(): string
    {
        $this->routerPath = tempnam(sys_get_temp_dir(), 'oidc_stub_') . '.php';
        file_put_contents($this->routerPath, <<<'ROUTER'
            <?php
            if (($_SERVER['REQUEST_URI'] ?? '') === '/.well-known/openid-configuration') {
                header('Content-Type: application/json');
                echo json_encode([
                    'issuer' => 'https://stub.example.com',
                    'token_endpoint' => 'https://stub.example.com/token',
                    'seen_accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
                ]);

                return true;
            }

            http_response_code(404);
            echo 'no such document';

            return true;
            ROUTER);

        $port = self::freePort();
        // Pipes rather than /dev/null handles: under Infection the inherited
        // descriptors are not ones proc_open() can dup, and it fails with
        // "posix_spawn() failed: Bad file descriptor" -- which failed the test
        // for every mutant and scored the file a meaningless 100%.
        $server = proc_open(
            ['php', '-S', '127.0.0.1:' . $port, $this->routerPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server, 'could not start the stub provider');
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

        self::fail('the stub provider never accepted a connection');
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

    public function testATwoHundredHandsBackTheBody(): void
    {
        $body = $this->fetcher()->fetch($this->stubProvider() . self::DOCUMENT_PATH);

        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('https://stub.example.com/token', $decoded['token_endpoint']);
    }

    public function testItAsksForJson(): void
    {
        // The Accept header is one entry in the options array; dropping it
        // leaves a provider free to answer with HTML.
        $body = $this->fetcher()->fetch($this->stubProvider() . self::DOCUMENT_PATH);

        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('application/json', $decoded['seen_accept']);
    }

    public function testAnythingButATwoHundredIsNotADocument(): void
    {
        // A provider that answers 404 with a body still answers with a body,
        // so the status is the only thing separating it from a real document.
        self::assertNull($this->fetcher()->fetch($this->stubProvider() . '/nope'));
    }

    public function testAnUnreachableProviderYieldsNoDocument(): void
    {
        self::assertNull($this->fetcher()->fetch('http://127.0.0.1:9' . self::DOCUMENT_PATH));
    }

    public function testATargetTheOutboundChecksRejectIsRefusedNotFetched(): void
    {
        // Hosted mode must not dial link-local addresses. The refusal is an
        // exception rather than a null so the caller can tell "blocked" from
        // "no such document".
        $this->expectException(\InvalidArgumentException::class);
        $this->fetcher('hosted')->fetch('http://169.254.169.254' . self::DOCUMENT_PATH);
    }
}
