<?php

namespace App\Controller\Api;

use App\Entity\EndUser;
use App\Repository\EndUserRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD for EndUsers — JSON API used by the React frontend via axios.
 */
#[Route('/api/projects/{uuid}/end-users', name: 'api_admin_end_users_')]
class EndUserAdminController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private EndUserRepository $endUserRepository,
        private ProjectMemberRepository $memberRepo,
        private EntityManagerInterface $em,
        private Security $security,
    ) {}

    #[Route('/{endUserUuid}', name: 'destroy', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['DELETE'])]
    public function destroy(string $uuid, string $endUserUuid): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);

        if (!$this->isMember($project)) return $this->json(['error' => 'Forbidden'], 403);

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => 'Not found'], 404);

        $this->em->remove($endUser);
        $this->em->flush();

        return $this->json(['success' => true, 'deleted' => $endUserUuid]);
    }

    #[Route('/{endUserUuid}/status', name: 'status', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['PATCH'])]
    public function status(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);

        if (!$this->isMember($project)) return $this->json(['error' => 'Forbidden'], 403);

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => 'Not found'], 404);

        $data = $request->toArray();
        $newStatus = $data['status'] ?? '';
        if (!in_array($newStatus, ['active', 'banned', 'pending'], true)) {
            return $this->json(['error' => 'Invalid status'], 422);
        }

        $endUser->status = $newStatus;
        if ($newStatus === 'banned') {
            $endUser->tokenVersion++;
        }
        $this->em->flush();

        return $this->json(['success' => true, 'status' => $newStatus]);
    }

    private function isMember(\App\Entity\Project $project): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof \App\Entity\User) return false;
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) return true;
        return $this->memberRepo->findActiveByUserAndProject($user, $project) !== null;
    }
}
