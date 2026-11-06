<?php

namespace App\Controller\Api;

use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private ProjectRepository $projectRepository,
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
    public function show(string $projectId, Request $request): JsonResponse
    {
        if ($this->projectLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            $limit = $this->projectLimiter->create($identifier)->consume(1);
            if (false === $limit->isAccepted()) {
                $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
                return $this->secureJson([
                    'error' => $this->trans('api_project_info.rate_limit_exceeded', [], 'Too many requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                    'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                    'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
                ]);
            }
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('api_project_info.not_found', [], 'Project not found.')], 404);
        }
        if ($project === null) {
            return $this->secureJson(['error' => $this->trans('api_project_info.not_found', [], 'Project not found.')], 404);
        }

        if (!$project->publicApi) {
            return $this->secureJson(['error' => $this->trans('api_project_info.public_disabled', [], 'Public API access is disabled for this project.')], 403);
        }

        $collections = $this->collectionRepository->findByProject($project);

        return $this->secureJson([
            'project' => [
                'uuid'          => $project->uuid?->toString(),
                'name'          => $project->name,
                'defaultLocale' => $project->defaultLocale,
                'locales'       => $project->locales,
            ],
            'collections' => array_map(fn ($c) => [
                'name'        => $c->name,
                'slug'        => $c->slug,
                'isSingleton' => $c->isSingleton,
                'publicCreate' => $c->settings['public_create'] ?? false,
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
