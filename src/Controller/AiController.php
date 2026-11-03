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

        $this->denyProjectAccess($request, $project, 'content.create');

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }
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

        try {
            $result = $this->ai->generateContent($brief, $collection, $locale);
            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/projects/{uuid}/ai/translate', name: 'ai_translate', methods: ['POST'])]
    public function translate(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyProjectAccess($request, $project, 'content.update');

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }
        $content = $body['content'] ?? [];
        $targetLocale = $body['locale'] ?? 'en';

        if (empty($content)) {
            return $this->json(['error' => 'Contenu requis'], 400);
        }

        try {
            $translated = $this->ai->translateContent($content, $targetLocale, $project);
            return $this->json(['original' => $content, 'translated' => $translated, 'locale' => $targetLocale]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/projects/{uuid}/ai/summarize', name: 'ai_summarize', methods: ['POST'])]
    public function summarize(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyProjectAccess($request, $project, 'project.view');

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }
        $text = $body['text'] ?? '';
        $maxWords = (int) ($body['maxWords'] ?? 80);

        if (empty($text)) {
            return $this->json(['error' => 'Texte requis'], 400);
        }

        try {
            return $this->json(['summary' => $this->ai->summarize($text, $maxWords, $project)]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/projects/{uuid}/ai/seo', name: 'ai_seo', methods: ['POST'])]
    public function seo(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyProjectAccess($request, $project, 'project.view');

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }
        $content = $body['content'] ?? [];

        if (empty($content)) {
            return $this->json(['error' => 'Contenu requis'], 400);
        }

        try {
            return $this->json($this->ai->generateSeo($content, $project));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/projects/{uuid}/ai/suggest-schema', name: 'ai_suggest_schema', methods: ['GET'])]
    public function suggestSchema(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyProjectAccess($request, $project, 'project.manage');

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

        try {
            return $this->json($this->ai->suggestSchema($collection));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/projects/{uuid}/ai/models', name: 'ai_models', methods: ['GET'])]
    public function models(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyProjectAccess($request, $project, 'project.view');

        return $this->json($this->ai->getAvailableModels());
    }

    private function denyProjectAccess(Request $request, Project $project, string $attribute): void
    {
        if ($request->attributes->get('_project') === $project) {
            return;
        }
        $this->denyAccessUnlessGranted($attribute, $project);
    }

    private function findProject(string $uuid): ?Project
    {
        return $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
    }
}
