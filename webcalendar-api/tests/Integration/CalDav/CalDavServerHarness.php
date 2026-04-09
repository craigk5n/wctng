<?php

declare(strict_types=1);

namespace App\Tests\Integration\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\CalDav\CorePrincipalBackend;
use App\Service\CoreServiceFactory;
use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\DAVACL;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\HTTP\ResponseInterface;

/**
 * In-process sabre/dav Server harness for integration testing CalDAV.
 *
 * Constructs the same DAV tree and plugins as the production CalDavController
 * but with an always-authenticate test auth backend, so tests can exercise
 * the full HTTP + XML + property-handler pipeline without a running web
 * server, JWT issuance, or a database other than the in-memory SQLite used
 * by IntegrationTestCase.
 *
 * Usage inside an IntegrationTestCase subclass:
 *
 *     $harness = new CalDavServerHarness($this->factory, 'admin');
 *     $response = $harness->propfind('/calendars/admin/', [
 *         '{DAV:}displayname',
 *         '{DAV:}resourcetype',
 *     ]);
 *     $this->assertSame(207, $response->getStatus());
 *
 * The harness is intentionally thin: it's a request builder + `invokeMethod`
 * dispatcher. Tests own the assertions on status codes, XML bodies, and
 * headers.
 */
final class CalDavServerHarness
{
    public function __construct(
        private readonly CoreServiceFactory $factory,
        private readonly string $currentUser = 'admin',
    ) {
    }

    /**
     * Build a fresh sabre/dav Server for this request. Rebuilding per call
     * avoids any node-level caching inside the tree (calendar metadata
     * captured at construction time), so state changes between calls
     * (like a sync-token bump) are visible to subsequent assertions.
     */
    private function buildServer(): DAV\Server
    {
        $principalBackend = new CorePrincipalBackend($this->factory);
        $calendarBackend = new CoreCalendarBackend($this->factory);

        $tree = [
            new DAVACL\PrincipalCollection($principalBackend),
            new CalDAV\CalendarRoot($principalBackend, $calendarBackend),
        ];

        $server = new DAV\Server($tree);
        $server->setBaseUri('/dav/');

        $server->addPlugin(new DAV\Auth\Plugin($this->buildAuthBackend()));
        $server->addPlugin(new DAVACL\Plugin());
        $server->addPlugin(new CalDAV\Plugin());
        // Enables sync-collection REPORT — required for SyncSupport to be
        // exposed over HTTP. Without this plugin, clients get
        // "ReportNotSupported" even though the backend implements the
        // interface.
        $server->addPlugin(new DAV\Sync\Plugin());

        return $server;
    }

    /**
     * Dispatch a raw request through a fresh in-process server and return
     * the sabre Response. Tests should interact with this method directly
     * for anything the convenience wrappers below don't cover.
     *
     * @param array<string, string> $headers
     */
    public function invoke(string $method, string $uri, string $body = '', array $headers = []): Response
    {
        // Always authenticate as the test user via Basic auth.
        $headers['Authorization'] = 'Basic ' . base64_encode($this->currentUser . ':test');

        $request = new Request($method, $uri, $headers, $body !== '' ? $body : null);
        $response = new Response();

        $server = $this->buildServer();
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        // Swap in a no-op Sapi so sabre's exec() doesn't try to emit to
        // php://output via header()/echo — which would throw under PHPUnit.
        // The $sapi property is untyped at runtime; duck typing is all
        // sabre needs. We use reflection to sidestep PHPStan's complaint
        // about the (docblock-only) property type.
        $sapiProp = new \ReflectionProperty($server, 'sapi');
        $sapiProp->setValue($server, new NullSapi());

        // exec() runs the full pipeline (including Sync\Plugin REPORT
        // handlers) and honors setBaseUri, which invokeMethod() does not.
        $server->exec();

        return $response;
    }

    /**
     * Convenience helper: PROPFIND with a list of requested properties.
     *
     * @param list<string> $properties
     */
    public function propfind(string $uri, array $properties, int $depth = 0): Response
    {
        $propXml = '';
        foreach ($properties as $prop) {
            // Expect "{namespace}localname" form
            if (preg_match('/^\{([^}]+)\}(.+)$/', $prop, $m) === 1) {
                $propXml .= sprintf('<x0:%s xmlns:x0="%s"/>', $m[2], $m[1]);
            }
        }

        $body = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    {$propXml}
  </d:prop>
</d:propfind>
XML;

        return $this->invoke('PROPFIND', $uri, $body, [
            'Depth' => (string) $depth,
            'Content-Type' => 'application/xml',
        ]);
    }

    /**
     * Convenience helper: sync-collection REPORT.
     */
    public function syncCollection(string $uri, ?string $syncToken = null): Response
    {
        $token = $syncToken ?? '';
        $body = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<d:sync-collection xmlns:d="DAV:">
  <d:sync-token>{$token}</d:sync-token>
  <d:sync-level>1</d:sync-level>
  <d:prop>
    <d:getetag/>
  </d:prop>
</d:sync-collection>
XML;

        return $this->invoke('REPORT', $uri, $body, [
            'Depth' => '1',
            'Content-Type' => 'application/xml',
        ]);
    }

    private function buildAuthBackend(): AbstractBasic
    {
        return new class($this->currentUser) extends AbstractBasic {
            public function __construct(string $expectedUser)
            {
                $this->principalPrefix = 'principals/';
                $this->setRealm('webcalendar');
                // The principal URI is derived from the username — since
                // we accept any password for the expected user, all tests
                // effectively run as $expectedUser.
                $this->expectedUser = $expectedUser;
            }

            private string $expectedUser;

            #[\Override]
            protected function validateUserPass($username, $password): bool
            {
                return $username === $this->expectedUser;
            }
        };
    }
}

/**
 * No-op Sapi used by the harness to suppress sabre's emit-to-stdout path
 * during exec(). Tests read the mutated Response object directly.
 *
 * The sabre $server->sapi property is untyped at runtime so we can assign
 * any object with a sendResponse(ResponseInterface) method.
 */
final class NullSapi
{
    public function sendResponse(ResponseInterface $response): void
    {
        // Swallow — tests read the Response object directly.
    }
}

