<?php

namespace App\Controller\AdminApi;

use App\Service\AuditService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin-api')]
class PingController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.admin_api_limiter')]
        private readonly ?RateLimiterFactory $adminApiLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    #[Route('/_ping', name: 'admin_api_ping', methods: ['GET'])]
    public function ping(Request $request): JsonResponse
    {
        $userIdentifier = null;
        try {
            $userIdentifier = $this->getUser()?->getUserIdentifier();
        } catch (\Throwable) {}

        if ($this->adminApiLimiter !== null) {
            $identifier = $userIdentifier ?? ($request->getClientIp() ?: '127.0.0.1');
            if (!$this->adminApiLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('admin_api.ping.rate_limit_exceeded', [], 'Too many ping requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        return new JsonResponse(['data' => ['user' => $userIdentifier]], 200, self::SECURITY_HEADERS);
    }
}
