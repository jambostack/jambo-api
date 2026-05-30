<?php

namespace App\EventSubscriber;

use App\Repository\SiteDomainRepository;
use App\Service\NativeRenderer;
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

    /**
     * @param string[] $reservedHostnames Hostnames that must never be intercepted
     *                                    (typically the CMS's own domain).
     */
    public function __construct(
        private readonly SiteDomainRepository $siteDomainRepository,
        private readonly PublishedSiteStorage $storage,
        private readonly array $reservedHostnames = [],
        private readonly ?NativeRenderer $nativeRenderer = null,
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

        // Ne jamais intercepter les hostnames réservés (le domaine du CMS lui-même).
        if (in_array($host, $this->reservedHostnames, true)) {
            return;
        }

        $siteDomain = $this->siteDomainRepository->findByDomain($host);
        if ($siteDomain === null) {
            return;
        }

        $workbench = $siteDomain->workbenchProject;
        $uuid = $workbench->uuid->toRfc4122();
        $path = $event->getRequest()->getPathInfo();

        // Mode Twig natif — rendu serveur via NativeRenderer (Twig Sandbox + EAV direct)
        if ($workbench->framework === 'native') {
            if ($this->nativeRenderer === null) {
                $event->setResponse(new Response('NativeRenderer not configured — cannot serve native templates.', 503));
                return;
            }
            try {
                $html = $this->nativeRenderer->render($workbench, $path);
                $event->setResponse(new Response($html, 200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Cache-Control' => 'no-cache',
                ]));
                return;
            } catch (\RuntimeException $e) {
                $event->setResponse(new Response('Template Error: ' . $e->getMessage(), 500));
                return;
            }
        }

        // Mode statique (existants)
        [$content, $resolvedPath] = $this->resolveContent($uuid, $path);

        if ($content === null) {
            $event->setResponse(new Response('Not Found', 404));
            return;
        }

        // Le MIME est dérivé du fichier résolu (ex: index.html), pas du path de la requête.
        // Sans cela, / et /some/route serviraient du application/octet-stream au lieu de text/html.
        $ext  = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $isHtml = $ext === 'html';

        $response = new Response($content, 200, [
            'Content-Type'  => $mime,
            'Cache-Control' => $isHtml ? 'no-cache' : 'public, max-age=3600',
        ]);

        $event->setResponse($response);
    }

    /**
     * Résout le contenu à servir pour un path donné.
     *
     * @return array{0: ?string, 1: string} [content, resolvedFilePath]
     *         resolvedFilePath est le chemin du fichier effectivement servi (pour dériver le MIME type).
     */
    private function resolveContent(string $uuid, string $path): array
    {
        // /  -> index.html
        if ($path === '/' || $path === '') {
            $content = $this->storage->readFile($uuid, 'index.html');
            return [$content, 'index.html'];
        }

        // Essayer le chemin tel quel
        $relative = ltrim($path, '/');
        $content  = $this->storage->readFile($uuid, $relative);
        if ($content !== null) {
            return [$content, $relative];
        }

        // /some/path/  ->  /some/path/index.html
        $withIndex = rtrim($relative, '/') . '/index.html';
        $content   = $this->storage->readFile($uuid, $withIndex);
        if ($content !== null) {
            return [$content, $withIndex];
        }

        // Fallback SPA : index.html pour toute route non trouvée
        $content = $this->storage->readFile($uuid, 'index.html');
        return [$content, 'index.html'];
    }
}
