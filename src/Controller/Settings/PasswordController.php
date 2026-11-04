<?php

namespace App\Controller\Settings;

use App\Entity\User;
use App\Service\Security\PasswordPolicyValidator;
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
        private ?PasswordPolicyValidator $passwordValidator = null,
    ) {
        $this->passwordValidator ??= new PasswordPolicyValidator();
    }

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

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $validator = $this->passwordValidator ?? new PasswordPolicyValidator();
        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');

        // 1. Contrôle de rotation et vérification du mot de passe actuel
        $rotationResult = $validator->validateRotation($currentPassword, $newPassword, $user, $this->hasher);
        if (!$rotationResult['valid']) {
            $errorKey = str_contains($rotationResult['error'] ?? '', 'different') ? 'password' : 'current_password';
            return $this->json(['errors' => [$errorKey => $rotationResult['error']]], 422);
        }

        // 2. Correspondance de confirmation
        if ($newPassword !== $confirmation) {
            return $this->json(['errors' => ['password_confirmation' => 'Passwords do not match.']], 422);
        }

        // 3. Politique de complexité et de sécurité du compte administrateur / membre
        $valResult = $validator->validateAdminPassword($newPassword, $user->email, $user->name);
        if (!$valResult['valid']) {
            return $this->json(['errors' => ['password' => $valResult['errors'][0]]], 422);
        }

        $user->password = $this->hasher->hashPassword($user, $newPassword);
        $user->passwordChangedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->redirectToRoute('settings_password', [], 303);
    }
}
