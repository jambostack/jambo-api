<?php

namespace App\Controller;

use App\Enum\InsightsRange;
use App\Repository\ProjectRepository;
use App\Security\ProjectVoter;
use App\Service\Insights\InsightsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class InsightsController extends AbstractController
{
    public function __construct(
        private readonly InsightsService $insights,
        private readonly ProjectRepository $projectRepository,
    ) {}

    #[Route('/insights/summary', name: 'insights_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        return $this->json(['data' => $this->insights->summaryForUser($this->getUser())]);
    }

    #[Route('/insights/projects/{project}', name: 'insights_project', requirements: ['project' => '\d+'], methods: ['GET'])]
    public function project(int $project, Request $request): JsonResponse
    {
        $entity = $this->projectRepository->find($project);
        if (!$entity) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $entity);

        try {
            $range = InsightsRange::fromQuery($request->query->get('range'));
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid range'], 400);
        }

        return $this->json(['data' => $this->insights->forProject($entity, $range)]);
    }
}
