<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\CurlControlPlaneTransport;
use PHPUnit\Framework\TestCase;

/**
 * The curl half of the control-plane seam.
 *
 * Splitting the transport out left this class with no test at all, which the
 * changed-lines coverage gate caught on the very next push -- 15 of 17 changed
 * lines never executed. It cannot be tested against a real server here, but
 * both of its exits can be reached without one: a URL curl refuses to accept,
 * and a port with nothing listening on it.
 */
final class CurlControlPlaneTransportTest extends TestCase
{
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
