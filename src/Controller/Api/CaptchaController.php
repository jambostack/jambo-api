<?php

namespace App\Controller\Api;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Gregwar\Captcha\CaptchaBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class CaptchaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        #[Autowire(service: 'limiter.captcha')]
        private readonly RateLimiterFactory $captchaLimiter,
    ) {}

    #[Route('/api/{projectUuid}/captcha', name: 'api_captcha', methods: ['GET'],
        requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function captcha(string $projectUuid, Request $request): JsonResponse
    {
        // Vérifier que le projet existe
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Projet introuvable'], 404);
        }

        // Rate limiting
        $limiter = $this->captchaLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de requêtes. Réessayez plus tard.'], 429);
        }

        // Générer le captcha
        $builder = new CaptchaBuilder();
        $builder->build();

        // Token unique
        $token = bin2hex(random_bytes(16));

        // Stocker la réponse dans le cache (TTL 5 min, usage unique)
        $phrase = $builder->getPhrase();
        $this->cache->get('captcha.' . $token, function (ItemInterface $item) use ($phrase) {
            $item->expiresAfter(300);
            return $phrase;
        });

        return new JsonResponse([
            'token' => $token,
            'image' => $builder->inline(),
        ]);
    }
}
