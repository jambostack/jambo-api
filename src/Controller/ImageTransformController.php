<?php

namespace App\Controller;

use App\Entity\Media;
use App\Service\ImageTransformService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ImageTransformController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImageTransformService $imageTransform,
    ) {}

    #[Route('/cdn/media/{uuid}', name: 'cdn_media_transform', methods: ['GET'])]
    public function serve(string $uuid, Request $request): Response
    {
        $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $uuid]);

        // Vérifier que le média existe, n'est pas supprimé, et est accessible
        if (!$media || $media->isDeleted()) {
            return new Response('Média introuvable', 404);
        }

        // Vérifier que le projet autorise l'accès public ou que l'utilisateur est authentifié
        $project = $media->project;
        if (!$project?->publicApi && !$this->getUser()) {
            return new Response('Accès non autorisé', 403);
        }

        // Les fichiers sont rangés sous un dossier par projet (ProjectDirNamer) :
        // public/uploads/media/{projectUuid}/{fileName}.
        $mediaRoot = $this->getParameter('kernel.project_dir') . '/public/uploads/media/';
        $filePath = $mediaRoot . (string) $project->uuid . '/' . $media->fileName;
        if (!file_exists($filePath)) {
            // Repli : anciens médias éventuellement stockés à plat.
            $filePath = $mediaRoot . $media->fileName;
        }
        if (!file_exists($filePath)) {
            return new Response('Fichier introuvable', 404);
        }

        $params = $request->query->all();
        $hasParams = !empty(array_intersect_key($params, array_flip(['w', 'h', 'fit', 'fmt', 'q', 'bg'])));

        if (!$hasParams) {
            return new BinaryFileResponse($filePath, 200, [
                'Content-Type' => $media->mimeType ?? 'application/octet-stream',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        try {
            $cachePath = $this->imageTransform->transform($filePath, $params);
        } catch (\RuntimeException $e) {
            return new Response($e->getMessage(), 404);
        }

        return new BinaryFileResponse($cachePath, 200, [
            'Content-Type' => $this->imageTransform->getMimeType($cachePath),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Image-Transform' => 'cached',
        ]);
    }

    #[Route('/cdn/media/{uuid}/info', name: 'cdn_media_info', methods: ['GET'])]
    public function info(string $uuid): Response
    {
        $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $uuid]);
        if (!$media || $media->isDeleted()) {
            return $this->json(['error' => 'Média introuvable'], 404);
        }

        $filePath = $this->getParameter('kernel.project_dir') . '/public/uploads/media/' . $media->fileName;
        if (!file_exists($filePath)) {
            return $this->json(['error' => 'Fichier introuvable'], 404);
        }

        $imageSize = @getimagesize($filePath);

        return $this->json([
            'uuid' => $media->uuid->toRfc4122(),
            'name' => $media->originalName ?? $media->fileName,
            'mimeType' => $media->mimeType,
            'size' => $media->fileSize,
            'width' => $imageSize[0] ?? null,
            'height' => $imageSize[1] ?? null,
            'url' => $this->generateUrl('cdn_media_transform', ['uuid' => $media->uuid->toRfc4122()], 0),
            'transformations' => [
                'resize' => '?w=800&h=600',
                'crop' => '?w=400&h=400&fit=crop',
                'webp' => '?fmt=webp&q=80',
                'thumbnail' => '?w=200&h=200&fit=crop&fmt=webp&q=80',
            ],
        ]);
    }
}
