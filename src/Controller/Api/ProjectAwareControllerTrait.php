<?php

namespace App\Controller\Api;

use App\Entity\Project;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

trait ProjectAwareControllerTrait
{
    private function resolveProject(string $uuid, Request $request): Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        // Session auth (admin UI)
        $user = $this->security->getUser();
        if ($user instanceof \App\Entity\User) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                return $project;
            }
            if ($this->memberRepo->findActiveByUserAndProject($user, $project) !== null) {
                return $project;
            }
        }

        // ApiToken auth (CRM apps)
        $token = $this->tokenChecker->resolve($request);
        if ($token !== null && $token->project->uuid?->toString() === $uuid && $token->can('write')) {
            return $project;
        }

        return $this->json(['error' => 'Forbidden'], 403);
    }
}
