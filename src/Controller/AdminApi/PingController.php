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
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
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
            $limit = $this->adminApiLimiter->create($identifier)->consume(1);
            if (!$limit->isAccepted()) {
                $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
                $this->auditService?->log('admin_api_ping_rate_limit_exceeded', null, ['identifier' => $identifier], null, 'failure', 'Too many ping requests.', $userIdentifier, 'admin_api_ping');
                $this->auditService?->flush();
                return new JsonResponse([
                    'error' => $this->trans('admin_api.ping.rate_limit_exceeded', [], 'Too many ping requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, array_merge(self::SECURITY_HEADERS, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                    'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                    'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
                ]));
            }
        }

        return new JsonResponse(['data' => ['user' => $userIdentifier]], 200, self::SECURITY_HEADERS);
    }
}
