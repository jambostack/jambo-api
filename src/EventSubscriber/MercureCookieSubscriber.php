<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Sets the mercureAuthorization cookie after successful login.
 *
 * Scopes topics strictly to projects the authenticated user belongs to.
 * Super admins receive global wildcard access ['*'].
 */
class MercureCookieSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $mercureJwtSecret,
        private readonly string $mercurePublicUrl,
        private readonly RequestStack $requestStack,
        private readonly bool $mercureEnabled = false,
        private readonly ?ProjectMemberRepository $memberRepo = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->mercureEnabled) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($event->getResponse() === null) {
            return;
        }

        if (strlen($this->mercureJwtSecret) < 32) {
            return;
        }

        $isSuperAdmin = $user->isSuperAdmin() || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        if ($isSuperAdmin) {
            $subscribeTopics = ['*'];
            $publishTopics   = ['*'];
        } else {
            $subscribeTopics = [];
            $publishTopics   = [];

            if ($this->memberRepo !== null) {
                $memberships = $this->memberRepo->findActiveByUser($user);
                foreach ($memberships as $membership) {
                    $projUuid = $membership->project?->uuid?->toRfc4122();
                    if ($projUuid !== null) {
                        $subscribeTopics[] = "projects/{$projUuid}";
                        $subscribeTopics[] = "projects/{$projUuid}/*";

                        $role = $membership->role;
                        $roleName = strtolower($role instanceof Role ? $role->name : (is_string($role) ? $role : ''));
                        $canPub = in_array($roleName, ['owner', 'admin', 'manager', 'editor'], true)
                            || ($role instanceof Role && ($role->hasPermission('realtime.publish') || $role->hasPermission('content.edit')));

                        if ($canPub) {
                            $publishTopics[] = "projects/{$projUuid}";
                            $publishTopics[] = "projects/{$projUuid}/*";
                        }
                    }
                }
            }
        }

        $jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->mercureJwtSecret),
        );

        $domain = parse_url($this->mercurePublicUrl, PHP_URL_HOST) ?: '';
        $now = new \DateTimeImmutable();
        $token = $jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+24 hours'))
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->relatedTo($user->getUserIdentifier())
            ->issuedBy($domain !== '' ? 'https://' . $domain : 'jambostack')
            ->withClaim('mercure', [
                'subscribe' => array_values(array_unique($subscribeTopics)),
                'publish'   => array_values(array_unique($publishTopics)),
            ])
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey())
            ->toString();

        $cookie = Cookie::create('mercureAuthorization')
            ->withValue($token)
            ->withPath('/.well-known/mercure')
            ->withDomain($domain !== '' ? $domain : null)
            ->withHttpOnly(true)
            ->withSecure($this->requestStack->getCurrentRequest()?->isSecure() ?? false)
            ->withSameSite(Cookie::SAMESITE_STRICT)
            ->withExpires($now->modify('+24 hours'));

        $event->getResponse()->headers->setCookie($cookie);
    }
}
