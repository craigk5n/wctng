<?php

declare(strict_types=1);

namespace App\Controller\CalDav;

use App\CalDav\CoreAuthBackend;
use App\CalDav\CoreCalendarBackend;
use App\CalDav\CorePrincipalBackend;
use App\CalDav\NullSapi;
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
        private readonly CoreAuthBackend $authBackend,
        private readonly CorePrincipalBackend $principalBackend,
        private readonly CoreCalendarBackend $calendarBackend,
    ) {}

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

        $authBackend = $this->authBackend;
        $principalBackend = $this->principalBackend;
        $calendarBackend = $this->calendarBackend;

        // Build the DAV tree
        $tree = [
            new DAVACL\PrincipalCollection($principalBackend),
            new CalDAV\CalendarRoot($principalBackend, $calendarBackend),
        ];

        // Create the sabre/dav server. NullSapi stops sabre from writing the
        // response to PHP output itself; Symfony sends the returned Response.
        $server = new DAV\Server($tree, new NullSapi());
        $server->setBaseUri('/dav/');
        $server->httpRequest = $sabreRequest;

        // Plugins
        $server->addPlugin(new DAV\Auth\Plugin($authBackend));
        $server->addPlugin(new DAVACL\Plugin());
        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new CalDAV\Schedule\Plugin());
        // Exposes sync-collection REPORT over HTTP so clients can use
        // CoreCalendarBackend's SyncSupport. Without this plugin clients
        // get ReportNotSupported and fall back to full resyncs, which
        // also means the DEL-S1 sync-token bump cannot take effect.
        $server->addPlugin(new DAV\Sync\Plugin());
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
