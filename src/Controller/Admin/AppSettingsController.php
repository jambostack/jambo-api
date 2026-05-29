<?php

namespace App\Controller\Admin;

use App\Repository\AppSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Vich\UploaderBundle\Handler\UploadHandler;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/api/app-settings', name: 'admin_app_settings_')]
class AppSettingsController extends AbstractController
{
    public function __construct(
        private AppSettingsRepository $repository,
        private EntityManagerInterface $em,
        private UploadHandler $uploadHandler,
        private CacheInterface $cache,
    ) {}

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $settings = $this->repository->getOrCreate();

        return $this->json($this->serialize($settings));
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        $settings = $this->repository->getOrCreate();
        $changed  = false;

        // Handle JSON fields
        if ($request->getContentTypeFormat() === 'json') {
            $data = $request->toArray();
            if (isset($data['appName']) && trim($data['appName']) !== '') {
                $name = trim($data['appName']);
                if (mb_strlen($name) > 100) {
                    return $this->json(['errors' => ['appName' => 'App name must not exceed 100 characters']], 422);
                }
                $settings->appName = $name;
                $changed = true;
            }
        }

        // Multipart file uploads — each field maps to a Vich property
        $fileFields = [
            'logo'       => 'logoFile',
            'logo_dark'  => 'logoDarkFile',
            'logo_light' => 'logoLightFile',
            'icon_dark'  => 'iconDarkFile',
            'icon_light' => 'iconLightFile',
            'favicon'    => 'faviconFile',
        ];

        foreach ($fileFields as $formField => $property) {
            $file = $request->files->get($formField);
            if ($file === null) {
                continue;
            }

            $allowIco = ($formField === 'favicon');
            $error = $this->validateImageFile($file, $allowIco);
            if ($error !== null) {
                return $this->json(['errors' => [$formField => $error]], 422);
            }

            $settings->$property = $file;
            $this->uploadHandler->upload($settings, $property);
            $changed = true;
        }

        // AI providers (JSON body with key "aiProviders")
        if ($request->getContentTypeFormat() === 'json') {
            $data = $request->toArray();
            if (array_key_exists('aiProviders', $data) && is_array($data['aiProviders'])) {
                $current  = $settings->aiProviders ?? [];
                $incoming = $data['aiProviders'];

                foreach (['openai', 'anthropic', 'deepseek', 'ollama'] as $provider) {
                    if (!array_key_exists($provider, $incoming)) {
                        continue;
                    }
                    $p = $incoming[$provider];

                    // enabled state — always saved when present
                    if (array_key_exists('enabled', $p)) {
                        $current[$provider]['enabled'] = (bool) $p['enabled'];
                    }
                    // API key — empty string clears it
                    if (array_key_exists('key', $p)) {
                        $val = trim((string) $p['key']);
                        $current[$provider]['key'] = $val !== '' ? $val : ($current[$provider]['key'] ?? null);
                    }
                    if (array_key_exists('model', $p)) {
                        $current[$provider]['model'] = trim((string) $p['model']) ?: null;
                    }
                    if (array_key_exists('url', $p)) {
                        $current[$provider]['url'] = trim((string) $p['url']) ?: null;
                    }
                }

                $settings->aiProviders = $current;
                $changed = true;
            }
        }

        if ($changed) {
            $settings->updatedAt = new \DateTimeImmutable();
            $this->em->flush();
            $this->cache->delete('app_settings_data');
        }

        return $this->json($this->serialize($settings));
    }

    private function validateImageFile(UploadedFile $file, bool $allowIco = false): ?string
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        if ($allowIco) {
            $allowedMimes[] = 'image/x-icon';
            $allowedMimes[] = 'image/vnd.microsoft.icon';
        }

        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP, SVG' . ($allowIco ? ', ICO' : '');
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return 'File exceeds the maximum size of 2 MB';
        }

        return null;
    }

    private function serialize(\App\Entity\AppSettings $s): array
    {
        $providers = $s->aiProviders ?? [];

        return [
            'appName'      => $s->appName,
            'logoUrl'      => $s->getLogoUrl(),
            'logoDarkUrl'  => $s->getLogoDarkUrl(),
            'logoLightUrl' => $s->getLogoLightUrl(),
            'iconDarkUrl'  => $s->getIconDarkUrl(),
            'iconLightUrl' => $s->getIconLightUrl(),
            'faviconUrl'   => $s->getFaviconUrl(),
            'aiProviders'  => [
                'openai'    => [
                    'enabled'    => (bool) ($providers['openai']['enabled']    ?? false),
                    'configured' => !empty($providers['openai']['key']),
                    'model'      => $providers['openai']['model']    ?? 'gpt-4o',
                ],
                'anthropic' => [
                    'enabled'    => (bool) ($providers['anthropic']['enabled'] ?? false),
                    'configured' => !empty($providers['anthropic']['key']),
                    'model'      => $providers['anthropic']['model'] ?? 'claude-sonnet-4-6',
                ],
                'deepseek'  => [
                    'enabled'    => (bool) ($providers['deepseek']['enabled']  ?? false),
                    'configured' => !empty($providers['deepseek']['key']),
                    'model'      => $providers['deepseek']['model']  ?? 'deepseek-chat',
                ],
                'ollama'    => [
                    'enabled'    => (bool) ($providers['ollama']['enabled']    ?? false),
                    'configured' => !empty($providers['ollama']['url']),
                    'url'        => $providers['ollama']['url']       ?? '',
                    'model'      => $providers['ollama']['model']     ?? 'llama3.2',
                ],
            ],
        ];
    }

}
