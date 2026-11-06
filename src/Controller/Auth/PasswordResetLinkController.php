<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Entity\PasswordResetToken;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Service\AuditService;
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
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'Pragma'                 => 'no-cache',
    ];

    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $tokenRepository,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        #[Autowire(service: 'limiter.password_reset_limiter')]
        private RateLimiterFactory $passwordResetLimiter,
        private ?AuditService $auditService = null,
        private ?TranslatorInterface $customTranslator = null,
    ) {}

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

    #[Route('', name: 'app_forgot_password', methods: ['GET'])]
    public function create(Request $request): Response
    {
        $resp = $this->inertia($request, 'auth/forgot-password');
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $resp->headers->set($k, $v);
        }
        return $resp;
    }

    #[Route('', name: 'app_forgot_password_store', methods: ['POST'])]
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
                        toolName: 'password_reset_request_invalid_content_type',
                        project: null,
                        input: ['ip' => $ip, 'content_type' => $contentType],
                        output: null,
                        status: 'failure',
                        errorMessage: 'Invalid Content-Type for password reset request.',
                        createdBy: null,
                        source: 'forgot_password'
                    );
                    $this->auditService->flush();
                }
                $resp = $this->inertia($request, 'auth/forgot-password', [
                    'error' => $this->trans('auth.password_reset.invalid_content_type', [], 'Content-Type must be application/json or application/x-www-form-urlencoded.'),
                ], 415);
                foreach (self::SECURITY_HEADERS as $k => $v) {
                    $resp->headers->set($k, $v);
                }
                return $resp;
            }
        }

        if (strlen($rawContent) > self::MAX_PAYLOAD_BYTES) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_reset_request_payload_too_large',
                    project: null,
                    input: ['ip' => $ip, 'size' => strlen($rawContent)],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Payload exceeds maximum allowed size (16 KB).',
                    createdBy: null,
                    source: 'forgot_password'
                );
                $this->auditService->flush();
            }
            $resp = $this->inertia($request, 'auth/forgot-password', [
                'error' => $this->trans('auth.password_reset.payload_too_large', [], 'Payload exceeds maximum allowed size (16 KB).'),
            ], 413);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        // Passive purge of expired tokens on each reset request
        $this->tokenRepository->deleteExpired();

        $limiter = $this->passwordResetLimiter->create($ip);
        if (!$limiter->consume()->isAccepted()) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_reset_request_rate_limited',
                    project: null,
                    input: ['ip' => $ip],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Rate limit exceeded on password reset requests.',
                    createdBy: null,
                    source: 'forgot_password'
                );
                $this->auditService->flush();
            }
            $resp = $this->inertia($request, 'auth/forgot-password', [
                'error' => $this->trans('auth.password_reset.rate_limit_exceeded', [], 'Too many requests. Please try again later.'),
            ], 429);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        try {
            $email = $request->getPayload()->getString('email');
        } catch (\Throwable) {
            $email = (string) $request->request->get('email', '');
        }
        $email = mb_strtolower(trim($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_reset_request_invalid_email',
                    project: null,
                    input: ['ip' => $ip, 'email' => $email],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Invalid email address provided for password reset.',
                    createdBy: null,
                    source: 'forgot_password'
                );
                $this->auditService->flush();
            }
            $resp = $this->inertia($request, 'auth/forgot-password', [
                'errors' => ['email' => $this->trans('auth.password_reset.invalid_email', [], 'Invalid email address.')],
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
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

            $subject = $this->trans('auth.password_reset.email_subject', [], 'Reset your Jambo password');
            $heading = $this->trans('auth.password_reset.email_heading', [], 'Password Reset');
            $body = $this->trans('auth.password_reset.email_body', [], 'You requested a password reset for your Jambo account.');
            $button = $this->trans('auth.password_reset.email_button', [], 'Reset my password');
            $footer = $this->trans('auth.password_reset.email_footer', [], 'This link expires in 1 hour. If you did not make this request, ignore this email.');

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

        if ($this->auditService !== null) {
            $this->auditService->log(
                toolName: 'password_reset_requested',
                project: null,
                input: ['ip' => $ip, 'email' => $email, 'user_found' => ($user !== null)],
                output: ['success' => true],
                status: 'success',
                createdBy: $email,
                source: 'forgot_password'
            );
            $this->auditService->flush();
        }

        $resp = $this->inertia($request, 'auth/forgot-password', [
            'status' => $this->trans('auth.password_reset.email_sent_status', [], 'If this address corresponds to an account, an email containing the reset link has been sent.'),
        ]);
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $resp->headers->set($k, $v);
        }
        return $resp;
    }
}
