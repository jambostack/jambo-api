<?php

namespace App\Controller\UserManagement;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\PermissionRepository;
use App\Repository\RoleRepository;
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

#[Route('/api/roles', name: 'api_roles_')]
#[IsGranted('ROLE_USER')]
class RoleController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    public const MAX_BULK_IDS = 100;
    public const MAX_PERMISSIONS_PER_ROLE = 200;
    public const MAX_ROLES_COUNT = 50000;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'Pragma'                 => 'no-cache',
    ];

    private const PROTECTED_ROLES = [
        'ROLE_SUPER_ADMIN',
        'ROLE_ADMIN',
        'ROLE_USER',
        'admin',
        'super_admin',
    ];

    private const ADMINISTRATIVE_PERMISSIONS = [
        'roles.manage',
        'users.manage',
        'permissions.manage',
    ];

    public function __construct(
        private RoleRepository $roleRepository,
        private PermissionRepository $permissionRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private TranslatorInterface $translator,
        private AuditService $auditService,
        #[Autowire(service: 'limiter.token_management_limiter')]
        private ?RateLimiterFactory $roleLimiter = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function isJsonContentType(Request $request): bool
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType === '') {
            return false;
        }
        $cleanType = strtolower(trim(explode(';', $contentType)[0]));
        return in_array($cleanType, ['application/json', 'application/merge-patch+json'], true);
    }

    private function isProtectedRole(string $name): bool
    {
        $normalized = strtolower(trim($name));
        $protected = array_map('strtolower', self::PROTECTED_ROLES);
        return in_array($normalized, $protected, true)
            || $normalized === 'super_admin'
            || $normalized === 'role_super_admin'
            || $normalized === 'admin'
            || $normalized === 'role_admin'
            || $normalized === 'role_user'
            || $normalized === 'root'
            || $normalized === 'system';
    }

    private function getCurrentUserIdentifier(): ?string
    {
        try {
            $user = $this->getUser();
            return $user?->getUserIdentifier();
        } catch (\Throwable) {
            return null;
        }
    }

    private function checkRateLimit(?Request $request = null): ?JsonResponse
    {
        if ($this->roleLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            $userId = null;
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                    $userId = $identifier;
                }
            } catch (\Throwable) {}

            if (false === $this->roleLimiter->create($identifier)->consume(1)->isAccepted()) {
                $this->auditService->log(
                    toolName: 'role_rate_limit_exceeded',
                    project: null,
                    input: ['ip' => $request->getClientIp(), 'uri' => $request->getRequestUri()],
                    output: ['error' => 'rate_limit_exceeded'],
                    status: 'failure',
                    errorMessage: 'Rate limit exceeded on role management endpoint',
                    createdBy: $userId,
                    source: 'user_management'
                );
                $this->auditService->flush();

                return $this->secureJson([
                    'error' => $this->translator->trans('role.admin.rate_limit_exceeded', [], 'messages'),
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

        $allowedSorts = ['name' => 'r.name', 'created_at' => 'r.id', 'updated_at' => 'r.id'];
        $orderBy = $allowedSorts[$sortField] ?? 'r.name';

        $qb = $this->roleRepository->createQueryBuilder('r');

        if ($search !== '') {
            $qb->andWhere('r.name LIKE :search OR r.label LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        if ($filterName !== '') {
            $qb->andWhere('r.name LIKE :fname OR r.label LIKE :fname')
               ->setParameter('fname', '%' . $filterName . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $roles = $qb->select('r')->orderBy($orderBy, $direction)
                    ->setFirstResult(($page - 1) * $perPage)
                    ->setMaxResults($perPage)
                    ->getQuery()->getResult();

        $lastPage = max(1, (int) ceil($total / $perPage));
        $from     = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
        $to       = min($page * $perPage, $total);

        return $this->secureJson([
            'data'         => array_map(fn ($r) => $this->serialize($r), $roles),
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => $lastPage,
            'per_page'     => $perPage,
            'from'         => $from,
            'to'           => $to,
        ]);
    }

    #[Route('/bulk-delete', name: 'bulk_delete', methods: ['POST'], priority: 10)]
    #[IsGranted('roles.manage')]
    public function bulkDelete(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (!$this->isJsonContentType($request)) {
            $this->auditService->log(
                toolName: 'role_invalid_content_type',
                project: null,
                input: ['content_type' => $request->headers->get('Content-Type'), 'uri' => $request->getRequestUri()],
                output: ['error' => 'invalid_content_type'],
                status: 'failure',
                errorMessage: 'Content-Type must be application/json.',
                createdBy: $this->getCurrentUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();

            return $this->secureJson([
                'error' => $this->translator->trans('role.admin.invalid_content_type', [], 'messages'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.payload_too_large')], 413);
        }

        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.demo_mode_disabled')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.invalid_json')], 400);
        }
        $ids      = $data['ids'] ?? [];
        $password = (string) ($data['password'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            return $this->secureJson(['errors' => ['ids' => [$this->translator->trans('role.admin.no_roles_selected')]]], 422);
        }

        if (count($ids) > self::MAX_BULK_IDS) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.bulk_limit_exceeded', ['%max%' => self::MAX_BULK_IDS])], 422);
        }

        foreach ($ids as $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                return $this->secureJson(['errors' => ['ids' => [$this->translator->trans('role.admin.invalid_id')]]], 422);
            }
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->hasher->isPasswordValid($currentUser, $password)) {
            $this->auditService->log(
                toolName: 'role_bulk_delete_failed_auth',
                project: null,
                input: ['attempted_by' => $currentUser->getUserIdentifier()],
                output: ['error' => 'invalid_password'],
                status: 'failure',
                errorMessage: 'Invalid confirmation password',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('role.admin.invalid_password')], 403);
        }

        $deleted = 0;
        $deletedIds = [];
        foreach ($ids as $id) {
            $role = $this->roleRepository->find((int) $id);
            if ($role === null || $role->users->count() > 0 || $this->isProtectedRole($role->name)) {
                continue;
            }

            // Check if role is assigned to any ProjectMember
            $assignedMembersCount = (int) $this->em->createQuery(
                'SELECT COUNT(pm.id) FROM App\Entity\ProjectMember pm WHERE pm.role = :role'
            )->setParameter('role', $role)->getSingleScalarResult();

            if ($assignedMembersCount > 0) {
                continue;
            }

            $this->em->remove($role);
            $deletedIds[] = $role->id;
            $deleted++;
        }
        $this->em->flush();

        if ($deleted > 0) {
            $this->auditService->log(
                toolName: 'role_bulk_delete',
                project: null,
                input: ['ids' => $deletedIds, 'count' => $deleted],
                output: ['deleted' => $deleted],
                status: 'success',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
        }

        return $this->secureJson(['message' => $this->translator->trans('role.admin.deleted_success', ['%count%' => $deleted])]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimitResponse = $this->checkRateLimit($request))) {
            return $rateLimitResponse;
        }

        $role = $this->roleRepository->find($id);
        if ($role === null) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.not_found')], 404);
        }

        return $this->secureJson($this->serialize($role, true));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('roles.manage')]
    public function create(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (!$this->isJsonContentType($request)) {
            $this->auditService->log(
                toolName: 'role_invalid_content_type',
                project: null,
                input: ['content_type' => $request->headers->get('Content-Type'), 'uri' => $request->getRequestUri()],
                output: ['error' => 'invalid_content_type'],
                status: 'failure',
                errorMessage: 'Content-Type must be application/json.',
                createdBy: $this->getCurrentUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();

            return $this->secureJson([
                'error' => $this->translator->trans('role.admin.invalid_content_type', [], 'messages'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.payload_too_large')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.invalid_json')], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_required')]], 422);
        }

        if (mb_strlen($name) > 100) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_too_long')]], 422);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.invalid_name_format')]], 422);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_control_chars')]], 422);
        }

        $label = trim((string) ($data['label'] ?? $name));
        if (mb_strlen($label) > 255) {
            return $this->secureJson(['errors' => ['label' => $this->translator->trans('role.admin.label_too_long')]], 422);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $label)) {
            return $this->secureJson(['errors' => ['label' => $this->translator->trans('role.admin.label_control_chars')]], 422);
        }

        /** @var ?User $currentUser */
        $currentUser = null;
        try {
            $currentUser = $this->getUser();
        } catch (\Throwable) {}

        $existing = $this->roleRepository->findByName($name);

        // Anti-privilege escalation: reserved system role name
        if ($this->isProtectedRole($name)) {
            if ($existing !== null) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_exists')]], 422);
            }
            if ($currentUser !== null && !$currentUser->isSuperAdmin()) {
                $this->auditService->log(
                    toolName: 'role_create_privilege_escalation_attempt',
                    project: null,
                    input: ['attempted_role_name' => $name],
                    output: ['error' => 'protected_system_role'],
                    status: 'failure',
                    errorMessage: 'Attempt to create protected system role by non-superadmin',
                    createdBy: $currentUser->getUserIdentifier(),
                    source: 'user_management'
                );
                $this->auditService->flush();
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.cannot_create_protected_role')]], 403);
            }
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.cannot_create_protected_role')]], 422);
        }

        if ($existing !== null) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_exists')]], 422);
        }

        $permissions = $data['permissions'] ?? null;
        if ($permissions !== null && !is_array($permissions)) {
            return $this->secureJson(['errors' => ['permissions' => $this->translator->trans('role.admin.permissions_must_be_array')]], 422);
        }

        if (is_array($permissions)) {
            if (count($permissions) > self::MAX_PERMISSIONS_PER_ROLE) {
                return $this->secureJson(['errors' => ['permissions' => $this->translator->trans('role.admin.permissions_limit_exceeded')]], 422);
            }

            // Anti-privilege escalation: cannot assign permissions not possessed
            if ($currentUser !== null && !$currentUser->isSuperAdmin()) {
                foreach ($permissions as $permName) {
                    $permNameStr = (string) $permName;
                    if (!$currentUser->hasPermission($permNameStr)) {
                        $this->auditService->log(
                            toolName: 'role_create_privilege_escalation_attempt',
                            project: null,
                            input: ['role_name' => $name, 'permission' => $permNameStr],
                            output: ['error' => 'unowned_permission'],
                            status: 'failure',
                            errorMessage: 'Attempt to assign unowned permission',
                            createdBy: $currentUser->getUserIdentifier(),
                            source: 'user_management'
                        );
                        $this->auditService->flush();
                        return $this->secureJson(['error' => $this->translator->trans('role.admin.cannot_assign_unowned_permissions')], 403);
                    }
                }
            }

            // Validate permission existence
            foreach ($permissions as $permName) {
                $permNameStr = (string) $permName;
                $permObj = $this->permissionRepository->findOneBy(['name' => $permNameStr]);
                if ($permObj === null) {
                    return $this->secureJson(['errors' => ['permissions' => $this->translator->trans('role.admin.invalid_permission')]], 422);
                }
            }
        }

        $totalRoles = $this->roleRepository->count([]);
        if ($totalRoles >= self::MAX_ROLES_COUNT) {
            return $this->secureJson([
                'error' => $this->translator->trans('role.admin.max_roles_exceeded', ['%max%' => self::MAX_ROLES_COUNT]),
                'code' => 'MAX_ROLES_EXCEEDED',
            ], 422);
        }

        $role = new Role();
        $role->name  = $name;
        $role->label = $label ?: $name;

        if (is_array($permissions)) {
            $this->attachPermissions($role, $permissions);
        }

        $this->em->persist($role);
        $this->em->flush();

        $this->auditService->log(
            toolName: 'role_create',
            project: null,
            input: ['id' => $role->id, 'name' => $role->name],
            output: ['success' => true, 'id' => $role->id],
            status: 'success',
            createdBy: $currentUser?->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(array_merge($this->serialize($role, true), [
            'message' => $this->translator->trans('role.admin.created_success'),
        ]), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('roles.manage')]
    public function update(int $id, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (!$this->isJsonContentType($request)) {
            $this->auditService->log(
                toolName: 'role_invalid_content_type',
                project: null,
                input: ['content_type' => $request->headers->get('Content-Type'), 'uri' => $request->getRequestUri()],
                output: ['error' => 'invalid_content_type'],
                status: 'failure',
                errorMessage: 'Content-Type must be application/json.',
                createdBy: $this->getCurrentUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();

            return $this->secureJson([
                'error' => $this->translator->trans('role.admin.invalid_content_type', [], 'messages'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.payload_too_large')], 413);
        }

        $role = $this->roleRepository->find($id);
        if ($role === null) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // System roles cannot be renamed or modified by non-superadmins
        if ($this->isProtectedRole($role->name) && !$currentUser->isSuperAdmin()) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.system_role_protected')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.invalid_json')], 400);
        }

        if (isset($data['name'])) {
            $newName = trim((string) $data['name']);
            if ($newName === '') {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_cannot_be_empty')]], 422);
            }
            if (mb_strlen($newName) > 100) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_too_long')]], 422);
            }
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $newName)) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.invalid_name_format')]], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $newName)) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_control_chars')]], 422);
            }

            // Cannot rename an already protected role even if superadmin
            if ($this->isProtectedRole($role->name) && strtolower($newName) !== strtolower($role->name)) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.system_role_cannot_rename')]], 422);
            }

            // Cannot rename a non-protected role to a protected role name
            if (!$this->isProtectedRole($role->name) && $this->isProtectedRole($newName)) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.cannot_create_protected_role')]], 403);
            }

            $existing = $this->roleRepository->findByName($newName);
            if ($existing !== null && $existing->id !== $role->id) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('role.admin.name_exists')]], 422);
            }
            $role->name = $newName;
        }

        if (isset($data['label'])) {
            $newLabel = trim((string) $data['label']);
            if (mb_strlen($newLabel) > 255) {
                return $this->secureJson(['errors' => ['label' => $this->translator->trans('role.admin.label_too_long')]], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $newLabel)) {
                return $this->secureJson(['errors' => ['label' => $this->translator->trans('role.admin.label_control_chars')]], 422);
            }
            $role->label = $newLabel;
        } elseif (isset($data['name'])) {
            $role->label = $role->name;
        }

        if (isset($data['permissions'])) {
            if (!is_array($data['permissions'])) {
                return $this->secureJson(['errors' => ['permissions' => $this->translator->trans('role.admin.permissions_must_be_array')]], 422);
            }

            if (count($data['permissions']) > self::MAX_PERMISSIONS_PER_ROLE) {
                return $this->secureJson(['errors' => ['permissions' => $this->translator->trans('role.admin.permissions_limit_exceeded')]], 422);
            }

            // Anti-privilege escalation: cannot assign permissions not possessed
            if (!$currentUser->isSuperAdmin()) {
                foreach ($data['permissions'] as $permName) {
                    $permNameStr = (string) $permName;
                    if (!$currentUser->hasPermission($permNameStr)) {
                        $this->auditService->log(
                            toolName: 'role_update_privilege_escalation_attempt',
                            project: null,
                            input: ['role_id' => $role->id, 'permission' => $permNameStr],
                            output: ['error' => 'unowned_permission'],
                            status: 'failure',
                            errorMessage: 'Attempt to assign unowned permission during role update',
                            createdBy: $currentUser->getUserIdentifier(),
                            source: 'user_management'
                        );
                        $this->auditService->flush();
                        return $this->secureJson(['error' => $this->translator->trans('role.admin.cannot_assign_unowned_permissions')], 403);
                    }
                }
            }

            // Validate permission existence
            foreach ($data['permissions'] as $permName) {
                $permNameStr = (string) $permName;
                $permObj = $this->permissionRepository->findOneBy(['name' => $permNameStr]);
                if ($permObj === null) {
                    return $this->secureJson(['errors' => ['permissions' => $this->translator->trans('role.admin.invalid_permission')]], 422);
                }
            }

            $role->permissions->clear();
            $this->attachPermissions($role, $data['permissions']);
        }

        $this->em->flush();

        $this->auditService->log(
            toolName: 'role_update',
            project: null,
            input: ['id' => $role->id, 'name' => $role->name],
            output: ['success' => true, 'id' => $role->id],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(array_merge($this->serialize($role, true), [
            'message' => $this->translator->trans('role.admin.updated_success'),
        ]));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('roles.manage')]
    public function delete(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimitResponse = $this->checkRateLimit($request))) {
            return $rateLimitResponse;
        }

        $role = $this->roleRepository->find($id);
        if ($role === null) {
            return $this->secureJson(['error' => $this->translator->trans('role.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $roleId = $role->id;
        $roleName = $role->name;

        if ($this->isProtectedRole($role->name)) {
            $this->auditService->log(
                toolName: 'role_delete_failed_system_role',
                project: null,
                input: ['id' => $roleId, 'name' => $roleName],
                output: ['error' => 'protected_system_role'],
                status: 'failure',
                errorMessage: 'Attempt to delete protected system role',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('role.admin.system_role_protected')], 422);
        }

        if ($role->users->count() > 0) {
            $this->auditService->log(
                toolName: 'role_delete_failed_assigned_user',
                project: null,
                input: ['id' => $roleId, 'name' => $roleName, 'users_count' => $role->users->count()],
                output: ['error' => 'assigned_to_users'],
                status: 'failure',
                errorMessage: 'Cannot delete role assigned to users',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('role.admin.cannot_delete_assigned')], 422);
        }

        // Check if role is assigned to any ProjectMember
        $assignedMembersCount = (int) $this->em->createQuery(
            'SELECT COUNT(pm.id) FROM App\Entity\ProjectMember pm WHERE pm.role = :role'
        )->setParameter('role', $role)->getSingleScalarResult();

        if ($assignedMembersCount > 0) {
            $this->auditService->log(
                toolName: 'role_delete_failed_assigned_member',
                project: null,
                input: ['id' => $roleId, 'name' => $roleName, 'members_count' => $assignedMembersCount],
                output: ['error' => 'assigned_to_project_member'],
                status: 'failure',
                errorMessage: 'Cannot delete role assigned to project members',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('role.admin.cannot_delete_assigned_to_members')], 422);
        }

        $this->em->remove($role);
        $this->em->flush();

        $this->auditService->log(
            toolName: 'role_delete',
            project: null,
            input: ['id' => $roleId, 'name' => $roleName],
            output: ['success' => true],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(null, 204);
    }

    private function attachPermissions(Role $role, array $permissionNames): void
    {
        foreach ($permissionNames as $name) {
            $permission = $this->permissionRepository->findOneBy(['name' => (string) $name]);
            if ($permission !== null && !$role->permissions->contains($permission)) {
                $role->permissions->add($permission);
            }
        }
    }

    private function serialize(Role $role, bool $withPermissions = false): array
    {
        $data = [
            'id'          => $role->id,
            'name'        => $role->name,
            'label'       => $role->label ?: $role->name,
            'usersCount'  => $role->users->count(),
            'created_at'  => null,
            'updated_at'  => null,
        ];

        if ($withPermissions) {
            $data['permissions'] = $role->permissions->map(fn ($p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'label' => $p->label,
                'group' => $p->group,
            ])->toArray();
        }

        return $data;
    }
}
