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
            $msg = method_exists($this, 'trans')
                ? $this->trans('admin_api.no_token', [], 'No personal access token.')
                : (property_exists($this, 'translator') && $this->translator
                    ? $this->translator->trans('admin_api.no_token', [], 'messages')
                    : 'No personal access token.');
            throw new AccessDeniedHttpException($msg);
        }
        return $pat;
    }

    private function requireScope(Request $request, string $scope): void
    {
        if (!$this->pat($request)->can($scope)) {
            $msg = method_exists($this, 'trans')
                ? $this->trans('admin_api.missing_scope', ['%scope%' => $scope], sprintf('Missing scope: %s', $scope))
                : (property_exists($this, 'translator') && $this->translator
                    ? $this->translator->trans('admin_api.missing_scope', ['%scope%' => $scope], 'messages')
                    : sprintf('Missing scope: %s', $scope));
            throw new AccessDeniedHttpException($msg);
        }
    }
}
