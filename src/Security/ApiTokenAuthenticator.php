<?php

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private ApiTokenRepository $tokenRepository,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization')
            && str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get('Authorization', '');
        $plainToken = substr($authHeader, 7); // Remove "Bearer "

        if (empty($plainToken)) {
            throw new CustomUserMessageAuthenticationException('No API token provided.');
        }

        $token = $this->tokenRepository->findByPlainToken($plainToken);

        if ($token === null) {
            throw new CustomUserMessageAuthenticationException('Invalid API token.');
        }

        if ($token->isExpired()) {
            throw new CustomUserMessageAuthenticationException('API token has expired.');
        }

        // Attach the token to request for use in controllers
        $request->attributes->set('_api_token', $token);

        return new SelfValidatingPassport(
            new UserBadge($token->project->uuid->toString(), function () use ($token) {
                // Return a pseudo-user representing the API token's project
                // Controllers must use $request->attributes->get('_api_token') directly
                return null;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // Continue request handling
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED);
    }
}
