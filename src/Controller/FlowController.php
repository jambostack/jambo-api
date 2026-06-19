<?php

namespace App\Controller;

use App\Service\Flow\NodeRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/automations')]
class FlowController extends AbstractController
{
    public function __construct(
        private readonly NodeRegistry $nodeRegistry,
    ) {}

    #[Route('/node-catalog', name: 'api_automation_node_catalog', methods: ['GET'])]
    public function nodeCatalog(): JsonResponse
    {
        return $this->json([
            'categories' => $this->nodeRegistry->getCatalog(),
        ]);
    }
}
