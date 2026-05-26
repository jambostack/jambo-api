<?php

namespace App\Controller;

use App\Service\FrontendRouteManifestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Base controller that adds inertia() helper for manual Inertia.js rendering
 * (the inertia-bundle is not compatible with SF8, so we manage it ourselves).
 */
abstract class InertiaController extends AbstractController
{
    private FrontendRouteManifestService $routeManifest;
    private CacheInterface $cache;
    private TranslatorInterface $translator;

    #[Required]
    public function setRouteManifest(FrontendRouteManifestService $routeManifest): void
    {
        $this->routeManifest = $routeManifest;
    }

    #[Required]
    public function setCache(CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    protected function inertia(Request $request, string $component, array $props = []): Response
    {
        // Shared props available on every page
        $props = array_merge($this->sharedProps(), $props);

        // Only include the Ziggy route manifest on the initial full-page load.
        // For subsequent Inertia XHR navigation, window.Ziggy is already set.
        if (!$request->headers->has('X-Inertia')) {
            $props['ziggy'] = $this->cache->get('inertia_route_manifest', function (ItemInterface $item) use ($request) {
                $item->expiresAfter(null); // lives until cache:clear
                return $this->routeManifest->buildManifest($request);
            });
        }

        $page = [
            'component' => $component,
            'props'     => $props,
            'url'       => $request->getRequestUri(),
            'version'   => null,
        ];

        if ($request->headers->has('X-Inertia')) {
            return new JsonResponse($page, 200, [
                'X-Inertia'          => 'true',
                'Vary'               => 'X-Inertia',
                'X-Inertia-Location' => $request->getRequestUri(),
            ]);
        }

        return $this->render('app.html.twig', ['page' => $page]);
    }

    private function sharedProps(): array
    {
        /** @var \App\Entity\User|null $user */
        $user   = $this->getUser();
        $locale = $user?->locale ?? 'en';

        return [
            'auth' => [
                'user' => $user ? [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->getUserIdentifier(),
                ] : null,
            ],
            'flash' => [
                'success' => null,
                'error'   => null,
                'message' => null,
            ],
            'locale'       => $locale,
            'translations' => $this->loadTranslations($locale),
        ];
    }

    private function loadTranslations(string $locale = 'en'): array
    {
        $dir  = $this->getParameter('kernel.project_dir') . '/translations';
        $file = "{$dir}/messages.{$locale}.php";
        if (file_exists($file)) {
            return require $file;
        }

        $fallback = "{$dir}/messages.en.php";
        return file_exists($fallback) ? require $fallback : [];
    }
}
