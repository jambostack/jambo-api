<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Message\Attachment;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\ProjectMailerService;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProjectEmailController extends AbstractController
{
    use ProjectAwareControllerTrait;

    public const MAX_PUBLIC_PAYLOAD_BYTES = 131072; // 128 KB
    public const MAX_ADMIN_PAYLOAD_BYTES = 16777216; // 16 MB
    public const MAX_SUBJECT_LENGTH = 500;
    public const MAX_PUBLIC_BODY_LENGTH = 65536; // 64 KB
    public const MAX_ADMIN_BODY_LENGTH = 1048576; // 1 MB
    public const MAX_RECIPIENTS_COUNT = 20; // per cc / bcc
    public const MAX_ATTACHMENTS_COUNT = 10;
    public const MAX_FILENAME_LENGTH = 255;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectMailerService $mailerService,
        private readonly CacheInterface $cache,
        private readonly ProjectRepository $projectRepository,
        private readonly ApiTokenChecker $tokenChecker,
        private readonly Security $security,
        private readonly ProjectMemberRepository $memberRepo,
        #[Autowire(service: 'limiter.project_email')]
        private readonly RateLimiterFactory $emailLimiter,
        #[Autowire(service: 'limiter.project_email_admin')]
        private readonly RateLimiterFactory $adminEmailLimiter,
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

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('/api/{projectUuid}/email', name: 'api_project_email', methods: ['POST'],
        requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Route('/api/projects/{projectUuid}/email', name: 'api_project_email_alt', methods: ['POST'],
        requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function send(string $projectUuid, Request $request): JsonResponse
    {
        // Resoudre le projet
        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Projet introuvable')], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Projet introuvable')], 404);
        }

        // Rate limiting
        $identifier = $request->getClientIp() ?: '127.0.0.1';
        $limit = $this->emailLimiter->create($identifier)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
            return $this->secureJson([
                'error' => $this->trans('email.rate_limit_exceeded', [], 'Trop de requêtes. Réessayez plus tard.'),
                'code'  => 'RATE_LIMIT_EXCEEDED',
            ], 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit->getLimit(),
                'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
            ]);
        }

        if (strlen($request->getContent()) > self::MAX_PUBLIC_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('email.payload_too_large', [], 'Email payload too large (max 128KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        // Parser le body
        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('email.invalid_json', [], 'JSON invalide')], 400);
        }

        // Valider les champs requis
        $subject = trim((string) ($body['subject'] ?? ''));
        $emailBody = trim((string) ($body['body'] ?? ''));
        if ($subject === '' || $emailBody === '') {
            return $this->secureJson(['error' => $this->trans('email.subject_and_body_required', [], 'subject et body sont requis')], 422);
        }

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            return $this->secureJson([
                'error' => $this->trans('email.subject_too_long', ['%max%' => self::MAX_SUBJECT_LENGTH], sprintf('Subject exceeds maximum length (%d characters).', self::MAX_SUBJECT_LENGTH)),
                'code'  => 'SUBJECT_TOO_LONG',
            ], 422);
        }

        if (mb_strlen($emailBody) > self::MAX_PUBLIC_BODY_LENGTH) {
            return $this->secureJson([
                'error' => $this->trans('email.body_too_long', ['%max%' => self::MAX_PUBLIC_BODY_LENGTH], sprintf('Body exceeds maximum length (%d characters).', self::MAX_PUBLIC_BODY_LENGTH)),
                'code'  => 'BODY_TOO_LONG',
            ], 422);
        }

        // Valider le captcha
        $captchaToken = (string) ($body['captchaToken'] ?? '');
        $captchaAnswer = (string) ($body['captchaAnswer'] ?? '');

        if ($captchaToken === '' || $captchaAnswer === '') {
            return $this->secureJson(['error' => $this->trans('email.captcha_required', [], 'Captcha requis')], 422);
        }

        $cacheKey = 'captcha.' . $captchaToken;
        $expectedAnswer = $this->cache->get($cacheKey, fn () => null);

        if ($expectedAnswer === null) {
            return $this->secureJson(['error' => $this->trans('email.captcha_expired', [], 'Captcha expire ou invalide')], 422);
        }

        // Comparaison case-insensitive
        if (strtolower($captchaAnswer) !== strtolower($expectedAnswer)) {
            // Supprimer l'entree cache apres echec (usage unique)
            $this->cache->delete($cacheKey);
            return $this->secureJson(['error' => $this->trans('email.captcha_incorrect', [], 'Reponse captcha incorrecte')], 422);
        }

        // Supprimer l'entree cache (usage unique)
        $this->cache->delete($cacheKey);

        // Mode (A) : formulaire de contact — destinataire fixe
        $settings = $this->mailerService->getSettings($project);
        if ($settings === null || !$settings->enabled) {
            return $this->secureJson(['error' => $this->trans('email.mailer_not_configured', [], 'Mailer non configure pour ce projet')], 503);
        }

        $to = $settings->fromEmail;
        $replyTo = isset($body['replyTo']) ? trim((string) $body['replyTo']) : null;
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return $this->secureJson(['error' => $this->trans('email.invalid_reply_to', [], 'replyTo invalide')], 422);
        }

        // Honeypot : champ cache — si rempli, c'est un bot (ne pas envoyer mais retourner succes)
        if (!empty($body['website'] ?? '')) {
            return $this->secureJson(['sent' => true]);
        }

        try {
            $this->mailerService->send($project, $to, $subject, $emailBody, $replyTo);
        } catch (\RuntimeException $e) {
            $this->auditService?->log(
                toolName: 'public_email_send',
                project: $project,
                input: ['to' => $to, 'subject' => mb_substr($subject, 0, 100)],
                output: null,
                status: 'failure',
                errorMessage: 'Email delivery failed',
                source: 'public_email',
            );
            return $this->secureJson(['error' => $this->trans('email.send_failed', [], 'Email sending failed. Please check mailer settings.'), 'code' => 'EMAIL_SEND_FAILED'], 503);
        }

        return $this->secureJson(['sent' => true]);
    }

    #[Route('/api/admin/projects/{uuid}/email', name: 'api_admin_project_email', methods: ['POST'],
        requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function sendAsAdmin(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        // Rate limiting (basé sur le projet)
        $limit = $this->adminEmailLimiter->create('project_' . $project->id)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
            return $this->secureJson([
                'error' => $this->trans('email.rate_limit_exceeded', [], 'Trop de requêtes. Réessayez plus tard.'),
                'code'  => 'RATE_LIMIT_EXCEEDED',
            ], 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit->getLimit(),
                'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
            ]);
        }

        if (strlen($request->getContent()) > self::MAX_ADMIN_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('email.payload_too_large', [], 'Email payload exceeds limit (max 16MB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        // Parser le body
        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('email.invalid_json', [], 'JSON invalide')], 400);
        }

        // Valider les champs requis
        $to = trim((string) ($body['to'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));
        $textBody = trim((string) ($body['body'] ?? ''));
        if ($to === '' || $subject === '' || $textBody === '') {
            return $this->secureJson(['error' => $this->trans('email.to_subject_body_required', [], 'to, subject et body sont requis')], 422);
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->secureJson(['error' => $this->trans('email.invalid_to', [], 'to invalide')], 422);
        }

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            return $this->secureJson([
                'error' => $this->trans('email.subject_too_long', ['%max%' => self::MAX_SUBJECT_LENGTH], sprintf('Subject exceeds maximum length (%d characters).', self::MAX_SUBJECT_LENGTH)),
                'code'  => 'SUBJECT_TOO_LONG',
            ], 422);
        }

        if (mb_strlen($textBody) > self::MAX_ADMIN_BODY_LENGTH) {
            return $this->secureJson([
                'error' => $this->trans('email.body_too_long', ['%max%' => self::MAX_ADMIN_BODY_LENGTH], sprintf('Body exceeds maximum length (%d characters).', self::MAX_ADMIN_BODY_LENGTH)),
                'code'  => 'BODY_TOO_LONG',
            ], 422);
        }

        // Valider replyTo
        $replyTo = isset($body['replyTo']) ? trim((string) $body['replyTo']) : null;
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return $this->secureJson(['error' => $this->trans('email.invalid_reply_to', [], 'replyTo invalide')], 422);
        }

        // Valider CC / BCC
        foreach (['cc', 'bcc'] as $field) {
            if (isset($body[$field]) && is_array($body[$field])) {
                if (count($body[$field]) > self::MAX_RECIPIENTS_COUNT) {
                    return $this->secureJson([
                        'error' => $this->trans('email.too_many_recipients', ['%field%' => $field, '%max%' => self::MAX_RECIPIENTS_COUNT], sprintf('Too many recipients in %s (max %d).', $field, self::MAX_RECIPIENTS_COUNT)),
                        'code'  => 'TOO_MANY_RECIPIENTS',
                    ], 422);
                }
                foreach ($body[$field] as $addr) {
                    if (!is_string($addr) || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        return $this->secureJson(['error' => $this->trans('email.invalid_recipient', ['%field%' => $field, '%email%' => (string) $addr], $field . ' invalide: ' . (string) $addr)], 422);
                    }
                }
            }
        }

        // Construire les pièces jointes
        $attachments = [];
        if (isset($body['attachments']) && is_array($body['attachments'])) {
            if (count($body['attachments']) > self::MAX_ATTACHMENTS_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('email.too_many_attachments', ['%max%' => self::MAX_ATTACHMENTS_COUNT], sprintf('Too many attachments (max %d).', self::MAX_ATTACHMENTS_COUNT)),
                    'code'  => 'TOO_MANY_ATTACHMENTS',
                ], 422);
            }
            foreach ($body['attachments'] as $a) {
                if (!is_array($a) || empty($a['content']) || empty($a['filename'])) {
                    return $this->secureJson(['error' => $this->trans('email.attachment_missing_fields', [], 'attachment content et filename requis')], 422);
                }
                if (strlen((string) $a['content']) > 15 * 1024 * 1024) {
                    return $this->secureJson(['error' => $this->trans('email.attachment_too_large', [], 'Pièce jointe trop volumineuse (max 10MB)')], 422);
                }
                $rawFilename = (string) $a['filename'];
                if (mb_strlen($rawFilename) > self::MAX_FILENAME_LENGTH) {
                    return $this->secureJson(['error' => $this->trans('email.attachment_filename_too_long', [], 'Attachment filename is too long.')], 422);
                }
                $safeFilename = basename(str_replace(["\0", "\r", "\n"], '', $rawFilename));
                $ext = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
                if (in_array($ext, UploadSecurityValidator::FORBIDDEN_EXTENSIONS, true)) {
                    return $this->secureJson(['error' => $this->trans('email.attachment_forbidden_extension', ['%ext%' => $ext], sprintf('L\'extension de pièce jointe .%s est strictement interdite.', $ext))], 422);
                }
                $decoded = base64_decode((string) $a['content'], true);
                if ($decoded === false) {
                    return $this->secureJson(['error' => $this->trans('email.invalid_attachment_content', [], 'Invalid base64 attachment content.'), 'code' => 'INVALID_ATTACHMENT'], 422);
                }
                $attachments[] = new Attachment(
                    content: $decoded,
                    filename: $safeFilename,
                    mimeType: (string) ($a['mimeType'] ?? 'application/octet-stream'),
                );
            }
        }

        // Envoyer
        try {
            $this->mailerService->send(
                $project,
                $to,
                $subject,
                $textBody,
                htmlBody: $body['htmlBody'] ?? null,
                replyTo: $replyTo,
                cc: $body['cc'] ?? [],
                bcc: $body['bcc'] ?? [],
                attachments: $attachments,
            );
        } catch (\RuntimeException $e) {
            $this->auditService?->log(
                toolName: 'admin_email_send',
                project: $project,
                input: ['to' => $to, 'subject' => mb_substr($subject, 0, 100), 'attachments_count' => count($attachments)],
                output: null,
                status: 'failure',
                errorMessage: 'Email delivery failed',
                source: 'admin_email',
            );
            return $this->secureJson(['error' => $this->trans('email.send_failed', [], 'Email sending failed. Please check mailer settings.'), 'code' => 'EMAIL_SEND_FAILED'], 503);
        }

        $this->auditService?->log(
            toolName: 'admin_email_send',
            project: $project,
            input: ['to' => $to, 'subject' => mb_substr($subject, 0, 100), 'attachments_count' => count($attachments)],
            output: ['sent' => true],
            status: 'success',
            source: 'admin_email',
        );

        return $this->secureJson(['sent' => true]);
    }
}
