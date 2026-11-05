<?php

namespace App\Controller;

use App\Entity\Project;
use App\Service\ApiTokenChecker;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class SearchController extends AbstractController
{
    public const MAX_QUERY_LENGTH = 500;
    public const MAX_PAGE_LIMIT = 100;
    public const MAX_OFFSET = 10000;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchService $search,
        #[Autowire(service: 'limiter.search_limiter')]
        private readonly ?RateLimiterFactory $searchLimiter = null,
        #[Autowire(service: 'limiter.search_reindex_limiter')]
        private readonly ?RateLimiterFactory $reindexLimiter = null,
        private readonly ?ApiTokenChecker $tokenChecker = null,
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

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('/api/projects/{uuid}/search', name: 'search_endpoint', methods: ['GET'])]
    public function search(string $uuid, Request $request): JsonResponse
    {
        if ($this->searchLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (!$this->searchLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('search.rate_limit_exceeded', [], 'Too many search requests. Please retry later.')], 429);
            }
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            return $this->secureJson(['error' => $this->trans('search.project_not_found', [], 'Project not found.')], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('search.project_not_found', [], 'Project not found.')], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('search.project_not_found', [], 'Project not found.')], 404);
        }

        // Cloisonnement strict multi-tenant
        $attrProject = $request->attributes->get('_project');
        if ($attrProject instanceof Project && $attrProject->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
            return $this->secureJson(['error' => $this->trans('search.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403);
        }

        $token = $this->tokenChecker?->resolve($request);
        if ($token !== null) {
            if ($token->project->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
                return $this->secureJson(['error' => $this->trans('search.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403);
            }
            if (!$token->can('read') && !$token->can('*') && !$token->can('write')) {
                return $this->secureJson(['error' => $this->trans('search.token_missing_read_ability', [], 'Forbidden: token missing read ability.')], 403);
            }
        } else {
            if ($request->attributes->get('_project') !== $project) {
                $this->denyAccessUnlessGranted('project.view', $project);
            }
        }

        $rawQuery = (string) $request->query->get('q', '');
        $query = trim(str_replace("\0", '', $rawQuery));

        if ($query === '') {
            return $this->secureJson(['error' => $this->trans('search.query_required', [], 'Search query parameter "q" is required.')], 400);
        }

        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->secureJson(['error' => $this->trans('search.query_too_long', ['%max%' => self::MAX_QUERY_LENGTH], sprintf('Search query exceeds maximum allowed length (%d characters).', self::MAX_QUERY_LENGTH))], 400);
        }

        $options = [
            'limit' => min(max(1, (int) $request->query->get('limit', 20)), self::MAX_PAGE_LIMIT),
            'offset' => min(max(0, (int) $request->query->get('offset', 0)), self::MAX_OFFSET),
        ];

        $collection = $request->query->get('collection');
        $locale = $request->query->get('locale');
        $filters = [];

        if (!empty($collection)) {
            $colStr = (string) $collection;
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $colStr)) {
                return $this->secureJson(['error' => $this->trans('search.invalid_collection_format', [], 'Invalid collection format.')], 400);
            }
            $options['collection'] = $colStr;
            $cleanColl = addslashes($colStr);
            $filters[] = "_collection = \"{$cleanColl}\"";
        }

        if (!empty($locale)) {
            $locStr = (string) $locale;
            if (!preg_match('/^[a-zA-Z]{2,4}([-_][a-zA-Z0-9]{2,8})?$/', $locStr)) {
                return $this->secureJson(['error' => $this->trans('search.invalid_locale_format', [], 'Invalid locale format.')], 400);
            }
            $options['locale'] = $locStr;
            $cleanLoc = addslashes($locStr);
            $filters[] = "locale = \"{$cleanLoc}\"";
        }

        if (!empty($filters)) {
            $options['filter'] = implode(' AND ', $filters);
        }

        $results = $this->search->search($project, $query, $options);

        return $this->secureJson($results);
    }

    #[Route('/api/projects/{uuid}/search/reindex', name: 'search_reindex', methods: ['POST'])]
    public function reindex(string $uuid, Request $request): JsonResponse
    {
        if ($this->reindexLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (!$this->reindexLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('search.reindex_rate_limit_exceeded', [], 'Reindexing rate limit exceeded. Please retry in a few minutes.')], 429);
            }
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            return $this->secureJson(['error' => $this->trans('search.project_not_found', [], 'Project not found.')], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('search.project_not_found', [], 'Project not found.')], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('search.project_not_found', [], 'Project not found.')], 404);
        }

        // Cloisonnement strict multi-tenant
        $attrProject = $request->attributes->get('_project');
        if ($attrProject instanceof Project && $attrProject->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
            return $this->secureJson(['error' => $this->trans('search.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403);
        }

        $token = $this->tokenChecker?->resolve($request);
        if ($token !== null) {
            if ($token->project->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
                return $this->secureJson(['error' => $this->trans('search.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403);
            }
            if (!$token->can('*') && !$token->can('manage') && !$token->can('write')) {
                return $this->secureJson(['error' => $this->trans('search.token_missing_manage_ability', [], 'Forbidden: token missing manage ability.')], 403);
            }
        } else {
            if ($request->attributes->get('_project') !== $project) {
                $this->denyAccessUnlessGranted('project.manage', $project);
            }
        }

        $count = $this->search->rebuildIndex($project);

        return $this->secureJson([
            'indexed' => $count,
            'success' => true,
            'message' => $this->trans('search.reindex_success', ['%count%' => $count], sprintf('Search index successfully rebuilt (%d documents indexed).', $count)),
        ]);
    }
}
