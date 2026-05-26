<?php

namespace App\Controller\Api;

use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\EavDataFormatterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/{projectId}/{collectionSlug}', name: 'public_api_content_', requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
class ContentController extends AbstractController
{
    public function __construct(
        private ApiTokenChecker $tokenChecker,
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private ContentEntryRepository $entryRepository,
        private FieldRepository $fieldRepository,
        private EavDataFormatterService $formatter,
        private EntityManagerInterface $em,
    ) {}

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
        $statusFilter = in_array($status, ['draft', 'published'], true) ? $status : null;

        $entries = $this->entryRepository->findByCollectionPaginated($collection, $page, $perPage, $locale, $statusFilter);
        $total   = $this->entryRepository->countByCollection($collection, $locale, $statusFilter);

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

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(Request $request, string $projectId, string $collectionSlug, string $uuid): JsonResponse
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

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request, string $projectId, string $collectionSlug): JsonResponse
    {
        $token = $this->tokenChecker->resolve($request);
        if ($token === null || !$token->can('write')) {
            return $this->json(['error' => 'Unauthorized. Token requires write ability.'], 401);
        }

        if ($token->project->uuid->toString() !== $projectId) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($token->project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found.'], 404);
        }

        $data   = $request->toArray();
        $locale = $data['locale'] ?? $token->project->defaultLocale;

        $entry = new ContentEntry();
        $entry->project    = $token->project;
        $entry->collection = $collection;
        $entry->locale     = $locale;
        $entry->status     = $data['status'] ?? 'draft';

        $this->hydrateFieldValues($entry, $data, $collection);

        $this->em->persist($entry);
        $this->em->flush();

        return $this->json($this->formatter->formatEntry($entry), 201);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $projectId, string $collectionSlug, string $uuid): JsonResponse
    {
        $token = $this->tokenChecker->resolve($request);
        if ($token === null || !$token->can('write')) {
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
            $entry->status = $data['status'];
        }
        if (isset($data['locale'])) {
            $entry->locale = $data['locale'];
        }

        $this->hydrateFieldValues($entry, $data, $collection);
        $this->em->flush();

        return $this->json($this->formatter->formatEntry($entry));
    }

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

    /**
     * Resolves a project by UUID and enforces the publicApi gate for GET endpoints.
     * Returns the Project on success, or a JsonResponse on failure.
     */
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

    private function hydrateFieldValues(ContentEntry $entry, array $data, \App\Entity\Collection $collection): void
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
            match ($field->type) {
                'number'   => $fieldValue->numberValue = $value !== null ? (string) $value : null,
                'boolean'  => $fieldValue->booleanValue = (bool) $value,
                'date'     => $fieldValue->dateValue = $value ? new \DateTime($value) : null,
                'datetime' => $fieldValue->datetimeValue = $value ? new \DateTime($value) : null,
                'json'     => $fieldValue->jsonValue = is_array($value) ? $value : json_decode($value, true),
                default    => $fieldValue->textValue = $value,
            };
        }
    }
}
