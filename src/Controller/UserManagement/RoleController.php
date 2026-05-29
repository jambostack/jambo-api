<?php

namespace App\Controller\UserManagement;

use App\Entity\Role;
use App\Repository\PermissionRepository;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/roles', name: 'api_roles_')]
class RoleController extends AbstractController
{
    public function __construct(
        private RoleRepository $roleRepository,
        private PermissionRepository $permissionRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $search    = (string) $request->query->get('search', '');
        $page      = max(1, $request->query->getInt('page', 1));
        $perPage   = min(100, max(1, $request->query->getInt('per_page', 20)));
        $sortField = $request->query->get('sort', 'name');
        $direction = strtoupper($request->query->get('direction', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        $filterName = (string) $request->query->get('filter_name', '');

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

        return $this->json([
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
        $data     = $request->toArray();
        $ids      = $data['ids'] ?? [];
        $password = (string) ($data['password'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            return $this->json(['errors' => ['ids' => ['No roles selected.']]], 422);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->hasher->isPasswordValid($currentUser, $password)) {
            return $this->json(['error' => 'Invalid password'], 403);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $role = $this->roleRepository->find((int) $id);
            if ($role === null) {
                continue;
            }
            $this->em->remove($role);
            $deleted++;
        }
        $this->em->flush();

        return $this->json(['message' => "$deleted role(s) deleted successfully."]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $role = $this->roleRepository->find($id);
        if ($role === null) {
            return $this->json(['error' => 'Role not found'], 404);
        }

        return $this->json($this->serialize($role, true));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('roles.manage')]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        if (empty($data['name'])) {
            return $this->json(['errors' => ['name' => 'Name is required.']], 422);
        }

        $role = new Role();
        $role->name  = $data['name'];
        $role->label = $data['label'] ?? $data['name'];

        $this->attachPermissions($role, $data['permissions'] ?? []);

        $this->em->persist($role);
        $this->em->flush();

        return $this->json($this->serialize($role, true), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('roles.manage')]
    public function update(int $id, Request $request): JsonResponse
    {
        $role = $this->roleRepository->find($id);
        if ($role === null) {
            return $this->json(['error' => 'Role not found'], 404);
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            $role->name = $data['name'];
        }
        if (isset($data['label'])) {
            $role->label = $data['label'];
        } elseif (isset($data['name'])) {
            $role->label = $data['name'];
        }
        if (isset($data['permissions'])) {
            $role->permissions->clear();
            $this->attachPermissions($role, $data['permissions']);
        }

        $this->em->flush();

        return $this->json($this->serialize($role, true));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('roles.manage')]
    public function delete(int $id): JsonResponse
    {
        $role = $this->roleRepository->find($id);
        if ($role === null) {
            return $this->json(['error' => 'Role not found'], 404);
        }

        $this->em->remove($role);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function attachPermissions(Role $role, array $permissionNames): void
    {
        foreach ($permissionNames as $name) {
            $permission = $this->permissionRepository->findOneBy(['name' => $name]);
            if ($permission !== null) {
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
