<?php

namespace App\Controller\Api;

use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint public de découverte d'un projet par son UUID :
 *   GET /api/{projectId}
 *
 * Renvoie les infos du projet + la liste de ses collections (avec leurs champs),
 * en respectant le flag `publicApi`. Pratique pour découvrir l'API sans connaître
 * à l'avance les slugs de collections — complète `GET /public-api` (résolu par token).
 *
 * La contrainte UUID sur {projectId} évite tout conflit avec les routes littérales
 * (`/api/docs`, `/api/projects/...`) et avec `/api/{projectId}/{collectionSlug}`.
 */
#[OA\Tag(name: 'Project')]
#[Route('/api/{projectId}', name: 'public_api_project_info_', requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
class ProjectInfoController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
    ) {}

    #[OA\Get(
        path: '/api/{projectId}',
        summary: 'Get project info + collections (public discovery)',
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project info + collections',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'project', properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'defaultLocale', type: 'string', example: 'en'),
                        new OA\Property(property: 'locales', type: 'array', items: new OA\Items(type: 'string')),
                    ]),
                    new OA\Property(property: 'collections', type: 'array', items: new OA\Items(ref: '#/components/schemas/Collection')),
                ])
            ),
            new OA\Response(response: 403, description: 'Public API disabled', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('', name: 'show', methods: ['GET'])]
    public function show(string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        if (!$project->publicApi) {
            return $this->json(['error' => 'Public API access is disabled for this project.'], 403);
        }

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
