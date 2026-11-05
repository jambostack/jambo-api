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
        $notFoundMsg = method_exists($this, 'trans') ? $this->trans('project.not_found', [], 'Project not found') : 'Project not found';
        $forbiddenMsg = method_exists($this, 'trans') ? $this->trans('common.forbidden', [], 'Forbidden') : 'Forbidden';
        $managePermMsg = method_exists($this, 'trans') ? $this->trans('project.no_manage_permission', [], 'You do not have permission to manage end-user fields.') : 'You do not have permission to manage end-user fields.';

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $notFoundMsg], 404);
        }
        if (!$project) {
            return $this->json(['error' => $notFoundMsg], 404);
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
                    return $this->json(['error' => $managePermMsg], 403);
                }
                return $project;
            }
        }

        // ApiToken auth (CRM apps)
        // Cloisonnement strict des portées selon la méthode HTTP via ApiTokenChecker
        $token = $this->tokenChecker->resolve($request);
        if ($token !== null && $token->project->uuid?->toString() === $uuid) {
            if ($requireManage && !$token->can('manage') && !$token->can('*')) {
                return $this->json(['error' => $managePermMsg], 403);
            }
            if ($this->tokenChecker->isAllowedForMethod($token, $request->getMethod())) {
                return $project;
            }
        }

        return $this->json(['error' => $forbiddenMsg], 403);
    }
}
