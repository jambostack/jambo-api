<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class EnsureProjectMemberSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectMemberRepository $memberRepo,
        private TokenStorageInterface $tokenStorage,
        private ApiTokenChecker $tokenChecker,
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
            // Pas de session : seules les routes admin (/api/projects/*) sont
            // concernées — un anonyme n'y a jamais accès. Les jetons API (CRM)
            // passent avec une capacité adaptée à la méthode HTTP. Les routes
            // publiques à attribut projectUuid (/api/{uuid}/email, /captcha)
            // restent hors du périmètre.
            if (!str_starts_with($request->getPathInfo(), '/api/projects/')) {
                return;
            }
            $this->handleUnauthenticated($event, $request, (string) $projectUuid);
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

    private function handleUnauthenticated(RequestEvent $event, Request $request, string $projectUuid): void
    {
        $apiToken = $this->tokenChecker->resolve($request);
        if ($apiToken === null) {
            $event->setResponse(new JsonResponse(['error' => 'Authentication required.'], 401));
            return;
        }

        if ($apiToken->project->uuid?->toString() !== $projectUuid) {
            $event->setResponse(new JsonResponse(['error' => 'Access denied to this project.'], 403));
            return;
        }

        // Capacité requise selon la méthode HTTP ; 'write' (héritage) couvre tout.
        $required = match ($request->getMethod()) {
            'GET', 'HEAD'  => 'read',
            'POST'         => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE'       => 'delete',
            default        => null,
        };

        if ($required === null || (!$apiToken->can($required) && !$apiToken->can('write'))) {
            $event->setResponse(new JsonResponse(['error' => 'Insufficient token abilities.'], 403));
            return;
        }

        $request->attributes->set('_project', $apiToken->project);
    }
}
