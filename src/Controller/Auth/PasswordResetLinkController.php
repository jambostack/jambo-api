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
            return $this->inertia($request, 'auth/forgot-password', [
                'error' => 'Too many requests. Please try again later.',
            ]);
        }

        $email = $request->getPayload()->getString('email');

        $user = $this->userRepository->findByEmail($email);
        if ($user === null) {
            return $this->inertia($request, 'auth/forgot-password', [
                'errors' => ['email' => 'Aucun compte trouvé avec cet email.'],
            ]);
        }

        // Remove previous tokens for this email
        $existing = $this->tokenRepository->findBy(['email' => $email]);
        foreach ($existing as $old) {
            $this->em->remove($old);
        }

        $resetToken = new PasswordResetToken(null, $email);
        $this->em->persist($resetToken);
        $this->em->flush();

        $resetUrl = $this->generateUrl(
            'app_reset_password',
            ['token' => $resetToken->token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->mailer->send(
            (new Email())
                ->from('noreply@jambostack.site')
                ->to($email)
                ->subject('Réinitialisation de votre mot de passe Jambo')
                ->html(sprintf(
                    '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:20px">
                    <h2>Réinitialisation de mot de passe</h2>
                    <p>Vous avez demandé la réinitialisation de votre mot de passe Jambo.</p>
                    <p><a href="%s" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px">Réinitialiser mon mot de passe</a></p>
                    <p style="color:#666;font-size:14px">Ce lien expire dans 1 heure. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.</p>
                    </body></html>',
                    $resetUrl
                ))
        );

        return $this->inertia($request, 'auth/forgot-password', [
            'status' => 'We have emailed your password reset link!',
        ]);
    }
}
