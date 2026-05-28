<?php

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\EndUserFieldRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;

#[Route(
    '/api/{projectId}/openapi.json',
    name: 'api_project_openapi_spec',
    requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
    methods: ['GET', 'OPTIONS'],
    priority: 20,
)]
class ProjectOpenApiController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private FieldRepository $fieldRepository,
        private EndUserFieldRepository $endUserFieldRepository,
        private ProjectMemberRepository $memberRepo,
        private ApiTokenChecker $tokenChecker,
        private Security $security,
    ) {}

    public function __invoke(Request $request, string $projectId): Response
    {
        // CORS preflight — always allow, no body
        if ($request->getMethod() === 'OPTIONS') {
            return $this->corsResponse(new Response('', 204));
        }

        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->corsResponse($this->json(['error' => 'Project not found'], 404));
        }

        if (!$this->isAuthorized($request, $project)) {
            return $this->corsResponse($this->json(['error' => 'Authentication required'], 401));
        }

        $collections   = $this->collectionRepository->findByProject($project);
        $endUserFields = $this->endUserFieldRepository->findByProject($project);
        $fieldsByCollection = $this->fieldRepository->findByCollectionsGrouped($collections);

        $etag = $this->computeEtag($project, $collections, $fieldsByCollection, $endUserFields);
        if ($request->headers->get('If-None-Match') === $etag) {
            $response = new Response('', 304);
            $response->headers->set('ETag', $etag);
            return $this->corsResponse($response);
        }

        $spec = $this->buildSpec($projectId, $project, $collections, $fieldsByCollection, $endUserFields);

        $response = $this->json($spec);
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'private, max-age=60, must-revalidate');

        return $this->corsResponse($response);
    }

    private function isAuthorized(Request $request, Project $project): bool
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                return true;
            }
            return $this->memberRepo->findActiveByUserAndProject($user, $project) !== null;
        }

        $token = $this->tokenChecker->resolve($request);
        return $token !== null && $token->project?->id === $project->id;
    }

    private function corsResponse(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, If-None-Match');
        $response->headers->set('Access-Control-Max-Age', '3600');
        return $response;
    }

    /**
     * @param array<int, \App\Entity\Field[]> $fieldsByCollection
     * @param \App\Entity\EndUserField[] $endUserFields
     */
    private function computeEtag(Project $project, array $collections, array $fieldsByCollection, array $endUserFields): string
    {
        $parts = [$project->uuid?->toString(), $project->name];
        foreach ($collections as $c) {
            $parts[] = $c->id . ':' . $c->slug;
            foreach ($fieldsByCollection[$c->id] ?? [] as $f) {
                $parts[] = $f->id . ':' . $f->slug . ':' . $f->type;
            }
        }
        foreach ($endUserFields as $f) {
            $parts[] = 'eu:' . $f->id . ':' . $f->slug . ':' . $f->type;
        }

        return '"' . sha1(implode('|', $parts)) . '"';
    }

    /**
     * @param \App\Entity\Collection[] $collections
     * @param array<int, \App\Entity\Field[]> $fieldsByCollection
     * @param \App\Entity\EndUserField[] $endUserFields
     */
    private function buildSpec(string $projectId, Project $project, array $collections, array $fieldsByCollection, array $endUserFields): array
    {
        return [
            'openapi'    => '3.0.3',
            'info'       => $this->buildInfo($project),
            'servers'    => [['url' => '/api/' . $projectId, 'description' => 'Project API base URL']],
            'components' => [
                'securitySchemes' => $this->buildSecuritySchemes(),
                'schemas'         => $this->buildSchemas($collections, $fieldsByCollection, $endUserFields),
            ],
            'paths' => [
                ...$this->buildAuthPaths(),
                ...$this->buildFilePaths(),
                ...$this->buildContentPaths($collections, $fieldsByCollection),
            ],
            'tags' => [
                ['name' => 'Auth',    'description' => 'End-user authentication'],
                ['name' => 'Files',   'description' => 'Media file management'],
                ['name' => 'Content', 'description' => 'Collection content'],
            ],
        ];
    }

    private function buildInfo(Project $project): array
    {
        return [
            'title'       => $project->name . ' API',
            'description' => 'Dynamic REST API for the "' . $project->name . '" project.',
            'version'     => '1.0.0',
        ];
    }

    private function buildSecuritySchemes(): array
    {
        return [
            'ApiToken' => [
                'type'        => 'http',
                'scheme'      => 'bearer',
                'description' => 'Static API token generated in project settings',
            ],
            'EndUserJWT' => [
                'type'         => 'http',
                'scheme'       => 'bearer',
                'bearerFormat' => 'JWT',
                'description'  => 'Short-lived JWT issued by /auth/login or /auth/register',
            ],
        ];
    }

    /**
     * @param \App\Entity\Collection[] $collections
     * @param array<int, \App\Entity\Field[]> $fieldsByCollection
     * @param \App\Entity\EndUserField[] $endUserFields
     */
    private function buildSchemas(array $collections, array $fieldsByCollection, array $endUserFields): array
    {
        $schemas = $this->commonSchemas();
        $schemas['EndUserProfile'] = $this->endUserProfileSchema($endUserFields);

        foreach ($collections as $collection) {
            $schemas[$this->toSchemaName($collection->slug)] = $this->collectionSchema($fieldsByCollection[$collection->id] ?? []);
        }

        return $schemas;
    }

    private function commonSchemas(): array
    {
        return [
            'TokenPair' => [
                'type'       => 'object',
                'properties' => [
                    'access_token'  => ['type' => 'string'],
                    'refresh_token' => ['type' => 'string'],
                ],
            ],
            'PaginatedMeta' => [
                'type'       => 'object',
                'properties' => [
                    'total'    => ['type' => 'integer'],
                    'page'     => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'pages'    => ['type' => 'integer'],
                ],
            ],
            'MediaFile' => [
                'type'       => 'object',
                'properties' => [
                    'uuid'         => ['type' => 'string', 'format' => 'uuid'],
                    'fileName'     => ['type' => 'string'],
                    'originalName' => ['type' => 'string'],
                    'mimeType'     => ['type' => 'string'],
                    'fileSize'     => ['type' => 'integer'],
                    'url'          => ['type' => 'string'],
                    'alt'          => ['type' => 'string', 'nullable' => true],
                    'caption'      => ['type' => 'string', 'nullable' => true],
                    'width'        => ['type' => 'integer', 'nullable' => true],
                    'height'       => ['type' => 'integer', 'nullable' => true],
                ],
            ],
        ];
    }

    /**
     * @param \App\Entity\EndUserField[] $endUserFields
     */
    private function endUserProfileSchema(array $endUserFields): array
    {
        $customProperties = [];
        foreach ($endUserFields as $field) {
            if (!$field->isSystem) {
                $customProperties[$field->slug] = $this->fieldTypeToSchema($field->type, $field->options);
            }
        }

        return [
            'type'       => 'object',
            'properties' => [
                'uuid'          => ['type' => 'string', 'format' => 'uuid'],
                'email'         => ['type' => 'string', 'format' => 'email'],
                'name'          => ['type' => 'string', 'nullable' => true],
                'status'        => ['type' => 'string', 'enum' => ['active', 'banned', 'pending']],
                'avatar_url'    => ['type' => 'string', 'nullable' => true],
                'custom_fields' => empty($customProperties)
                    ? ['type' => 'object']
                    : ['type' => 'object', 'properties' => $customProperties],
                'created_at'    => ['type' => 'string', 'format' => 'date-time'],
                'updated_at'    => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /** @param \App\Entity\Field[] $fields */
    private function collectionSchema(array $fields): array
    {
        $properties = [
            'uuid'       => ['type' => 'string', 'format' => 'uuid'],
            'status'     => ['type' => 'string', 'enum' => ['draft', 'published']],
            'locale'     => ['type' => 'string', 'example' => 'en'],
            'created_at' => ['type' => 'string', 'format' => 'date-time'],
            'updated_at' => ['type' => 'string', 'format' => 'date-time'],
        ];
        foreach ($fields as $field) {
            $properties[$field->slug] = $this->fieldTypeToSchema($field->type, $field->options);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function buildAuthPaths(): array
    {
        $jsonBody = fn (array $schema, bool $required = true) => [
            'required' => $required,
            'content'  => ['application/json' => ['schema' => $schema]],
        ];
        $userResp = ['$ref' => '#/components/schemas/EndUserProfile'];
        $err      = ['description' => 'Error'];
        $tokenObj = [
            'type'       => 'object',
            'properties' => [
                'user'          => $userResp,
                'access_token'  => ['type' => 'string'],
                'refresh_token' => ['type' => 'string'],
            ],
        ];
        $dataWrap = fn (array $inner) => ['application/json' => ['schema' => [
            'type' => 'object', 'properties' => ['data' => $inner],
        ]]];

        return [
            '/auth/register' => [
                'post' => [
                    'tags'        => ['Auth'],
                    'summary'     => 'Register a new end-user',
                    'requestBody' => $jsonBody([
                        'type'       => 'object',
                        'required'   => ['email', 'password'],
                        'properties' => [
                            'email'    => ['type' => 'string', 'format' => 'email'],
                            'password' => ['type' => 'string', 'minLength' => 8],
                            'name'     => ['type' => 'string', 'nullable' => true],
                        ],
                    ]),
                    'responses' => [
                        '201' => ['description' => 'User registered', 'content' => $dataWrap($tokenObj)],
                        '409' => $err + ['description' => 'Email already registered'],
                        '422' => $err + ['description' => 'Validation error'],
                    ],
                ],
            ],
            '/auth/login' => [
                'post' => [
                    'tags'        => ['Auth'],
                    'summary'     => 'Authenticate an end-user',
                    'requestBody' => $jsonBody([
                        'type'       => 'object',
                        'required'   => ['email', 'password'],
                        'properties' => [
                            'email'    => ['type' => 'string', 'format' => 'email'],
                            'password' => ['type' => 'string'],
                        ],
                    ]),
                    'responses' => [
                        '200' => ['description' => 'Login successful', 'content' => $dataWrap($tokenObj)],
                        '401' => $err + ['description' => 'Invalid credentials'],
                        '403' => $err + ['description' => 'Account banned or inactive'],
                    ],
                ],
            ],
            '/auth/refresh' => [
                'post' => [
                    'tags'        => ['Auth'],
                    'summary'     => 'Refresh access and refresh tokens',
                    'requestBody' => $jsonBody([
                        'type'       => 'object',
                        'required'   => ['refresh_token'],
                        'properties' => ['refresh_token' => ['type' => 'string']],
                    ]),
                    'responses' => [
                        '200' => ['description' => 'New token pair', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TokenPair']]]],
                        '401' => $err + ['description' => 'Invalid or expired refresh token'],
                    ],
                ],
            ],
            '/auth/me' => [
                'get' => [
                    'tags'      => ['Auth'],
                    'summary'   => 'Get authenticated end-user profile',
                    'security'  => [['EndUserJWT' => []]],
                    'responses' => [
                        '200' => ['description' => 'Current user', 'content' => $dataWrap($userResp)],
                        '401' => $err + ['description' => 'Unauthorized'],
                    ],
                ],
                'patch' => [
                    'tags'        => ['Auth'],
                    'summary'     => 'Update authenticated end-user profile',
                    'security'    => [['EndUserJWT' => []]],
                    'requestBody' => $jsonBody([
                        'type'       => 'object',
                        'properties' => [
                            'name'          => ['type' => 'string', 'nullable' => true],
                            'custom_fields' => ['type' => 'object', 'nullable' => true],
                            'password'      => ['type' => 'string', 'minLength' => 8, 'nullable' => true],
                        ],
                    ], false),
                    'responses' => [
                        '200' => ['description' => 'Updated user', 'content' => $dataWrap($userResp)],
                        '401' => $err + ['description' => 'Unauthorized'],
                    ],
                ],
            ],
            '/auth/logout' => [
                'post' => [
                    'tags'      => ['Auth'],
                    'summary'   => 'Invalidate all tokens for the authenticated end-user',
                    'security'  => [['EndUserJWT' => []]],
                    'responses' => ['204' => ['description' => 'Logged out']],
                ],
            ],
            '/auth/forgot-password' => [
                'post' => [
                    'tags'        => ['Auth'],
                    'summary'     => 'Request a password reset link',
                    'requestBody' => $jsonBody([
                        'type'       => 'object',
                        'required'   => ['email'],
                        'properties' => ['email' => ['type' => 'string', 'format' => 'email']],
                    ]),
                    'responses' => ['200' => ['description' => 'Reset link sent (if email exists)']],
                ],
            ],
            '/auth/reset-password' => [
                'post' => [
                    'tags'        => ['Auth'],
                    'summary'     => 'Reset password using a token',
                    'requestBody' => $jsonBody([
                        'type'       => 'object',
                        'required'   => ['token', 'password'],
                        'properties' => [
                            'token'    => ['type' => 'string'],
                            'password' => ['type' => 'string', 'minLength' => 8],
                        ],
                    ]),
                    'responses' => [
                        '200' => ['description' => 'Password reset successfully'],
                        '400' => $err + ['description' => 'Invalid or expired token'],
                    ],
                ],
            ],
        ];
    }

    private function buildFilePaths(): array
    {
        $mediaRef = ['$ref' => '#/components/schemas/MediaFile'];
        $idParam  = ['name' => 'identifier', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];

        return [
            '/files' => [
                'get' => [
                    'tags'       => ['Files'],
                    'summary'    => 'List media files',
                    'security'   => [['ApiToken' => []]],
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'List of files', 'content' => ['application/json' => ['schema' => [
                            'type' => 'object', 'properties' => [
                                'data' => ['type' => 'array', 'items' => $mediaRef],
                                'meta' => ['$ref' => '#/components/schemas/PaginatedMeta'],
                            ],
                        ]]]],
                    ],
                ],
                'post' => [
                    'tags'        => ['Files'],
                    'summary'     => 'Upload a media file',
                    'security'    => [['ApiToken' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['multipart/form-data' => ['schema' => [
                            'type' => 'object', 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']],
                        ]]],
                    ],
                    'responses' => [
                        '201' => ['description' => 'File uploaded', 'content' => ['application/json' => ['schema' => [
                            'type' => 'object', 'properties' => ['data' => $mediaRef],
                        ]]]],
                        '422' => ['description' => 'No file provided or invalid type'],
                    ],
                ],
            ],
            '/files/{identifier}' => [
                'get' => [
                    'tags'       => ['Files'],
                    'summary'    => 'Get a media file by UUID or filename',
                    'security'   => [['ApiToken' => []]],
                    'parameters' => [$idParam],
                    'responses'  => [
                        '200' => ['description' => 'File details', 'content' => ['application/json' => ['schema' => [
                            'type' => 'object', 'properties' => ['data' => $mediaRef],
                        ]]]],
                        '404' => ['description' => 'File not found'],
                    ],
                ],
                'delete' => [
                    'tags'       => ['Files'],
                    'summary'    => 'Delete a media file',
                    'security'   => [['ApiToken' => []]],
                    'parameters' => [$idParam],
                    'responses'  => [
                        '204' => ['description' => 'File deleted'],
                        '404' => ['description' => 'File not found'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param \App\Entity\Collection[] $collections
     * @param array<int, \App\Entity\Field[]> $fieldsByCollection
     */
    private function buildContentPaths(array $collections, array $fieldsByCollection): array
    {
        $paths = [];
        foreach ($collections as $collection) {
            $paths += $this->pathsForCollection($collection);
        }
        return $paths;
    }

    private function pathsForCollection(\App\Entity\Collection $collection): array
    {
        $slug      = $collection->slug;
        $schemaRef = ['$ref' => '#/components/schemas/' . $this->toSchemaName($slug)];
        $dataWrap  = ['application/json' => ['schema' => [
            'type' => 'object', 'properties' => ['data' => $schemaRef],
        ]]];

        if ($collection->isSingleton) {
            return ['/' . $slug => [
                'get' => [
                    'tags'      => ['Content'],
                    'summary'   => 'Get ' . $collection->name . ' (singleton)',
                    'security'  => [['ApiToken' => []]],
                    'responses' => [
                        '200' => ['description' => $collection->name, 'content' => $dataWrap],
                        '404' => ['description' => 'Entry not found'],
                    ],
                ],
                'put' => [
                    'tags'        => ['Content'],
                    'summary'     => 'Upsert ' . $collection->name . ' (singleton)',
                    'security'    => [['ApiToken' => []]],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $schemaRef]]],
                    'responses'   => [
                        '200' => ['description' => 'Updated', 'content' => $dataWrap],
                    ],
                ],
            ]];
        }

        $uuidParam = ['name' => 'uuid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];

        return [
            '/' . $slug => [
                'get' => [
                    'tags'       => ['Content'],
                    'summary'    => 'List ' . $collection->name . ' entries',
                    'security'   => [['ApiToken' => []]],
                    'parameters' => [
                        ['name' => 'page',     'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20]],
                        ['name' => 'status',   'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['draft', 'published']]],
                        ['name' => 'locale',   'in' => 'query', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'List of ' . $collection->name, 'content' => ['application/json' => ['schema' => [
                            'type' => 'object', 'properties' => [
                                'data' => ['type' => 'array', 'items' => $schemaRef],
                                'meta' => ['$ref' => '#/components/schemas/PaginatedMeta'],
                            ],
                        ]]]],
                    ],
                ],
                'post' => [
                    'tags'        => ['Content'],
                    'summary'     => 'Create a ' . $collection->name . ' entry',
                    'security'    => [['ApiToken' => []]],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $schemaRef]]],
                    'responses'   => [
                        '201' => ['description' => 'Entry created', 'content' => $dataWrap],
                        '422' => ['description' => 'Validation error'],
                    ],
                ],
            ],
            '/' . $slug . '/{uuid}' => [
                'get' => [
                    'tags'       => ['Content'],
                    'summary'    => 'Get a ' . $collection->name . ' entry',
                    'security'   => [['ApiToken' => []]],
                    'parameters' => [$uuidParam],
                    'responses'  => [
                        '200' => ['description' => $collection->name . ' entry', 'content' => $dataWrap],
                        '404' => ['description' => 'Entry not found'],
                    ],
                ],
                'patch' => [
                    'tags'        => ['Content'],
                    'summary'     => 'Update a ' . $collection->name . ' entry',
                    'security'    => [['ApiToken' => []]],
                    'parameters'  => [$uuidParam],
                    'requestBody' => ['content' => ['application/json' => ['schema' => $schemaRef]]],
                    'responses'   => [
                        '200' => ['description' => 'Updated', 'content' => $dataWrap],
                        '404' => ['description' => 'Entry not found'],
                    ],
                ],
                'delete' => [
                    'tags'       => ['Content'],
                    'summary'    => 'Delete a ' . $collection->name . ' entry',
                    'security'   => [['ApiToken' => []]],
                    'parameters' => [$uuidParam],
                    'responses'  => [
                        '204' => ['description' => 'Entry deleted'],
                        '404' => ['description' => 'Entry not found'],
                    ],
                ],
            ],
        ];
    }

    private function fieldTypeToSchema(string $type, ?array $options): array
    {
        return match ($type) {
            'number'      => ['type' => 'number'],
            'integer'     => ['type' => 'integer'],
            'boolean'     => ['type' => 'boolean'],
            'date'        => ['type' => 'string', 'format' => 'date'],
            'datetime'    => ['type' => 'string', 'format' => 'date-time'],
            'email'       => ['type' => 'string', 'format' => 'email'],
            'url'         => ['type' => 'string', 'format' => 'uri'],
            'json'        => ['type' => 'object'],
            'multiselect' => ['type' => 'array', 'items' => ['type' => 'string']],
            'select'      => $this->selectSchema($options),
            'media'       => ['type' => 'string', 'format' => 'uuid', 'description' => 'Media file UUID'],
            'relation'    => ['type' => 'string', 'format' => 'uuid', 'description' => 'Related entry UUID'],
            default       => ['type' => 'string'],
        };
    }

    private function selectSchema(?array $options): array
    {
        $choices = $options['choices'] ?? [];
        if (empty($choices)) {
            return ['type' => 'string'];
        }

        $values = array_column($choices, 'value') ?: array_values($choices);
        $values = array_values(array_filter($values, 'is_string'));

        return empty($values)
            ? ['type' => 'string']
            : ['type' => 'string', 'enum' => $values];
    }

    private function toSchemaName(string $slug): string
    {
        $name = str_replace(['-', '_'], '', ucwords($slug, '-_'));
        // OpenAPI schema names must start with a letter
        return preg_match('/^[A-Za-z]/', $name) === 1 ? $name : 'Schema' . $name;
    }
}
