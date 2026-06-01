<?php

namespace App\Controller;

use App\Repository\AppSettingsRepository;
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
    private AppSettingsRepository $appSettingsRepository;

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

    #[Required]
    public function setAppSettingsRepository(AppSettingsRepository $appSettingsRepository): void
    {
        $this->appSettingsRepository = $appSettingsRepository;
    }

    protected function inertia(Request $request, string $component, array $props = []): Response
    {
        // Shared props available on every page
        $props = array_merge($this->sharedProps(), $props);

        // Only include the Ziggy route manifest on the initial full-page load.
        // For subsequent Inertia XHR navigation, window.Ziggy is already set.
        if (!$request->headers->has('X-Inertia')) {
            $props['ziggy'] = $this->routeManifest->buildManifest($request);
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
            'appSettings'  => $this->loadAppSettings(),
        ];
    }

    private function loadAppSettings(): array
    {
        return $this->cache->get('app_settings_data', function (ItemInterface $item) {
            $item->expiresAfter(300); // 5 minutes
            $s = $this->appSettingsRepository->getOrCreate();
            $p = $s->aiProviders ?? [];

            return [
                'appName'      => $s->appName,
                'logoUrl'      => $s->getLogoUrl(),
                'logoDarkUrl'  => $s->getLogoDarkUrl(),
                'logoLightUrl' => $s->getLogoLightUrl(),
                'iconDarkUrl'  => $s->getIconDarkUrl(),
                'iconLightUrl' => $s->getIconLightUrl(),
                'faviconUrl'   => $s->getFaviconUrl(),
                'aiProviders'  => [
                    'openai'    => ['enabled' => (bool)($p['openai']['enabled']    ?? false), 'configured' => !empty($p['openai']['key']),    'model' => $p['openai']['model']    ?? 'gpt-4o'],
                    'anthropic' => ['enabled' => (bool)($p['anthropic']['enabled'] ?? false), 'configured' => !empty($p['anthropic']['key']), 'model' => $p['anthropic']['model'] ?? 'claude-sonnet-4-6'],
                    'deepseek'  => ['enabled' => (bool)($p['deepseek']['enabled']  ?? false), 'configured' => !empty($p['deepseek']['key']),  'model' => $p['deepseek']['model']  ?? 'deepseek-chat'],
                    'ollama'    => ['enabled' => (bool)($p['ollama']['enabled']    ?? false), 'configured' => !empty($p['ollama']['url']),    'url'   => $p['ollama']['url']      ?? '', 'model' => $p['ollama']['model'] ?? 'llama3.2'],
                    'gemini'    => ['enabled' => (bool)($p['gemini']['enabled']    ?? false), 'configured' => !empty($p['gemini']['key']),    'model' => $p['gemini']['model']    ?? 'gemini-2.0-flash'],
                    'openrouter'=> ['enabled' => (bool)($p['openrouter']['enabled']?? false), 'configured' => !empty($p['openrouter']['key']),'model' => $p['openrouter']['model']?? 'openai/gpt-4o'],
                    'mistral'   => ['enabled' => (bool)($p['mistral']['enabled']   ?? false), 'configured' => !empty($p['mistral']['key']),   'model' => $p['mistral']['model']   ?? 'mistral-large-latest'],
                    'groq'      => ['enabled' => (bool)($p['groq']['enabled']      ?? false), 'configured' => !empty($p['groq']['key']),      'model' => $p['groq']['model']      ?? 'llama-3.3-70b-versatile'],
                    'xai'       => ['enabled' => (bool)($p['xai']['enabled']       ?? false), 'configured' => !empty($p['xai']['key']),       'model' => $p['xai']['model']       ?? 'grok-2-latest'],
                    'perplexity'=> ['enabled' => (bool)($p['perplexity']['enabled']?? false), 'configured' => !empty($p['perplexity']['key']),'model' => $p['perplexity']['model']?? 'sonar-pro'],
                    'qwen'      => ['enabled' => (bool)($p['qwen']['enabled']      ?? false), 'configured' => !empty($p['qwen']['key']),      'model' => $p['qwen']['model']      ?? 'qwen-max'],
                ],
            ];
        });
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
