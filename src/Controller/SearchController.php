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
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }

        $this->denyAccessUnlessGranted('project.view', $project);

        $query = $request->query->get('q');
        if (empty($query)) {
            return $this->json(['error' => 'Paramètre "q" requis'], 400);
        }

        $options = [
            'limit' => min((int) $request->query->get('limit', 20), 100),
            'offset' => (int) $request->query->get('offset', 0),
        ];

        $collection = $request->query->get('collection');
        $locale = $request->query->get('locale');
        $filters = [];
        if ($collection) $filters[] = "_collection = $collection";
        if ($locale) $filters[] = "locale = $locale";
        if (!empty($filters)) $options['filter'] = implode(' AND ', $filters);

        $results = $this->search->search($project, $query, $options);

        return $this->json($results);
    }

    #[Route('/api/projects/{uuid}/search/reindex', name: 'search_reindex', methods: ['POST'])]
    public function reindex(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }

        $this->denyAccessUnlessGranted('project.manage', $project);

        $count = $this->search->rebuildIndex($project);

        return $this->json(['indexed' => $count, 'success' => true]);
    }
}
