<?php

namespace App\EventSubscriber;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Pose le cookie mercureAuthorization après un login admin réussi.
 *
 * Le navigateur envoie automatiquement ce cookie sur les requêtes
 * EventSource vers le hub Mercure (Path=/.well-known/mercure).
 * Aucun code JS n'est nécessaire pour l'authentification SSE.
 */
class MercureCookieSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $mercureJwtSecret,
        private readonly string $mercurePublicUrl,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof \App\Entity\User) {
            // Ne concerne que les admins (User entity), pas les end-users
            return;
        }

        // Si le secret Mercure n'est pas configuré ou trop court, on ne fait rien
        if (strlen($this->mercureJwtSecret) < 16) {
            return;
        }

        $jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->mercureJwtSecret),
        );

        $now = new \DateTimeImmutable();
        $token = $jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+24 hours'))
            ->withClaim('mercure', [
                'subscribe' => ['*'],   // L'admin peut souscrire à tous les topics
                'publish'   => ['*'],
            ])
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey())
            ->toString();

        // Déterminer le domaine du cookie à partir de l'URL publique Mercure
        $domain = parse_url($this->mercurePublicUrl, PHP_URL_HOST) ?: '';

        $cookie = Cookie::create('mercureAuthorization')
            ->withValue($token)
            ->withPath('/.well-known/mercure')
            ->withDomain($domain !== '' ? $domain : null)
            ->withHttpOnly(true)
            ->withSecure(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ->withSameSite('Strict')
            ->withExpires($now->modify('+24 hours'));

        $event->getResponse()->headers->setCookie($cookie);
    }
}
