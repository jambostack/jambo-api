<?php

namespace App\Controller\Settings;

use App\Controller\InertiaController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings', name: 'settings_')]
class LocaleController extends InertiaController
{
    private const ALLOWED_LOCALES = ['en', 'fr', 'es', 'ar'];

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    #[Route('/locale', name: 'locale_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $data = $request->toArray();
        $locale = $data['locale'] ?? null;

        if (!in_array($locale, self::ALLOWED_LOCALES, true)) {
            return $this->json(['error' => 'Invalid locale.'], 422);
        }

        $user = $this->getUser();
        $user->locale = $locale;
        $this->em->flush();

        $referer = $request->headers->get('referer', '/');
        return $this->redirect($referer);
    }
}
