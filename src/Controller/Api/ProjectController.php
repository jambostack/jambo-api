<?php

namespace App\Controller\Api;

use App\Repository\CollectionRepository;
use App\Service\ApiTokenChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public-api', name: 'public_api_')]
class ProjectController extends AbstractController
{
    public function __construct(
        private ApiTokenChecker $tokenChecker,
        private CollectionRepository $collectionRepository,
    ) {}

    #[Route('', name: 'schema', methods: ['GET'])]
    public function schema(Request $request): JsonResponse
    {
        $token = $this->tokenChecker->resolve($request);
        if ($token === null) {
            return $this->json(['error' => 'Unauthorized. Provide a valid Bearer token.'], 401);
        }

        $project = $token->project;
        $collections = $this->collectionRepository->findByProject($project);

        return $this->json([
            'project' => [
                'uuid'          => $project->uuid->toString(),
                'name'          => $project->name,
                'defaultLocale' => $project->defaultLocale,
                'locales'       => $project->locales,
            ],
            'collections' => array_map(fn ($c) => [
                'name'        => $c->name,
                'slug'        => $c->slug,
                'isSingleton' => $c->isSingleton,
                'fields'      => array_map(fn ($f) => [
                    'name'       => $f->name,
                    'slug'       => $f->slug,
                    'type'       => $f->type,
                    'isRequired' => $f->isRequired,
                    'options'    => $f->options,
                ], $c->fields->toArray()),
            ], $collections),
        ]);
    }
}
