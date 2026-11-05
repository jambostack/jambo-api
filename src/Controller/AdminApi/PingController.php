<?php

namespace App\Controller\AdminApi;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin-api')]
class PingController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
    ];

    #[Route('/_ping', name: 'admin_api_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return new JsonResponse(['data' => ['user' => $this->getUser()?->getUserIdentifier()]], 200, self::SECURITY_HEADERS);
    }
}
