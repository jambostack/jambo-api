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
        return [
            'appName'      => $s->appName,
            'logoUrl'      => $s->getLogoUrl(),
            'logoDarkUrl'  => $s->getLogoDarkUrl(),
            'logoLightUrl' => $s->getLogoLightUrl(),
            'iconDarkUrl'  => $s->getIconDarkUrl(),
            'iconLightUrl' => $s->getIconLightUrl(),
            'faviconUrl'   => $s->getFaviconUrl(),
        ];
    }
}
