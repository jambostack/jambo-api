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
use App\Service\AuditService;
use App\Service\EavDataFormatterService;
use App\Service\EavFieldHelperService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries', name: 'api_content_')]
class ContentController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 1048576; // 1MB

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

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
        private EavFieldHelperService $fieldValidator,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator?->trans($id, $parameters) ?? $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $collection->project);
        }

        $search = trim((string) $request->query->get('search', ''));
        if (mb_strlen($search) > 255) {
            return $this->secureJson(['error' => $this->trans('content.admin.search_query_too_long'), 'code' => 'SEARCH_QUERY_TOO_LONG'], 422);
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 15)));

        // Locale filter is optional: only applied when the caller explicitly passes ?locale=xx
        $locale  = $request->query->has('locale') ? $request->query->getString('locale') : null;

        // Status filter (supports both query param names); 'trashed' is handled separately (deletedAt)
        $statusRaw = $request->query->get('status') ?? $request->query->get('filter_status');
        $status    = in_array($statusRaw, ['draft', 'published'], true) ? $statusRaw : null;

        // Sort validation
        $sortRaw = $request->query->getString('sort', 'created_at:desc');
        $sortParts = explode(':', $sortRaw, 2);
        $sortField = strtolower($sortParts[0] ?? 'created_at');
        $sortDir   = strtolower($sortParts[1] ?? 'desc');
        $allowedSortFields = ['created_at', 'updated_at', 'published_at', 'id'];
        $allowedSortDirs = ['asc', 'desc'];
        if (!in_array($sortField, $allowedSortFields, true) || !in_array($sortDir, $allowedSortDirs, true)) {
            return $this->secureJson(['error' => $this->trans('content.admin.invalid_sort_clause'), 'code' => 'INVALID_SORT_CLAUSE'], 422);
        }
        $sort = $sortField . ':' . $sortDir;

        // Date filters validation
        $dateFrom = $request->query->has('date_from') ? trim($request->query->getString('date_from')) : null;
        $dateTo   = $request->query->has('date_to') ? trim($request->query->getString('date_to')) : null;

        if ($dateFrom !== null && $dateFrom !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}(?::\d{2})?)?$/', $dateFrom) || strtotime($dateFrom) === false) {
                return $this->secureJson(['error' => $this->trans('content.admin.invalid_date_filter'), 'code' => 'INVALID_DATE_FILTER'], 422);
            }
        } else {
            $dateFrom = null;
        }

        if ($dateTo !== null && $dateTo !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}(?::\d{2})?)?$/', $dateTo) || strtotime($dateTo) === false) {
                return $this->secureJson(['error' => $this->trans('content.admin.invalid_date_filter'), 'code' => 'INVALID_DATE_FILTER'], 422);
            }
        } else {
            $dateTo = null;
        }

        $assignedToId = $request->query->has('assigned_to_id') ? $request->query->getInt('assigned_to_id') : null;
        if ($assignedToId !== null && $assignedToId <= 0) {
            $assignedToId = null;
        }

        $entries  = $this->entryRepository->findByCollectionPaginated(
            $collection,
            $page,
            $perPage,
            $locale,
            $status,
            $search !== '' ? $search : null,
            $sort,
            $dateFrom,
            $dateTo,
            $assignedToId
        );
        $total    = $this->entryRepository->countByCollection(
            $collection,
            $locale,
            $status,
            $search !== '' ? $search : null,
            $dateFrom,
            $dateTo
        );

        $lastPage = max(1, (int) ceil($total / $perPage));
        $from     = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
        $to       = min($page * $perPage, $total);

        return $this->secureJson([
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

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $collection->project);
        }

        $locale = $request->query->getString('locale');
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 15)));

        $entries = $this->entryRepository->findTrashedPaginated($collection, $page, $perPage, $locale ?: null);
        $total = $this->entryRepository->countTrashedByCollection($collection, $locale ?: null);

        return $this->secureJson([
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

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $collection->project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('content.admin.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        $project = $collection->project;
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('content.admin.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }
        $user = $this->getUser();

        if (isset($data['fields']) && is_array($data['fields']) && count($data['fields']) > 200) {
            return $this->secureJson(['error' => $this->trans('content.admin.too_many_fields'), 'code' => 'TOO_MANY_FIELDS'], 422);
        }

        // Validate status against workflow
        $statuses = $collection->getWorkflowStatuses();
        $systemStatuses = ['draft', 'scheduled'];
        $validStatuses = array_merge(array_column($statuses, 'slug'), $systemStatuses);
        $status = $data['status'] ?? 'draft';
        if (!in_array($status, $validStatuses, true)) {
            return $this->secureJson(['errors' => ['status' => $this->trans('content.admin.invalid_status')], 'code' => 'INVALID_STATUS'], 422);
        }

        $locale = $data['locale'] ?? $project->defaultLocale;
        $validLocales = array_unique(array_merge($project->locales ?? [], [$project->defaultLocale]));
        if (!in_array($locale, $validLocales, true)) {
            return $this->secureJson(['error' => $this->trans('content.admin.locale_not_enabled', ['%locale%' => $locale]), 'code' => 'LOCALE_NOT_ENABLED'], 422);
        }

        if ($collection->isSingleton && $this->entryRepository->hasNonDeletedEntryForCollection($collection)) {
            return $this->secureJson(['error' => $this->trans('content.admin.singleton_already_exists'), 'code' => 'SINGLETON_ALREADY_EXISTS'], 409);
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
                return $this->secureJson(['errors' => ['assigned_to_id' => $this->trans('content.admin.assignee_not_member')], 'code' => 'ASSIGNEE_NOT_MEMBER'], 422);
            }
        }

        $this->em->persist($entry);

        // Validation des champs avant saveFieldValues
        $validationErrors = $this->validatePayloadFields($collection, $data['fields'] ?? [], $entry);
        if (!empty($validationErrors)) {
            return $this->secureJson(['errors' => $validationErrors, 'code' => 'VALIDATION_FAILED'], 422);
        }

        $this->saveFieldValues($entry, $collection, $data['fields'] ?? []);

        $this->em->flush();

        $this->dispatcher->dispatch(new ContentEvent(ContentEvent::CREATED, $project, $entry));

        $this->auditService?->log(
            toolName: 'content_create',
            project: $project,
            input: ['collection' => $collection->slug, 'uuid' => $entry->uuid?->toRfc4122(), 'status' => $entry->status],
            output: ['success' => true, 'id' => $entry->id],
            status: 'success',
            createdBy: $user?->getUserIdentifier(),
            source: 'content_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->formatter->formatEntry($entry)], 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $uuid);
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $entry->project);
        }

        return $this->secureJson(['data' => $this->formatter->formatEntry($entry)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, string $uuid, Request $request): JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $uuid);
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $entry->project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('content.admin.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('content.admin.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (isset($data['fields']) && is_array($data['fields']) && count($data['fields']) > 200) {
            return $this->secureJson(['error' => $this->trans('content.admin.too_many_fields'), 'code' => 'TOO_MANY_FIELDS'], 422);
        }

        // Validate status against workflow
        $statuses = $entry->collection->getWorkflowStatuses();
        $systemStatuses = ['draft', 'scheduled'];
        $validStatuses = array_merge(array_column($statuses, 'slug'), $systemStatuses);
        $status = $data['status'] ?? 'draft';
        if (!in_array($status, $validStatuses, true)) {
            return $this->secureJson(['errors' => ['status' => $this->trans('content.admin.invalid_status')], 'code' => 'INVALID_STATUS'], 422);
        }

        if (isset($data['locale'])) {
            $validLocales = array_unique(array_merge($entry->project->locales ?? [], [$entry->project->defaultLocale]));
            if (!in_array($data['locale'], $validLocales, true)) {
                return $this->secureJson(['error' => $this->trans('content.admin.locale_not_enabled', ['%locale%' => $data['locale']]), 'code' => 'LOCALE_NOT_ENABLED'], 422);
            }
            $entry->locale = $data['locale'];
        }
        $previousStatus = $entry->status;
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
                    return $this->secureJson(['errors' => ['assigned_to_id' => $this->trans('content.admin.assignee_not_member')], 'code' => 'ASSIGNEE_NOT_MEMBER'], 422);
                }
            } else {
                $entry->assignedTo = null;
            }
        }

        if (isset($data['fields'])) {
            // Validation des champs avant la transaction
            $validationErrors = $this->validatePayloadFields($entry->collection, $data['fields'], $entry);
            if (!empty($validationErrors)) {
                return $this->secureJson(['errors' => $validationErrors, 'code' => 'VALIDATION_FAILED'], 422);
            }

            // Capture l'état courant comme version AVANT d'écraser les champs (EAV)
            if ($entry->fieldValues->count() > 0) {
                $this->versioning->createVersion($entry, $this->trans('content.admin.version_save_label'));
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

        // Dispatch STATUS_CHANGED si le statut a changé
        if ($previousStatus !== $entry->status) {
            $statusEvent = new ContentEvent(ContentEvent::STATUS_CHANGED, $entry->project, $entry);
            $statusEvent->previousStatus = $previousStatus;
            $this->dispatcher->dispatch($statusEvent);
        }

        $this->auditService?->log(
            toolName: 'content_update',
            project: $entry->project,
            input: ['collection' => $entry->collection->slug, 'uuid' => $entry->uuid?->toRfc4122(), 'status' => $entry->status],
            output: ['success' => true, 'id' => $entry->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'content_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->formatter->formatEntry($entry)]);
    }

    #[Route('/{uuid}/duplicate', name: 'duplicate', methods: ['POST'])]
    public function duplicate(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $source = $this->resolveEntry($projectUuid, $collectionSlug, $uuid);
        if ($source instanceof JsonResponse) {
            return $source;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $source->project);
        }

        $clone = new ContentEntry();
        $clone->collection = $source->collection;
        $clone->project    = $source->project;
        $clone->locale     = $source->locale;
        $clone->status     = 'draft';
        $clone->createdBy  = $this->getUser();
        $clone->updatedBy  = $this->getUser();

        $this->em->persist($clone);

        $collection = $source->collection;

        // Collecter les valeurs pour validation
        $fieldData = [];
        foreach ($collection->fields as $field) {
            if ($field->isDeleted()) {
                continue;
            }
            foreach ($source->fieldValues as $fv) {
                if ($fv->field && $fv->field->slug === $field->slug) {
                    $fieldData[$field->slug] = $fv->textValue ?? $fv->numberValue ?? $fv->jsonValue ?? $fv->booleanValue ?? $fv->dateValue ?? $fv->datetimeValue;
                    break;
                }
            }
        }

        $validationErrors = $this->validatePayloadFields($collection, $fieldData, $clone);
        if (!empty($validationErrors)) {
            return $this->secureJson(['errors' => $validationErrors], 422);
        }

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

        $this->auditService?->log(
            toolName: 'content_duplicate',
            project: $source->project,
            input: ['collection' => $collection->slug, 'source_uuid' => $source->uuid?->toRfc4122(), 'new_uuid' => $clone->uuid?->toRfc4122()],
            output: ['success' => true, 'id' => $clone->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'content_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->formatter->formatEntry($clone)], 201);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $entry = $this->resolveEntry($projectUuid, $collectionSlug, $uuid);
        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $entry->project);
        }

        // Soft delete
        $entry->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->dispatcher->dispatch(new ContentEvent(ContentEvent::DELETED, $entry->project, $entry));

        $this->auditService?->log(
            toolName: 'content_delete',
            project: $entry->project,
            input: ['collection' => $entry->collection->slug, 'uuid' => $entry->uuid?->toRfc4122()],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'content_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    #[Route('/{uuid}/restore', name: 'restore', methods: ['PATCH'])]
    public function restore(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.restore', $collection->project);
        }

        try {
            $uuidObj = Uuid::fromString($uuid);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('content.admin.invalid_uuid_format'), 'code' => 'INVALID_UUID_FORMAT'], 422);
        }

        $entry = $this->entryRepository->findOneBy(['uuid' => $uuidObj, 'collection' => $collection]);
        if ($entry === null) {
            return $this->secureJson(['error' => $this->trans('content.admin.entry_not_found'), 'code' => 'ENTRY_NOT_FOUND'], 404);
        }

        $entry->deletedAt = null;
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'content_restore',
            project: $collection->project,
            input: ['collection' => $collection->slug, 'uuid' => $entry->uuid?->toRfc4122()],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'content_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->formatter->formatEntry($entry)]);
    }

    #[Route('/{uuid}/force-delete', name: 'force_delete', methods: ['DELETE'])]
    public function forceDelete(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $collection = $this->resolveCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $collection->project);
        }

        try {
            $uuidObj = Uuid::fromString($uuid);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('content.admin.invalid_uuid_format'), 'code' => 'INVALID_UUID_FORMAT'], 422);
        }

        $entry = $this->entryRepository->findOneBy(['uuid' => $uuidObj, 'collection' => $collection]);
        if ($entry === null) {
            return $this->secureJson(['error' => $this->trans('content.admin.entry_not_found'), 'code' => 'ENTRY_NOT_FOUND'], 404);
        }

        $entryId = $entry->id;
        $entryUuid = $entry->uuid?->toRfc4122();
        $project = $collection->project;

        $this->em->remove($entry);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'content_force_delete',
            project: $project,
            input: ['collection' => $collection->slug, 'id' => $entryId, 'uuid' => $entryUuid],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'content_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    private function validatePayloadFields(Collection $collection, array $fields, ?ContentEntry $currentEntry = null): array
    {
        $validationErrors = [];
        $fieldEntities = $this->fieldRepository->findByCollection($collection);
        foreach ($fieldEntities as $field) {
            if ($field->isDeleted()) {
                continue;
            }
            $fieldValue = $fields[$field->slug] ?? null;

            // Security bounds
            if (is_string($fieldValue)) {
                if (in_array($field->type, ['text', 'slug', 'color', 'icon', 'time'], true) && mb_strlen($fieldValue) > 65535) {
                    $validationErrors['fields.' . $field->slug] = $this->trans('content.admin.field_text_too_long', ['%field%' => $field->name]);
                    continue;
                }
                if (in_array($field->type, ['longtext', 'richtext', 'wysiwyg', 'markdown', 'code'], true) && mb_strlen($fieldValue) > 1000000) {
                    $validationErrors['fields.' . $field->slug] = $this->trans('content.admin.field_rich_text_too_long', ['%field%' => $field->name]);
                    continue;
                }
                if ($field->type === 'url' && $fieldValue !== '') {
                    if (!filter_var($fieldValue, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $fieldValue)) {
                        $validationErrors['fields.' . $field->slug] = $this->trans('content.admin.field_url_invalid', ['%field%' => $field->name]);
                        continue;
                    }
                }
                if ($field->type === 'email' && $fieldValue !== '') {
                    if (!filter_var($fieldValue, FILTER_VALIDATE_EMAIL)) {
                        $validationErrors['fields.' . $field->slug] = $this->trans('content.admin.field_email_invalid', ['%field%' => $field->name]);
                        continue;
                    }
                }
            } elseif (is_array($fieldValue)) {
                $json = json_encode($fieldValue);
                if ($json !== false && strlen($json) > 65536) {
                    $validationErrors['fields.' . $field->slug] = $this->trans('content.admin.field_json_too_large', ['%field%' => $field->name]);
                    continue;
                }
                if ($this->getArrayDepth($fieldValue) > 10) {
                    $validationErrors['fields.' . $field->slug] = $this->trans('content.admin.field_json_too_deep', ['%field%' => $field->name]);
                    continue;
                }
            }

            if (in_array($field->type, ['number', 'decimal', 'rating'], true) && $fieldValue !== null && $fieldValue !== '') {
                if (!is_numeric($fieldValue)) {
                    $validationErrors['fields.' . $field->slug] = $this->trans('content.admin.field_numeric_invalid', ['%field%' => $field->name]);
                    continue;
                }
            }

            $fieldErrors = $this->fieldValidator->validateFieldValue($field, $fieldValue);
            if (!empty($fieldErrors)) {
                $validationErrors['fields.' . $field->slug] = $fieldErrors[0];
            }
        }

        if (!empty($validationErrors)) {
            return $validationErrors;
        }

        // Uniqueness check
        foreach ($fieldEntities as $field) {
            if ($field->isDeleted()) continue;
            $rules = $field->validationRules;
            if (empty($rules['unique'])) continue;

            $fieldValue = $fields[$field->slug] ?? null;
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

            if ($currentEntry !== null && $currentEntry->id !== null) {
                $qb->andWhere('ce.id != :entryId')
                   ->setParameter('entryId', $currentEntry->id);
            }

            $count = (int) $qb->getQuery()->getSingleScalarResult();
            if ($count > 0) {
                $validationErrors['fields.' . $field->slug] = $this->trans('content.admin.field_value_already_exists', [
                    '%value%' => (string)$fieldValue,
                    '%field%' => $field->name,
                ]);
            }
        }

        return $validationErrors;
    }

    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->getArrayDepth($value) + 1;
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                }
            }
        }
        return $maxDepth;
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
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('content.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('content.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if ($project === null) {
            return $this->secureJson(['error' => $this->trans('content.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return $this->secureJson(['error' => $this->trans('content.admin.collection_not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404);
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
            return $this->secureJson(['error' => $this->trans('content.admin.invalid_uuid_format'), 'code' => 'INVALID_UUID_FORMAT'], 422);
        }

        $entry = $this->entryRepository->findOneBy([
            'uuid'       => $uuidObj,
            'collection' => $collection,
            'deletedAt'  => null,
        ]);

        if ($entry === null) {
            return $this->secureJson(['error' => $this->trans('content.admin.entry_not_found'), 'code' => 'ENTRY_NOT_FOUND'], 404);
        }

        return $entry;
    }
}
