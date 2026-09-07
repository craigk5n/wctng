<?php

declare(strict_types=1);

namespace App\CalDav;

use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;

/**
 * Sapi that never writes to PHP's output.
 *
 * sabre/dav sends the response to the SAPI itself at the end of
 * Server::exec(). Since CalDavController hands the response back to Symfony,
 * the default Sapi would emit headers and body a second time: duplicate
 * Content-Length headers (nginx rejects OPTIONS with a 502) and doubled,
 * malformed XML bodies. Suppressing the write leaves Symfony as the only
 * sender, while exec() keeps serializing DAV exceptions into error responses.
 */
final class NullSapi extends Sapi
{
    #[\Override]
    public static function sendResponse(ResponseInterface $response): void {}
}
