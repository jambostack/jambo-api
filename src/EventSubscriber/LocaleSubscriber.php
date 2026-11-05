<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ?TranslatorInterface $translator = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $targetLocale = null;

        $token = $this->tokenStorage->getToken();
        if ($token !== null) {
            $user = $token->getUser();
            if ($user instanceof User && !empty($user->locale)) {
                $targetLocale = $user->locale;
            }
        }

        if ($targetLocale === null && $request->headers->has('Accept-Language')) {
            $preferred = $request->getPreferredLanguage(['en', 'fr', 'es', 'ar']);
            if ($preferred) {
                $targetLocale = $preferred;
            }
        }

        if ($targetLocale !== null) {
            $request->setLocale($targetLocale);
            if ($this->translator instanceof LocaleAwareInterface) {
                $this->translator->setLocale($targetLocale);
            }
        }
    }
}
