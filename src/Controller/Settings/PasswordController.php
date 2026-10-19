<?php

namespace App\Controller\Settings;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class PasswordController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/api/settings/password', name: 'api_settings_password', methods: ['PUT'])]
    public function update(Request $request): Response
    {
        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->json(['errors' => ['current_password' => 'Password changes are disabled in demo mode.']], 403);
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $data = $request->toArray();

        if (!$this->hasher->isPasswordValid($user, $data['current_password'] ?? '')) {
            return $this->json(['errors' => ['current_password' => 'The current password is incorrect.']], 422);
        }

        if (strlen($data['password'] ?? '') < 8) {
            return $this->json(['errors' => ['password' => 'Password must be at least 8 characters.']], 422);
        }

        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            return $this->json(['errors' => ['password_confirmation' => 'Passwords do not match.']], 422);
        }

        $user->password = $this->hasher->hashPassword($user, $data['password']);
        $this->em->flush();

        return $this->redirectToRoute('settings_password', [], 303);
    }
}
