<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class MercureFallbackController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'limiter.realtime_limiter')]
        private readonly ?RateLimiterFactory $realtimeLimiter = null,
    ) {}

    #[Route('/.well-known/mercure', name: 'mercure_fallback', methods: ['GET', 'POST', 'OPTIONS'])]
    public function handle(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return new Response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Last-Event-ID',
            ]);
        }

        if ($this->realtimeLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            $limiter = $this->realtimeLimiter->create($identifier);
            if (false === $limiter->consume(1)->isAccepted()) {
                return new JsonResponse(['error' => 'Too many requests. Try again later.'], 429);
            }
        }

        // Si une requête EventSource (GET) arrive sur ce serveur alors qu'aucun hub
        // Mercure dédié (Caddy/Go) n'est actif, renvoyer text/event-stream avec un
        // événement de fermeture propre au lieu d'une page HTML (évite l'erreur MIME type).
        if ($request->isMethod('GET')) {
            return new StreamedResponse(function () {
                echo "event: close\ndata: " . json_encode(['error' => 'Mercure hub not running on this host']) . "\n\n";
                flush();
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'close',
                'Access-Control-Allow-Origin' => '*',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        return new JsonResponse(['error' => 'Mercure hub not available on this host.'], 503);
    }
}
