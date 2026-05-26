<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Entity\PasswordResetToken;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/forgot-password')]
class PasswordResetLinkController extends InertiaController
{
    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $tokenRepository,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        #[Autowire(service: 'limiter.password_reset_limiter')]
        private RateLimiterFactory $passwordResetLimiter,
    ) {}

    #[Route('', name: 'app_forgot_password', methods: ['GET'])]
    public function create(Request $request): Response
    {
        return $this->inertia($request, 'auth/forgot-password');
    }

    #[Route('', name: 'app_forgot_password_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        // Passive purge of expired tokens on each reset request
        $this->tokenRepository->deleteExpired();

        $limiter = $this->passwordResetLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests. Please try again later.'], 429);
        }

        $email = $request->getPayload()->getString('email');

        // Always return success to prevent email enumeration
        $user = $this->userRepository->findByEmail($email);
        if ($user !== null) {
            // Remove previous tokens for this email
            $existing = $this->tokenRepository->findBy(['email' => $email]);
            foreach ($existing as $old) {
                $this->em->remove($old);
            }

            $resetToken = new PasswordResetToken($email);
            $this->em->persist($resetToken);
            $this->em->flush();

            $resetUrl = $this->generateUrl(
                'app_reset_password',
                ['token' => $resetToken->token],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $this->mailer->send(
                (new Email())
                    ->to($email)
                    ->subject('Reset your password')
                    ->html(sprintf('<p>Click <a href="%s">here</a> to reset your password. Link expires in 1 hour.</p>', $resetUrl))
            );
        }

        return $this->inertia($request, 'auth/forgot-password', [
            'status' => 'We have emailed your password reset link!',
        ]);
    }
}
