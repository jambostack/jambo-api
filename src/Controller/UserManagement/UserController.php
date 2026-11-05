<?php

namespace App\Controller\UserManagement;

use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\Security\PasswordPolicyValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/users', name: 'api_users_')]
#[IsGranted('ROLE_USER')]
class UserController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    public const MAX_BULK_IDS = 100;

    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private TranslatorInterface $translator,
        private AuditService $auditService,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $search    = (string) $request->query->get('search', '');
        $page      = max(1, $request->query->getInt('page', 1));
        $perPage   = min(100, max(1, $request->query->getInt('per_page', 20)));
        $sortField = $request->query->get('sort', 'name');
        $direction = strtoupper($request->query->get('direction', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        $filterName  = (string) $request->query->get('filter_name', '');
        $filterEmail = (string) $request->query->get('filter_email', '');

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

        return $this->json([
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
        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->translator->trans('user.admin.payload_too_large')], 413);
        }

        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->json(['error' => $this->translator->trans('user.admin.demo_mode_disabled')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->translator->trans('user.admin.invalid_json')], 400);
        }
        $ids      = $data['ids'] ?? [];
        $password = (string) ($data['password'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            return $this->json(['errors' => ['ids' => [$this->translator->trans('user.admin.no_users_selected')]]], 422);
        }

        if (count($ids) > self::MAX_BULK_IDS) {
            return $this->json(['error' => $this->translator->trans('user.admin.bulk_limit_exceeded', ['%max%' => self::MAX_BULK_IDS])], 422);
        }

        foreach ($ids as $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                return $this->json(['errors' => ['ids' => [$this->translator->trans('user.admin.invalid_id')]]], 422);
            }
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->hasher->isPasswordValid($currentUser, $password)) {
            return $this->json(['error' => $this->translator->trans('user.admin.invalid_password')], 403);
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

        return $this->json(['message' => $this->translator->trans('user.admin.deleted_success', ['%count%' => $deleted])]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->json(['error' => $this->translator->trans('user.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->id !== $user->id && !$currentUser->isSuperAdmin() && !$this->isGranted('users.manage')) {
            return $this->json(['error' => $this->translator->trans('user.admin.access_denied')], 403);
        }

        return $this->json($this->serialize($user));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('users.manage')]
    public function create(Request $request): JsonResponse
    {
        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->translator->trans('user.admin.payload_too_large')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->translator->trans('user.admin.invalid_json')], 400);
        }

        $email = trim((string) ($data['email'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '' || $name === '') {
            return $this->json(['error' => $this->translator->trans('user.admin.required_fields')], 422);
        }

        if (mb_strlen($name) > 255) {
            return $this->json(['errors' => ['name' => $this->translator->trans('user.admin.name_too_long')]], 422);
        }

        if (mb_strlen($email) > 254) {
            return $this->json(['errors' => ['email' => $this->translator->trans('user.admin.email_too_long')]], 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['errors' => ['email' => $this->translator->trans('user.admin.invalid_email')]], 422);
        }

        if ($this->userRepository->findByEmail($email) !== null) {
            return $this->json(['errors' => ['email' => $this->translator->trans('user.admin.email_already_taken')]], 422);
        }

        if (mb_strlen($password) < 8 || mb_strlen($password) > 4096) {
            return $this->json(['errors' => ['password' => $this->translator->trans('user.admin.password_length')]], 422);
        }

        $pwdValidator = new PasswordPolicyValidator();
        $pwdResult = $pwdValidator->validateAdminPassword($password, $email, $name);
        if (!$pwdResult['valid']) {
            return $this->json(['errors' => ['password' => $pwdResult['errors'][0]]], 422);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Anti-privilege escalation: non-superadmin cannot create superadmin
        $isSuperAdminRequested = !empty($data['is_super_admin'])
            || (!empty($data['roles']) && is_array($data['roles']) && in_array('ROLE_SUPER_ADMIN', $data['roles'], true));
        if ($isSuperAdminRequested && !$currentUser->isSuperAdmin()) {
            return $this->json(['error' => $this->translator->trans('user.admin.cannot_escalate_super_admin')], 403);
        }

        $user = new User();
        $user->name  = $name;
        $user->email = $email;
        $user->password = $this->hasher->hashPassword($user, $password);
        $user->passwordChangedAt = new \DateTimeImmutable();
        if ($isSuperAdminRequested && $currentUser->isSuperAdmin()) {
            $user->roles = ['ROLE_SUPER_ADMIN'];
        }

        if (!empty($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $roleId) {
                $role = $this->roleRepository->find((int) $roleId);
                if ($role !== null) {
                    if (!$currentUser->isSuperAdmin()) {
                        foreach ($role->permissions as $perm) {
                            if (!$currentUser->hasPermission($perm->name)) {
                                return $this->json(['error' => $this->translator->trans('user.admin.cannot_assign_unowned_permissions')], 403);
                            }
                        }
                    }
                    $user->userRoles->add($role);
                }
            }
        } elseif (!empty($data['role'])) {
            $role = $this->roleRepository->findByName($data['role']);
            if ($role !== null) {
                if (!$currentUser->isSuperAdmin()) {
                    foreach ($role->permissions as $perm) {
                        if (!$currentUser->hasPermission($perm->name)) {
                            return $this->json(['error' => $this->translator->trans('user.admin.cannot_assign_unowned_permissions')], 403);
                        }
                    }
                }
                $user->userRoles->add($role);
            }
        }

        $this->em->persist($user);
        $this->em->flush();

        $this->auditService->log(
            toolName: 'user_create',
            project: null,
            input: ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
            output: ['success' => true, 'id' => $user->id],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->json(array_merge($this->serialize($user), [
            'message' => $this->translator->trans('user.admin.created_success'),
        ]), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('users.manage')]
    public function update(int $id, Request $request): JsonResponse
    {
        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->translator->trans('user.admin.payload_too_large')], 413);
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->json(['error' => $this->translator->trans('user.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Anti-privilege escalation: non-superadmin cannot modify a superadmin
        if ($user->isSuperAdmin() && !$currentUser->isSuperAdmin()) {
            return $this->json(['error' => $this->translator->trans('user.admin.cannot_modify_super_admin')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->translator->trans('user.admin.invalid_json')], 400);
        }

        // Anti-privilege escalation: superadmin promotion check
        if (isset($data['is_super_admin'])) {
            if (!$currentUser->isSuperAdmin()) {
                return $this->json(['error' => $this->translator->trans('user.admin.cannot_escalate_super_admin')], 403);
            }
            if ($data['is_super_admin']) {
                $user->roles = array_unique(array_merge($user->roles, ['ROLE_SUPER_ADMIN']));
            } else {
                if ($user->isSuperAdmin() && $this->countSuperAdmins() <= 1) {
                    return $this->json(['error' => $this->translator->trans('user.admin.cannot_delete_last_super_admin')], 422);
                }
                $user->roles = array_values(array_diff($user->roles, ['ROLE_SUPER_ADMIN']));
            }
        }

        if (isset($data['name'])) {
            $user->name = trim((string) $data['name']);
        }
        if (isset($data['email'])) {
            $newEmail = trim((string) $data['email']);
            if (mb_strlen($newEmail) > 254) {
                return $this->json(['errors' => ['email' => $this->translator->trans('user.admin.email_too_long')]], 422);
            }
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['errors' => ['email' => $this->translator->trans('user.admin.invalid_email')]], 422);
            }
            $existing = $this->userRepository->findByEmail($newEmail);
            if ($existing !== null && $existing->id !== $user->id) {
                return $this->json(['errors' => ['email' => $this->translator->trans('user.admin.email_already_taken')]], 409);
            }
            $user->email = $newEmail;
        }
        if (!empty($data['password'])) {
            $newPassword = (string) $data['password'];
            if (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 4096) {
                return $this->json(['errors' => ['password' => $this->translator->trans('user.admin.password_length')]], 422);
            }
            $pwdValidator = new PasswordPolicyValidator();
            $pwdResult = $pwdValidator->validateAdminPassword($newPassword, $user->email, $user->name);
            if (!$pwdResult['valid']) {
                return $this->json(['errors' => ['password' => $pwdResult['errors'][0]]], 422);
            }
            if ($user->password !== null && $this->hasher->isPasswordValid($user, $newPassword)) {
                return $this->json(['errors' => ['password' => $this->translator->trans('user.admin.password_different')]], 422);
            }
            $user->password = $this->hasher->hashPassword($user, $newPassword);
            $user->passwordChangedAt = new \DateTimeImmutable();
        }

        if (isset($data['roles']) && is_array($data['roles'])) {
            $newRoles = [];
            foreach ($data['roles'] as $roleId) {
                $role = $this->roleRepository->find((int) $roleId);
                if ($role !== null) {
                    if (!$currentUser->isSuperAdmin()) {
                        foreach ($role->permissions as $perm) {
                            if (!$currentUser->hasPermission($perm->name)) {
                                return $this->json(['error' => $this->translator->trans('user.admin.cannot_assign_unowned_permissions')], 403);
                            }
                        }
                    }
                    $newRoles[] = $role;
                }
            }
            $user->userRoles->clear();
            foreach ($newRoles as $r) {
                $user->userRoles->add($r);
            }
        }

        $this->em->flush();

        $this->auditService->log(
            toolName: 'user_update',
            project: null,
            input: ['id' => $user->id, 'email' => $user->email],
            output: ['success' => true, 'id' => $user->id],
            status: 'success',
            createdBy: $currentUser->getUserIdentifier(),
            source: 'user_management'
        );
        $this->auditService->flush();

        return $this->json(array_merge($this->serialize($user), [
            'message' => $this->translator->trans('user.admin.updated_success'),
        ]));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('users.manage')]
    public function delete(int $id): JsonResponse
    {
        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->json(['error' => $this->translator->trans('user.admin.demo_mode_disabled')], 403);
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->json(['error' => $this->translator->trans('user.admin.not_found')], 404);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($user === $currentUser) {
            return $this->json(['error' => $this->translator->trans('user.admin.cannot_delete_self')], 422);
        }

        if ($user->isSuperAdmin()) {
            if ($this->countSuperAdmins() <= 1) {
                return $this->json(['error' => $this->translator->trans('user.admin.cannot_delete_last_super_admin')], 422);
            }
            if (!$currentUser->isSuperAdmin()) {
                return $this->json(['error' => $this->translator->trans('user.admin.cannot_modify_super_admin')], 403);
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

        return $this->json(null, 204);
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
            'password_changed_at' => $user->passwordChangedAt?->format(\DateTimeInterface::ATOM),
            'created_at'          => $user->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'          => $user->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
