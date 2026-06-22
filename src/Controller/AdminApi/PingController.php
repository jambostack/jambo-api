<?php

namespace App\Controller\AdminApi;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin-api')]
class PingController extends AbstractController
{
    #[Route('/_ping', name: 'admin_api_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return $this->json(['data' => ['user' => $this->getUser()?->getUserIdentifier()]]);
    }
}
