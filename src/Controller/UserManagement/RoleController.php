<?php

namespace App\Controller\UserManagement;

use App\Controller\InertiaController;
use App\Entity\Role;
use App\Repository\PermissionRepository;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/roles', name: 'api_roles_')]
class RoleController extends InertiaController
{
    public function __construct(
        private RoleRepository $roleRepository,
        private PermissionRepository $permissionRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $roles = $this->roleRepository->findBy([], ['name' => 'ASC']);

        return $this->json([
            'data' => array_map(fn ($r) => $this->serialize($r), $roles),
        ]);
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

        if (empty($data['name']) || empty($data['label'])) {
            return $this->json(['error' => 'name and label are required'], 422);
        }

        $role = new Role();
        $role->name  = $data['name'];
        $role->label = $data['label'];

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

        if (isset($data['label'])) {
            $role->label = $data['label'];
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
            'id'         => $role->id,
            'name'       => $role->name,
            'label'      => $role->label,
            'usersCount' => $role->users->count(),
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
