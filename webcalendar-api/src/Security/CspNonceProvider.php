<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Generates a per-request CSP nonce exposed on the Request via the
 * `csp_nonce` attribute, so both the response subscriber (for the CSP
 * header) and controllers (for `<script nonce="...">`) can read the same
 * value without coupling to each other.
 *
 * Falls back to an empty string in CLI or test contexts where no request
 * is on the stack — callers that inline scripts should check for that
 * and skip the nonce attribute rather than render `nonce=""`.
 */
final readonly class CspNonceProvider
{
    public const REQUEST_ATTR = 'csp_nonce';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function getNonce(): string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return '';
        }

        /** @var mixed $existing */
        $existing = $request->attributes->get(self::REQUEST_ATTR);
        if (\is_string($existing) && $existing !== '') {
            return $existing;
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $request->attributes->set(self::REQUEST_ATTR, $nonce);

        return $nonce;
    }
}
