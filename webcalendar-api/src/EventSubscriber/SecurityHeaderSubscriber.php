<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Emits the modern security-header set on every main-request response:
 * Content-Security-Policy (nonce-based), Permissions-Policy,
 * Referrer-Policy, X-Content-Type-Options, X-Frame-Options, and HSTS
 * (HTTPS-only with preload). Deliberately does NOT emit the deprecated
 * X-XSS-Protection header.
 *
 * CSP policy is HTTP-friendly: connect-src / img-src allow plain http://
 * and ws:// so internal, non-TLS deployments (intranets, lab setups,
 * on-prem dev stacks) keep working. This is by design — if you're on
 * HTTPS you still get nonce-based CSP *and* HSTS with preload.
 */
final class SecurityHeaderSubscriber implements EventSubscriberInterface
{
    private const PERMISSIONS_POLICY = 'geolocation=(), microphone=(), camera=(), payment=(), usb=()';

    /**
     * Extra hosts allowed to serve scripts/styles/images. Kept narrow;
     * expand only when we actually need a new CDN vendor, and record
     * the reason in the code review.
     */
    private const EXTRA_SCRIPT_SRC = 'https://unpkg.com';
    private const EXTRA_STYLE_SRC = 'https://unpkg.com';
    private const EXTRA_IMG_SRC = 'https://tile.openstreetmap.org';

    public function __construct(
        private readonly CspNonceProvider $nonceProvider,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -50]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);

        // Deprecated: removed in favor of CSP. If something upstream set
        // it (middleware, nginx), strip it so we have a single source of
        // truth for XSS protection.
        $headers->remove('X-XSS-Protection');

        $nonce = $this->nonceProvider->getNonce();
        $policy = $this->buildCsp($nonce);
        $headers->set('Content-Security-Policy', $policy);
        // Soft-rollout: surface anything the enforced policy would block
        // via the same policy in report-only mode so we learn about
        // regressions without an outage. Remove once the dashboards
        // show clean signals for a release cycle.
        $headers->set(
            'Content-Security-Policy-Report-Only',
            $policy . '; report-uri /api/v2/csp-report',
        );

        if ($event->getRequest()->isSecure()) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age=63072000; includeSubDomains; preload',
            );
        }
    }

    private function buildCsp(string $nonce): string
    {
        $nonceSrc = $nonce !== '' ? "'nonce-{$nonce}'" : '';
        $scriptSrc = trim("'self' {$nonceSrc} " . self::EXTRA_SCRIPT_SRC);

        $directives = [
            "default-src 'self'",
            "script-src {$scriptSrc}",
            // 'unsafe-inline' on style-src is a deliberate trade-off —
            // the SEO event/index pages ship hand-crafted inline CSS
            // blocks; restricting this would require template rewrites
            // for every public HTML page. Revisit if/when we move those
            // to external stylesheets.
            "style-src 'self' 'unsafe-inline' " . self::EXTRA_STYLE_SRC,
            // http: is allowed on purpose for internal HTTP deployments;
            // data: is needed for inline <img> in some sanitized event
            // descriptions.
            "img-src 'self' data: http: https: " . self::EXTRA_IMG_SRC,
            'font-src \'self\' data:',
            "connect-src 'self' http: https: ws: wss:",
            "media-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }
}
