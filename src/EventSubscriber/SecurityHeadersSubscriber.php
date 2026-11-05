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
 * - X-Frame-Options: DENY on sensitive endpoints, SAMEORIGIN on backoffice, omitted on embeds
 * - X-Permitted-Cross-Domain-Policies: none
 * - Content-Security-Policy sandbox defense for SVG payloads, frame-ancestors for studio/auth
 * - Cross-Origin-Opener-Policy: same-origin-allow-popups
 * - Cross-Origin-Resource-Policy: cross-origin for public APIs/assets, same-origin otherwise
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
        $path = $request->getPathInfo();

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

        // 5. Anti-clickjacking : X-Frame-Options & CSP frame-ancestors
        $isEmbedRoute = str_starts_with($path, '/forms/embed')
            || str_starts_with($path, '/share/')
            || str_starts_with($path, '/embed/');

        $isStudioRoute = str_contains($path, '/studio');

        $isStrictDenyRoute = str_starts_with($path, '/admin-api/tokens')
            || str_starts_with($path, '/settings/tokens');

        if (!$headers->has('X-Frame-Options')) {
            $csp = (string) $headers->get('Content-Security-Policy', '');
            if (!str_contains($csp, 'frame-ancestors')) {
                if ($isEmbedRoute) {
                    // Embed widgets allow iframe framing; do not set X-Frame-Options
                } elseif ($isStrictDenyRoute) {
                    $headers->set('X-Frame-Options', 'DENY');
                } else {
                    $headers->set('X-Frame-Options', 'SAMEORIGIN');
                }
            }
        }

        // 6. X-Permitted-Cross-Domain-Policies: none
        if (!$headers->has('X-Permitted-Cross-Domain-Policies')) {
            $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        }

        // 7. Défense en profondeur CSP pour HTML, SVG, Studio et flux sensibles
        $contentType = (string) $headers->get('Content-Type', '');
        $isHtml = str_contains($contentType, 'text/html');

        if (str_contains($contentType, 'image/svg+xml') && !$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        } elseif ($isHtml && !$headers->has('Content-Security-Policy')) {
            $frameAncestors = $isEmbedRoute ? '*' : ($isStrictDenyRoute ? "'none'" : "'self'");
            $cspDirectives = [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: blob: https:",
                "connect-src 'self' https: wss:",
                "frame-ancestors {$frameAncestors}",
                "base-uri 'self'",
                "form-action 'self'",
                "object-src 'none'",
            ];
            $headers->set('Content-Security-Policy', implode('; ', $cspDirectives) . ';');
        } elseif ($isStrictDenyRoute) {
            $existingCsp = (string) $headers->get('Content-Security-Policy', '');
            if (!str_contains($existingCsp, 'frame-ancestors')) {
                $headers->set('Content-Security-Policy', trim($existingCsp . " frame-ancestors 'none';"));
            }
        } elseif ($isStudioRoute) {
            $existingCsp = (string) $headers->get('Content-Security-Policy', '');
            if (!str_contains($existingCsp, 'frame-ancestors')) {
                $headers->set('Content-Security-Policy', trim($existingCsp . " frame-ancestors 'self';"));
            }
        }

        // 8. Cross-Origin-Opener-Policy (COOP) : isolation d'exécution top-level (omis sur embeds iframes)
        if (!$headers->has('Cross-Origin-Opener-Policy')) {
            if (!$isEmbedRoute) {
                $headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
            }
        }

        // 9. Cross-Origin-Resource-Policy (CORP) : protection des ressources contre l'inclusion illicite
        if (!$headers->has('Cross-Origin-Resource-Policy')) {
            $isPublicAssetOrApi = str_starts_with($path, '/api/')
                || str_starts_with($path, '/files/')
                || str_starts_with($path, '/storage/')
                || str_starts_with($path, '/forms/embed')
                || str_starts_with($path, '/embed/')
                || str_starts_with($path, '/share/')
                || (bool) preg_match('#^/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/(forms|redirects|sitemap|robots)#i', $path);
            $headers->set('Cross-Origin-Resource-Policy', $isPublicAssetOrApi ? 'cross-origin' : 'same-origin');
        }

        // 10. Isolation et durcissement des cookies de session admin
        foreach ($response->headers->getCookies() as $cookie) {
            $cookieName = $cookie->getName();
            if ($cookieName === 'JAMBOSESSID' || $cookieName === 'PHPSESSID') {
                $isAdminRoute = str_starts_with($path, '/admin')
                    || str_starts_with($path, '/settings')
                    || str_starts_with($path, '/user-management')
                    || str_starts_with($path, '/backup')
                    || str_starts_with($path, '/plugins');

                $targetSameSite = $isAdminRoute ? 'Strict' : ($cookie->getSameSite() ?? 'Lax');
                $targetSecure = $isSecure || $cookie->isSecure();

                $hardenedCookie = \Symfony\Component\HttpFoundation\Cookie::create(
                    $cookie->getName(),
                    $cookie->getValue(),
                    $cookie->getExpiresTime(),
                    $cookie->getPath() ?: '/',
                    $cookie->getDomain(),
                    $targetSecure,
                    true, // HttpOnly
                    $cookie->isRaw(),
                    $targetSameSite,
                );
                $response->headers->setCookie($hardenedCookie);
            }
        }

        // 11. Durcissement systématique des réponses d'erreur (HTTP 4xx et 5xx) : anti-cache absolu et suppression des cookies en cas de 500
        if ($response->getStatusCode() >= 400) {
            $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $headers->set('Pragma', 'no-cache');
            $headers->set('Expires', '0');

            if ($response->getStatusCode() >= 500) {
                $headers->remove('Set-Cookie');
            }
        }
    }
}
