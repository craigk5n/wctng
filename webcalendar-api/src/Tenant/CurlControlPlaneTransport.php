<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * ControlPlaneTransport over curl.
 *
 * Everything here is a raw call into ext-curl, which is the point: it is the
 * part that cannot be tested without a server, kept small enough to read.
 */
final class CurlControlPlaneTransport implements ControlPlaneTransport
{
    private const int TIMEOUT_SECONDS = 5;
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    #[\Override]
    public function post(string $url, string $payload): int
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return 0;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode;
    }
}
