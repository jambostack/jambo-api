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
    public const MIN_QUERY_LENGTH = 1;
    public const MAX_QUERY_LENGTH = 500;
    public const MAX_QUERY_TERMS = 25;
    public const MAX_NESTING_DEPTH = 3;
    public const MAX_PAGE_LIMIT = 100;
    public const DEFAULT_PAGE_LIMIT = 20;
    public const MAX_OFFSET = 10000;

    public const ALLOWED_STATUSES = ['published', 'draft', 'archived', 'scheduled'];
    public const ALLOWED_SORT_FIELDS = ['created_at', 'updated_at', 'published_at', 'status', 'slug'];

    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                 => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
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
        private readonly ?\App\Service\AuditService $auditService = null,
    ) {}

    private function resolveIdentifier(Request $request, ?\App\Entity\ApiToken $token = null): string
    {
        try {
            $user = $this->getUser();
            if ($user !== null) {
                return 'user_' . $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        if ($token !== null) {
            return 'token_' . $token->id;
        }

        return $request->getClientIp() ?: '127.0.0.1';
    }

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
        $token = $this->tokenChecker?->resolve($request);
        $identifier = $this->resolveIdentifier($request, $token);

        if ($this->searchLimiter !== null) {
            if (!$this->searchLimiter->create($identifier)->consume(1)->isAccepted()) {
                $this->auditService?->log(
                    toolName: 'search_rate_limit_exceeded',
                    project: null,
                    input: ['identifier' => $identifier],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Search rate limit exceeded',
                    createdBy: $identifier,
                    source: 'search_controller',
                );
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
            $this->auditService?->log(
                toolName: 'search_tenant_mismatch',
                project: $project,
                input: ['uuid' => $uuid, 'attr_project' => $attrProject->uuid?->toRfc4122()],
                output: null,
                status: 'failed',
                errorMessage: 'Forbidden: token belongs to another project',
                createdBy: $identifier,
                source: 'search_controller',
            );
            return $this->secureJson(['error' => $this->trans('search.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403);
        }

        if ($token !== null) {
            if ($token->project->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
                $this->auditService?->log(
                    toolName: 'search_tenant_mismatch',
                    project: $project,
                    input: ['uuid' => $uuid, 'token_project' => $token->project->uuid?->toRfc4122()],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Forbidden: token belongs to another project',
                    createdBy: $identifier,
                    source: 'search_controller',
                );
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

        // 1. Validation stricte du paramètre de recherche full-text `q`
        $allQuery = $request->query->all();
        if (!array_key_exists('q', $allQuery)) {
            return $this->secureJson(['error' => $this->trans('search.query_required', [], 'Search query parameter "q" is required.')], 400);
        }

        $rawQuery = $allQuery['q'];
        if (!is_string($rawQuery)) {
            return $this->secureJson(['error' => $this->trans('search.invalid_query_format', [], 'Invalid search query format.')], 400);
        }

        // Éliminer les caractères de contrôle binaires dangereux
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawQuery);
        $query = trim((string) $clean);

        if ($query === '') {
            return $this->secureJson(['error' => $this->trans('search.query_required', [], 'Search query parameter "q" is required.')], 400);
        }

        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->secureJson(['error' => $this->trans('search.query_too_long', ['%max%' => self::MAX_QUERY_LENGTH], sprintf('Search query exceeds maximum allowed length (%d characters).', self::MAX_QUERY_LENGTH))], 400);
        }

        // Protection DoS : exiger au moins un caractère cherchable (lettre ou chiffre Unicode)
        if (!preg_match('/[\p{L}\p{N}]/u', $query)) {
            return $this->secureJson(['error' => $this->trans('search.query_no_searchable_character', [], 'Search query must contain at least one searchable letter or number.')], 400);
        }

        // Limitation de la complexité des requêtes (nombre de termes max)
        $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($terms) > self::MAX_QUERY_TERMS) {
            return $this->secureJson(['error' => $this->trans('search.query_too_complex', ['%max%' => self::MAX_QUERY_TERMS], sprintf('Search query exceeds maximum allowed term count (%d terms).', self::MAX_QUERY_TERMS))], 400);
        }

        // Protection contre l'épuisement CPU par imbrication profonde de parenthèses
        $depth = 0;
        $maxDepth = 0;
        $len = strlen($query);
        for ($i = 0; $i < $len; $i++) {
            if ($query[$i] === '(') {
                $depth++;
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                }
            } elseif ($query[$i] === ')') {
                $depth = max(0, $depth - 1);
            }
        }
        if ($maxDepth > self::MAX_NESTING_DEPTH) {
            return $this->secureJson(['error' => $this->trans('search.query_nesting_too_deep', ['%max%' => self::MAX_NESTING_DEPTH], sprintf('Search query parenthesis nesting exceeds allowed depth (%d levels).', self::MAX_NESTING_DEPTH))], 400);
        }

        // Réduction des répétitions excessives de wildcards pouvant provoquer des ReDoS
        $query = (string) preg_replace('/([%_*?~]){5,}/', '$1$1$1', $query);

        // 2. Pagination plafonnée (limit, offset, page)
        if (array_key_exists('limit', $allQuery)) {
            $rawLimit = $allQuery['limit'];
            if (!is_scalar($rawLimit) || !is_numeric($rawLimit) || (int) $rawLimit < 1) {
                return $this->secureJson(['error' => $this->trans('search.invalid_limit_format', [], 'Invalid search limit parameter.')], 400);
            }
            $limit = min((int) $rawLimit, self::MAX_PAGE_LIMIT);
        } else {
            $limit = self::DEFAULT_PAGE_LIMIT;
        }

        if (array_key_exists('offset', $allQuery)) {
            $rawOffset = $allQuery['offset'];
            if (!is_scalar($rawOffset) || !is_numeric($rawOffset) || (int) $rawOffset < 0) {
                return $this->secureJson(['error' => $this->trans('search.invalid_offset_format', [], 'Invalid search offset parameter.')], 400);
            }
            $offset = (int) $rawOffset;
            if ($offset > self::MAX_OFFSET) {
                return $this->secureJson(['error' => $this->trans('search.offset_too_high', ['%max%' => self::MAX_OFFSET], sprintf('Search offset exceeds maximum allowed limit (%d).', self::MAX_OFFSET))], 400);
            }
        } elseif (array_key_exists('page', $allQuery)) {
            $rawPage = $allQuery['page'];
            if (!is_scalar($rawPage) || !is_numeric($rawPage) || (int) $rawPage < 1) {
                return $this->secureJson(['error' => $this->trans('search.invalid_page_format', [], 'Invalid search page parameter.')], 400);
            }
            $offset = ((int) $rawPage - 1) * $limit;
            if ($offset > self::MAX_OFFSET) {
                return $this->secureJson(['error' => $this->trans('search.offset_too_high', ['%max%' => self::MAX_OFFSET], sprintf('Search offset exceeds maximum allowed limit (%d).', self::MAX_OFFSET))], 400);
            }
        } else {
            $offset = 0;
        }

        $options = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        // 3. Filtres sécurisés (collection, locale, status, sort)
        $filters = [];

        if (array_key_exists('collection', $allQuery) && $allQuery['collection'] !== null && $allQuery['collection'] !== '') {
            $rawCol = $allQuery['collection'];
            if (!is_string($rawCol) || !preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $rawCol)) {
                return $this->secureJson(['error' => $this->trans('search.invalid_collection_format', [], 'Invalid collection format.')], 400);
            }
            $options['collection'] = $rawCol;
            $cleanColl = addslashes($rawCol);
            $filters[] = "_collection = \"{$cleanColl}\"";
        }

        if (array_key_exists('locale', $allQuery) && $allQuery['locale'] !== null && $allQuery['locale'] !== '') {
            $rawLoc = $allQuery['locale'];
            if (!is_string($rawLoc) || strlen($rawLoc) > 16 || !preg_match('/^[a-zA-Z]{2,4}([-_][a-zA-Z0-9]{2,8})?$/', $rawLoc)) {
                return $this->secureJson(['error' => $this->trans('search.invalid_locale_format', [], 'Invalid locale format.')], 400);
            }
            $options['locale'] = $rawLoc;
            $cleanLoc = addslashes($rawLoc);
            $filters[] = "locale = \"{$cleanLoc}\"";
        }

        if (array_key_exists('status', $allQuery) && $allQuery['status'] !== null && $allQuery['status'] !== '') {
            $rawStatus = $allQuery['status'];
            if (!is_string($rawStatus) || !in_array(strtolower($rawStatus), self::ALLOWED_STATUSES, true)) {
                return $this->secureJson(['error' => $this->trans('search.invalid_status_format', [], 'Invalid status filter format. Allowed values: published, draft, archived, scheduled.')], 400);
            }
            $cleanStatus = strtolower($rawStatus);
            $options['status'] = $cleanStatus;
            $filters[] = "status = \"{$cleanStatus}\"";
        }

        if (array_key_exists('sort', $allQuery) && $allQuery['sort'] !== null && $allQuery['sort'] !== '') {
            $rawSort = $allQuery['sort'];
            if (!is_string($rawSort)) {
                return $this->secureJson(['error' => $this->trans('search.invalid_sort_format', [], 'Invalid sort parameter format or disallowed sort field.')], 400);
            }
            $parts = explode(':', trim($rawSort), 2);
            $field = strtolower(trim($parts[0]));
            $direction = isset($parts[1]) ? strtolower(trim($parts[1])) : 'desc';
            if (!in_array($field, self::ALLOWED_SORT_FIELDS, true) || !in_array($direction, ['asc', 'desc'], true)) {
                return $this->secureJson(['error' => $this->trans('search.invalid_sort_format', [], 'Invalid sort parameter format or disallowed sort field.')], 400);
            }
            $options['sort'] = ["{$field}:{$direction}"];
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
        $token = $this->tokenChecker?->resolve($request);
        $identifier = $this->resolveIdentifier($request, $token);

        if ($this->reindexLimiter !== null) {
            if (!$this->reindexLimiter->create($identifier)->consume(1)->isAccepted()) {
                $this->auditService?->log(
                    toolName: 'search_reindex_rate_limit_exceeded',
                    project: null,
                    input: ['identifier' => $identifier],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Reindexing rate limit exceeded',
                    createdBy: $identifier,
                    source: 'search_controller',
                );
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
            $this->auditService?->log(
                toolName: 'search_tenant_mismatch',
                project: $project,
                input: ['uuid' => $uuid, 'attr_project' => $attrProject->uuid?->toRfc4122()],
                output: null,
                status: 'failed',
                errorMessage: 'Forbidden: token belongs to another project',
                createdBy: $identifier,
                source: 'search_controller',
            );
            return $this->secureJson(['error' => $this->trans('search.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403);
        }

        if ($token !== null) {
            if ($token->project->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
                $this->auditService?->log(
                    toolName: 'search_tenant_mismatch',
                    project: $project,
                    input: ['uuid' => $uuid, 'token_project' => $token->project->uuid?->toRfc4122()],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Forbidden: token belongs to another project',
                    createdBy: $identifier,
                    source: 'search_controller',
                );
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

        // Contrôle des quotas de documents indexables par forfait/tier
        $quotaLimit = $this->search->getProjectSearchDocLimit($project);
        $totalEntries = $this->search->getProjectIndexedCount($project);
        if ($totalEntries > $quotaLimit) {
            $this->auditService?->log(
                toolName: 'search_reindex_quota_exceeded',
                project: $project,
                input: ['quota_limit' => $quotaLimit, 'total_entries' => $totalEntries],
                output: null,
                status: 'failed',
                errorMessage: 'Search index document quota exceeded',
                createdBy: $identifier,
                source: 'search_controller',
            );
            return $this->secureJson([
                'error' => $this->trans('search.quota.document_limit_exceeded', [
                    '%limit%' => (string) $quotaLimit,
                    '%count%' => (string) $totalEntries,
                ], sprintf('Search index document quota exceeded for this tier (%d/%d documents).', $totalEntries, $quotaLimit)),
                'code' => 'SEARCH_DOCUMENT_LIMIT_EXCEEDED',
                'limit' => $quotaLimit,
                'count' => $totalEntries,
            ], 422);
        }

        $startTime = microtime(true);
        $count = $this->search->rebuildIndex($project);
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        $this->auditService?->log(
            toolName: 'search_reindex',
            project: $project,
            input: ['uuid' => $uuid],
            output: ['indexed' => $count, 'limit' => $quotaLimit],
            status: 'success',
            createdBy: $identifier,
            source: 'search_controller',
            durationMs: $durationMs,
        );

        return $this->secureJson([
            'indexed' => $count,
            'limit' => $quotaLimit,
            'success' => true,
            'message' => $this->trans('search.reindex_success', ['%count%' => $count], sprintf('Search index successfully rebuilt (%d documents indexed).', $count)),
        ]);
    }
}
