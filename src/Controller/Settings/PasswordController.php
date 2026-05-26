<?php

namespace App\Controller\Settings;

use App\Controller\InertiaController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class PasswordController extends InertiaController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/api/settings/password', name: 'api_settings_password', methods: ['PUT'])]
    public function update(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
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
