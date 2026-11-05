<?php

namespace App\Controller;

use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/automations')]
#[IsGranted('ROLE_USER')]
class FlowController extends AbstractController
{
    private readonly FlowValidator $validator;

    public function __construct(
        private readonly NodeRegistry $nodeRegistry,
        ?FlowValidator $validator = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->validator = $validator ?? new FlowValidator();
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    #[Route('/node-catalog', name: 'api_automation_node_catalog', methods: ['GET'])]
    public function nodeCatalog(): JsonResponse
    {
        return $this->json([
            'message'    => $this->trans('flow.admin.catalog_loaded', [], 'Flow node catalog retrieved successfully.'),
            'categories' => $this->nodeRegistry->getCatalog(),
        ]);
    }

    #[Route('/validate-flow', name: 'api_automation_validate_flow', methods: ['POST'])]
    public function validateFlow(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('flow.admin.invalid_json', [], 'Invalid JSON flow specification.')], 400);
        }

        $flowGraph = $data['flow_graph'] ?? null;
        if (!is_array($flowGraph) || empty($flowGraph)) {
            return $this->json(['error' => $this->trans('flow.admin.graph_required', [], 'Flow graph is required.')], 422);
        }

        $result = $this->validator->validate($flowGraph, $this->nodeRegistry);
        if (!$result['valid']) {
            return $this->json([
                'valid'   => false,
                'error'   => $this->trans('flow.admin.validation_failed', [], 'Flow graph validation failed.'),
                'details' => $result['errors'],
            ], 422);
        }

        return $this->json([
            'valid'   => true,
            'message' => $this->trans('flow.admin.validation_success', [], 'Flow graph is valid.'),
        ]);
    }
}
