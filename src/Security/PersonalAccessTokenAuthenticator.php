<?php

namespace App\Security;

use App\Entity\ApiToken;
use App\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
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

class PersonalAccessTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private PersonalAccessTokenRepository $patRepository,
        private EntityManagerInterface $em,
        private string $appSecret,
    ) {}

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $plain = substr($request->headers->get('Authorization', ''), 7);
        if ($plain === '') {
            throw new CustomUserMessageAuthenticationException('No token provided.');
        }

        $pat = $this->patRepository->findByPlainToken($plain);
        if ($pat === null || $pat->isExpired() || $pat->user === null) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired token.');
        }

        // Track usage + upgrade legacy hash to HMAC on first use.
        $pat->lastUsedAt = new \DateTimeImmutable();
        if ($pat->tokenVersion === 1) {
            $pat->tokenHash = ApiToken::hashToken($plain, $this->appSecret);
            $pat->tokenVersion = 2;
        }
        $this->em->flush();

        $request->attributes->set('_pat', $pat);

        return new SelfValidatingPassport(new UserBadge($pat->user->email));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED);
    }
}
