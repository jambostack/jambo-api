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

class EndUserJwtAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private EndUserJwtService $jwtService,
        private EndUserRepository $endUserRepository,
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

        $claims = $this->jwtService->validateToken($jwt);
        if ($claims === null) {
            throw new AuthenticationException('Invalid or expired token.');
        }

        if ($this->jwtService->isRefreshToken($claims)) {
            throw new AuthenticationException('Refresh tokens cannot be used for API access.');
        }

        $endUserUuid = $claims['euid'];
        $tokenVersion = $claims['tkn'];

        return new SelfValidatingPassport(
            new UserBadge($endUserUuid, function ($uuid) use ($tokenVersion) {
                $endUser = $this->endUserRepository->findOneBy(['uuid' => $uuid]);
                if ($endUser === null || !$endUser->isActive()) {
                    throw new AuthenticationException('User not found or inactive.');
                }
                if ($endUser->tokenVersion !== $tokenVersion) {
                    throw new AuthenticationException('Token version mismatch - please re-login.');
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
        return new JsonResponse([
            'error' => $exception->getMessage(),
        ], Response::HTTP_UNAUTHORIZED);
    }
}
