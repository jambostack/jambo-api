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
    private function resolveProject(string $uuid, Request $request, bool $requireManage = false): Project|JsonResponse
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
            $member = $this->memberRepo->findActiveByUserAndProject($user, $project);
            if ($member !== null) {
                if ($requireManage && $member->role?->hasPermission('project.manage') !== true) {
                    return $this->json(['error' => 'You do not have permission to manage end-user fields.'], 403);
                }
                return $project;
            }
        }

        // ApiToken auth (CRM apps)
        // Note: l'UI de création de jeton expose les capacités granulaires (create/read/update/delete).
        // 'write' (héritage) est considéré satisfait par n'importe quelle capacité d'écriture granulaire,
        // afin que les jetons créés via l'UI puissent gérer les end-users.
        $token = $this->tokenChecker->resolve($request);
        $canWrite = $token !== null && (
            $token->can('write') || $token->can('create') || $token->can('update') || $token->can('delete')
        );
        if ($token !== null && $token->project->uuid?->toString() === $uuid && $canWrite) {
            return $project;
        }

        return $this->json(['error' => 'Forbidden'], 403);
    }
}
