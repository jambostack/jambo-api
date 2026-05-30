<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Service\ProjectMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

class ProjectEmailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectMailerService $mailerService,
        private readonly CacheInterface $cache,
        #[Autowire(service: 'limiter.project_email')]
        private readonly RateLimiterFactory $emailLimiter,
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
}
