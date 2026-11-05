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
use Symfony\Contracts\Translation\TranslatorInterface;

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private ApiTokenRepository $tokenRepository,
        private ?TranslatorInterface $translator = null,
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
            throw new CustomUserMessageAuthenticationException('auth.api_token.not_provided');
        }

        if (strlen($plainToken) > 1024) {
            throw new CustomUserMessageAuthenticationException('auth.api_token.invalid');
        }

        $token = $this->tokenRepository->findByPlainToken($plainToken);

        if ($token === null) {
            throw new CustomUserMessageAuthenticationException('auth.api_token.invalid');
        }

        if ($token->isExpired()) {
            throw new CustomUserMessageAuthenticationException('auth.api_token.expired');
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
        $message = $this->translator !== null
            ? $this->translator->trans($exception->getMessageKey(), $exception->getMessageData(), 'messages')
            : $exception->getMessageKey();
        return new JsonResponse(['error' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
