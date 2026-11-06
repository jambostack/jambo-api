<?php

namespace App\Controller\UserManagement;

use App\Entity\Permission;
use App\Entity\User;
use App\Repository\PermissionRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/permissions', name: 'api_permissions_')]
#[IsGranted('ROLE_USER')]
class PermissionController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    public const MAX_BULK_IDS = 100;
    public const MAX_PERMISSIONS_COUNT = 5000;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'Pragma'                 => 'no-cache',
    ];

    public const PROTECTED_PERMISSIONS = [
        'users.manage',
        'roles.manage',
        'project.manage',
        'project.create',
        'collection.create',
        'collection.update',
        'collection.delete',
        'content.create',
        'content.update',
        'content.delete',
        'content.trash',
        'content.restore',
        'assets.view',
    ];

    public function __construct(
        private PermissionRepository $permissionRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private TranslatorInterface $translator,
        private AuditService $auditService,
        #[Autowire(service: 'limiter.token_management_limiter')]
        private ?RateLimiterFactory $permissionLimiter = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function checkRateLimit(?Request $request = null): ?JsonResponse
    {
        if ($this->permissionLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->permissionLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->translator->trans('permission.admin.rate_limit_exceeded', [], 'messages'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }
        $search    = mb_substr(trim((string) $request->query->get('search', '')), 0, 100);
        $page      = max(1, $request->query->getInt('page', 1));
        $perPage   = min(100, max(1, $request->query->getInt('per_page', 20)));
        $sortField = $request->query->get('sort', 'name');
        $direction = strtoupper($request->query->get('direction', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        $filterName = mb_substr(trim((string) $request->query->get('filter_name', '')), 0, 100);

        $allowedSorts = ['name' => 'p.name', 'group' => 'p.group', 'label' => 'p.label'];
        $orderBy = $allowedSorts[$sortField] ?? 'p.name';

        $qb = $this->permissionRepository->createQueryBuilder('p');

        if ($search !== '') {
            $qb->andWhere('p.name LIKE :search OR p.label LIKE :search OR p.group LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        if ($filterName !== '') {
            $qb->andWhere('p.name LIKE :fname OR p.label LIKE :fname')
               ->setParameter('fname', '%' . $filterName . '%');
        }

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $permissions = $qb->select('p')->orderBy($orderBy, $direction)
                          ->setFirstResult(($page - 1) * $perPage)
                          ->setMaxResults($perPage)
                          ->getQuery()->getResult();

        $lastPage = max(1, (int) ceil($total / $perPage));
        $from     = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
        $to       = min($page * $perPage, $total);

        return $this->secureJson([
            'data'         => array_map(fn ($p) => $this->serialize($p), $permissions),
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => $lastPage,
            'per_page'     => $perPage,
            'from'         => $from,
            'to'           => $to,
        ]);
    }

    #[Route('/bulk-delete', name: 'bulk_delete', methods: ['POST'], priority: 10)]
    #[IsGranted('users.manage')]
    public function bulkDelete(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.payload_too_large')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.invalid_json')], 400);
        }
        $ids      = $data['ids'] ?? [];
        $password = (string) ($data['password'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            return $this->secureJson(['errors' => ['ids' => [$this->translator->trans('permission.admin.no_permissions_selected')]]], 422);
        }

        if (count($ids) > self::MAX_BULK_IDS) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.bulk_limit_exceeded', ['%max%' => self::MAX_BULK_IDS])], 422);
        }

        foreach ($ids as $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                return $this->secureJson(['errors' => ['ids' => [$this->translator->trans('permission.admin.invalid_id')]]], 422);
            }
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->hasher->isPasswordValid($currentUser, $password)) {
            $this->auditService->log(
                toolName: 'permission_bulk_delete_failed_auth',
                project: null,
                input: ['attempted_by' => $currentUser->getUserIdentifier()],
                output: ['error' => 'invalid_password'],
                status: 'failure',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.invalid_password')], 403);
        }

        $deleted = 0;
        $deletedIds = [];
        foreach ($ids as $id) {
            $permission = $this->permissionRepository->find((int) $id);
            if ($permission === null || in_array($permission->name, self::PROTECTED_PERMISSIONS, true)) {
                continue;
            }

            $isAssigned = (int) $this->em->createQuery(
                'SELECT COUNT(r.id) FROM App\Entity\Role r JOIN r.permissions p WHERE p.id = :pid'
            )->setParameter('pid', $permission->id)->getSingleScalarResult() > 0;

            if ($isAssigned) {
                continue;
            }

            $this->em->remove($permission);
            $deletedIds[] = $permission->id;
            $deleted++;
        }
        $this->em->flush();

        if ($deleted > 0) {
            $this->auditService->log(
                toolName: 'permission_bulk_delete',
                project: null,
                input: ['ids' => $deletedIds, 'count' => $deleted],
                output: ['deleted' => $deleted],
                status: 'success',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
        }

        return $this->secureJson(['message' => $this->translator->trans('permission.admin.deleted_success', ['%count%' => $deleted])]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimitResponse = $this->checkRateLimit($request))) {
            return $rateLimitResponse;
        }

        $permission = $this->permissionRepository->find($id);
        if ($permission === null) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.not_found')], 404);
        }

        return $this->secureJson($this->serialize($permission));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('users.manage')]
    public function create(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.payload_too_large')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.invalid_json')], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('permission.admin.name_required')]], 422);
        }

        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $name)) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('permission.admin.invalid_name_format')]], 422);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('permission.admin.name_control_chars')]], 422);
        }

        $existing = $this->permissionRepository->findOneBy(['name' => $name]);
        if ($existing !== null) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('permission.admin.name_exists')]], 422);
        }

        $permission = new Permission();
        $permission->name  = $name;
        $label = trim((string) ($data['label'] ?? $name)) ?: $name;
        if (mb_strlen($label) > 255) {
            return $this->secureJson(['errors' => ['label' => $this->translator->trans('permission.admin.label_too_long')]], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $label)) {
            return $this->secureJson(['errors' => ['label' => $this->translator->trans('permission.admin.label_control_chars')]], 422);
        }

        $group = trim((string) ($data['group'] ?? 'custom')) ?: 'custom';
        if (mb_strlen($group) > 100) {
            return $this->secureJson(['errors' => ['group' => $this->translator->trans('permission.admin.group_too_long')]], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $group)) {
            return $this->secureJson(['errors' => ['group' => $this->translator->trans('permission.admin.group_control_chars')]], 422);
        }

        $totalPerms = $this->permissionRepository->count([]);
        if ($totalPerms >= self::MAX_PERMISSIONS_COUNT) {
            return $this->secureJson([
                'error' => $this->translator->trans('permission.admin.max_permissions_exceeded', ['%max%' => self::MAX_PERMISSIONS_COUNT]),
                'code' => 'MAX_PERMISSIONS_EXCEEDED',
            ], 422);
        }

        $permission->label = $label;
        $permission->group = $group;

        $this->em->persist($permission);
        $this->em->flush();

        /** @var ?User $currentUser */
        $currentUser = null;
        try {
            $currentUser = $this->getUser();
        } catch (\Throwable) {}
        $this->auditService->log(
            toolName: 'permission_create',
            project: null,
            input: ['id' => $permission->id, 'name' => $permission->name],
            output: ['success' => true, 'id' => $permission->id],
            status: 'success',
            createdBy: $currentUser?->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(array_merge($this->serialize($permission), [
            'message' => $this->translator->trans('permission.admin.created_success'),
        ]), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('users.manage')]
    public function update(int $id, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.payload_too_large')], 413);
        }

        $permission = $this->permissionRepository->find($id);
        if ($permission === null) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.not_found')], 404);
        }

        if (in_array($permission->name, self::PROTECTED_PERMISSIONS, true)) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.system_permission_protected')], 422);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.invalid_json')], 400);
        }

        if (isset($data['name'])) {
            $newName = trim((string) $data['name']);
            if ($newName === '') {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('permission.admin.name_required')]], 422);
            }
            if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $newName)) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('permission.admin.invalid_name_format')]], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $newName)) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('permission.admin.name_control_chars')]], 422);
            }
            $existing = $this->permissionRepository->findOneBy(['name' => $newName]);
            if ($existing !== null && $existing->id !== $permission->id) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('permission.admin.name_exists')]], 422);
            }
            $permission->name = $newName;
        }

        if (isset($data['label'])) {
            $newLabel = trim((string) $data['label']) ?: $permission->name;
            if (mb_strlen($newLabel) > 255) {
                return $this->secureJson(['errors' => ['label' => $this->translator->trans('permission.admin.label_too_long')]], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $newLabel)) {
                return $this->secureJson(['errors' => ['label' => $this->translator->trans('permission.admin.label_control_chars')]], 422);
            }
            $permission->label = $newLabel;
        }
        if (isset($data['group'])) {
            $newGroup = trim((string) $data['group']) ?: 'custom';
            if (mb_strlen($newGroup) > 100) {
                return $this->secureJson(['errors' => ['group' => $this->translator->trans('permission.admin.group_too_long')]], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $newGroup)) {
                return $this->secureJson(['errors' => ['group' => $this->translator->trans('permission.admin.group_control_chars')]], 422);
            }
            $permission->group = $newGroup;
        }

        $this->em->flush();

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $this->auditService->log(
            toolName: 'permission_update',
            project: null,
            input: ['id' => $permission->id, 'name' => $permission->name],
            output: ['success' => true, 'id' => $permission->id],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(array_merge($this->serialize($permission), [
            'message' => $this->translator->trans('permission.admin.updated_success'),
        ]));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('users.manage')]
    public function delete(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimitResponse = $this->checkRateLimit($request))) {
            return $rateLimitResponse;
        }

        $permission = $this->permissionRepository->find($id);
        if ($permission === null) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.not_found')], 404);
        }

        if (in_array($permission->name, self::PROTECTED_PERMISSIONS, true)) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.system_permission_protected')], 422);
        }

        $isAssigned = (int) $this->em->createQuery(
            'SELECT COUNT(r.id) FROM App\Entity\Role r JOIN r.permissions p WHERE p.id = :pid'
        )->setParameter('pid', $permission->id)->getSingleScalarResult() > 0;

        if ($isAssigned) {
            return $this->secureJson(['error' => $this->translator->trans('permission.admin.cannot_delete_assigned')], 422);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $permId = $permission->id;
        $permName = $permission->name;

        $this->em->remove($permission);
        $this->em->flush();

        $this->auditService->log(
            toolName: 'permission_delete',
            project: null,
            input: ['id' => $permId, 'name' => $permName],
            output: ['success' => true],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(['message' => $this->translator->trans('permission.admin.single_deleted_success')]);
    }

    private function serialize(Permission $p): array
    {
        return [
            'id'    => $p->id,
            'name'  => $p->name,
            'label' => $p->label,
            'group' => $p->group,
        ];
    }
}
