<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Event\ContentEvent;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Service\EavDataFormatterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries', name: 'api_content_')]
class ContentController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private ContentEntryRepository $entryRepository,
        private FieldRepository $fieldRepository,
        private EavDataFormatterService $formatter,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private \App\Service\VersioningService $versioning,
        private \App\Repository\ProjectMemberRepository $memberRepo,
        private \App\Service\FieldValueHydrator $fieldValueHydrator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 15)));

        // Locale filter is optional: only applied when the caller explicitly passes ?locale=xx
        $locale  = $request->query->has('locale') ? $request->query->getString('locale') : null;

        // Status filter (supports both query param names); 'trashed' is handled separately (deletedAt)
        $statusRaw = $request->query->get('status') ?? $request->query->get('filter_status');
        $status    = in_array($statusRaw, ['draft', 'published'], true) ? $statusRaw : null;

        $entries  = $this->entryRepository->findByCollectionPaginated($collection, $page, $perPage, $locale, $status);
        $total    = $this->entryRepository->countByCollection($collection, $locale, $status);

        $lastPage = max(1, (int) ceil($total / $perPage));
        $from     = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
        $to       = min($page * $perPage, $total);

        return $this->json([
            'data'         => array_values(array_map(fn ($e) => $this->formatter->formatEntry($e), $entries)),
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => $lastPage,
            'per_page'     => $perPage,
            'from'         => $from,
            'to'           => $to,
        ]);
    }

    #[Route('/trash', name: 'trash', methods: ['GET'])]
    public function trash(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $locale = $request->query->getString('locale');
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 15)));

        $entries = $this->entryRepository->findTrashedPaginated($collection, $page, $perPage, $locale ?: null);
        $total = $this->entryRepository->countByCollection($collection, $locale ?: null);

        return $this->json([
            'data' => array_values(array_map(fn ($e) => $this->formatter->formatEntry($e), $entries)),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($total / $perPage)],
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $project = $collection->project;
        $data    = $request->toArray();
        $user    = $this->getUser();

        // Validate status against workflow
        $statuses = $collection->getWorkflowStatuses();
        $systemStatuses = ['draft', 'scheduled'];
        $validStatuses = array_merge(array_column($statuses, 'slug'), $systemStatuses);
        $status = $data['status'] ?? 'draft';
        if (!in_array($status, $validStatuses, true)) {
            return $this->json(['errors' => ['status' => 'Invalid status for this collection.']], 422);
        }

        $locale = $data['locale'] ?? $project->defaultLocale;
        $validLocales = array_unique(array_merge($project->locales ?? [], [$project->defaultLocale]));
        if (!in_array($locale, $validLocales, true)) {
            return $this->json(['error' => sprintf('Locale "%s" is not enabled for this project.', $locale)], 422);
        }

        if ($collection->isSingleton && $this->entryRepository->hasNonDeletedEntryForCollection($collection)) {
            return $this->json(['error' => 'This collection is a singleton and already has an entry.'], 409);
        }

        $entry = new ContentEntry();
        $entry->collection = $collection;
        $entry->project    = $project;
        $entry->locale     = $locale;
        $entry->status     = $data['status'] ?? $collection->getDefaultStatus();
        if ($entry->status === 'scheduled' && isset($data['scheduledAt'])) {
            $entry->scheduledAt = new \DateTimeImmutable($data['scheduledAt']);
        }
        $entry->createdBy  = $user;
        $entry->updatedBy  = $user;

        // Handle assignment
        if (isset($data['assigned_to_id'])) {
            $assignee = $this->em->getRepository(\App\Entity\User::class)->find($data['assigned_to_id']);
            if ($assignee !== null && $this->memberRepo->findActiveByUserAndProject($assignee, $collection->project) !== null) {
                $entry->assignedTo = $assignee;
            } elseif ($assignee !== null) {
                return $this->json(['errors' => ['assigned_to_id' => 'User is not a member of this project.']], 422);
            }
        }

        $this->em->persist($entry);
        $this->saveFieldValues($entry, $collection, $data['fields'] ?? []);
        $this->em->flush();

        $this->dispatcher->dispatch(new ContentEvent(ContentEvent::CREATED, $project, $entry));

        return $this->json(['data' => $this->formatter->formatEntry($entry)], 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $uuid);
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        return $this->json(['data' => $this->formatter->formatEntry($entry)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, string $uuid, Request $request): JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $uuid);
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        $data = $request->toArray();

        // Validate status against workflow
        $statuses = $entry->collection->getWorkflowStatuses();
        $systemStatuses = ['draft', 'scheduled'];
        $validStatuses = array_merge(array_column($statuses, 'slug'), $systemStatuses);
        $status = $data['status'] ?? 'draft';
        if (!in_array($status, $validStatuses, true)) {
            return $this->json(['errors' => ['status' => 'Invalid status for this collection.']], 422);
        }

        if (isset($data['locale'])) {
            $validLocales = array_unique(array_merge($entry->project->locales ?? [], [$entry->project->defaultLocale]));
            if (!in_array($data['locale'], $validLocales, true)) {
                return $this->json(['error' => sprintf('Locale "%s" is not enabled for this project.', $data['locale'])], 422);
            }
            $entry->locale = $data['locale'];
        }
        if (isset($data['status'])) {
            $entry->status = $data['status'];
        }
        if ($entry->status === 'scheduled' && isset($data['scheduledAt'])) {
            $entry->scheduledAt = new \DateTimeImmutable($data['scheduledAt']);
        }
        $entry->updatedBy = $this->getUser();

        // Handle assignment
        if (array_key_exists('assigned_to_id', $data)) {
            if ($data['assigned_to_id'] !== null) {
                $assignee = $this->em->getRepository(\App\Entity\User::class)->find($data['assigned_to_id']);
                if ($assignee !== null && $this->memberRepo->findActiveByUserAndProject($assignee, $entry->collection->project) !== null) {
                    $entry->assignedTo = $assignee;
                } elseif ($assignee !== null) {
                    return $this->json(['errors' => ['assigned_to_id' => 'User is not a member of this project.']], 422);
                }
            } else {
                $entry->assignedTo = null;
            }
        }

        if (isset($data['fields'])) {
            // Capture l'état courant comme version AVANT d'écraser les champs (EAV)
            if ($entry->fieldValues->count() > 0) {
                $this->versioning->createVersion($entry, 'Sauvegarde');
            }

            $this->em->wrapInTransaction(function () use ($entry, $data): void {
                foreach ($entry->fieldValues as $fv) {
                    $this->em->remove($fv);
                }
                $this->em->flush();
                $entry->fieldValues->clear();
                $this->saveFieldValues($entry, $entry->collection, $data['fields']);
                $this->em->flush();
            });
        } else {
            $this->em->flush();
        }

        $this->dispatcher->dispatch(new ContentEvent(ContentEvent::UPDATED, $entry->project, $entry));

        return $this->json(['data' => $this->formatter->formatEntry($entry)]);
    }

    #[Route('/{uuid}/duplicate', name: 'duplicate', methods: ['POST'])]
    public function duplicate(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $source = $this->resolveEntry($projectUuid, $collectionSlug, $uuid);
        if ($source instanceof JsonResponse) {
            return $source;
        }

        $clone = new ContentEntry();
        $clone->collection = $source->collection;
        $clone->project    = $source->project;
        $clone->locale     = $source->locale;
        $clone->status     = 'draft';
        $clone->createdBy  = $this->getUser();
        $clone->updatedBy  = $this->getUser();

        $this->em->persist($clone);

        foreach ($source->fieldValues as $fv) {
            $newFv = new ContentFieldValue();
            $newFv->contentEntry  = $clone;
            $newFv->field         = $fv->field;
            $newFv->fieldType     = $fv->fieldType;
            $newFv->textValue     = $fv->textValue;
            $newFv->numberValue   = $fv->numberValue;
            $newFv->booleanValue  = $fv->booleanValue;
            $newFv->dateValue     = $fv->dateValue;
            $newFv->datetimeValue = $fv->datetimeValue;
            $newFv->jsonValue     = $fv->jsonValue;
            $this->em->persist($newFv);
        }

        $this->em->flush();

        return $this->json(['data' => $this->formatter->formatEntry($clone)], 201);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $uuid);
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        // Soft delete
        $entry->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->dispatcher->dispatch(new ContentEvent(ContentEvent::DELETED, $entry->project, $entry));

        return $this->json(null, 204);
    }

    #[Route('/{uuid}/restore', name: 'restore', methods: ['PATCH'])]
    public function restore(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        try {
            $uuidObj = Uuid::fromString($uuid);
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid UUID format'], 422);
        }

        $entry = $this->entryRepository->findOneBy(['uuid' => $uuidObj, 'collection' => $collection]);
        if ($entry === null) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        $entry->deletedAt = null;
        $this->em->flush();

        return $this->json(['data' => $this->formatter->formatEntry($entry)]);
    }

    #[Route('/{uuid}/force-delete', name: 'force_delete', methods: ['DELETE'])]
    public function forceDelete(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        try {
            $uuidObj = Uuid::fromString($uuid);
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid UUID format'], 422);
        }

        $entry = $this->entryRepository->findOneBy(['uuid' => $uuidObj, 'collection' => $collection]);
        if ($entry === null) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        $this->em->remove($entry);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function saveFieldValues(ContentEntry $entry, Collection $collection, array $fieldData): void
    {
        $fields = $this->fieldRepository->findByCollection($collection);
        $fieldMap = [];
        foreach ($fields as $f) {
            $fieldMap[$f->slug] = $f;
        }

        foreach ($fieldData as $slug => $value) {
            if (!isset($fieldMap[$slug])) {
                continue;
            }
            $field = $fieldMap[$slug];
            $fv = new ContentFieldValue();
            $fv->contentEntry = $entry;
            $fv->field        = $field;

            $this->fieldValueHydrator->hydrate($fv, $value, $field->type);

            $this->em->persist($fv);
        }
    }



    private function resolveCollection(string $projectUuid, string $collectionSlug): Collection|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return $this->json(['error' => 'Collection not found'], 404);
        }

        return $collection;
    }

    private function resolveEntry(string $projectUuid, string $collectionSlug, string $uuid): ContentEntry|JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        try {
            $uuidObj = Uuid::fromString($uuid);
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid UUID format'], 422);
        }

        $entry = $this->entryRepository->findOneBy([
            'uuid'       => $uuidObj,
            'collection' => $collection,
            'deletedAt'  => null,
        ]);

        if ($entry === null) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        return $entry;
    }
}
