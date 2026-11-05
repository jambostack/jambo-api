<?php

namespace App\Security;

use App\Repository\EndUserRepository;
use App\Service\EndUserJwtService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\Translation\TranslatorInterface;

class EndUserJwtAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private EndUserJwtService $jwtService,
        private EndUserRepository $endUserRepository,
        private ?TranslatorInterface $translator = null,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization')
            && str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $header = $request->headers->get('Authorization', '');
        $jwt = substr($header, 7);

        if ($jwt === '' || strlen($jwt) > 8192) {
            throw new AuthenticationException('Invalid or expired token.');
        }

        $claims = $this->jwtService->validateToken($jwt);
        if ($claims === null) {
            throw new AuthenticationException('Invalid or expired token.');
        }

        if ($this->jwtService->isRefreshToken($claims)) {
            throw new AuthenticationException('Refresh tokens cannot be used for API access.');
        }

        if (($claims['tfa'] ?? false) === true) {
            throw new AuthenticationException('2FA challenge tokens cannot be used for API access.');
        }

        $endUserUuid = $claims['euid'] ?? $claims['sub'] ?? null;
        if ($endUserUuid === null) {
            throw new AuthenticationException('Invalid token: missing subject.');
        }

        $tokenVersion = $claims['tkn'] ?? null;
        $tokenProjectId = $claims['pid'] ?? null;

        return new SelfValidatingPassport(
            new UserBadge((string) $endUserUuid, function ($uuid) use ($tokenVersion, $tokenProjectId, $request) {
                $endUser = $this->endUserRepository->findOneBy(['uuid' => $uuid]);
                if ($endUser === null || !$endUser->isActive()) {
                    throw new AuthenticationException('User not found or inactive.');
                }
                if ($tokenVersion !== null && $endUser->tokenVersion !== $tokenVersion) {
                    throw new AuthenticationException('Token version mismatch - please re-login.');
                }

                // Verify tenant isolation against token claims
                if ($tokenProjectId !== null) {
                    $projectUuid = $endUser->project?->uuid?->toString();
                    if ($projectUuid !== null && $tokenProjectId !== $projectUuid && (int) $tokenProjectId !== $endUser->project?->id) {
                        throw new AuthenticationException('Token does not match user project.');
                    }
                }

                // Verify tenant isolation against current route if applicable
                $routeProjectId = $request->attributes->get('projectId');
                if (is_string($routeProjectId) && $endUser->project?->uuid?->toString() !== $routeProjectId) {
                    throw new AuthenticationException('Token does not belong to this project.');
                }

                return $endUser;
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $request->attributes->set('_end_user', $token->getUser());
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $this->translator !== null
            ? $this->translator->trans($exception->getMessage(), [], 'messages')
            : $exception->getMessage();
        if ($message === $exception->getMessage() || $message === '') {
            $message = $exception->getMessage();
        }

        return new JsonResponse([
            'error' => $message,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
