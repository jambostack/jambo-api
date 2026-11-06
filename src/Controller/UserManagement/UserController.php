<?php

namespace App\Controller\UserManagement;

use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\ProjectAuthLockoutService;
use App\Service\Security\PasswordPolicyValidator;
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

#[Route('/api/users', name: 'api_users_')]
#[IsGranted('ROLE_USER')]
class UserController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    public const MAX_BULK_IDS = 100;
    public const MAX_USERS_COUNT = 10000;

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

    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private TranslatorInterface $translator,
        private AuditService $auditService,
        private ?ProjectAuthLockoutService $lockoutService = null,
        #[Autowire(service: 'limiter.token_management_limiter')]
        private ?RateLimiterFactory $userLimiter = null,
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
        if ($this->userLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            $userId = null;
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                    $userId = $identifier;
                }
            } catch (\Throwable) {}

            if (false === $this->userLimiter->create($identifier)->consume(1)->isAccepted()) {
                $this->auditService->log(
                    toolName: 'user_rate_limit_exceeded',
                    project: null,
                    input: ['ip' => $request->getClientIp(), 'uri' => $request->getRequestUri()],
                    output: ['error' => 'rate_limit_exceeded'],
                    status: 'failure',
                    errorMessage: 'Rate limit exceeded on user management endpoint',
                    createdBy: $userId,
                    source: 'user_management'
                );
                $this->auditService->flush();

                return $this->secureJson([
                    'error' => $this->translator->trans('user.admin.rate_limit_exceeded', [], 'messages'),
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

        $filterName  = mb_substr(trim((string) $request->query->get('filter_name', '')), 0, 100);
        $filterEmail = mb_substr(trim((string) $request->query->get('filter_email', '')), 0, 100);

        $allowedSorts = ['name' => 'u.name', 'email' => 'u.email', 'created_at' => 'u.createdAt'];
        $orderBy = $allowedSorts[$sortField] ?? 'u.name';

        $qb = $this->userRepository->createQueryBuilder('u')
            ->leftJoin('u.userRoles', 'r')
            ->addSelect('r');

        if ($search !== '') {
            $qb->andWhere('u.name LIKE :search OR u.email LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        if ($filterName !== '') {
            $qb->andWhere('u.name LIKE :fname')->setParameter('fname', '%' . $filterName . '%');
        }
        if ($filterEmail !== '') {
            $qb->andWhere('u.email LIKE :femail')->setParameter('femail', '%' . $filterEmail . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT u.id)')->getQuery()->getSingleScalarResult();

        $users = $qb->select('u')->orderBy($orderBy, $direction)
                    ->setFirstResult(($page - 1) * $perPage)
                    ->setMaxResults($perPage)
                    ->getQuery()->getResult();

        $lastPage = max(1, (int) ceil($total / $perPage));
        $from     = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
        $to       = min($page * $perPage, $total);

        return $this->secureJson([
            'data'         => array_map(fn ($u) => $this->serialize($u), $users),
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

        if (!$this->isJsonContentType($request)) {
            $this->auditService->log(
                toolName: 'user_invalid_content_type',
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
                'error' => $this->translator->trans('user.admin.invalid_content_type', [], 'messages'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.payload_too_large')], 413);
        }

        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.demo_mode_disabled')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.invalid_json')], 400);
        }
        $ids      = $data['ids'] ?? [];
        $password = (string) ($data['password'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            return $this->secureJson(['errors' => ['ids' => [$this->translator->trans('user.admin.no_users_selected')]]], 422);
        }

        if (count($ids) > self::MAX_BULK_IDS) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.bulk_limit_exceeded', ['%max%' => self::MAX_BULK_IDS])], 422);
        }

        foreach ($ids as $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                return $this->secureJson(['errors' => ['ids' => [$this->translator->trans('user.admin.invalid_id')]]], 422);
            }
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->hasher->isPasswordValid($currentUser, $password)) {
            $this->auditService->log(
                toolName: 'user_bulk_delete_failed_auth',
                project: null,
                input: ['attempted_by' => $currentUser->getUserIdentifier()],
                output: ['error' => 'invalid_password'],
                status: 'failure',
                errorMessage: 'Invalid confirmation password',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('user.admin.invalid_password')], 403);
        }

        $deleted = 0;
        $deletedIds = [];
        foreach ($ids as $id) {
            $user = $this->userRepository->find((int) $id);
            if ($user === null || $user === $currentUser) {
                continue;
            }
            if ($user->isSuperAdmin()) {
                if (!$currentUser->isSuperAdmin()) {
                    continue;
                }
                if ($this->countSuperAdmins() <= 1) {
                    continue;
                }
            }
            $this->em->remove($user);
            $deletedIds[] = $user->id;
            $deleted++;
        }
        $this->em->flush();

        if ($deleted > 0) {
            $this->auditService->log(
                toolName: 'user_bulk_delete',
                project: null,
                input: ['ids' => $deletedIds, 'count' => $deleted],
                output: ['deleted' => $deleted],
                status: 'success',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
        }

        return $this->secureJson(['message' => $this->translator->trans('user.admin.deleted_success', ['%count%' => $deleted])]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimitResponse = $this->checkRateLimit($request))) {
            return $rateLimitResponse;
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->id !== $user->id && !$currentUser->isSuperAdmin() && !$this->isGranted('users.manage')) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.access_denied')], 403);
        }

        return $this->secureJson($this->serialize($user));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('users.manage')]
    public function create(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (!$this->isJsonContentType($request)) {
            $this->auditService->log(
                toolName: 'user_invalid_content_type',
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
                'error' => $this->translator->trans('user.admin.invalid_content_type', [], 'messages'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.payload_too_large')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.invalid_json')], 400);
        }

        $email = trim((string) ($data['email'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '' || $name === '') {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.required_fields')], 422);
        }

        if (mb_strlen($name) > 255) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('user.admin.name_too_long')]], 422);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return $this->secureJson(['errors' => ['name' => $this->translator->trans('user.admin.name_control_chars')]], 422);
        }

        if (mb_strlen($email) > 254) {
            return $this->secureJson(['errors' => ['email' => $this->translator->trans('user.admin.email_too_long')]], 422);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $email)) {
            return $this->secureJson(['errors' => ['email' => $this->translator->trans('user.admin.email_control_chars')]], 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->secureJson(['errors' => ['email' => $this->translator->trans('user.admin.invalid_email')]], 422);
        }

        if ($this->userRepository->findByEmail($email) !== null) {
            return $this->secureJson(['errors' => ['email' => $this->translator->trans('user.admin.email_already_taken')]], 422);
        }

        if (mb_strlen($password) < 8 || mb_strlen($password) > 4096) {
            return $this->secureJson(['errors' => ['password' => $this->translator->trans('user.admin.password_length')]], 422);
        }

        $pwdValidator = new PasswordPolicyValidator();
        $pwdResult = $pwdValidator->validateAdminPassword($password, $email, $name);
        if (!$pwdResult['valid']) {
            return $this->secureJson(['errors' => ['password' => $pwdResult['errors'][0]]], 422);
        }

        $totalUsers = $this->userRepository->count([]);
        if ($totalUsers >= self::MAX_USERS_COUNT) {
            return $this->secureJson([
                'error' => $this->translator->trans('user.admin.max_users_exceeded', ['%max%' => self::MAX_USERS_COUNT]),
                'code' => 'MAX_USERS_EXCEEDED',
            ], 422);
        }

        /** @var ?User $currentUser */
        $currentUser = null;
        try {
            $currentUser = $this->getUser();
        } catch (\Throwable) {}

        // Anti-privilege escalation: non-superadmin cannot create superadmin
        $isSuperAdminRequested = !empty($data['is_super_admin'])
            || (!empty($data['roles']) && is_array($data['roles']) && in_array('ROLE_SUPER_ADMIN', $data['roles'], true));
        if ($isSuperAdminRequested && ($currentUser !== null && !$currentUser->isSuperAdmin())) {
            $this->auditService->log(
                toolName: 'user_create_privilege_escalation_attempt',
                project: null,
                input: ['email' => $email, 'attempted_by' => $currentUser->getUserIdentifier()],
                output: ['error' => 'cannot_escalate_super_admin'],
                status: 'failure',
                errorMessage: 'Attempt to create superadmin by non-superadmin',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_escalate_super_admin')], 403);
        }

        $user = new User();
        $user->name  = $name;
        $user->email = $email;
        $user->password = $this->hasher->hashPassword($user, $password);
        $user->passwordChangedAt = new \DateTimeImmutable();
        if ($isSuperAdminRequested && ($currentUser === null || $currentUser->isSuperAdmin())) {
            $user->roles = ['ROLE_SUPER_ADMIN'];
        }

        $rolesToAdd = [];
        if (isset($data['roles'])) {
            if (!is_array($data['roles'])) {
                return $this->secureJson(['errors' => ['roles' => [$this->translator->trans('user.admin.roles_must_be_array')]]], 422);
            }
            foreach ($data['roles'] as $roleId) {
                if (!is_numeric($roleId) || (int) $roleId <= 0) {
                    return $this->secureJson(['errors' => ['roles' => [$this->translator->trans('user.admin.invalid_role')]]], 422);
                }
                $role = $this->roleRepository->find((int) $roleId);
                if ($role === null) {
                    return $this->secureJson(['errors' => ['roles' => [$this->translator->trans('user.admin.invalid_role')]]], 422);
                }
                if ($currentUser !== null && !$currentUser->isSuperAdmin()) {
                    if ($this->isProtectedRole($role->name)) {
                        $this->auditService->log(
                            toolName: 'user_create_privilege_escalation_attempt',
                            project: null,
                            input: ['role_name' => $role->name, 'email' => $email],
                            output: ['error' => 'protected_system_role'],
                            status: 'failure',
                            errorMessage: 'Attempt to assign protected system role by non-superadmin',
                            createdBy: $currentUser->getUserIdentifier(),
                            source: 'user_management'
                        );
                        $this->auditService->flush();
                        return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_assign_protected_role')], 403);
                    }
                    foreach ($role->permissions as $perm) {
                        if (!$currentUser->hasPermission($perm->name)) {
                            $this->auditService->log(
                                toolName: 'user_create_privilege_escalation_attempt',
                                project: null,
                                input: ['permission' => $perm->name, 'email' => $email],
                                output: ['error' => 'unowned_permission'],
                                status: 'failure',
                                errorMessage: 'Attempt to assign unowned permission',
                                createdBy: $currentUser->getUserIdentifier(),
                                source: 'user_management'
                            );
                            $this->auditService->flush();
                            return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_assign_unowned_permissions')], 403);
                        }
                    }
                }
                $rolesToAdd[] = $role;
            }
        } elseif (!empty($data['role'])) {
            $role = $this->roleRepository->findByName(trim((string) $data['role']));
            if ($role === null) {
                return $this->secureJson(['errors' => ['role' => [$this->translator->trans('user.admin.invalid_role')]]], 422);
            }
            if ($currentUser !== null && !$currentUser->isSuperAdmin()) {
                if ($this->isProtectedRole($role->name)) {
                    $this->auditService->log(
                        toolName: 'user_create_privilege_escalation_attempt',
                        project: null,
                        input: ['role_name' => $role->name, 'email' => $email],
                        output: ['error' => 'protected_system_role'],
                        status: 'failure',
                        errorMessage: 'Attempt to assign protected system role by non-superadmin',
                        createdBy: $currentUser->getUserIdentifier(),
                        source: 'user_management'
                    );
                    $this->auditService->flush();
                    return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_assign_protected_role')], 403);
                }
                foreach ($role->permissions as $perm) {
                    if (!$currentUser->hasPermission($perm->name)) {
                        $this->auditService->log(
                            toolName: 'user_create_privilege_escalation_attempt',
                            project: null,
                            input: ['permission' => $perm->name, 'email' => $email],
                            output: ['error' => 'unowned_permission'],
                            status: 'failure',
                            errorMessage: 'Attempt to assign unowned permission',
                            createdBy: $currentUser->getUserIdentifier(),
                            source: 'user_management'
                            );
                        $this->auditService->flush();
                        return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_assign_unowned_permissions')], 403);
                    }
                }
            }
            $rolesToAdd[] = $role;
        }

        foreach ($rolesToAdd as $r) {
            $user->userRoles->add($r);
        }

        $this->em->persist($user);
        $this->em->flush();

        $this->auditService->log(
            toolName: 'user_create',
            project: null,
            input: ['id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'roles' => array_map(fn($r) => $r->name, $rolesToAdd)],
            output: ['success' => true, 'id' => $user->id],
            status: 'success',
            createdBy: $currentUser?->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(array_merge($this->serialize($user), [
            'message' => $this->translator->trans('user.admin.created_success'),
        ]), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('users.manage')]
    public function update(int $id, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (!$this->isJsonContentType($request)) {
            $this->auditService->log(
                toolName: 'user_invalid_content_type',
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
                'error' => $this->translator->trans('user.admin.invalid_content_type', [], 'messages'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.payload_too_large')], 413);
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Anti-privilege escalation: non-superadmin cannot modify a superadmin
        if ($user->isSuperAdmin() && !$currentUser->isSuperAdmin()) {
            $this->auditService->log(
                toolName: 'user_update_privilege_escalation_attempt',
                project: null,
                input: ['target_user_id' => $user->id, 'attempted_by' => $currentUser->getUserIdentifier()],
                output: ['error' => 'cannot_modify_super_admin'],
                status: 'failure',
                errorMessage: 'Attempt to modify superadmin by non-superadmin',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_modify_super_admin')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.invalid_json')], 400);
        }

        $changes = [];

        // Anti-privilege escalation: superadmin promotion check
        if (isset($data['is_super_admin'])) {
            if (!$currentUser->isSuperAdmin()) {
                $this->auditService->log(
                    toolName: 'user_update_privilege_escalation_attempt',
                    project: null,
                    input: ['target_user_id' => $user->id, 'attempted_by' => $currentUser->getUserIdentifier()],
                    output: ['error' => 'cannot_escalate_super_admin'],
                    status: 'failure',
                    errorMessage: 'Attempt to change superadmin status by non-superadmin',
                    createdBy: $currentUser->getUserIdentifier(),
                    source: 'user_management'
                );
                $this->auditService->flush();
                return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_escalate_super_admin')], 403);
            }
            $wasSuperAdmin = $user->isSuperAdmin();
            if ($data['is_super_admin']) {
                $user->roles = array_unique(array_merge($user->roles, ['ROLE_SUPER_ADMIN']));
            } else {
                if ($user->isSuperAdmin() && $this->countSuperAdmins() <= 1) {
                    return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_delete_last_super_admin')], 422);
                }
                $user->roles = array_values(array_diff($user->roles, ['ROLE_SUPER_ADMIN']));
            }
            $changes['is_super_admin'] = ['from' => $wasSuperAdmin, 'to' => (bool) $data['is_super_admin']];
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('user.admin.name_cannot_be_empty')]], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('user.admin.name_too_long')]], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return $this->secureJson(['errors' => ['name' => $this->translator->trans('user.admin.name_control_chars')]], 422);
            }
            if ($user->name !== $name) {
                $changes['name'] = ['from' => $user->name, 'to' => $name];
                $user->name = $name;
            }
        }

        if (isset($data['email'])) {
            $newEmail = trim((string) $data['email']);
            if ($newEmail === '') {
                return $this->secureJson(['errors' => ['email' => $this->translator->trans('user.admin.invalid_email')]], 422);
            }
            if (mb_strlen($newEmail) > 254) {
                return $this->secureJson(['errors' => ['email' => $this->translator->trans('user.admin.email_too_long')]], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->secureJson(['errors' => ['email' => $this->translator->trans('user.admin.invalid_email')]], 422);
            }
            $existing = $this->userRepository->findByEmail($newEmail);
            if ($existing !== null && $existing->id !== $user->id) {
                return $this->secureJson(['errors' => ['email' => $this->translator->trans('user.admin.email_already_taken')]], 409);
            }
            if ($user->email !== $newEmail) {
                $changes['email'] = ['from' => $user->email, 'to' => $newEmail];
                $user->email = $newEmail;
            }
        }

        if (!empty($data['password'])) {
            $newPassword = (string) $data['password'];
            if (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 4096) {
                return $this->secureJson(['errors' => ['password' => $this->translator->trans('user.admin.password_length')]], 422);
            }
            $pwdValidator = new PasswordPolicyValidator();
            $pwdResult = $pwdValidator->validateAdminPassword($newPassword, $user->email, $user->name);
            if (!$pwdResult['valid']) {
                return $this->secureJson(['errors' => ['password' => $pwdResult['errors'][0]]], 422);
            }
            if ($user->password !== null && $this->hasher->isPasswordValid($user, $newPassword)) {
                return $this->secureJson(['errors' => ['password' => $this->translator->trans('user.admin.password_different')]], 422);
            }
            $user->password = $this->hasher->hashPassword($user, $newPassword);
            $user->passwordChangedAt = new \DateTimeImmutable();
            $changes['password_changed'] = true;
        }

        if (isset($data['roles'])) {
            // Anti-privilege escalation: non-superadmin cannot modify own roles
            if ($currentUser->id === $user->id && !$currentUser->isSuperAdmin()) {
                $this->auditService->log(
                    toolName: 'user_update_privilege_escalation_attempt',
                    project: null,
                    input: ['target_user_id' => $user->id, 'attempted_by' => $currentUser->getUserIdentifier()],
                    output: ['error' => 'cannot_modify_own_roles'],
                    status: 'failure',
                    errorMessage: 'Attempt by delegated admin to modify own roles',
                    createdBy: $currentUser->getUserIdentifier(),
                    source: 'user_management'
                );
                $this->auditService->flush();
                return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_modify_own_roles')], 403);
            }

            if (!is_array($data['roles'])) {
                return $this->secureJson(['errors' => ['roles' => [$this->translator->trans('user.admin.roles_must_be_array')]]], 422);
            }

            $newRoles = [];
            foreach ($data['roles'] as $roleId) {
                if (!is_numeric($roleId) || (int) $roleId <= 0) {
                    return $this->secureJson(['errors' => ['roles' => [$this->translator->trans('user.admin.invalid_role')]]], 422);
                }
                $role = $this->roleRepository->find((int) $roleId);
                if ($role === null) {
                    return $this->secureJson(['errors' => ['roles' => [$this->translator->trans('user.admin.invalid_role')]]], 422);
                }
                if (!$currentUser->isSuperAdmin()) {
                    if ($this->isProtectedRole($role->name)) {
                        $this->auditService->log(
                            toolName: 'user_update_privilege_escalation_attempt',
                            project: null,
                            input: ['role_name' => $role->name, 'target_user_id' => $user->id],
                            output: ['error' => 'protected_system_role'],
                            status: 'failure',
                            errorMessage: 'Attempt to assign protected system role by non-superadmin',
                            createdBy: $currentUser->getUserIdentifier(),
                            source: 'user_management'
                        );
                        $this->auditService->flush();
                        return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_assign_protected_role')], 403);
                    }
                    foreach ($role->permissions as $perm) {
                        if (!$currentUser->hasPermission($perm->name)) {
                            $this->auditService->log(
                                toolName: 'user_update_privilege_escalation_attempt',
                                project: null,
                                input: ['permission' => $perm->name, 'target_user_id' => $user->id],
                                output: ['error' => 'unowned_permission'],
                                status: 'failure',
                                errorMessage: 'Attempt to assign unowned permission',
                                createdBy: $currentUser->getUserIdentifier(),
                                source: 'user_management'
                            );
                            $this->auditService->flush();
                            return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_assign_unowned_permissions')], 403);
                        }
                    }
                }
                $newRoles[] = $role;
            }
            $user->userRoles->clear();
            foreach ($newRoles as $r) {
                $user->userRoles->add($r);
            }
            $changes['roles'] = array_map(fn($r) => $r->name, $newRoles);
        }

        $this->em->flush();

        $this->auditService->log(
            toolName: 'user_update',
            project: null,
            input: ['id' => $user->id, 'email' => $user->email, 'changes' => $changes],
            output: ['success' => true, 'id' => $user->id],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(array_merge($this->serialize($user), [
            'message' => $this->translator->trans('user.admin.updated_success'),
        ]));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('users.manage')]
    public function delete(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimitResponse = $this->checkRateLimit($request))) {
            return $rateLimitResponse;
        }

        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.demo_mode_disabled')], 403);
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($user === $currentUser) {
            $this->auditService->log(
                toolName: 'user_delete_failed_self',
                project: null,
                input: ['id' => $user->id, 'email' => $user->email],
                output: ['error' => 'cannot_delete_self'],
                status: 'failure',
                errorMessage: 'Attempt to delete own account',
                createdBy: $currentUser->getUserIdentifier(),
                source: 'user_management'
            );
            $this->auditService->flush();
            return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_delete_self')], 422);
        }

        if ($user->isSuperAdmin()) {
            if ($this->countSuperAdmins() <= 1) {
                $this->auditService->log(
                    toolName: 'user_delete_failed_last_super_admin',
                    project: null,
                    input: ['id' => $user->id, 'email' => $user->email],
                    output: ['error' => 'cannot_delete_last_super_admin'],
                    status: 'failure',
                    errorMessage: 'Attempt to delete the last super administrator',
                    createdBy: $currentUser->getUserIdentifier(),
                    source: 'user_management'
                );
                $this->auditService->flush();
                return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_delete_last_super_admin')], 422);
            }
            if (!$currentUser->isSuperAdmin()) {
                $this->auditService->log(
                    toolName: 'user_delete_privilege_escalation_attempt',
                    project: null,
                    input: ['target_id' => $user->id, 'email' => $user->email],
                    output: ['error' => 'cannot_modify_super_admin'],
                    status: 'failure',
                    errorMessage: 'Attempt to delete superadmin by non-superadmin',
                    createdBy: $currentUser->getUserIdentifier(),
                    source: 'user_management'
                );
                $this->auditService->flush();
                return $this->secureJson(['error' => $this->translator->trans('user.admin.cannot_modify_super_admin')], 403);
            }
        }

        $userId = $user->id;
        $userEmail = $user->email;

        $this->em->remove($user);
        $this->em->flush();

        $this->auditService->log(
            toolName: 'user_delete',
            project: null,
            input: ['id' => $userId, 'email' => $userEmail],
            output: ['success' => true],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson(null, 204);
    }

    #[Route('/{id}/reset-2fa', name: 'reset_2fa', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function resetTwoFactor(int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimitResponse = $this->checkRateLimit($request))) {
            return $rateLimitResponse;
        }

        $targetUser = $this->userRepository->find($id);
        if ($targetUser === null) {
            return $this->secureJson(['error' => $this->translator->trans('user.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($targetUser === $currentUser) {
            return $this->secureJson(['error' => $this->translator->trans('user_management.cannot_reset_self_2fa_via_admin')], 400);
        }

        if (!$targetUser->twoFactorEnabled) {
            return $this->secureJson(['error' => $this->translator->trans('user_management.2fa_not_enabled')], 400);
        }

        $targetUser->twoFactorEnabled = false;
        $targetUser->twoFactorMethod = null;
        $targetUser->twoFactorSecret = null;
        $targetUser->twoFactorBackupCodes = null;
        $targetUser->twoFactorConfirmedAt = null;
        $this->em->flush();

        $this->lockoutService?->clearAttempts('user_2fa_account:' . $targetUser->id);
        $this->lockoutService?->clearAttempts('user_2fa_backup:' . $targetUser->id);
        $this->lockoutService?->clearAttempts('security_2fa_user:' . $targetUser->id);

        $this->auditService->log(
            toolName: 'admin_user_2fa_reset',
            project: null,
            input: ['target_user_id' => $targetUser->id, 'email' => $targetUser->email],
            output: ['success' => true],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->secureJson([
            'message' => $this->translator->trans('user_management.2fa_reset_success', ['%email%' => $targetUser->email]),
            'two_factor_enabled' => false,
        ]);
    }

    private function countSuperAdmins(): int
    {
        return (int) $this->em->createQuery(
            "SELECT COUNT(u.id) FROM App\\Entity\\User u WHERE u.roles LIKE :role"
        )->setParameter('role', '%ROLE_SUPER_ADMIN%')->getSingleScalarResult();
    }

    private function serialize(User $user): array
    {
        $roles = $user->userRoles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'label' => $r->label])->toArray();

        return [
            'id'                  => $user->id,
            'uuid'                => $user->uuid?->toString(),
            'name'                => $user->name,
            'email'               => $user->email,
            'roles'               => $roles,
            'userRoles'           => $roles,
            'is_super_admin'      => $user->isSuperAdmin(),
            'two_factor_enabled'  => $user->twoFactorEnabled,
            'two_factor_method'   => $user->twoFactorMethod,
            'password_changed_at' => $user->passwordChangedAt?->format(\DateTimeInterface::ATOM),
            'created_at'          => $user->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'          => $user->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
