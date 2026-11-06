<?php

namespace App\Controller\Api;

use App\Repository\CollectionRepository;
use App\Service\ApiTokenChecker;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[OA\Tag(name: 'Collections')]
#[Route('/public-api/collections', name: 'public_api_collections_')]
class CollectionController extends AbstractController
{
    public const MAX_SLUG_LENGTH = 128;

    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                 => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    public function __construct(
        private ApiTokenChecker $tokenChecker,
        private CollectionRepository $collectionRepository,
        private ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.project_limiter')]
        private ?RateLimiterFactory $projectLimiter = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->projectLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            if (!$this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('api_collection.rate_limit_exceeded', [], 'Too many requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    #[OA\Get(
        path: '/public-api/collections',
        summary: 'List all collections for the authenticated project',
        security: [['ApiToken' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, maximum: 200)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of collections', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Collection')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginatedMeta'),
            ])),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $token = $this->tokenChecker->resolve($request);
        if ($token === null) {
            return $this->secureJson(['error' => $this->trans('api_collection.unauthorized', [], 'Unauthorized.')], 401);
        }
        if (!$this->tokenChecker->isAllowedForMethod($token, $request->getMethod())) {
            return $this->secureJson(['error' => $this->trans('api_collection.insufficient_abilities', [], 'Insufficient token abilities.')], 403);
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(200, max(1, $request->query->getInt('per_page', 50)));

        $collections = $this->collectionRepository->findByProjectPaginated($token->project, $page, $perPage);
        $total       = $this->collectionRepository->countByProject($token->project);

        return $this->secureJson([
            'data' => array_map(fn ($c) => [
                'name'        => $c->name,
                'slug'        => $c->slug,
                'description' => $c->description,
                'isSingleton' => $c->isSingleton,
                'fields'      => array_map(fn ($f) => [
                    'name'       => $f->name,
                    'slug'       => $f->slug,
                    'type'       => $f->type,
                    'isRequired' => $f->isRequired,
                ], array_values($c->fields->filter(fn ($f) => !$f->isDeleted())->toArray())),
            ], $collections),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    #[OA\Get(
        path: '/public-api/collections/{slug}',
        summary: 'Get a single collection with its fields',
        security: [['ApiToken' => []]],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Collection found', content: new OA\JsonContent(ref: '#/components/schemas/Collection')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Collection not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(Request $request, string $slug): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (strlen($slug) > self::MAX_SLUG_LENGTH || !preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
            return $this->secureJson(['error' => $this->trans('api_collection.not_found', [], 'Collection not found.')], 404);
        }

        $token = $this->tokenChecker->resolve($request);
        if ($token === null) {
            return $this->secureJson(['error' => $this->trans('api_collection.unauthorized', [], 'Unauthorized.')], 401);
        }
        if (!$this->tokenChecker->isAllowedForMethod($token, $request->getMethod())) {
            return $this->secureJson(['error' => $this->trans('api_collection.insufficient_abilities', [], 'Insufficient token abilities.')], 403);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($token->project, $slug);
        if ($collection === null) {
            return $this->secureJson(['error' => $this->trans('api_collection.not_found', [], 'Collection not found.')], 404);
        }

        return $this->secureJson([
            'name'        => $collection->name,
            'slug'        => $collection->slug,
            'description' => $collection->description,
            'isSingleton' => $collection->isSingleton,
            'workflow'    => $collection->settings['workflow'] ?? [
                'statuses'      => $collection->getWorkflowStatuses(),
                'defaultStatus' => $collection->getDefaultStatus(),
            ],
            'fields'      => array_map(fn ($f) => [
                'name'       => $f->name,
                'slug'       => $f->slug,
                'type'       => $f->type,
                'isRequired' => $f->isRequired,
                'options'    => $f->options,
            ], array_values($collection->fields->filter(fn ($f) => !$f->isDeleted())->toArray())),
        ]);
    }
}
