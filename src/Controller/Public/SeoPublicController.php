<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\ProjectRepository;
use App\Service\Seo\SitemapGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SeoPublicController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepo,
        private readonly SitemapGenerator $sitemapGenerator,
    ) {}

    #[Route('/{projectUuid}/sitemap.xml', name: 'public_sitemap', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Route('/api/projects/{projectUuid}/sitemap.xml', name: 'api_public_sitemap')]
    public function sitemap(string $projectUuid): Response
    {
        try {
            $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            throw $this->createNotFoundException('Project not found.');
        }
        if (!$project) {
            throw $this->createNotFoundException('Project not found.');
        }

        $xml = $this->sitemapGenerator->generateSitemap($project);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    #[Route('/{projectUuid}/sitemap-images.xml', name: 'public_image_sitemap', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Route('/api/projects/{projectUuid}/sitemap-images.xml', name: 'api_public_image_sitemap')]
    public function imageSitemap(string $projectUuid): Response
    {
        try {
            $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            throw $this->createNotFoundException('Project not found.');
        }
        if (!$project) {
            throw $this->createNotFoundException('Project not found.');
        }

        $xml = $this->sitemapGenerator->generateImageSitemap($project);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    #[Route('/{projectUuid}/robots.txt', name: 'public_robots', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Route('/api/projects/{projectUuid}/robots.txt', name: 'api_public_robots')]
    public function robots(string $projectUuid): Response
    {
        try {
            $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            throw $this->createNotFoundException('Project not found.');
        }
        if (!$project) {
            throw $this->createNotFoundException('Project not found.');
        }

        $seo = $project->getSeoSettings();
        $rawBaseUrl = rtrim($project->previewUrl ?? 'https://example.com', '/');
        $baseUrl = str_replace(["\r", "\n"], '', $rawBaseUrl);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://'))) {
            $baseUrl = 'https://example.com';
        }

        $cleanUuid = str_replace(["\r", "\n"], '', $projectUuid);

        $content = "User-agent: *\n";
        $content .= "Allow: /\n";
        if ($seo['enableSitemap']) {
            $content .= "Sitemap: {$baseUrl}/{$cleanUuid}/sitemap.xml\n";
        }

        return new Response($content, 200, ['Content-Type' => 'text/plain']);
    }
}
