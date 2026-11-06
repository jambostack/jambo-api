<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class MercureFallbackController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Referrer-Policy'        => 'no-referrer',
        'X-XSS-Protection'       => '0',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.realtime_limiter')]
        private readonly ?RateLimiterFactory $realtimeLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

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
            $limitResult = $limiter->consume(1);
            if (false === $limitResult->isAccepted()) {
                $retryAfter = max(1, $limitResult->getRetryAfter()->getTimestamp() - time());
                return new JsonResponse([
                    'error' => $this->trans('mercure.rate_limit_exceeded', [], 'Too many requests. Try again later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, array_merge(self::SECURITY_HEADERS, ['Retry-After' => (string) $retryAfter]));
            }
        }

        // Si une requête EventSource (GET) arrive sur ce serveur alors qu'aucun hub
        // Mercure dédié (Caddy/Go) n'est actif, renvoyer text/event-stream avec un
        // événement de fermeture propre au lieu d'une page HTML (évite l'erreur MIME type).
        if ($request->isMethod('GET')) {
            $closedMsg = $this->trans('mercure.hub_not_available', [], 'Mercure hub not available on this host.');
            return new StreamedResponse(function () use ($closedMsg) {
                echo "event: close\ndata: " . json_encode(['error' => $closedMsg], JSON_THROW_ON_ERROR) . "\n\n";
                flush();
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'close',
                'Access-Control-Allow-Origin' => '*',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        return new JsonResponse([
            'error' => $this->trans('mercure.hub_not_available', [], 'Mercure hub not available on this host.'),
            'code'  => 'MERCURE_NOT_AVAILABLE',
        ], 503, self::SECURITY_HEADERS);
    }
}
