<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Message\Attachment;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\ProjectMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

class ProjectEmailController extends AbstractController
{
    use ProjectAwareControllerTrait;

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
    ) {}

    #[Route('/api/{projectUuid}/email', name: 'api_project_email', methods: ['POST'],
        requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function send(string $projectUuid, Request $request): JsonResponse
    {
        // Resoudre le projet
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Projet introuvable'], 404);
        }

        // Rate limiting
        $limiter = $this->emailLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de requetes. Reessayez plus tard.'], 429);
        }

        // Parser le body
        try {
            $body = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'JSON invalide'], 400);
        }

        // Valider les champs requis
        $subject = trim((string) ($body['subject'] ?? ''));
        $emailBody = trim((string) ($body['body'] ?? ''));
        if ($subject === '' || $emailBody === '') {
            return new JsonResponse(['error' => 'subject et body sont requis'], 422);
        }

        // Valider le captcha
        $captchaToken = (string) ($body['captchaToken'] ?? '');
        $captchaAnswer = (string) ($body['captchaAnswer'] ?? '');

        if ($captchaToken === '' || $captchaAnswer === '') {
            return new JsonResponse(['error' => 'Captcha requis'], 422);
        }

        $cacheKey = 'captcha.' . $captchaToken;
        $expectedAnswer = $this->cache->get($cacheKey, fn () => null);

        if ($expectedAnswer === null) {
            return new JsonResponse(['error' => 'Captcha expire ou invalide'], 422);
        }

        // Comparaison case-insensitive
        if (strtolower($captchaAnswer) !== strtolower($expectedAnswer)) {
            // Supprimer l'entree cache apres echec (usage unique)
            $this->cache->delete($cacheKey);
            return new JsonResponse(['error' => 'Reponse captcha incorrecte'], 422);
        }

        // Supprimer l'entree cache (usage unique)
        $this->cache->delete($cacheKey);

        // Mode (A) : formulaire de contact — destinataire fixe
        $settings = $this->mailerService->getSettings($project);
        if ($settings === null || !$settings->enabled) {
            return new JsonResponse(['error' => 'Mailer non configure pour ce projet'], 503);
        }

        $to = $settings->fromEmail;
        $replyTo = isset($body['replyTo']) ? trim((string) $body['replyTo']) : null;
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'replyTo invalide'], 422);
        }

        // Honeypot : champ cache — si rempli, c'est un bot (ne pas envoyer mais retourner succes)
        if (!empty($body['website'] ?? '')) {
            return new JsonResponse(['sent' => true]);
        }

        try {
            $this->mailerService->send($project, $to, $subject, $emailBody, $replyTo);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 503);
        }

        return new JsonResponse(['sent' => true]);
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
        $limiter = $this->adminEmailLimiter->create('project_' . $project->id);
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de requêtes. Réessayez plus tard.'], 429);
        }

        // Parser le body
        try {
            $body = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'JSON invalide'], 400);
        }

        // Valider les champs requis
        $to = trim((string) ($body['to'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));
        $textBody = trim((string) ($body['body'] ?? ''));
        if ($to === '' || $subject === '' || $textBody === '') {
            return new JsonResponse(['error' => 'to, subject et body sont requis'], 422);
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'to invalide'], 422);
        }

        // Valider replyTo
        $replyTo = isset($body['replyTo']) ? trim((string) $body['replyTo']) : null;
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'replyTo invalide'], 422);
        }

        // Valider CC / BCC
        foreach (['cc', 'bcc'] as $field) {
            if (isset($body[$field]) && is_array($body[$field])) {
                foreach ($body[$field] as $addr) {
                    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        return new JsonResponse(['error' => $field . ' invalide: ' . $addr], 422);
                    }
                }
            }
        }

        // Construire les pièces jointes
        $attachments = [];
        if (isset($body['attachments']) && is_array($body['attachments'])) {
            foreach ($body['attachments'] as $a) {
                if (empty($a['content']) || empty($a['filename'])) {
                    return new JsonResponse(['error' => 'attachment content et filename requis'], 422);
                }
                $attachments[] = new Attachment(
                    content: base64_decode($a['content']),
                    filename: $a['filename'],
                    mimeType: $a['mimeType'] ?? 'application/octet-stream',
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
            return new JsonResponse(['error' => $e->getMessage()], 503);
        }

        return new JsonResponse(['sent' => true]);
    }
}
