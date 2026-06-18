<?php
namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class TwoFactorRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator) {}

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) return;

        if ($user->twoFactorEnabled) {
            $session = $event->getRequest()->getSession();
            $session->set('two_factor_user_id', $user->id);
            $session->set('two_factor_expires', time() + 300); // 5 minutes

            // Si méthode = email, envoyer automatiquement le code
            if ($user->twoFactorMethod === 'email') {
                // L'envoi sera fait par le challenge controller au premier GET
            }

            $response = new RedirectResponse($this->urlGenerator->generate('two_factor_challenge'));
            $event->setResponse($response);
            // Empêcher la création de la session complète
            $event->getRequest()->getSession()->remove('_security_main');
        }
    }
}
