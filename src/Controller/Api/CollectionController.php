<?php

namespace App\Controller\Api;

use App\Repository\CollectionRepository;
use App\Service\ApiTokenChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public-api/collections', name: 'public_api_collections_')]
class CollectionController extends AbstractController
{
    public function __construct(
        private ApiTokenChecker $tokenChecker,
        private CollectionRepository $collectionRepository,
    ) {}

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
