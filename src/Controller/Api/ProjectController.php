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

#[OA\Tag(name: 'Project')]
#[Route('/public-api', name: 'public_api_')]
class ProjectController extends AbstractController
{
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

    #[OA\Get(
        path: '/public-api',
        summary: 'Get project schema — project info + all collections with fields',
        security: [['ApiToken' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project schema',
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
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('', name: 'schema', methods: ['GET'])]
    public function schema(Request $request): JsonResponse
    {
        $token = $this->tokenChecker->resolve($request);
        if ($token === null) {
            return $this->secureJson(['error' => $this->trans('api_project.unauthorized', [], 'Unauthorized. Provide a valid Bearer token.')], 401);
        }
        if (!$this->tokenChecker->isAllowedForMethod($token, $request->getMethod())) {
            return $this->secureJson(['error' => $this->trans('api_project.insufficient_abilities', [], 'Insufficient token abilities.')], 403);
        }

        if ($this->projectLimiter !== null) {
            $identifier = $token->tokenHash ?? ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('api_project.rate_limit_exceeded', [], 'Too many requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        $project = $token->project;
        $collections = $this->collectionRepository->findByProject($project);

        return $this->secureJson([
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
