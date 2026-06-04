<?php

namespace App\Controller\Settings;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/settings', name: 'api_settings_')]
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
        $data = $request->toArray();

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }

        if (isset($data['email'])) {
            if ($data['email'] !== $user->email && $this->userRepository->findByEmail($data['email']) !== null) {
                return $this->json(['errors' => ['email' => 'Email already taken.']], 422);
            }
            $user->email = $data['email'];
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
        $this->em->remove($user);
        $this->em->flush();

        return $this->redirectToRoute('app_login', [], 303);
    }
}
