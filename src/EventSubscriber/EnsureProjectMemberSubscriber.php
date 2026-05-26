<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class EnsureProjectMemberSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectMemberRepository $memberRepo,
        private TokenStorageInterface $tokenStorage,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request     = $event->getRequest();
        $projectUuid = $request->attributes->get('projectUuid');

        if ($projectUuid === null) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return;
        }

        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);

        if ($project === null) {
            $event->setResponse(new JsonResponse(['error' => 'Project not found.'], 404));
            return;
        }

        $member = $this->memberRepo->findActiveByUserAndProject($user, $project);

        if ($member === null) {
            $event->setResponse(new JsonResponse(['error' => 'Access denied to this project.'], 403));
            return;
        }

        $request->attributes->set('_project', $project);
    }
}
