<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InvitationController extends AbstractController
{
    public function __construct(
        private ProjectMemberRepository $memberRepo,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/invitation/accept/{token}', name: 'invitation_accept', methods: ['GET', 'POST'])]
    #[Route('/api/invitations/{token}/accept', name: 'api_invitation_accept', methods: ['POST', 'GET'])]
    public function accept(string $token, Request $request): Response
    {
        $wantsJson = str_contains((string) $request->headers->get('Accept'), 'application/json')
            || str_starts_with($request->getPathInfo(), '/api/');

        $member = $this->memberRepo->findOneBy(['invitationToken' => $token]);

        if ($member === null || $member->status !== ProjectMemberStatus::Pending) {
            if ($wantsJson) {
                return new JsonResponse(['error' => 'Ce lien d\'invitation est invalide.'], 404);
            }
            return $this->render('invitation/invalid.html.twig', [
                'error' => 'Ce lien d\'invitation est invalide.',
            ]);
        }

        if ($member->isTokenExpired()) {
            if ($wantsJson) {
                return new JsonResponse([
                    'error'        => 'Ce lien d\'invitation a expiré.',
                    'project_name' => $member->project->name,
                ], 410);
            }
            return $this->render('invitation/invalid.html.twig', [
                'error' => 'Ce lien d\'invitation a expiré.',
                'project_name' => $member->project->name,
            ]);
        }

        if (!$this->getUser()) {
            if ($wantsJson) {
                return new JsonResponse(['error' => 'Authentication required'], 401);
            }
            $redirectUrl = $this->generateUrl('invitation_accept', ['token' => $token]);
            return $this->redirectToRoute('app_login', ['_target_path' => $redirectUrl]);
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($member->email !== $user->email) {
            if ($wantsJson) {
                return new JsonResponse(['error' => 'Cette invitation est destinée à ' . $member->email . '.'], 403);
            }
            return $this->render('invitation/invalid.html.twig', [
                'error' => 'Cette invitation est destinée à ' . $member->email . '.',
            ]);
        }

        // Check if user is already an active member (added directly while invitation was pending)
        $existingActive = $this->memberRepo->findActiveByUserAndProject($user, $member->project);
        if ($existingActive !== null) {
            $this->em->remove($member);
            $this->em->flush();
            if ($wantsJson) {
                return new JsonResponse([
                    'message' => 'Vous êtes déjà membre du projet "' . $member->project->name . '".',
                    'project' => [
                        'id'   => $member->project->id,
                        'uuid' => $member->project->uuid?->toRfc4122(),
                        'name' => $member->project->name,
                    ],
                ]);
            }
            $this->addFlash('info', 'Vous êtes déjà membre du projet "' . $member->project->name . '".');
            return $this->redirectToRoute('projects_show', ['project' => $member->project->id]);
        }

        $member->user            = $user;
        $member->status          = ProjectMemberStatus::Active;
        $member->joinedAt        = new \DateTimeImmutable();
        $member->invitationToken = null;
        $member->tokenExpiresAt  = null;

        $this->em->flush();

        if ($wantsJson) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Vous avez rejoint le projet "' . $member->project->name . '".',
                'project' => [
                    'id'   => $member->project->id,
                    'uuid' => $member->project->uuid?->toRfc4122(),
                    'name' => $member->project->name,
                ],
            ]);
        }

        $this->addFlash('success', 'Vous avez rejoint le projet "' . $member->project->name . '".');

        return $this->redirectToRoute('projects_show', ['project' => $member->project->id]);
    }
}
