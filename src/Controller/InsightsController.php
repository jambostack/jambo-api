<?php

namespace App\Controller;

use App\Enum\InsightsRange;
use App\Repository\ProjectRepository;
use App\Security\ProjectVoter;
use App\Service\Insights\InsightsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class InsightsController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly InsightsService $insights,
        private readonly ProjectRepository $projectRepository,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.insights_limiter')]
        private readonly ?RateLimiterFactory $insightsLimiter = null,
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
    public function summary(?Request $request = null): JsonResponse
    {
        if ($this->insightsLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->insightsLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('insights.rate_limit_exceeded', [], 'Too many insights requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        try {
            $user = $this->getUser();
        } catch (\Throwable) {
            $user = null;
        }

        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([
                'error' => $this->trans('insights.auth_required', [], 'Authentication required'),
                'code' => 'AUTH_REQUIRED',
            ], 401, self::SECURITY_HEADERS);
        }

        return new JsonResponse(['data' => $this->insights->summaryForUser($user)], 200, self::SECURITY_HEADERS);
    }

    #[Route('/insights/projects/{project}', name: 'insights_project', methods: ['GET'])]
    #[Route('/api/projects/{project}/insights', name: 'api_insights_project', methods: ['GET'])]
    public function project(string $project, Request $request): JsonResponse
    {
        if ($this->insightsLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->insightsLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('insights.rate_limit_exceeded', [], 'Too many insights requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

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
            return new JsonResponse([
                'error' => $this->trans('insights.invalid_range', [], 'Invalid range'),
                'code' => 'INVALID_RANGE',
            ], 400, self::SECURITY_HEADERS);
        }

        return new JsonResponse(['data' => $this->insights->forProject($entity, $range)], 200, self::SECURITY_HEADERS);
    }
}
