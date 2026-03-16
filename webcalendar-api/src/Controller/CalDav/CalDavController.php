<?php

declare(strict_types=1);

namespace App\Controller\CalDav;

use App\CalDav\CoreAuthBackend;
use App\Service\CoreServiceFactory;
use Sabre\DAV;
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

        // Auth backend
        $authBackend = new CoreAuthBackend($this->coreServiceFactory);

        // Build a minimal DAV tree
        $tree = [
            new DAV\SimpleCollection('principals'),
            new DAV\SimpleCollection('calendars'),
        ];

        // Create the sabre/dav server
        $server = new DAV\Server($tree);
        $server->setBaseUri('/dav/');

        // Inject request
        $server->httpRequest = $sabreRequest;

        // Add auth plugin
        $authPlugin = new DAV\Auth\Plugin($authBackend);
        $server->addPlugin($authPlugin);

        // Add browser plugin for dev
        $server->addPlugin(new DAV\Browser\Plugin());

        // Create response sapi and run
        $sabreResponse = new \Sabre\HTTP\Response();
        $server->httpResponse = $sabreResponse;

        // Use exec() which handles exceptions internally
        $server->exec();

        // Convert sabre response to Symfony response
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
