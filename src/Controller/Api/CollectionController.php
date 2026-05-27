<?php

namespace App\Controller\Api;

use App\Repository\CollectionRepository;
use App\Service\ApiTokenChecker;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Collections')]
#[Route('/public-api/collections', name: 'public_api_collections_')]
class CollectionController extends AbstractController
{
    public function __construct(
        private ApiTokenChecker $tokenChecker,
        private CollectionRepository $collectionRepository,
    ) {}

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
        $token = $this->tokenChecker->resolve($request);
        if ($token === null) {
            return $this->json(['error' => 'Unauthorized.'], 401);
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(200, max(1, $request->query->getInt('per_page', 50)));

        $collections = $this->collectionRepository->findByProjectPaginated($token->project, $page, $perPage);
        $total       = $this->collectionRepository->countByProject($token->project);

        return $this->json([
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
                ], $c->fields->filter(fn ($f) => !$f->isDeleted())->toArray()),
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
        $token = $this->tokenChecker->resolve($request);
        if ($token === null) {
            return $this->json(['error' => 'Unauthorized.'], 401);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($token->project, $slug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found.'], 404);
        }

        return $this->json([
            'name'        => $collection->name,
            'slug'        => $collection->slug,
            'description' => $collection->description,
            'isSingleton' => $collection->isSingleton,
            'fields'      => array_map(fn ($f) => [
                'name'       => $f->name,
                'slug'       => $f->slug,
                'type'       => $f->type,
                'isRequired' => $f->isRequired,
                'options'    => $f->options,
            ], $collection->fields->filter(fn ($f) => !$f->isDeleted())->toArray()),
        ]);
    }
}
