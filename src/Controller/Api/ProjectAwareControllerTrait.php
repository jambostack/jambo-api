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
        // La capacité requise dépend de la méthode HTTP (read/create/update/delete).
        // 'write' (héritage) couvre toutes les actions, y compris la lecture,
        // pour ne pas casser les jetons existants.
        $token = $this->tokenChecker->resolve($request);
        if ($token !== null && $token->project->uuid?->toString() === $uuid) {
            if ($requireManage && !$token->can('manage')) {
                return $this->json(['error' => 'You do not have permission to manage end-user fields.'], 403);
            }
            $required = match ($request->getMethod()) {
                'GET', 'HEAD'  => 'read',
                'POST'         => 'create',
                'PUT', 'PATCH' => 'update',
                'DELETE'       => 'delete',
                default        => null,
            };
            if ($required !== null && ($token->can($required) || $token->can('write'))) {
                return $project;
            }
        }

        return $this->json(['error' => 'Forbidden'], 403);
    }
}
