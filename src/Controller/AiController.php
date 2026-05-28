<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use App\Service\AiContentService;
use App\Service\EavDataFormatterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiContentService $ai,
        private EavDataFormatterService $formatter,
    ) {}

    #[Route('/api/projects/{uuid}/ai/generate', name: 'ai_generate', methods: ['POST'])]
    public function generate(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyAccessUnlessGranted('content.create', $project);

        $body = json_decode($request->getContent(), true);
        $brief = $body['brief'] ?? '';
        $collectionSlug = $body['collection'] ?? '';

        if (empty($brief) || empty($collectionSlug)) {
            return $this->json(['error' => 'Brief et collection requis'], 400);
        }

        $collection = $this->em->getRepository(Collection::class)->findOneBy([
            'project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null,
        ]);
        if (!$collection) {
            return $this->json(['error' => 'Collection introuvable'], 404);
        }

        $locale = $body['locale'] ?? $project->defaultLocale ?? 'fr';
        $result = $this->ai->generateContent($brief, $collection, $locale);

        return $this->json($result);
    }

    #[Route('/api/projects/{uuid}/ai/translate', name: 'ai_translate', methods: ['POST'])]
    public function translate(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyAccessUnlessGranted('content.update', $project);

        $body = json_decode($request->getContent(), true);
        $content = $body['content'] ?? [];
        $targetLocale = $body['locale'] ?? 'en';

        if (empty($content)) {
            return $this->json(['error' => 'Contenu requis'], 400);
        }

        $translated = $this->ai->translateContent($content, $targetLocale);

        return $this->json(['original' => $content, 'translated' => $translated, 'locale' => $targetLocale]);
    }

    #[Route('/api/projects/{uuid}/ai/summarize', name: 'ai_summarize', methods: ['POST'])]
    public function summarize(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyAccessUnlessGranted('project.view', $project);

        $body = json_decode($request->getContent(), true);
        $text = $body['text'] ?? '';
        $maxWords = (int) ($body['maxWords'] ?? 80);

        if (empty($text)) {
            return $this->json(['error' => 'Texte requis'], 400);
        }

        return $this->json(['summary' => $this->ai->summarize($text, $maxWords)]);
    }

    #[Route('/api/projects/{uuid}/ai/seo', name: 'ai_seo', methods: ['POST'])]
    public function seo(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyAccessUnlessGranted('project.view', $project);

        $body = json_decode($request->getContent(), true);
        $content = $body['content'] ?? [];

        if (empty($content)) {
            return $this->json(['error' => 'Contenu requis'], 400);
        }

        return $this->json($this->ai->generateSeo($content));
    }

    #[Route('/api/projects/{uuid}/ai/suggest-schema', name: 'ai_suggest_schema', methods: ['GET'])]
    public function suggestSchema(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyAccessUnlessGranted('project.manage', $project);

        $collectionSlug = $request->query->get('collection');
        if (!$collectionSlug) {
            return $this->json(['error' => 'Paramètre collection requis'], 400);
        }

        $collection = $this->em->getRepository(Collection::class)->findOneBy([
            'project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null,
        ]);
        if (!$collection) {
            return $this->json(['error' => 'Collection introuvable'], 404);
        }

        return $this->json($this->ai->suggestSchema($collection));
    }

    #[Route('/api/projects/{uuid}/ai/models', name: 'ai_models', methods: ['GET'])]
    public function models(string $uuid): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyAccessUnlessGranted('project.view', $project);

        return $this->json($this->ai->getAvailableModels());
    }

    private function findProject(string $uuid): ?Project
    {
        return $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
    }
}
