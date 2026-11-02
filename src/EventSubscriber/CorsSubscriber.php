<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
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
     * Sans ceci, Symfony renvoie 405 car aucune route ne répond à OPTIONS.
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

        // Les routes avec un contrôleur OPTIONS explicite (ex: TUS protocol discovery) passent au contrôleur
        if (str_contains($request->getPathInfo(), '/files/tus')) {
            return;
        }

        // Répondre directement avec les headers CORS
        $response = new Response('', 204);
        $this->addCorsHeaders($response);

        $event->setResponse($response);
    }

    /**
     * Ajoute les headers CORS à toutes les réponses.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->addCorsHeaders($event->getResponse());
    }

    private function addCorsHeaders(Response $response): void
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, Origin, X-Requested-With, Tus-Resumable, Upload-Length, Upload-Metadata, Upload-Offset');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Length, Content-Range, Upload-Offset, Location, Upload-Length, Tus-Version, Tus-Resumable, Tus-Max-Size, Tus-Extension');
    }
}
