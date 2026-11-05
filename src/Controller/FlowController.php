<?php

namespace App\Controller;

use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/automations')]
#[IsGranted('ROLE_USER')]
class FlowController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    public const MAX_NODES_COUNT = 100;
    public const MAX_EDGES_COUNT = 200;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    private readonly FlowValidator $validator;

    public function __construct(
        private readonly NodeRegistry $nodeRegistry,
        ?FlowValidator $validator = null,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.flow_limiter')]
        private readonly ?RateLimiterFactory $flowLimiter = null,
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
    public function nodeCatalog(?Request $request = null): JsonResponse
    {
        if ($this->flowLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->flowLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('flow.admin.rate_limit_exceeded', [], 'Too many flow requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        return new JsonResponse([
            'message'    => $this->trans('flow.admin.catalog_loaded', [], 'Flow node catalog retrieved successfully.'),
            'categories' => $this->nodeRegistry->getCatalog(),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/validate-flow', name: 'api_automation_validate_flow', methods: ['POST'])]
    public function validateFlow(Request $request): JsonResponse
    {
        if ($this->flowLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->flowLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('flow.admin.rate_limit_exceeded', [], 'Too many flow requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('flow.admin.payload_too_large', [], 'Flow payload exceeds maximum allowed size (512 KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('flow.admin.invalid_json', [], 'Invalid JSON flow specification.'),
                'code'  => 'INVALID_JSON',
            ], 400, self::SECURITY_HEADERS);
        }

        $flowGraph = $data['flow_graph'] ?? null;
        if (!is_array($flowGraph) || empty($flowGraph)) {
            return new JsonResponse([
                'error' => $this->trans('flow.admin.graph_required', [], 'Flow graph is required.'),
                'code'  => 'GRAPH_REQUIRED',
            ], 422, self::SECURITY_HEADERS);
        }

        $nodes = $flowGraph['nodes'] ?? [];
        if (is_array($nodes) && count($nodes) > self::MAX_NODES_COUNT) {
            $msg = $this->trans('flow.admin.nodes_limit_exceeded', ['%max%' => self::MAX_NODES_COUNT], sprintf('Flow graph cannot contain more than %d nodes.', self::MAX_NODES_COUNT));
            return new JsonResponse([
                'valid'   => false,
                'error'   => $msg,
                'code'    => 'NODES_LIMIT_EXCEEDED',
                'details' => [$msg],
            ], 422, self::SECURITY_HEADERS);
        }

        $edges = $flowGraph['edges'] ?? [];
        if (is_array($edges) && count($edges) > self::MAX_EDGES_COUNT) {
            $msg = $this->trans('flow.admin.edges_limit_exceeded', ['%max%' => self::MAX_EDGES_COUNT], sprintf('Flow graph cannot contain more than %d edges.', self::MAX_EDGES_COUNT));
            return new JsonResponse([
                'valid'   => false,
                'error'   => $msg,
                'code'    => 'EDGES_LIMIT_EXCEEDED',
                'details' => [$msg],
            ], 422, self::SECURITY_HEADERS);
        }

        $result = $this->validator->validate($flowGraph, $this->nodeRegistry);
        if (!$result['valid']) {
            return new JsonResponse([
                'valid'   => false,
                'error'   => $this->trans('flow.admin.validation_failed', [], 'Flow graph validation failed.'),
                'code'    => 'VALIDATION_FAILED',
                'details' => $result['errors'],
            ], 422, self::SECURITY_HEADERS);
        }

        return new JsonResponse([
            'valid'   => true,
            'message' => $this->trans('flow.admin.validation_success', [], 'Flow graph is valid.'),
        ], 200, self::SECURITY_HEADERS);
    }
}
