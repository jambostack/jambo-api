<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegisteredUserController extends InertiaController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
        #[Autowire(service: 'limiter.register_limiter')]
        private RateLimiterFactory $registerLimiter,
    ) {}

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
            return $this->json(['error' => 'Too many requests. Please try again later.'], 429);
        }

        $data = $request->getPayload()->all();

        $errors = $this->validator->validate($data, new Assert\Collection([
            'name'                  => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 255)],
            'email'                 => [new Assert\NotBlank(), new Assert\Email()],
            'password'              => [new Assert\NotBlank(), new Assert\Length(min: 8)],
            'password_confirmation' => [new Assert\NotBlank()],
        ]));

        if (count($errors) > 0) {
            return $this->json(['errors' => $this->formatErrors($errors)], 422);
        }

        if ($data['password'] !== $data['password_confirmation']) {
            return $this->json(['errors' => ['password_confirmation' => 'Passwords do not match.']], 422);
        }

        $user = new User();
        $user->name  = $data['name'];
        $user->email = $data['email'];
        $user->password = $this->hasher->hashPassword($user, $data['password']);

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
