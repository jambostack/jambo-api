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
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/forgot-password')]
class PasswordResetLinkController extends InertiaController
{
    private const MAX_PAYLOAD_BYTES = 16384; // 16 KB

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
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->inertia($request, 'auth/forgot-password', [
                'error' => $this->trans('auth.password_reset.payload_too_large', [], 'Payload exceeds maximum allowed size (16 KB).'),
            ], 413);
        }

        // Passive purge of expired tokens on each reset request
        $this->tokenRepository->deleteExpired();

        $limiter = $this->passwordResetLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->inertia($request, 'auth/forgot-password', [
                'error' => $this->trans('auth.password_reset.rate_limit_exceeded', [], 'Too many requests. Please try again later.'),
            ]);
        }

        try {
            $email = $request->getPayload()->getString('email');
        } catch (\Throwable) {
            $email = (string) $request->request->get('email', '');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->inertia($request, 'auth/forgot-password', [
                'errors' => ['email' => $this->trans('auth.password_reset.invalid_email', [], 'Invalid email address.')],
            ]);
        }

        $user = $this->userRepository->findByEmail($email);
        if ($user !== null) {
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

            $subject = $this->trans('auth.password_reset.email_subject', [], 'Réinitialisation de votre mot de passe Jambo');
            $heading = $this->trans('auth.password_reset.email_heading', [], 'Réinitialisation de mot de passe');
            $body = $this->trans('auth.password_reset.email_body', [], 'Vous avez demandé la réinitialisation de votre mot de passe Jambo.');
            $button = $this->trans('auth.password_reset.email_button', [], 'Réinitialiser mon mot de passe');
            $footer = $this->trans('auth.password_reset.email_footer', [], 'Ce lien expire dans 1 heure. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.');

            try {
                $this->mailer->send(
                    (new Email())
                        ->from('noreply@jambostack.site')
                        ->to($email)
                        ->subject($subject)
                        ->html(sprintf(
                            '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:20px">
                            <h2>%s</h2>
                            <p>%s</p>
                            <p><a href="%s" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px">%s</a></p>
                            <p style="color:#666;font-size:14px">%s</p>
                            </body></html>',
                            htmlspecialchars($heading),
                            htmlspecialchars($body),
                            htmlspecialchars($resetUrl),
                            htmlspecialchars($button),
                            htmlspecialchars($footer)
                        ))
                );
            } catch (\Throwable) {
                // Silently ignore mail transport errors in test/dev
            }
        }

        return $this->inertia($request, 'auth/forgot-password', [
            'status' => $this->trans('auth.password_reset.email_sent_status', [], 'If this address corresponds to an account, an email with a reset link has been sent.'),
        ]);
    }
}
