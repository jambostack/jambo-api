<?php

namespace App\EventSubscriber;

use App\Repository\SiteDomainRepository;
use App\Service\PublishedSiteStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SiteHostResolver implements EventSubscriberInterface
{
    private const MIME_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'mjs'  => 'application/javascript',
        'json' => 'application/json',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'txt'  => 'text/plain',
    ];

    public function __construct(
        private readonly SiteDomainRepository $siteDomainRepository,
        private readonly PublishedSiteStorage $storage,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $host = $event->getRequest()->getHost();
        $siteDomain = $this->siteDomainRepository->findByDomain($host);
        if ($siteDomain === null) {
            return;
        }

        $uuid = $siteDomain->workbenchProject->uuid->toRfc4122();
        $path = $event->getRequest()->getPathInfo();

        $content = $this->resolveContent($uuid, $path);

        if ($content === null) {
            $event->setResponse(new Response('Not Found', 404));
            return;
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? 'application/octet-stream';

        $response = new Response($content, 200, [
            'Content-Type'  => $mime,
            'Cache-Control' => $ext === 'html' ? 'no-cache' : 'public, max-age=31536000, immutable',
        ]);

        $event->setResponse($response);
    }

    private function resolveContent(string $uuid, string $path): ?string
    {
        // /  -> index.html
        if ($path === '/' || $path === '') {
            return $this->storage->readFile($uuid, 'index.html');
        }

        // Essayer le chemin tel quel
        $relative = ltrim($path, '/');
        $content  = $this->storage->readFile($uuid, $relative);
        if ($content !== null) {
            return $content;
        }

        // /some/path/  ->  /some/path/index.html
        $withIndex = rtrim($relative, '/') . '/index.html';
        $content   = $this->storage->readFile($uuid, $withIndex);
        if ($content !== null) {
            return $content;
        }

        // Fallback SPA : index.html pour toute route non trouvee
        return $this->storage->readFile($uuid, 'index.html');
    }
}
