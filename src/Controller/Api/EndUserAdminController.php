<?php

namespace App\Controller\Api;

use App\Entity\EndUser;
use App\Repository\EndUserRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Controller\Api\ProjectAwareControllerTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD for EndUsers — JSON API used by admin UI (Inertia) AND CRM apps via ApiToken.
 */
#[Route('/api/projects/{uuid}/end-users', name: 'api_admin_end_users_')]
class EndUserAdminController extends AbstractController
{
    use ProjectAwareControllerTrait;

    public function __construct(
        private ProjectRepository $projectRepository,
        private EndUserRepository $endUserRepository,
        private ProjectMemberRepository $memberRepo,
        private EntityManagerInterface $em,
        private Security $security,
        private ApiTokenChecker $tokenChecker,
        private UserPasswordHasherInterface $hasher,
    ) {}

    // ─── LIST ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $status  = $request->query->get('status', '');
        $search  = $request->query->get('search', '');
        $uuids   = $request->query->all('uuids');
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $qb = $this->em->createQueryBuilder()
            ->select('eu')
            ->from(EndUser::class, 'eu')
            ->where('eu.project = :project')
            ->setParameter('project', $project)
            ->orderBy('eu.createdAt', 'DESC');

        // Résolution ciblée pour les champs relation : uuids[] court-circuite
        // la pagination (le nombre d'uuids demandés borne le résultat).
        if ($uuids !== []) {
            $uuidObjects = [];
            foreach (array_slice($uuids, 0, 100) as $u) {
                try {
                    $uuidObjects[] = \Symfony\Component\Uid\Uuid::fromString((string) $u)->toBinary();
                } catch (\InvalidArgumentException) {
                    // uuid mal formé → ignoré
                }
            }
            $qb->andWhere('eu.uuid IN (:uuids)')->setParameter('uuids', $uuidObjects);
            $perPage = max(count($uuidObjects), 1);
            $page = 1;
        }

        if ($status !== '' && in_array($status, ['active', 'banned', 'pending'], true)) {
            $qb->andWhere('eu.status = :status')->setParameter('status', $status);
        }
        if ($search !== '') {
            $qb->andWhere('eu.email LIKE :search OR eu.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $totalQb = clone $qb;
        $total = (int) $totalQb->select('COUNT(eu.id)')->getQuery()->getSingleScalarResult();

        $qb->setMaxResults($perPage)->setFirstResult(($page - 1) * $perPage);
        $endUsers = $qb->getQuery()->getResult();

        return $this->json([
            'data' => array_map(fn (EndUser $eu) => $this->serializeUser($eu), $endUsers),
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'pages'     => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    // ─── SHOW ────────────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}', name: 'show', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function show(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => 'Not found'], 404);

        return $this->json(['data' => $this->serializeUser($endUser)]);
    }

    // ─── CREATE ──────────────────────────────────────────────────────────────

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $data = $request->toArray();
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $username = trim($data['username'] ?? '');
        $status   = $data['status'] ?? 'active';
        $customFields = $data['custom_fields'] ?? null;

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Valid email is required'], 422);
        }
        if (strlen($password) < 6) {
            return $this->json(['error' => 'Password must be at least 6 characters'], 422);
        }
        if (!in_array($status, ['active', 'banned', 'pending'], true)) {
            return $this->json(['error' => 'Invalid status'], 422);
        }

        $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if ($existing) {
            return $this->json(['error' => 'Email already registered'], 409);
        }

        $endUser = new EndUser($project, $email);
        $endUser->username = $username !== '' ? $username : null;
        $endUser->status = $status;
        $endUser->password = $this->hasher->hashPassword($endUser, $password);
        if ($customFields !== null) {
            $endUser->customFields = (array) $customFields;
        }

        $this->em->persist($endUser);
        $this->em->flush();

        return $this->json(['data' => $this->serializeUser($endUser)], 201);
    }

    // ─── UPDATE ──────────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}', name: 'update', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['PATCH'])]
    public function update(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => 'Not found'], 404);

        $data = $request->toArray();

        if (isset($data['email'])) {
            $email = trim($data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Invalid email'], 422);
            }
            $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
            if ($existing && $existing->uuid->toString() !== $endUserUuid) {
                return $this->json(['error' => 'Email already taken'], 409);
            }
            $endUser->email = $email;
        }
        if (isset($data['username'])) {
            $endUser->username = trim($data['username']) ?: null;
        }
        if (isset($data['status'])) {
            if (!in_array($data['status'], ['active', 'banned', 'pending'], true)) {
                return $this->json(['error' => 'Invalid status'], 422);
            }
            $endUser->status = $data['status'];
            if ($data['status'] === 'banned') $endUser->tokenVersion++;
        }
        if (isset($data['custom_fields'])) {
            $endUser->customFields = (array) $data['custom_fields'];
        }
        // Reset password (admin-initiated)
        if (!empty($data['password'] ?? '')) {
            if (strlen($data['password']) < 6) {
                return $this->json(['error' => 'Password must be at least 6 characters'], 422);
            }
            $endUser->password = $this->hasher->hashPassword($endUser, $data['password']);
            $endUser->tokenVersion++;
        }

        $this->em->flush();

        return $this->json(['data' => $this->serializeUser($endUser)]);
    }

    // ─── DELETE ──────────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}', name: 'destroy', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['DELETE'])]
    public function destroy(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => 'Not found'], 404);

        $this->em->remove($endUser);
        $this->em->flush();

        return $this->json(['success' => true, 'deleted' => $endUserUuid]);
    }

    // ─── STATUS ──────────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}/status', name: 'status', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['PATCH'])]
    public function status(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => 'Not found'], 404);

        $data = $request->toArray();
        $newStatus = $data['status'] ?? '';
        if (!in_array($newStatus, ['active', 'banned', 'pending'], true)) {
            return $this->json(['error' => 'Invalid status'], 422);
        }

        $endUser->status = $newStatus;
        if ($newStatus === 'banned') $endUser->tokenVersion++;
        $this->em->flush();

        return $this->json(['success' => true, 'status' => $newStatus]);
    }

    // ─── Serializer ──────────────────────────────────────────────────────────

    private function serializeUser(EndUser $eu): array
    {
        return [
            'uuid'          => $eu->uuid?->toString(),
            'email'         => $eu->email,
            'name'          => $eu->name,
            'status'        => $eu->status,
            'avatar_url'    => $eu->avatarUrl,
            'custom_fields' => $eu->customFields,
            'created_at'    => $eu->createdAt?->format(\DateTimeInterface::ATOM),
            'updated_at'    => $eu->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
