<?php

namespace App\Controller\UserManagement;

use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/users', name: 'api_users_')]
class UserController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
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
        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->json(['error' => 'User deletion is disabled in demo mode.'], 403);
        }

        $data     = $request->toArray();
        $ids      = $data['ids'] ?? [];
        $password = (string) ($data['password'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            return $this->json(['errors' => ['ids' => ['No users selected.']]], 422);
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->hasher->isPasswordValid($currentUser, $password)) {
            return $this->json(['error' => 'Invalid password'], 403);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $user = $this->userRepository->find((int) $id);
            if ($user === null || $user === $currentUser) {
                continue;
            }
            $this->em->remove($user);
            $deleted++;
        }
        $this->em->flush();

        return $this->json(['message' => "$deleted user(s) deleted successfully."]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->json(['error' => 'User not found'], 404);
        }

        return $this->json($this->serialize($user));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('users.manage')]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        if (empty($data['email']) || empty($data['password']) || empty($data['name'])) {
            return $this->json(['error' => 'name, email, and password are required'], 422);
        }

        if ($this->userRepository->findByEmail($data['email']) !== null) {
            return $this->json(['errors' => ['email' => 'Email already taken.']], 422);
        }

        $user = new User();
        $user->name  = $data['name'];
        $user->email = $data['email'];
        $user->password = $this->hasher->hashPassword($user, $data['password']);

        if (!empty($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $roleId) {
                $role = $this->roleRepository->find((int) $roleId);
                if ($role !== null) {
                    $user->userRoles->add($role);
                }
            }
        } elseif (!empty($data['role'])) {
            $role = $this->roleRepository->findByName($data['role']);
            if ($role !== null) {
                $user->userRoles->add($role);
            }
        }

        $this->em->persist($user);
        $this->em->flush();

        return $this->json($this->serialize($user), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('users.manage')]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['email'])) {
            $user->email = $data['email'];
        }
        if (!empty($data['password'])) {
            $user->password = $this->hasher->hashPassword($user, $data['password']);
        }
        if (isset($data['roles']) && is_array($data['roles'])) {
            $user->userRoles->clear();
            foreach ($data['roles'] as $roleId) {
                $role = $this->roleRepository->find((int) $roleId);
                if ($role !== null) {
                    $user->userRoles->add($role);
                }
            }
        }

        $this->em->flush();

        return $this->json($this->serialize($user));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('users.manage')]
    public function delete(int $id): JsonResponse
    {
        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->json(['error' => 'User deletion is disabled in demo mode.'], 403);
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->json(['error' => 'User not found'], 404);
        }

        if ($user === $this->getUser()) {
            return $this->json(['error' => 'You cannot delete your own account.'], 422);
        }

        $this->em->remove($user);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function serialize(User $user): array
    {
        $roles = $user->userRoles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'label' => $r->label])->toArray();

        return [
            'id'         => $user->id,
            'uuid'       => $user->uuid?->toString(),
            'name'       => $user->name,
            'email'      => $user->email,
            'roles'      => $roles,
            'userRoles'  => $roles,
            'created_at' => $user->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $user->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
