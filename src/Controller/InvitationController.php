<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class InvitationController extends AbstractController
{
    public function __construct(
        private ProjectMemberRepository $memberRepo,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        #[Autowire(service: 'limiter.invitation_limiter')]
        private ?RateLimiterFactory $invitationLimiter = null,
    ) {}

    #[Route('/invitation/accept/{token}', name: 'invitation_accept', methods: ['GET', 'POST'])]
    #[Route('/api/invitations/{token}/accept', name: 'api_invitation_accept', methods: ['POST', 'GET'])]
    public function accept(string $token, Request $request): Response
    {
        $wantsJson = str_contains((string) $request->headers->get('Accept'), 'application/json')
            || str_starts_with($request->getPathInfo(), '/api/');

        if ($this->invitationLimiter !== null) {
            $limiter = $this->invitationLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                $msg = $this->translator->trans('invitation.too_many_attempts') ?: 'Too many attempts. Please retry later.';
                if ($wantsJson) {
                    return new JsonResponse(['error' => $msg], 429);
                }
                return new Response($msg, 429, ['Content-Type' => 'text/plain']);
            }
        }

        $member = $this->memberRepo->findOneBy(['invitationToken' => $token]);

        if ($member === null || $member->status !== ProjectMemberStatus::Pending) {
            $msg = $this->translator->trans('invitation.invalid_link');
            if ($wantsJson) {
                return new JsonResponse(['error' => $msg], 404);
            }
            return $this->render('invitation/invalid.html.twig', [
                'error' => $msg,
            ]);
        }

        if ($member->isTokenExpired()) {
            $msg = $this->translator->trans('invitation.expired_link');
            if ($wantsJson) {
                return new JsonResponse([
                    'error'        => $msg,
                    'project_name' => $member->project->name,
                ], 410);
            }
            return $this->render('invitation/invalid.html.twig', [
                'error' => $msg,
                'project_name' => $member->project->name,
            ]);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            if ($wantsJson) {
                return new JsonResponse(['error' => 'Authentication required'], 401);
            }
            $redirectUrl = $this->generateUrl('invitation_accept', ['token' => $token]);
            return $this->redirectToRoute('app_login', ['_target_path' => $redirectUrl]);
        }

        if (strtolower((string) $member->email) !== strtolower((string) $user->email)) {
            $msg = $this->translator->trans('invitation.wrong_recipient', ['{email}' => $member->email]);
            if ($wantsJson) {
                return new JsonResponse(['error' => $msg], 403);
            }
            return $this->render('invitation/invalid.html.twig', [
                'error' => $msg,
            ]);
        }

        // Check if user is already an active member (added directly while invitation was pending)
        $existingActive = $this->memberRepo->findActiveByUserAndProject($user, $member->project);
        if ($existingActive !== null) {
            $this->em->remove($member);
            $this->em->flush();
            $alreadyMsg = $this->translator->trans('invitation.already_member', ['{project}' => $member->project->name]);
            if ($wantsJson) {
                return new JsonResponse([
                    'message' => $alreadyMsg,
                    'project' => [
                        'id'   => $member->project->id,
                        'uuid' => $member->project->uuid?->toRfc4122(),
                        'name' => $member->project->name,
                    ],
                ]);
            }
            $this->addFlash('info', $alreadyMsg);
            return $this->redirectToRoute('projects_show', ['project' => $member->project->id]);
        }

        $member->user            = $user;
        $member->status          = ProjectMemberStatus::Active;
        $member->joinedAt        = new \DateTimeImmutable();
        $member->invitationToken = null;
        $member->tokenExpiresAt  = null;

        $this->em->flush();

        $joinedMsg = $this->translator->trans('invitation.joined_success', ['{project}' => $member->project->name]);

        if ($wantsJson) {
            return new JsonResponse([
                'success' => true,
                'message' => $joinedMsg,
                'project' => [
                    'id'   => $member->project->id,
                    'uuid' => $member->project->uuid?->toRfc4122(),
                    'name' => $member->project->name,
                ],
            ]);
        }

        $this->addFlash('success', $joinedMsg);

        return $this->redirectToRoute('projects_show', ['project' => $member->project->id]);
    }
}
