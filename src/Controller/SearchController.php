<?php

namespace App\Controller;

use App\Entity\Project;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SearchService $search,
    ) {}

    #[Route('/api/projects/{uuid}/search', name: 'search_endpoint', methods: ['GET'])]
    public function search(string $uuid, Request $request): JsonResponse
    {
        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }

        $this->denyAccessUnlessGranted('project.view', $project);

        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return $this->json(['error' => 'Paramètre "q" requis'], 400);
        }
        if (mb_strlen($query) > 500) {
            $query = mb_substr($query, 0, 500);
        }

        $options = [
            'limit' => min(max(1, (int) $request->query->get('limit', 20)), 100),
            'offset' => max(0, (int) $request->query->get('offset', 0)),
        ];

        $collection = $request->query->get('collection');
        $locale = $request->query->get('locale');
        $filters = [];
        if (!empty($collection)) {
            $colStr = (string) $collection;
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $colStr)) {
                return $this->json(['error' => 'Invalid collection format'], 400);
            }
            $options['collection'] = $colStr;
            $cleanColl = addslashes($colStr);
            $filters[] = "_collection = \"{$cleanColl}\"";
        }
        if (!empty($locale)) {
            $locStr = (string) $locale;
            if (!preg_match('/^[a-zA-Z]{2,4}([-_][a-zA-Z0-9]{2,8})?$/', $locStr)) {
                return $this->json(['error' => 'Invalid locale format'], 400);
            }
            $options['locale'] = $locStr;
            $cleanLoc = addslashes($locStr);
            $filters[] = "locale = \"{$cleanLoc}\"";
        }
        if (!empty($filters)) {
            $options['filter'] = implode(' AND ', $filters);
        }

        $results = $this->search->search($project, $query, $options);

        return $this->json($results);
    }

    #[Route('/api/projects/{uuid}/search/reindex', name: 'search_reindex', methods: ['POST'])]
    public function reindex(string $uuid): JsonResponse
    {
        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }

        $this->denyAccessUnlessGranted('project.manage', $project);

        $count = $this->search->rebuildIndex($project);

        return $this->json(['indexed' => $count, 'success' => true]);
    }
}
