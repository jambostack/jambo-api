<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Health')]
class HealthController extends AbstractController
{
    public function __construct(
        private Connection $connection,
    ) {}

    #[OA\Get(
        path: '/health',
        summary: 'System healthcheck and readiness probe',
        responses: [
            new OA\Response(response: 200, description: 'System healthy and ready'),
            new OA\Response(response: 503, description: 'Service degraded or database unreachable'),
        ]
    )]
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function check(): JsonResponse
    {
        $status = 'pass';
        $httpCode = 200;
        $checks = [];

        // 1. Database Connectivity Check
        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = ['status' => 'pass'];
        } catch (\Throwable $e) {
            $status = 'fail';
            $httpCode = 503;
            $checks['database'] = [
                'status' => 'fail',
                'message' => 'Database connection failed: ' . $e->getMessage(),
            ];
        }

        // 2. Storage / Cache Directory Writeability Check
        $varDir = (string) $this->getParameter('kernel.project_dir') . '/var';
        if (is_dir($varDir) && is_writable($varDir)) {
            $checks['storage'] = ['status' => 'pass'];
        } else {
            $checks['storage'] = [
                'status' => 'warn',
                'message' => 'var directory is not writable',
            ];
        }

        return new JsonResponse([
            'status'    => $status,
            'version'   => '1.26.22',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks'    => $checks,
        ], $httpCode);
    }
}
