<?php

namespace App\Controller\Settings;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings', name: 'settings_')]
#[IsGranted('ROLE_USER')]
class LocaleController extends AbstractController
{
    private const ALLOWED_LOCALES = ['en', 'fr', 'es', 'ar'];

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    #[Route('/locale', name: 'locale_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $locale = $data['locale'] ?? null;

        if (!in_array($locale, self::ALLOWED_LOCALES, true)) {
            return $this->json(['error' => 'Invalid locale.'], 422);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $user->locale = $locale;
        $this->em->flush();

        $referer = $request->headers->get('referer', '/');
        return $this->redirect($referer);
    }
}
