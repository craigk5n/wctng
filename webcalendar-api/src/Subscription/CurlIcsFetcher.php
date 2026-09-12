<?php

declare(strict_types=1);

namespace App\Subscription;

use App\Security\OutboundUrlValidator;

/**
 * IcsFetcher over curl.
 *
 * Validation lives here rather than in the controller, matching
 * CurlWebhookTransport: the address that clears the checks is the one the
 * connection has to be pinned to, and that pin is a curl option. Checking at
 * the point of use also re-checks on every fetch, which matters because the
 * row outlives the check -- a subscription stored before this existed, or a
 * name that starts answering with an internal address, is still caught.
 */
final readonly class CurlIcsFetcher implements IcsFetcher
{
    private const TIMEOUT_SECONDS = 10;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * The body is held in memory and then regex-scanned, so an endless
     * response would otherwise cost a worker its memory limit. A calendar
     * that does not fit is one this could not parse anyway.
     */
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;

    public function __construct(private OutboundUrlValidator $urlValidator) {}

    #[\Override]
    public function fetch(string $url, ?string $etag): ?array
    {
        $target = $this->urlValidator->validate($url);

        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        $responseEtag = null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => $etag !== null ? ["If-None-Match: {$etag}"] : [],
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $header) use (&$responseEtag): int {
                if (stripos($header, 'ETag:') === 0) {
                    $responseEtag = trim(substr($header, 5));
                }

                return \strlen($header);
            },
            // Returning non-zero from the progress callback aborts the
            // transfer, which is the only cap that holds when the response
            // declares no length.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn(\CurlHandle $handle, int $downloadTotal, int $downloaded): int
                => $downloadTotal > self::MAX_BODY_BYTES || $downloaded > self::MAX_BODY_BYTES ? 1 : 0,
        ] + OutboundUrlValidator::curlSecurityOptions($target));

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!\is_string($body) || $status !== 200) {
            return null;
        }

        return ['body' => $body, 'etag' => $responseEtag];
    }
}
