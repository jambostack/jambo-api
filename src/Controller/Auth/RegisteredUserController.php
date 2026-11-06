<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Entity\User;
use App\Service\AuditService;
use App\Service\Security\PasswordPolicyValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegisteredUserController extends InertiaController
{
    private const MAX_PAYLOAD_BYTES = 16384; // 16 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'Pragma'                 => 'no-cache',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
        #[Autowire(service: 'limiter.register_limiter')]
        private RateLimiterFactory $registerLimiter,
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

    #[Route('/register', name: 'app_register', methods: ['GET'])]
    public function create(Request $request): Response
    {
        if ($this->getUser()) {
            $resp = $this->redirectToRoute('app_home');
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        $resp = $this->inertia($request, 'auth/register');
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $resp->headers->set($k, $v);
        }
        return $resp;
    }

    #[Route('/register', name: 'app_register_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $ip = $request->getClientIp() ?: '127.0.0.1';

        $contentType = $request->headers->get('Content-Type', '');
        $rawContent = $request->getContent();
        if ($rawContent !== '' && !empty($contentType)) {
            $isJson = str_contains($contentType, 'application/json');
            $isForm = str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data');
            if (!$isJson && !$isForm) {
                if ($this->auditService !== null) {
                    $this->auditService->log(
                        toolName: 'user_register_invalid_content_type',
                        project: null,
                        input: ['ip' => $ip, 'content_type' => $contentType],
                        output: null,
                        status: 'failure',
                        errorMessage: 'Invalid Content-Type for registration.',
                        createdBy: null,
                        source: 'register'
                    );
                    $this->auditService->flush();
                }
                return $this->secureJson([
                    'error' => $this->trans('auth.register.invalid_content_type', [], 'Content-Type must be application/json or application/x-www-form-urlencoded.')
                ], 415);
            }
        }

        if (strlen($rawContent) > self::MAX_PAYLOAD_BYTES) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'user_register_payload_too_large',
                    project: null,
                    input: ['ip' => $ip, 'size' => strlen($rawContent)],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Payload exceeds maximum allowed size (16 KB).',
                    createdBy: null,
                    source: 'register'
                );
                $this->auditService->flush();
            }
            return $this->secureJson(['error' => $this->trans('auth.register.payload_too_large', [], 'Payload exceeds maximum allowed size (16 KB).')], 413);
        }

        $limiter = $this->registerLimiter->create($ip);
        if (!$limiter->consume()->isAccepted()) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'user_register_rate_limited',
                    project: null,
                    input: ['ip' => $ip],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Rate limit exceeded on user registration.',
                    createdBy: null,
                    source: 'register'
                );
                $this->auditService->flush();
            }
            return $this->secureJson(['error' => $this->trans('auth.register.rate_limit_exceeded', [], 'Too many requests. Please try again later.')], 429);
        }

        try {
            $data = $request->getPayload()->all();
        } catch (\Throwable) {
            $data = [];
        }

        if (empty($data)) {
            $data = $request->request->all();
        }

        if (empty($data) && $rawContent !== '') {
            try {
                $data = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                if ($this->auditService !== null) {
                    $this->auditService->log(
                        toolName: 'user_register_invalid_json',
                        project: null,
                        input: ['ip' => $ip],
                        output: null,
                        status: 'failure',
                        errorMessage: 'Invalid JSON body',
                        createdBy: null,
                        source: 'register'
                    );
                    $this->auditService->flush();
                }
                return $this->secureJson(['error' => $this->trans('auth.register.invalid_json', [], 'Invalid JSON body')], 400);
            }
        }

        $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
        $email = isset($data['email']) && is_string($data['email']) ? mb_strtolower(trim($data['email'])) : '';
        $password = isset($data['password']) && is_string($data['password']) ? $data['password'] : '';
        $confirmation = isset($data['password_confirmation']) && is_string($data['password_confirmation']) ? $data['password_confirmation'] : '';

        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return $this->secureJson(['errors' => ['name' => $this->trans('auth.register.name_invalid_chars', [], 'Name contains invalid control characters.')]], 422);
        }

        if (mb_strlen($name) < 2) {
            return $this->secureJson(['errors' => ['name' => $this->trans('auth.register.name_too_short', [], 'Name must be at least 2 characters.')]], 422);
        }

        if (mb_strlen($name) > 255) {
            return $this->secureJson(['errors' => ['name' => $this->trans('auth.register.name_too_long', [], 'Name must not exceed 255 characters.')]], 422);
        }

        if (mb_strlen($email) > 180) {
            return $this->secureJson(['errors' => ['email' => $this->trans('auth.register.email_too_long', [], 'Email address must not exceed 180 characters.')]], 422);
        }

        $errors = $this->validator->validate([
            'name'                  => $name,
            'email'                 => $email,
            'password'              => $password,
            'password_confirmation' => $confirmation,
        ], new Assert\Collection([
            'name'                  => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 255)],
            'email'                 => [new Assert\NotBlank(), new Assert\Email()],
            'password'              => [new Assert\NotBlank(), new Assert\Length(min: 8, max: 4096)],
            'password_confirmation' => [new Assert\NotBlank()],
        ]));

        if (count($errors) > 0) {
            return $this->secureJson(['errors' => $this->formatErrors($errors)], 422);
        }

        if ($password !== $confirmation) {
            return $this->secureJson(['errors' => ['password_confirmation' => $this->trans('auth.register.passwords_do_not_match', [], 'Passwords do not match.')]], 422);
        }

        $pwdValidator = new PasswordPolicyValidator();
        $pwdResult = $pwdValidator->validateAdminPassword($password, $email, $name);
        if (!$pwdResult['valid']) {
            return $this->secureJson(['errors' => ['password' => $pwdResult['errors'][0]]], 422);
        }

        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'user_register_conflict',
                    project: null,
                    input: ['ip' => $ip, 'email' => $email],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Registration attempt with existing email.',
                    createdBy: null,
                    source: 'register'
                );
                $this->auditService->flush();
            }
            return $this->secureJson(['errors' => ['email' => $this->trans('auth.register.email_in_use', [], 'This email is already in use.')]], 422);
        }

        $user = new User();
        $user->name  = $name;
        $user->email = $email;
        $user->password = $this->hasher->hashPassword($user, $password);
        $user->passwordChangedAt = new \DateTimeImmutable();

        $this->em->persist($user);
        $this->em->flush();

        if ($this->auditService !== null) {
            $this->auditService->log(
                toolName: 'user_registered',
                project: null,
                input: ['ip' => $ip, 'email' => $email, 'name' => $name, 'userId' => $user->id],
                output: ['success' => true, 'userId' => $user->id],
                status: 'success',
                createdBy: $email,
                source: 'register'
            );
            $this->auditService->flush();
        }

        $resp = $this->redirectToRoute('app_login');
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $resp->headers->set($k, $v);
        }
        return $resp;
    }

    private function formatErrors(\Symfony\Component\Validator\ConstraintViolationListInterface $errors): array
    {
        $formatted = [];
        foreach ($errors as $error) {
            $key = trim($error->getPropertyPath(), '[]');
            $formatted[$key] = $error->getMessage();
        }
        return $formatted;
    }
}
