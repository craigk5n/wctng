<?php

declare(strict_types=1);

namespace App\Controller\CalDav;

use App\CalDav\CoreAuthBackend;
use App\CalDav\CoreCalendarBackend;
use App\CalDav\CorePrincipalBackend;
use App\Service\CoreServiceFactory;
use App\Tenant\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAVACL;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Symfony controller that bootstraps a sabre/dav CalDAV server.
 *
 * All /dav/* requests are handled by sabre/dav internally.
 */
final class CalDavController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/dav/{path}', name: 'caldav', methods: ['GET', 'PUT', 'DELETE', 'POST', 'PROPFIND', 'PROPPATCH', 'REPORT', 'MKCALENDAR', 'OPTIONS', 'MOVE', 'COPY', 'LOCK', 'UNLOCK'], requirements: ['path' => '.*'], defaults: ['path' => ''])]
    public function handle(Request $request): Response
    {
        // Build sabre HTTP request from Symfony request
        $flatHeaders = [];
        foreach ($request->headers->all() as $name => $values) {
            $flatHeaders[$name] = implode(', ', $values);
        }

        $sabreRequest = new \Sabre\HTTP\Request(
            $request->getMethod(),
            $request->getRequestUri(),
            $flatHeaders,
            $request->getContent() ?: null,
        );

        // Backends
        $authBackend = new CoreAuthBackend($this->coreServiceFactory, $this->jwtEncoder, $this->tenantContext);
        $principalBackend = new CorePrincipalBackend($this->coreServiceFactory);
        $calendarBackend = new CoreCalendarBackend($this->coreServiceFactory);

        // Build the DAV tree
        $tree = [
            new DAVACL\PrincipalCollection($principalBackend),
            new CalDAV\CalendarRoot($principalBackend, $calendarBackend),
        ];

        // Create the sabre/dav server
        $server = new DAV\Server($tree);
        $server->setBaseUri('/dav/');
        $server->httpRequest = $sabreRequest;

        // Plugins
        $server->addPlugin(new DAV\Auth\Plugin($authBackend));
        $server->addPlugin(new DAVACL\Plugin());
        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new DAV\Browser\Plugin());

        // Run
        $sabreResponse = new \Sabre\HTTP\Response();
        $server->httpResponse = $sabreResponse;
        $server->exec();

        // Convert to Symfony response
        $body = $sabreResponse->getBodyAsString();
        $statusCode = $sabreResponse->getStatus();

        $response = new Response($body, $statusCode);
        foreach ($sabreResponse->getHeaders() as $name => $values) {
            /** @var string|string[] $values */
            if (\is_array($values)) {
                $response->headers->set($name, implode(', ', $values));
            } else {
                $response->headers->set($name, $values);
            }
        }

        return $response;
    }
}
