<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\ContentVersionRepository;
use App\Service\AuditService;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class VersioningController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 16384;
    public const MAX_PAGE_LIMIT = 100;
    public const DEFAULT_PAGE_LIMIT = 50;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private VersioningService $versioning,
        private ContentVersionRepository $versionRepo,
        private ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.versioning_limiter')]
        private ?RateLimiterFactory $versioningLimiter = null,
        private ?AuditService $auditService = null,
    ) {}

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->versioningLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            $limit = $this->versioningLimiter->create($identifier)->consume(1);
            if (false === $limit->isAccepted()) {
                $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
                return new JsonResponse([
                    'error' => $this->trans('versioning.rate_limit_exceeded', [], 'Too many versioning requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429, array_merge(self::SECURITY_HEADERS, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                    'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                    'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
                ]));
            }
        }
        return null;
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions', name: 'versions_list', methods: ['GET'])]
    public function list(string $projectUuid, string $collectionSlug, string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return new JsonResponse(['error' => $this->trans('versioning.entry_not_found', [], 'Entry not found.')], 404, self::SECURITY_HEADERS);

        if (!$this->getUser() && $request->attributes->get('_project') !== $entry->project) {
            return new JsonResponse(['error' => $this->trans('versioning.auth_required', [], 'Authentication required.')], 401, self::SECURITY_HEADERS);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $entry->project);
        }

        $limit = min(self::MAX_PAGE_LIMIT, max(1, $request->query->getInt('limit', self::DEFAULT_PAGE_LIMIT)));
        $page = max(1, $request->query->getInt('page', 1));

        $allVersions = $this->versionRepo->findByEntry($entry);
        $slicedVersions = array_slice($allVersions, ($page - 1) * $limit, $limit);

        $versions = array_map(fn($v) => [
            'uuid' => $v->uuid->toRfc4122(),
            'versionNumber' => $v->versionNumber,
            'label' => $v->label,
            'createdAt' => $v->createdAt->format(\DateTimeInterface::ATOM),
            'createdBy' => $v->createdBy ? ($v->createdBy->name ?: $v->createdBy->email) : null,
        ], $slicedVersions);

        return new JsonResponse($versions, 200, self::SECURITY_HEADERS);
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/{versionNumber}/restore', name: 'versions_restore', requirements: ['versionNumber' => '\d+'], methods: ['POST'])]
    public function restore(string $projectUuid, string $collectionSlug, string $uuid, int $versionNumber, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($versionNumber < 1 || $versionNumber > 1000000) {
            return new JsonResponse(['error' => $this->trans('versioning.invalid_version', [], 'Invalid version number.')], 422, self::SECURITY_HEADERS);
        }

        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return new JsonResponse(['error' => $this->trans('versioning.entry_not_found', [], 'Entry not found.')], 404, self::SECURITY_HEADERS);

        if (!$this->getUser() && $request->attributes->get('_project') !== $entry->project) {
            return new JsonResponse(['error' => $this->trans('versioning.auth_required', [], 'Authentication required.')], 401, self::SECURITY_HEADERS);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $entry->project);
        }

        $ok = $this->versioning->restoreVersion($entry, $versionNumber);
        if (!$ok) {
            return new JsonResponse(['error' => $this->trans('versioning.version_not_found', [], 'Version not found.')], 404, self::SECURITY_HEADERS);
        }

        $this->auditService?->log(
            toolName: 'version_restore',
            project: $entry->project,
            input: [
                'entry_uuid' => $uuid,
                'collection' => $collectionSlug,
                'version' => $versionNumber,
            ],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
            source: 'versioning'
        );
        $this->auditService?->flush();

        $msg = $this->trans('versioning.restored_successfully', ['%version%' => $versionNumber], "Restoration to version %version% completed.");
        return new JsonResponse(['success' => true, 'message' => $msg], 200, self::SECURITY_HEADERS);
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/diff', name: 'versions_diff', methods: ['GET'])]
    public function diff(string $projectUuid, string $collectionSlug, string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($request->query->has('v1')) {
            $v1Param = (int) $request->query->get('v1');
            if ($v1Param < 1 || $v1Param > 1000000) {
                return new JsonResponse(['error' => $this->trans('versioning.invalid_version', [], 'Invalid version number.')], 422, self::SECURITY_HEADERS);
            }
        }

        if ($request->query->has('v2')) {
            $v2Param = (int) $request->query->get('v2');
            if ($v2Param < 1 || $v2Param > 1000000) {
                return new JsonResponse(['error' => $this->trans('versioning.invalid_version', [], 'Invalid version number.')], 422, self::SECURITY_HEADERS);
            }
        }

        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return new JsonResponse(['error' => $this->trans('versioning.entry_not_found', [], 'Entry not found.')], 404, self::SECURITY_HEADERS);

        if (!$this->getUser() && $request->attributes->get('_project') !== $entry->project) {
            return new JsonResponse(['error' => $this->trans('versioning.auth_required', [], 'Authentication required.')], 401, self::SECURITY_HEADERS);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $entry->project);
        }

        $v1 = (int) $request->query->get('v1', 1);
        $v2 = (int) $request->query->get('v2', $this->versionRepo->getNextVersionNumber($entry) - 1);

        $diff = $this->versioning->diff($entry, $v1, $v2);
        if (isset($diff['error'])) {
            return new JsonResponse(['error' => $this->trans('versioning.version_not_found', [], 'Version not found.')], 404, self::SECURITY_HEADERS);
        }

        return new JsonResponse($diff, 200, self::SECURITY_HEADERS);
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/prune', name: 'versions_prune', methods: ['POST', 'DELETE'])]
    public function prune(string $projectUuid, string $collectionSlug, string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return new JsonResponse(['error' => $this->trans('versioning.entry_not_found', [], 'Entry not found.')], 404, self::SECURITY_HEADERS);

        if (!$this->getUser() && $request->attributes->get('_project') !== $entry->project) {
            return new JsonResponse(['error' => $this->trans('versioning.auth_required', [], 'Authentication required.')], 401, self::SECURITY_HEADERS);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $entry->project);
        }

        $keep = $request->query->getInt('keep', 20);
        if ($request->getContent()) {
            try {
                $payload = $request->toArray();
                if (isset($payload['keep'])) {
                    $keep = (int) $payload['keep'];
                }
            } catch (\Throwable) {}
        }

        if ($keep < 1 || $keep > 100) {
            return new JsonResponse(['error' => $this->trans('versioning.keep_invalid', [], 'Invalid keep count parameter (must be between 1 and 100).')], 422, self::SECURITY_HEADERS);
        }

        $prunedCount = $this->versioning->pruneVersions($entry, $keep);

        $this->auditService?->log(
            toolName: 'version_prune',
            project: $entry->project,
            input: ['entry_uuid' => $uuid, 'collection' => $collectionSlug, 'keep' => $keep],
            output: ['pruned_count' => $prunedCount],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
            source: 'versioning'
        );
        $this->auditService?->flush();

        $msg = $this->trans('versioning.versions_pruned_successfully', ['%count%' => $prunedCount], "Older versions pruned successfully (%count% removed).");
        return new JsonResponse(['success' => true, 'pruned' => $prunedCount, 'message' => $msg], 200, self::SECURITY_HEADERS);
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/{versionNumber}', name: 'versions_detail', requirements: ['versionNumber' => '\d+'], methods: ['GET'])]
    public function detail(string $projectUuid, string $collectionSlug, string $uuid, int $versionNumber, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($versionNumber < 1 || $versionNumber > 1000000) {
            return new JsonResponse(['error' => $this->trans('versioning.invalid_version', [], 'Invalid version number.')], 422, self::SECURITY_HEADERS);
        }

        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return new JsonResponse(['error' => $this->trans('versioning.entry_not_found', [], 'Entry not found.')], 404, self::SECURITY_HEADERS);

        if (!$this->getUser() && $request->attributes->get('_project') !== $entry->project) {
            return new JsonResponse(['error' => $this->trans('versioning.auth_required', [], 'Authentication required.')], 401, self::SECURITY_HEADERS);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $entry->project);
        }

        $version = $this->versionRepo->findVersionByNumber($entry, $versionNumber);
        if (!$version) {
            return new JsonResponse(['error' => $this->trans('versioning.version_not_found', [], 'Version not found.')], 404, self::SECURITY_HEADERS);
        }

        return new JsonResponse([
            'uuid' => $version->uuid?->toRfc4122(),
            'versionNumber' => $version->versionNumber,
            'label' => $version->label,
            'snapshot' => $version->snapshot,
            'createdAt' => $version->createdAt->format(\DateTimeInterface::ATOM),
            'createdBy' => $version->createdBy ? ($version->createdBy->name ?: $version->createdBy->email) : null,
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/{versionNumber}', name: 'versions_delete', requirements: ['versionNumber' => '\d+'], methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, string $uuid, int $versionNumber, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($versionNumber < 1 || $versionNumber > 1000000) {
            return new JsonResponse(['error' => $this->trans('versioning.invalid_version', [], 'Invalid version number.')], 422, self::SECURITY_HEADERS);
        }

        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return new JsonResponse(['error' => $this->trans('versioning.entry_not_found', [], 'Entry not found.')], 404, self::SECURITY_HEADERS);

        if (!$this->getUser() && $request->attributes->get('_project') !== $entry->project) {
            return new JsonResponse(['error' => $this->trans('versioning.auth_required', [], 'Authentication required.')], 401, self::SECURITY_HEADERS);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $entry->project);
        }

        $version = $this->versionRepo->findVersionByNumber($entry, $versionNumber);
        if (!$version) {
            return new JsonResponse(['error' => $this->trans('versioning.version_not_found', [], 'Version not found.')], 404, self::SECURITY_HEADERS);
        }

        $deleted = $this->versioning->deleteVersion($entry, $versionNumber);
        if (!$deleted) {
            return new JsonResponse(['error' => $this->trans('versioning.cannot_delete_last_version', [], 'Cannot delete the only existing version of an entry.')], 422, self::SECURITY_HEADERS);
        }

        $this->auditService?->log(
            toolName: 'version_delete',
            project: $entry->project,
            input: ['entry_uuid' => $uuid, 'collection' => $collectionSlug, 'version' => $versionNumber],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
            source: 'versioning'
        );
        $this->auditService?->flush();

        $msg = $this->trans('versioning.version_deleted_successfully', ['%version%' => $versionNumber], "Version %version% was successfully deleted.");
        return new JsonResponse(['success' => true, 'message' => $msg], 200, self::SECURITY_HEADERS);
    }

    #[Route('/api/projects/{projectUuid}/versions/prune', name: 'project_versions_prune', methods: ['POST'])]
    public function pruneProjectVersions(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse(['error' => $this->trans('versioning.payload_too_large', [], 'Payload too large.')], 413, self::SECURITY_HEADERS);
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $projectUuid)) {
            return new JsonResponse(['error' => $this->trans('versioning.project_not_found', [], 'Project not found.')], 404, self::SECURITY_HEADERS);
        }

        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('versioning.project_not_found', [], 'Project not found.')], 404, self::SECURITY_HEADERS);
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $project) {
            return new JsonResponse(['error' => $this->trans('versioning.auth_required', [], 'Authentication required.')], 401, self::SECURITY_HEADERS);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $project);
        }

        $days = null;
        $keep = null;

        if ($request->query->has('days')) {
            $days = $request->query->getInt('days');
        }
        if ($request->query->has('keep')) {
            $keep = $request->query->getInt('keep');
        }

        if ($request->getContent()) {
            try {
                $payload = $request->toArray();
                if (isset($payload['days'])) {
                    $days = (int) $payload['days'];
                }
                if (isset($payload['keep'])) {
                    $keep = (int) $payload['keep'];
                }
            } catch (\Throwable) {}
        }

        if ($days === null && $keep === null) {
            return new JsonResponse(['error' => $this->trans('versioning.prune_params_required', [], 'Either days or keep parameter is required.')], 422, self::SECURITY_HEADERS);
        }

        $totalPruned = 0;
        if ($days !== null) {
            if ($days < 1 || $days > 365) {
                return new JsonResponse(['error' => $this->trans('versioning.days_invalid', [], 'Invalid days parameter (must be between 1 and 365).')], 422, self::SECURITY_HEADERS);
            }
            $totalPruned += $this->versioning->pruneOlderThanForProject($project, $days);
        }

        if ($keep !== null) {
            if ($keep < 1 || $keep > 100) {
                return new JsonResponse(['error' => $this->trans('versioning.keep_invalid', [], 'Invalid keep count parameter (must be between 1 and 100).')], 422, self::SECURITY_HEADERS);
            }
            $totalPruned += $this->versioning->pruneProjectEntries($project, $keep);
        }

        $this->auditService?->log(
            toolName: 'project_versions_prune',
            project: $project,
            input: ['days' => $days, 'keep' => $keep],
            output: ['pruned_count' => $totalPruned],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
            source: 'versioning'
        );
        $this->auditService?->flush();

        $msg = $this->trans('versioning.project_versions_pruned_successfully', ['%count%' => $totalPruned], "Project versions pruned successfully (%count% removed).");
        return new JsonResponse(['success' => true, 'pruned' => $totalPruned, 'message' => $msg], 200, self::SECURITY_HEADERS);
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/{versionNumber}/label', name: 'versions_update_label', requirements: ['versionNumber' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function updateLabel(string $projectUuid, string $collectionSlug, string $uuid, int $versionNumber, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse(['error' => $this->trans('versioning.payload_too_large', [], 'Payload too large.')], 413, self::SECURITY_HEADERS);
        }

        if ($versionNumber < 1 || $versionNumber > 1000000) {
            return new JsonResponse(['error' => $this->trans('versioning.invalid_version', [], 'Invalid version number.')], 422, self::SECURITY_HEADERS);
        }

        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return new JsonResponse(['error' => $this->trans('versioning.entry_not_found', [], 'Entry not found.')], 404, self::SECURITY_HEADERS);

        if (!$this->getUser() && $request->attributes->get('_project') !== $entry->project) {
            return new JsonResponse(['error' => $this->trans('versioning.auth_required', [], 'Authentication required.')], 401, self::SECURITY_HEADERS);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $entry->project);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('versioning.invalid_json', [], 'Invalid JSON payload.')], 400, self::SECURITY_HEADERS);
        }

        $label = $payload['label'] ?? null;
        if ($label !== null && !is_string($label)) {
            return new JsonResponse(['error' => $this->trans('versioning.label_invalid_type', [], 'Label must be a string.')], 422, self::SECURITY_HEADERS);
        }

        $updated = $this->versioning->updateVersionLabel($entry, $versionNumber, $label);
        if (!$updated) {
            return new JsonResponse(['error' => $this->trans('versioning.version_not_found', [], 'Version not found.')], 404, self::SECURITY_HEADERS);
        }

        $this->auditService?->log(
            toolName: 'version_label_update',
            project: $entry->project,
            input: ['entry_uuid' => $uuid, 'version' => $versionNumber, 'label' => $updated->label],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
            source: 'versioning'
        );
        $this->auditService?->flush();

        $msg = $this->trans('versioning.label_updated_successfully', [], 'Version label updated successfully.');
        return new JsonResponse(['success' => true, 'label' => $updated->label, 'message' => $msg], 200, self::SECURITY_HEADERS);
    }

    private function trans(string $key, array|string $paramsOrDefault = [], array|string $defaultOrParams = []): string
    {
        if (is_array($paramsOrDefault)) {
            $params = $paramsOrDefault;
            $default = is_string($defaultOrParams) ? $defaultOrParams : '';
        } else {
            $default = $paramsOrDefault;
            $params = is_array($defaultOrParams) ? $defaultOrParams : [];
        }

        if ($this->translator !== null) {
            $translated = $this->translator->trans($key, $params, 'messages');
            if ($translated !== $key) {
                return $translated;
            }
        }
        return $default !== '' ? strtr($default, $params) : $key;
    }

    private function findEntry(string $projectUuid, string $collectionSlug, string $uuid): ?ContentEntry
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $projectUuid)) {
            return null;
        }
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $collectionSlug)) {
            return null;
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
            if (!$project) return null;

            $collection = $this->em->getRepository(Collection::class)->findOneBy([
                'project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null,
            ]);
            if (!$collection) return null;

            return $this->em->getRepository(ContentEntry::class)->findOneBy([
                'collection' => $collection, 'uuid' => $uuid, 'deletedAt' => null,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
