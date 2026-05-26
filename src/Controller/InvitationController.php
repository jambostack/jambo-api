<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InvitationController extends AbstractController
{
    public function __construct(
        private ProjectMemberRepository $memberRepo,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/invitation/accept/{token}', name: 'invitation_accept', methods: ['GET'])]
    public function accept(string $token): Response
    {
        $member = $this->memberRepo->findOneBy(['invitationToken' => $token]);

        if ($member === null || $member->status !== ProjectMemberStatus::Pending) {
            return $this->render('invitation/invalid.html.twig', [
                'error' => 'Ce lien d\'invitation est invalide.',
            ]);
        }

        if ($member->isTokenExpired()) {
            return $this->render('invitation/invalid.html.twig', [
                'error' => 'Ce lien d\'invitation a expiré.',
                'project_name' => $member->project->name,
            ]);
        }

        if (!$this->getUser()) {
            $redirectUrl = $this->generateUrl('invitation_accept', ['token' => $token]);
            return $this->redirectToRoute('app_login', ['_target_path' => $redirectUrl]);
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($member->email !== $user->email) {
            return $this->render('invitation/invalid.html.twig', [
                'error' => 'Cette invitation est destinée à ' . $member->email . '.',
            ]);
        }

        // Check if user is already an active member (added directly while invitation was pending)
        $existingActive = $this->memberRepo->findActiveByUserAndProject($user, $member->project);
        if ($existingActive !== null) {
            $this->em->remove($member);
            $this->em->flush();
            $this->addFlash('info', 'Vous êtes déjà membre du projet "' . $member->project->name . '".');
            return $this->redirectToRoute('projects_show', ['project' => $member->project->id]);
        }

        $member->user            = $user;
        $member->status          = ProjectMemberStatus::Active;
        $member->joinedAt        = new \DateTimeImmutable();
        $member->invitationToken = null;
        $member->tokenExpiresAt  = null;

        $this->em->flush();

        $this->addFlash('success', 'Vous avez rejoint le projet "' . $member->project->name . '".');

        return $this->redirectToRoute('projects_show', ['project' => $member->project->id]);
    }
}
