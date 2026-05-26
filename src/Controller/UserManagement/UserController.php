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
        $search  = (string) $request->query->get('search', '');
        $perPage = min(50, max(1, (int) $request->query->get('per_page', 20)));

        if ($search !== '') {
            $users = $this->userRepository->findBySearch($search, $perPage);
        } else {
            $users = $this->userRepository->findBy([], ['name' => 'ASC'], $perPage);
        }

        return $this->json([
            'data' => array_map(fn ($u) => $this->serialize($u), $users),
        ]);
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

        if (!empty($data['role'])) {
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
        if (isset($data['roles'])) {
            $user->roles = $data['roles'];
        }

        $this->em->flush();

        return $this->json($this->serialize($user));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('users.manage')]
    public function delete(int $id): JsonResponse
    {
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
        return [
            'id'        => $user->id,
            'uuid'      => $user->uuid?->toString(),
            'name'      => $user->name,
            'email'     => $user->email,
            'roles'     => $user->getRoles(),
            'userRoles' => $user->userRoles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'label' => $r->label])->toArray(),
            'createdAt' => $user->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
