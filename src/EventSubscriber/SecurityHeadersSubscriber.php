<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces global HTTP security headers across all kernel responses:
 * - X-Content-Type-Options: nosniff
 * - Strict-Transport-Security: HSTS with 1-year max-age, includeSubDomains, preload
 * - Permissions-Policy: restricted dangerous features
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - X-Frame-Options: SAMEORIGIN (defense in depth for older user agents)
 * - X-Permitted-Cross-Domain-Policies: none
 * - Content-Security-Policy sandbox defense for SVG payloads
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 64],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $headers = $response->headers;

        // 1. X-Content-Type-Options: nosniff
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        // 2. Strict-Transport-Security (HSTS)
        // Enforce when HTTPS is active, behind an HTTPS reverse-proxy, or in prod environment
        $isSecure = $request->isSecure()
            || strtolower((string) $request->server->get('HTTPS')) === 'on'
            || strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https';

        if ($isSecure || $this->environment === 'prod') {
            if (!$headers->has('Strict-Transport-Security')) {
                $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
            }
        }

        // 3. Permissions-Policy
        if (!$headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        }

        // 4. Referrer-Policy
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // 5. X-Frame-Options: SAMEORIGIN
        if (!$headers->has('X-Frame-Options')) {
            $csp = (string) $headers->get('Content-Security-Policy', '');
            if (!str_contains($csp, 'frame-ancestors')) {
                $headers->set('X-Frame-Options', 'SAMEORIGIN');
            }
        }

        // 6. X-Permitted-Cross-Domain-Policies: none
        if (!$headers->has('X-Permitted-Cross-Domain-Policies')) {
            $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        }

        // 7. Défense en profondeur CSP pour SVG
        $contentType = (string) $headers->get('Content-Type', '');
        if (str_contains($contentType, 'image/svg+xml') && !$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
    }
}
