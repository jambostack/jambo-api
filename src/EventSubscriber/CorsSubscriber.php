<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CorsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 255],
            KernelEvents::RESPONSE => ['onKernelResponse', 255],
        ];
    }

    /**
     * Intercepte les requêtes OPTIONS (preflight CORS) avant le routing Symfony.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Seulement les requêtes OPTIONS (preflight CORS)
        if ($request->getMethod() !== 'OPTIONS') {
            return;
        }

        // Les routes avec un contrôleur OPTIONS explicite (ex: TUS protocol discovery, Mercure fallback) passent au contrôleur
        if (str_contains($request->getPathInfo(), '/files/tus') || str_contains($request->getPathInfo(), '/.well-known/mercure')) {
            return;
        }

        $path = $request->getPathInfo();
        $origin = (string) $request->headers->get('Origin', '');

        if ($this->isPublicRoute($path)) {
            $response = new Response('', 204);
            $this->addPublicCorsHeaders($response);
            $event->setResponse($response);
            return;
        }

        // Route restreinte / backoffice : valider l'Origin
        if ($origin !== '' && $this->isAllowedOrigin($origin, $request)) {
            $response = new Response('', 204);
            $this->addRestrictedCorsHeaders($response, $origin);
            $event->setResponse($response);
            return;
        }

        // Origin non autorisée sur route restreinte : bloquer le preflight
        if ($origin !== '') {
            $response = new Response('CORS origin not allowed', 403);
            $event->setResponse($response);
            return;
        }

        // Sans Origin, preflight direct : 204 neutre
        $event->setResponse(new Response('', 204));
    }

    /**
     * Ajoute les headers CORS appropriés selon la nature de la route et l'Origin.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $path = $request->getPathInfo();
        $origin = (string) $request->headers->get('Origin', '');

        if ($this->isPublicRoute($path)) {
            $this->addPublicCorsHeaders($response);
            return;
        }

        if ($origin !== '' && $this->isAllowedOrigin($origin, $request)) {
            $this->addRestrictedCorsHeaders($response, $origin);
        }
    }

    /**
     * Détermine si une route est une API publique (Headless CMS, GraphQL, content delivery).
     */
    public function isPublicRoute(string $path): bool
    {
        $publicPatterns = [
            '#^/api/content#',
            '#^/api/projects/[^/]+/content#',
            '#^/api/projects/[^/]+/graphql#',
            '#^/api/projects/[^/]+/public#',
            '#^/api/projects/[^/]+/forms#',
            '#^/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/forms#',
            '#^/api/projects/[^/]+/redirects#',
            '#^/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/redirects#',
            '#^/forms/embed#',
            '#^/embed/forms#',
            '#^/share/#',
            '#^/api/projects/[^/]+/sitemap#',
            '#^/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/sitemap#',
            '#^/api/projects/[^/]+/robots\.txt#',
            '#^/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/robots\.txt#',
            '#^/api/[^/]+/auth/#',
            '#^/api/[^/]+/openapi\.json#',
            '#^/api/settings/admin-api/openapi\.json#',
            '#^/admin-api/openapi\.json#',
            '#^/api/docs#',
            '#^/api/forms/submit#',
            '#^/storage/#',
            '#^/files/#',
            '#^/api/system/info#',
            '#^/health#',
        ];

        foreach ($publicPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si l'origine d'un appel backoffice/restreint est autorisée.
     */
    public function isAllowedOrigin(string $origin, Request $request): bool
    {
        if ($origin === '' || $origin === 'null') {
            return false;
        }

        $parsedOrigin = parse_url($origin);
        if (!$parsedOrigin || !isset($parsedOrigin['host'])) {
            return false;
        }

        $host = strtolower($parsedOrigin['host']);

        // 1. Même hôte que la requête
        $requestHost = strtolower($request->getHost());
        if ($host === $requestHost) {
            return true;
        }

        // 2. Localhost / loopback pour environnement de développement
        if ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) {
            return true;
        }

        // 3. Origines explicitement autorisées via CORS_ALLOWED_ORIGINS
        $configured = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;
        if ($configured) {
            $allowedList = array_map('trim', explode(',', $configured));
            foreach ($allowedList as $allowed) {
                if ($allowed === '*' || strcasecmp($origin, $allowed) === 0) {
                    return true;
                }
                if (str_starts_with($allowed, '*.')) {
                    $domainSuffix = substr($allowed, 1);
                    $apexDomain = substr($allowed, 2);
                    if ($host === $apexDomain || str_ends_with($host, $domainSuffix)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function addPublicCorsHeaders(Response $response): void
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, Origin, X-Requested-With, Tus-Resumable, Upload-Length, Upload-Metadata, Upload-Offset');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Length, Content-Range, Upload-Offset, Location, Upload-Length, Tus-Version, Tus-Resumable, Tus-Max-Size, Tus-Extension');
    }

    private function addRestrictedCorsHeaders(Response $response, string $origin): void
    {
        if ($origin === '*') {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        } else {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-CSRF-Token');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Vary', 'Origin');
    }
}

