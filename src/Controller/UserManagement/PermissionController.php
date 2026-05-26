<?php

namespace App\Controller\UserManagement;

use App\Repository\PermissionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/permissions', name: 'api_permissions_')]
class PermissionController extends AbstractController
{
    public function __construct(
        private PermissionRepository $permissionRepository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $grouped = $this->permissionRepository->findAllGrouped();

        return $this->json(['data' => $grouped]);
    }
}
