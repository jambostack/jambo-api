<?php

namespace App\Controller;

use App\Enum\ShareDuration;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\ShareRepository;
use App\Service\AuditService;
use App\Service\Share\ShareService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/shares', name: 'api_shares_')]
class ShareController extends AbstractController
{
    public const MAX_PAYLOAD_SIZE = 16384; // 16KB
    public const MAX_PAGE_LIMIT = 100;
    public const DEFAULT_PAGE_LIMIT = 25;
    public const MAX_OFFSET = 10000;
    public const MAX_ACTIVE_SHARES_PER_ENTRY = 50;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
        'Referrer-Policy'        => 'no-referrer',
        'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none';",
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ContentEntryRepository $entryRepository,
        private readonly ShareRepository $shareRepository,
        private readonly ShareService $shareService,
        private readonly ?RateLimiterFactory $shareLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $projectUuid, Request $request): JsonResponse
    {
        if ($this->shareLimiter !== null) {
            $limiter = $this->shareLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('share.rate_limit_exceeded', [], 'Too many share requests. Try again later.')], 429);
            }
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('share.project_not_found', [], 'Project not found')], 404);
        }
        if (!$this->getUser() && $request->attributes->get('_project') !== $project) {
            return $this->secureJson(['error' => $this->trans('share.auth_required', [], 'Authentication required')], 401);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $project);
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_PAYLOAD_SIZE) {
            return $this->secureJson(['error' => $this->trans('share.payload_too_large', [], 'Payload too large (max 16KB).')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('share.invalid_json', [], 'Invalid JSON body')], 400);
        }

        $entryUuid = (string) ($data['entryUuid'] ?? '');
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $entryUuid)) {
            return $this->secureJson(['error' => $this->trans('share.entry_not_found', [], 'Entry not found')], 404);
        }

        try {
            $entry = $this->entryRepository->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        } catch (\Throwable) {
            $entry = null;
        }
        if (!$entry || $entry->isDeleted()) {
            return $this->secureJson(['error' => $this->trans('share.entry_not_found', [], 'Entry not found')], 404);
        }

        try {
            $duration = ShareDuration::fromQuery($data['duration'] ?? null);
        } catch (\InvalidArgumentException) {
            return $this->secureJson(['error' => $this->trans('share.invalid_duration', [], 'Invalid duration')], 400);
        }

        $activeSharesCount = $this->shareRepository->countActiveByEntry($entry);
        if ($activeSharesCount >= self::MAX_ACTIVE_SHARES_PER_ENTRY) {
            return $this->secureJson([
                'error' => $this->trans('share.max_shares_exceeded', [
                    '%max%' => self::MAX_ACTIVE_SHARES_PER_ENTRY,
                ], sprintf('Maximum active share links limit reached for this entry (%d).', self::MAX_ACTIVE_SHARES_PER_ENTRY)),
                'code' => 'MAX_ACTIVE_SHARES_EXCEEDED',
            ], 422);
        }

        ['share' => $share, 'plainToken' => $plain] = $this->shareService->create($entry, $duration, $this->getUser());

        $this->auditService?->log(
            toolName: 'share_create',
            project: $project,
            input: [
                'entry_uuid' => $entryUuid,
                'duration' => $data['duration'] ?? '1d',
            ],
            output: ['share_id' => $share->id],
            status: 'success',
            source: 'share_controller',
        );

        $url = $this->generateUrl('public_share_show', ['token' => $plain], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->secureJson(['data' => [
            'id'        => $share->id,
            'url'       => $url,
            'expiresAt' => $share->expiresAt?->format('c'),
            'createdAt' => $share->createdAt->format('c'),
        ]], 201);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, ?Request $request = null): JsonResponse
    {
        if ($this->shareLimiter !== null && $request !== null) {
            $limiter = $this->shareLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('share.rate_limit_exceeded', [], 'Too many share requests. Try again later.')], 429);
            }
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('share.project_not_found', [], 'Project not found')], 404);
        }
        if ($request !== null && !$this->getUser() && $request->attributes->get('_project') !== $project) {
            return $this->secureJson(['error' => $this->trans('share.auth_required', [], 'Authentication required')], 401);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $project);
        }

        $entryUuid = $request !== null ? (string) $request->query->get('entryUuid', '') : '';
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $entryUuid)) {
            return $this->secureJson(['error' => $this->trans('share.entry_not_found', [], 'Entry not found')], 404);
        }

        try {
            $entry = $this->entryRepository->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        } catch (\Throwable) {
            $entry = null;
        }
        if (!$entry) {
            return $this->secureJson(['error' => $this->trans('share.entry_not_found', [], 'Entry not found')], 404);
        }

        $limit = $request !== null ? min(self::MAX_PAGE_LIMIT, max(1, $request->query->getInt('limit', self::DEFAULT_PAGE_LIMIT))) : self::DEFAULT_PAGE_LIMIT;
        $page = $request !== null ? max(1, $request->query->getInt('page', 1)) : 1;
        $offset = min(self::MAX_OFFSET, ($page - 1) * $limit);

        $shares = $this->shareRepository->findByEntry($entry, $limit, $offset);
        $total = $this->shareRepository->countByEntry($entry);

        return $this->secureJson([
            'data' => array_map(fn ($s) => [
                'id'             => $s->id,
                'expiresAt'      => $s->expiresAt?->format('c'),
                'revokedAt'      => $s->revokedAt?->format('c'),
                'lastAccessedAt' => $s->lastAccessedAt?->format('c'),
                'viewCount'      => $s->viewCount,
                'createdAt'      => $s->createdAt->format('c'),
                'isValid'        => $s->isValid(),
                'isExpired'      => $s->isExpired(),
                'isRevoked'      => $s->isRevoked(),
            ], $shares),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ],
        ]);
    }

    #[Route('/{id}', name: 'destroy', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function destroy(string $projectUuid, int $id, ?Request $request = null): JsonResponse
    {
        if ($this->shareLimiter !== null && $request !== null) {
            $limiter = $this->shareLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('share.rate_limit_exceeded', [], 'Too many share requests. Try again later.')], 429);
            }
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('share.project_not_found', [], 'Project not found')], 404);
        }
        if ($request !== null && !$this->getUser() && $request->attributes->get('_project') !== $project) {
            return $this->secureJson(['error' => $this->trans('share.auth_required', [], 'Authentication required')], 401);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $project);
        }

        if ($id <= 0) {
            return $this->secureJson(['error' => $this->trans('share.not_found', [], 'Share not found')], 404);
        }

        $share = $this->shareRepository->find($id);
        if (!$share || $share->project?->id !== $project->id) {
            return $this->secureJson(['error' => $this->trans('share.not_found', [], 'Share not found')], 404);
        }

        $this->shareService->revoke($share);

        $this->auditService?->log(
            toolName: 'share_revoke',
            project: $project,
            input: ['share_id' => $id],
            output: null,
            status: 'success',
            source: 'share_controller',
        );

        return $this->secureJson(null, 204);
    }

    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $projectUuid)) {
            return null;
        }

        try {
            return $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }
}
