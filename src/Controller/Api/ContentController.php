<?php

namespace App\Controller\Api;

use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Media;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\FieldRepository;
use App\Repository\EndUserRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\EavDataFormatterService;
use App\Service\EavFieldHelperService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[OA\Tag(name: 'Content')]
#[Route('/api/{projectId}/{collectionSlug}', name: 'public_api_content_', requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
class ContentController extends AbstractController
{
    public function __construct(
        private ApiTokenChecker $tokenChecker,
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private ContentEntryRepository $entryRepository,
        private FieldRepository $fieldRepository,
        private MediaRepository $mediaRepository,
        private EndUserRepository $endUserRepository,
        private EavDataFormatterService $formatter,
        private EntityManagerInterface $em,
        private \App\Repository\ProjectMemberRepository $memberRepo,
        private \App\Service\FieldValueHydrator $fieldValueHydrator,
        private EavFieldHelperService $fieldValidator,
        private SluggerInterface $slugger,
    ) {}

    #[OA\Get(
        path: '/api/{projectId}/{collectionSlug}',
        summary: 'List content entries',
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'collectionSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'locale', in: 'query', schema: new OA\Schema(type: 'string', example: 'en')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'published', 'scheduled'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of entries', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContentEntry')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginatedMeta'),
            ])),
            new OA\Response(response: 403, description: 'Public API disabled', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Project or collection not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, string $projectId, string $collectionSlug): JsonResponse
    {
        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found.'], 404);
        }

        $locale  = $request->query->getString('locale', $project->defaultLocale);
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 15)));
        $status  = $request->query->getString('status', 'published');
        $search  = $request->query->getString('search', '');
        $sort    = $request->query->getString('sort', 'created_at:desc');
        $dateFrom = $request->query->getString('date_from', '');
        $dateTo   = $request->query->getString('date_to', '');

        $allStatuses = array_column($collection->getWorkflowStatuses(), 'slug');
        $allStatuses[] = 'draft';
        $allStatuses[] = 'scheduled';
        $statusFilter = in_array($status, $allStatuses, true) ? $status : null;

        $entries = $this->entryRepository->findByCollectionPaginated(
            $collection, $page, $perPage, $locale, $statusFilter, $search, $sort, $dateFrom, $dateTo
        );
        $total = $this->entryRepository->countByCollection($collection, $locale, $statusFilter, $search, $dateFrom, $dateTo);

        return $this->json([
            'data' => array_values(array_map(fn ($e) => $this->formatter->formatEntry($e), $entries)),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/{projectId}/{collectionSlug}/{uuid}',
        summary: 'Get a single content entry',
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'collectionSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'locale', in: 'query', schema: new OA\Schema(type: 'string', example: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Entry found', content: new OA\JsonContent(ref: '#/components/schemas/ContentEntry')),
            new OA\Response(response: 404, description: 'Entry not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(Request $_request, string $projectId, string $collectionSlug, string $uuid): JsonResponse
    {
        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found.'], 404);
        }

        $entry = $this->entryRepository->findOneBy(['uuid' => $uuid, 'collection' => $collection, 'deletedAt' => null]);
        if ($entry === null) {
            return $this->json(['error' => 'Entry not found.'], 404);
        }

        return $this->json($this->formatter->formatEntry($entry));
    }

    #[OA\Post(
        path: '/api/{projectId}/{collectionSlug}',
        summary: 'Create a content entry',
        security: [['ApiToken' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'scheduled'], default: 'draft'),
                new OA\Property(property: 'locale', type: 'string', example: 'en'),
            ],
            additionalProperties: new OA\AdditionalProperties(description: 'Dynamic field values keyed by field slug')
        )),
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'collectionSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Entry created', content: new OA\JsonContent(ref: '#/components/schemas/ContentEntry')),
            new OA\Response(response: 401, description: 'Unauthorized — token requires write ability', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request, string $projectId, string $collectionSlug): JsonResponse
    {
        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found.'], 404);
        }

        // Vérifier l'authentification : soit token API, soit collection en public_create
        $token = $this->tokenChecker->resolve($request);
        $publicCreate = $collection->settings['public_create'] ?? false;

        if ($token === null && !$publicCreate) {
            return $this->json(['error' => 'Authentication required. Provide an API token or enable public_create on the collection.'], 401);
        }

        if ($token !== null && $token->project->uuid->toString() !== $projectId) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        if ($token !== null && !$token->can('create')) {
            return $this->json(['error' => 'Unauthorized. Token requires create ability.'], 401);
        }

        // Accepter JSON ou multipart/form-data
        $contentType = $request->headers->get('Content-Type', '');
        $isMultipart = str_starts_with($contentType, 'multipart/form-data');

        if ($isMultipart) {
            $data = $request->request->all();
            $uploadedFiles = $request->files->all();
        } else {
            $data = $request->toArray();
            $uploadedFiles = [];
        }

        $locale = $data['locale'] ?? $token?->project->defaultLocale ?? $project->defaultLocale;

        $entry = new ContentEntry();
        $entry->project    = $project;
        $entry->collection = $collection;
        $entry->locale     = $locale;
        $status = $data['status'] ?? $collection->getDefaultStatus();
        $statuses = $collection->getWorkflowStatuses();
        $systemStatuses = ['draft', 'scheduled'];
        $validStatuses = array_merge(array_column($statuses, 'slug'), $systemStatuses);
        if (!in_array($status, $validStatuses, true)) {
            return $this->json(['errors' => ['status' => 'Invalid status for this collection.']], 422);
        }
        $entry->status = $status;

        // Handle assignment
        if (isset($data['assigned_to_id'])) {
            $assignee = $this->em->getRepository(\App\Entity\User::class)->find($data['assigned_to_id']);
            if ($assignee !== null && $this->memberRepo->findActiveByUserAndProject($assignee, $token->project) !== null) {
                $entry->assignedTo = $assignee;
            } elseif ($assignee !== null) {
                return $this->json(['errors' => ['assigned_to_id' => 'User is not a member of this project.']], 422);
            }
        }

        if ($entry->status === 'scheduled' && isset($data['scheduledAt'])) {
            $entry->scheduledAt = new \DateTimeImmutable($data['scheduledAt']);
        }
        if ($entry->status === 'scheduled' && $entry->scheduledAt === null) {
            return $this->json(['error' => 'scheduledAt is required when status is scheduled.'], 422);
        }

        // Si multipart : uploader les fichiers et les remplacer par leurs UUIDs
        if ($isMultipart && $uploadedFiles !== []) {
            $fields = $this->fieldRepository->findByCollection($collection);
            foreach ($fields as $field) {
                $fieldSlug = $field->slug;
                if (!isset($uploadedFiles[$fieldSlug]) || !$uploadedFiles[$fieldSlug] instanceof UploadedFile) {
                    continue;
                }
                $file = $uploadedFiles[$fieldSlug];
                if (!$file->isValid()) {
                    continue;
                }
                $media = new Media();
                $media->project = $project;
                $media->originalName = $file->getClientOriginalName();
                $media->setFile($file);
                $this->em->persist($media);
                $this->em->flush();

                // Remplacer le fichier par son UUID media
                $data[$fieldSlug] = [$media->uuid->toString()];

                // Remplir les champs *_filename et *_size si présents
                if (array_key_exists($fieldSlug . '_filename', $data)) {
                    $data[$fieldSlug . '_filename'] = $file->getClientOriginalName();
                }
                if (array_key_exists($fieldSlug . '_size', $data)) {
                    $data[$fieldSlug . '_size'] = $file->getSize();
                }
            }
        }

        $this->hydrateFieldValues($entry, $data, $collection, $project);

        // Validation des champs
        $validationErrors = [];
        foreach ($collection->fields as $field) {
            if ($field->isDeleted()) {
                continue;
            }
            $fieldValue = $data[$field->slug] ?? null;
            $fieldErrors = $this->fieldValidator->validateFieldValue($field, $fieldValue);
            if (!empty($fieldErrors)) {
                $validationErrors[$field->slug] = $fieldErrors[0];
            }
        }
        if (!empty($validationErrors)) {
            return $this->json(['errors' => $validationErrors], 422);
        }

        // Vérification d'unicité
        foreach ($collection->fields as $field) {
            if ($field->isDeleted()) continue;
            $rules = $field->validationRules;
            if (empty($rules['unique'])) continue;

            $fieldValue = $data[$field->slug] ?? null;
            if ($fieldValue === null || $fieldValue === '' || $fieldValue === []) continue;

            $qb = $this->em->createQueryBuilder();
            $qb->select('COUNT(cfv.id)')
               ->from(ContentFieldValue::class, 'cfv')
               ->join('cfv.contentEntry', 'ce')
               ->where('ce.collection = :collection')
               ->andWhere('cfv.field = :field')
               ->andWhere('cfv.textValue = :value')
               ->andWhere('ce.deletedAt IS NULL')
               ->setParameter('collection', $collection)
               ->setParameter('field', $field)
               ->setParameter('value', (string)$fieldValue);

            if (isset($entry)) {
                $qb->andWhere('ce.id != :entryId')
                   ->setParameter('entryId', $entry->id);
            }

            $count = $qb->getQuery()->getSingleScalarResult();
            if ($count > 0) {
                $validationErrors[$field->slug] = sprintf(
                    'La valeur "%s" existe déjà pour le champ "%s".',
                    (string)$fieldValue,
                    $field->name
                );
            }
        }
        if (!empty($validationErrors)) {
            return $this->json(['errors' => $validationErrors], 422);
        }

        // Generate slug from data or first meaningful field value
        if (!empty($data['slug'])) {
            $entry->slug = (string) $this->slugger->slug($data['slug'])->lower()->truncate(50, '');
        } elseif (!empty($data)) {
            // Fallback: build slug from first text field value
            $firstValue = reset($data);
            if (is_string($firstValue) && strlen($firstValue) > 0) {
                $entry->slug = (string) $this->slugger->slug($firstValue)->lower()->truncate(45, '');
            } else {
                $entry->slug = 'entry-' . bin2hex(random_bytes(4));
            }
        } else {
            $entry->slug = 'entry-' . bin2hex(random_bytes(4));
        }

        $this->em->persist($entry);
        $this->em->flush();

        return $this->json($this->formatter->formatEntry($entry), 201);
    }

    #[OA\Patch(
        path: '/api/{projectId}/{collectionSlug}/{uuid}',
        summary: 'Update a content entry (partial)',
        security: [['ApiToken' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'scheduled']),
                new OA\Property(property: 'locale', type: 'string'),
            ],
            additionalProperties: new OA\AdditionalProperties(description: 'Field values to update')
        )),
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'collectionSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Entry updated', content: new OA\JsonContent(ref: '#/components/schemas/ContentEntry')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Entry not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $projectId, string $collectionSlug, string $uuid): JsonResponse
    {
        $token = $this->tokenChecker->resolve($request);
        if ($token === null || !$token->can('create')) {
            return $this->json(['error' => 'Unauthorized. Token requires write ability.'], 401);
        }

        if ($token->project->uuid->toString() !== $projectId) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($token->project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found.'], 404);
        }

        $entry = $this->entryRepository->findOneBy(['uuid' => $uuid, 'collection' => $collection, 'deletedAt' => null]);
        if ($entry === null) {
            return $this->json(['error' => 'Entry not found.'], 404);
        }

        $data = $request->toArray();
        if (isset($data['status'])) {
            $status = $data['status'];
            $statuses = $collection->getWorkflowStatuses();
            $validStatuses = array_column($statuses, 'slug');
            $validStatuses[] = 'scheduled';
            if (!in_array($status, $validStatuses, true)) {
                return $this->json(['errors' => ['status' => 'Invalid status for this collection.']], 422);
            }
            $entry->status = $status;
        }
        if ($entry->status === 'scheduled' && isset($data['scheduledAt'])) {
            $entry->scheduledAt = new \DateTimeImmutable($data['scheduledAt']);
        }
        if ($entry->status === 'scheduled' && $entry->scheduledAt === null) {
            return $this->json(['error' => 'scheduledAt is required when status is scheduled.'], 422);
        }
        if (isset($data['locale'])) {
            $entry->locale = $data['locale'];
        }

        // Handle assignment
        if (isset($data['assigned_to_id'])) {
            $assignee = $this->em->getRepository(\App\Entity\User::class)->find($data['assigned_to_id']);
            if ($assignee !== null && $this->memberRepo->findActiveByUserAndProject($assignee, $token->project) !== null) {
                $entry->assignedTo = $assignee;
            } elseif ($assignee !== null) {
                return $this->json(['errors' => ['assigned_to_id' => 'User is not a member of this project.']], 422);
            }
        }

        $this->hydrateFieldValues($entry, $data, $collection, $token->project);

        // Validation des champs
        $validationErrors = [];
        foreach ($collection->fields as $field) {
            if ($field->isDeleted()) {
                continue;
            }
            $fieldValue = $data[$field->slug] ?? null;
            $fieldErrors = $this->fieldValidator->validateFieldValue($field, $fieldValue);
            if (!empty($fieldErrors)) {
                $validationErrors[$field->slug] = $fieldErrors[0];
            }
        }
        if (!empty($validationErrors)) {
            return $this->json(['errors' => $validationErrors], 422);
        }

        // Vérification d'unicité
        foreach ($collection->fields as $field) {
            if ($field->isDeleted()) continue;
            $rules = $field->validationRules;
            if (empty($rules['unique'])) continue;

            $fieldValue = $data[$field->slug] ?? null;
            if ($fieldValue === null || $fieldValue === '' || $fieldValue === []) continue;

            $qb = $this->em->createQueryBuilder();
            $qb->select('COUNT(cfv.id)')
               ->from(ContentFieldValue::class, 'cfv')
               ->join('cfv.contentEntry', 'ce')
               ->where('ce.collection = :collection')
               ->andWhere('cfv.field = :field')
               ->andWhere('cfv.textValue = :value')
               ->andWhere('ce.deletedAt IS NULL')
               ->setParameter('collection', $collection)
               ->setParameter('field', $field)
               ->setParameter('value', (string)$fieldValue);

            if (isset($entry)) {
                $qb->andWhere('ce.id != :entryId')
                   ->setParameter('entryId', $entry->id);
            }

            $count = $qb->getQuery()->getSingleScalarResult();
            if ($count > 0) {
                $validationErrors[$field->slug] = sprintf(
                    'La valeur "%s" existe déjà pour le champ "%s".',
                    (string)$fieldValue,
                    $field->name
                );
            }
        }
        if (!empty($validationErrors)) {
            return $this->json(['errors' => $validationErrors], 422);
        }

        $this->em->flush();

        return $this->json($this->formatter->formatEntry($entry));
    }

    #[OA\Delete(
        path: '/api/{projectId}/{collectionSlug}/{uuid}',
        summary: 'Soft-delete a content entry',
        security: [['ApiToken' => []]],
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'collectionSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Entry not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/{uuid}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(Request $request, string $projectId, string $collectionSlug, string $uuid): JsonResponse
    {
        $token = $this->tokenChecker->resolve($request);
        if ($token === null || !$token->can('delete')) {
            return $this->json(['error' => 'Unauthorized. Token requires delete ability.'], 401);
        }

        if ($token->project->uuid->toString() !== $projectId) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($token->project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found.'], 404);
        }

        $entry = $this->entryRepository->findOneBy(['uuid' => $uuid, 'collection' => $collection, 'deletedAt' => null]);
        if ($entry === null) {
            return $this->json(['error' => 'Entry not found.'], 404);
        }

        $entry->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function resolvePublicProject(string $projectId): Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        if (!$project->publicApi) {
            return $this->json(['error' => 'Public API access is disabled for this project.'], 403);
        }

        return $project;
    }

    private function hydrateFieldValues(ContentEntry $entry, array $data, \App\Entity\Collection $collection, Project $project): void
    {
        $fields = $this->fieldRepository->findByCollection($collection);

        foreach ($fields as $field) {
            if (!array_key_exists($field->slug, $data)) {
                continue;
            }

            $fieldValue = null;
            foreach ($entry->fieldValues as $fv) {
                if ($fv->field?->id === $field->id) {
                    $fieldValue = $fv;
                    break;
                }
            }

            if ($fieldValue === null) {
                $fieldValue = new ContentFieldValue();
                $fieldValue->contentEntry = $entry;
                $fieldValue->field        = $field;
                $fieldValue->fieldType    = $field->type;
                $entry->fieldValues->add($fieldValue);
                $this->em->persist($fieldValue);
            }

            $value = $data[$field->slug];

            // Les champs media/relation/enumeration nécessitent une validation IDOR
            // (cross-project security check), on la fait avant l'hydratation.
            if (in_array($field->type, ['media', 'relation', 'enumeration'], true)) {
                $value = $this->normalizeAndValidateIds($value, $field->type, $project, $field);
            }

            $this->fieldValueHydrator->hydrate($fieldValue, $value, $field->type);
        }
    }

    /**
     * Pour les conteneurs d'identifiants (media/relation/enumeration) :
     * accepte un array tel quel, décode une string JSON-array, sinon emballe
     * un scalaire isolé dans un tableau.
     *
     * Pour les champs media : valide que chaque UUID appartient bien au même
     * projet (prévention IDOR cross-project). Les UUIDs orphelins ou d'un
     * autre projet sont silencieusement écartés.
     *
     * Pour les champs relation : si targetCollection === 'end_users', valide
     * dans la table end_user ; sinon valide dans content_entry.
     */
    private function normalizeAndValidateIds(mixed $value, string $fieldType, Project $project, ?\App\Entity\Field $field = null): ?array
    {
        if (is_array($value)) {
            $ids = $value;
        } elseif ($value === null || $value === '') {
            return null;
        } else {
            $decoded = json_decode((string) $value, true);
            $ids = is_array($decoded) ? $decoded : [$value];
        }

        // Validation IDOR : chaque UUID doit appartenir au même projet
        if ($ids !== []) {
            $targetCollection = $field?->options['targetCollection'] ?? '';
            $validUuids = match (true) {
                $fieldType === 'media'                                         => $this->mediaRepository->findProjectMediaUuids($project, $ids),
                $fieldType === 'relation' && $targetCollection === 'end_users' => $this->endUserRepository->findProjectEndUserUuids($project, $ids),
                $fieldType === 'relation'                                      => $this->entryRepository->findProjectEntryUuids($project, $ids),
                default                                                        => $ids,
            };
            $ids = array_values(array_intersect($ids, $validUuids));
        }

        return $ids !== [] ? $ids : null;
    }
}
