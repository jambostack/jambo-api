<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Entity\User;
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
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
        #[Autowire(service: 'limiter.register_limiter')]
        private RateLimiterFactory $registerLimiter,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('/register', name: 'app_register', methods: ['GET'])]
    public function create(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->inertia($request, 'auth/register');
    }

    #[Route('/register', name: 'app_register_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $limiter = $this->registerLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->secureJson(['error' => $this->trans('auth.register.rate_limit_exceeded', [], 'Too many requests. Please try again later.')], 429);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('auth.register.payload_too_large', [], 'Payload exceeds maximum allowed size (16 KB).')], 413);
        }

        try {
            $data = $request->getPayload()->all();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('auth.register.invalid_json', [], 'Invalid JSON body')], 400);
        }

        $errors = $this->validator->validate($data, new Assert\Collection([
            'name'                  => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 255)],
            'email'                 => [new Assert\NotBlank(), new Assert\Email()],
            'password'              => [new Assert\NotBlank(), new Assert\Length(min: 8, max: 4096)],
            'password_confirmation' => [new Assert\NotBlank()],
        ]));

        if (count($errors) > 0) {
            return $this->secureJson(['errors' => $this->formatErrors($errors)], 422);
        }

        if ($data['password'] !== $data['password_confirmation']) {
            return $this->secureJson(['errors' => ['password_confirmation' => $this->trans('auth.register.passwords_do_not_match', [], 'Passwords do not match.')]], 422);
        }

        $pwdValidator = new PasswordPolicyValidator();
        $pwdResult = $pwdValidator->validateAdminPassword($data['password'], $data['email'], $data['name']);
        if (!$pwdResult['valid']) {
            return $this->secureJson(['errors' => ['password' => $pwdResult['errors'][0]]], 422);
        }

        if ($this->em->getRepository(User::class)->findOneBy(['email' => $data['email']]) !== null) {
            return $this->secureJson(['errors' => ['email' => $this->trans('auth.register.email_in_use', [], 'This email is already in use.')]], 422);
        }

        $user = new User();
        $user->name  = $data['name'];
        $user->email = $data['email'];
        $user->password = $this->hasher->hashPassword($user, $data['password']);
        $user->passwordChangedAt = new \DateTimeImmutable();

        $this->em->persist($user);
        $this->em->flush();

        return $this->redirectToRoute('app_login');
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
