<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Service\AuditService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfirmablePasswordController extends InertiaController
{
    private const MAX_PAYLOAD_BYTES = 16384; // 16 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'Pragma'                 => 'no-cache',
    ];

    public function __construct(
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'limiter.login_limiter')]
        private ?RateLimiterFactory $loginLimiter = null,
        private ?AuditService $auditService = null,
        private ?TranslatorInterface $customTranslator = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    protected function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->customTranslator !== null) {
            $res = $this->customTranslator->trans($id, $parameters, 'messages');
            if ($res !== $id) {
                return $res;
            }
        }
        return parent::trans($id, $parameters, $default);
    }

    #[Route('/confirm-password', name: 'app_confirm_password', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $resp = $this->inertia($request, 'auth/confirm-password');
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $resp->headers->set($k, $v);
        }
        return $resp;
    }

    #[Route('/confirm-password', name: 'app_confirm_password_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->secureJson(['error' => $this->trans('auth.confirm_password.auth_required', [], 'Authentication required')], 401);
        }

        $ip = $request->getClientIp() ?: '127.0.0.1';
        $contentType = $request->headers->get('Content-Type', '');
        $rawContent = $request->getContent();

        if ($rawContent !== '' && !empty($contentType)) {
            $isJson = str_contains($contentType, 'application/json');
            if (!$isJson) {
                if ($this->auditService !== null) {
                    $this->auditService->log(
                        toolName: 'password_confirm_invalid_content_type',
                        project: null,
                        input: ['ip' => $ip, 'user_id' => $user->id, 'content_type' => $contentType],
                        output: null,
                        status: 'failure',
                        errorMessage: 'Invalid Content-Type for password confirmation.',
                        createdBy: $user->getUserIdentifier(),
                        source: 'confirm_password'
                    );
                    $this->auditService->flush();
                }
                return $this->secureJson([
                    'error' => $this->trans('auth.confirm_password.invalid_content_type', [], 'Content-Type must be application/json.')
                ], 415);
            }
        }

        if (strlen($rawContent) > self::MAX_PAYLOAD_BYTES) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_confirm_payload_too_large',
                    project: null,
                    input: ['ip' => $ip, 'user_id' => $user->id, 'size' => strlen($rawContent)],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Payload exceeds maximum allowed size (16 KB).',
                    createdBy: $user->getUserIdentifier(),
                    source: 'confirm_password'
                );
                $this->auditService->flush();
            }
            return $this->secureJson(['error' => $this->trans('auth.confirm_password.payload_too_large', [], 'Payload exceeds maximum allowed size (16 KB).')], 413);
        }

        if ($this->loginLimiter !== null) {
            $identifier = $ip . '_' . $user->id;
            if (!$this->loginLimiter->create($identifier)->consume()->isAccepted()) {
                if ($this->auditService !== null) {
                    $this->auditService->log(
                        toolName: 'password_confirm_rate_limited',
                        project: null,
                        input: ['ip' => $ip, 'user_id' => $user->id],
                        output: null,
                        status: 'failure',
                        errorMessage: 'Too many confirmation attempts. Rate limited.',
                        createdBy: $user->getUserIdentifier(),
                        source: 'confirm_password'
                    );
                    $this->auditService->flush();
                }
                return $this->secureJson(['error' => $this->trans('auth.confirm_password.rate_limit_exceeded', [], 'Too many confirmation attempts. Please retry later.')], 429);
            }
        }

        try {
            $password = $request->getPayload()->getString('password');
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('auth.confirm_password.invalid_json', [], 'Invalid JSON body')], 400);
        }

        if (!$this->hasher->isPasswordValid($user, $password)) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_confirm_failed',
                    project: null,
                    input: ['ip' => $ip, 'user_id' => $user->id],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Incorrect password provided during password confirmation.',
                    createdBy: $user->getUserIdentifier(),
                    source: 'confirm_password'
                );
                $this->auditService->flush();
            }
            return $this->secureJson(['errors' => ['password' => $this->trans('auth.confirm_password.incorrect_password', [], 'The provided password was incorrect.')]], 422);
        }

        // Mark session as password-confirmed
        $request->getSession()->set('auth.password_confirmed_at', time());

        if ($this->auditService !== null) {
            $this->auditService->log(
                toolName: 'password_confirm_success',
                project: null,
                input: ['ip' => $ip, 'user_id' => $user->id],
                output: ['success' => true],
                status: 'success',
                createdBy: $user->getUserIdentifier(),
                source: 'confirm_password'
            );
            $this->auditService->flush();
        }

        $intended = $request->getSession()->get('url.intended', '/');
        // Reject absolute URLs to prevent open redirect attacks
        if (!str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            $intended = '/';
        }
        $resp = $this->redirect($intended);
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $resp->headers->set($k, $v);
        }
        return $resp;
    }
}
