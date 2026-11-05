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
use Symfony\Contracts\Translation\TranslatorInterface;

class InsightsController extends AbstractController
{
    public function __construct(
        private readonly InsightsService $insights,
        private readonly ProjectRepository $projectRepository,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    #[Route('/insights/summary', name: 'insights_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['error' => $this->trans('insights.auth_required', [], 'Authentication required')], 401);
        }

        return $this->json(['data' => $this->insights->summaryForUser($user)]);
    }

    #[Route('/insights/projects/{project}', name: 'insights_project', methods: ['GET'])]
    #[Route('/api/projects/{project}/insights', name: 'api_insights_project', methods: ['GET'])]
    public function project(string $project, Request $request): JsonResponse
    {
        $isNumeric = is_numeric($project) && (int) $project > 0;
        $isUuid = (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $project);

        if (!$isNumeric && !$isUuid) {
            throw $this->createNotFoundException($this->trans('insights.project_not_found', [], 'Project not found'));
        }

        try {
            $entity = $isNumeric
                ? $this->projectRepository->find((int) $project)
                : $this->projectRepository->findOneBy(['uuid' => $project]);
        } catch (\Throwable) {
            throw $this->createNotFoundException($this->trans('insights.project_not_found', [], 'Project not found'));
        }

        if (!$entity) {
            throw $this->createNotFoundException($this->trans('insights.project_not_found', [], 'Project not found'));
        }
        if ($request->attributes->get('_project') !== $entity) {
            $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $entity);
        }

        try {
            $range = InsightsRange::fromQuery($request->query->get('range'));
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => $this->trans('insights.invalid_range', [], 'Invalid range')], 400);
        }

        return $this->json(['data' => $this->insights->forProject($entity, $range)]);
    }
}
