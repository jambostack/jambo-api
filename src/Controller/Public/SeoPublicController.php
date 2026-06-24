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
    public function sitemap(string $projectUuid): Response
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            throw $this->createNotFoundException('Project not found.');
        }

        $xml = $this->sitemapGenerator->generateSitemap($project);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    #[Route('/{projectUuid}/sitemap-images.xml', name: 'public_image_sitemap', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function imageSitemap(string $projectUuid): Response
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            throw $this->createNotFoundException('Project not found.');
        }

        $xml = $this->sitemapGenerator->generateImageSitemap($project);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    #[Route('/{projectUuid}/robots.txt', name: 'public_robots', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function robots(string $projectUuid): Response
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            throw $this->createNotFoundException('Project not found.');
        }

        $seo = $project->getSeoSettings();
        $baseUrl = rtrim($project->previewUrl ?? 'https://example.com', '/');

        $content = "User-agent: *\n";
        $content .= "Allow: /\n";
        if ($seo['enableSitemap']) {
            $content .= "Sitemap: {$baseUrl}/{$projectUuid}/sitemap.xml\n";
        }

        return new Response($content, 200, ['Content-Type' => 'text/plain']);
    }
}
