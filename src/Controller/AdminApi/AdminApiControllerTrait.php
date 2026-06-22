<?php

namespace App\Controller\AdminApi;

use App\Entity\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait AdminApiControllerTrait
{
    private function pat(Request $request): PersonalAccessToken
    {
        $pat = $request->attributes->get('_pat');
        if (!$pat instanceof PersonalAccessToken) {
            throw new AccessDeniedHttpException('No personal access token.');
        }
        return $pat;
    }

    private function requireScope(Request $request, string $scope): void
    {
        if (!$this->pat($request)->can($scope)) {
            throw new AccessDeniedHttpException("Missing scope: $scope");
        }
    }
}
