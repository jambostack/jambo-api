<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfirmablePasswordController extends InertiaController
{
    private const MAX_PAYLOAD_BYTES = 16384; // 16 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'limiter.login_limiter')]
        private ?RateLimiterFactory $loginLimiter = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('/confirm-password', name: 'app_confirm_password', methods: ['GET'])]
    public function show(Request $request): Response
    {
        return $this->inertia($request, 'auth/confirm-password');
    }

    #[Route('/confirm-password', name: 'app_confirm_password_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->secureJson(['error' => $this->trans('auth.confirm_password.auth_required', [], 'Authentication required')], 401);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('auth.confirm_password.payload_too_large', [], 'Payload exceeds maximum allowed size (16 KB).')], 413);
        }

        if ($this->loginLimiter !== null) {
            $identifier = ($request->getClientIp() ?: '127.0.0.1') . '_' . $user->id;
            if (!$this->loginLimiter->create($identifier)->consume()->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('auth.confirm_password.rate_limit_exceeded', [], 'Too many confirmation attempts. Please retry later.')], 429);
            }
        }

        try {
            $password = $request->getPayload()->getString('password');
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('auth.confirm_password.invalid_json', [], 'Invalid JSON body')], 400);
        }

        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->secureJson(['errors' => ['password' => $this->trans('auth.confirm_password.incorrect_password', [], 'The provided password was incorrect.')]], 422);
        }

        // Mark session as password-confirmed
        $request->getSession()->set('auth.password_confirmed_at', time());

        $intended = $request->getSession()->get('url.intended', '/');
        // Reject absolute URLs to prevent open redirect attacks
        if (!str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            $intended = '/';
        }
        return $this->redirect($intended);
    }
}
