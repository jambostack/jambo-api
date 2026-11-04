<?php

namespace App\Controller\Settings;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/settings', name: 'api_settings_')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/profile', name: 'profile_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        return $this->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ]);
    }

    #[Route('/profile', name: 'profile_update', methods: ['PATCH'])]
    public function update(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }

        if (isset($data['email'])) {
            $newEmail = trim((string) $data['email']);
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['errors' => ['email' => 'Valid email address is required.']], 422);
            }
            if ($newEmail !== $user->email && $this->userRepository->findByEmail($newEmail) !== null) {
                return $this->json(['errors' => ['email' => 'Email already taken.']], 422);
            }
            $user->email = $newEmail;
        }

        $this->em->flush();

        return $this->redirectToRoute('settings_profile', [], 303);
    }

    #[Route('/profile', name: 'profile_delete', methods: ['DELETE'])]
    public function delete(): Response
    {
        if ($_ENV['DEMO_MODE'] ?? false) {
            return $this->json(['error' => 'Account deletion is disabled in demo mode.'], 403);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }
        $this->em->remove($user);
        $this->em->flush();

        return $this->redirectToRoute('app_login', [], 303);
    }
}
