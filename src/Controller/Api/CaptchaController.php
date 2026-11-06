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
use Symfony\Contracts\Translation\TranslatorInterface;

class CaptchaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        #[Autowire(service: 'limiter.captcha')]
        private readonly RateLimiterFactory $captchaLimiter,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    #[Route('/api/{projectUuid}/captcha', name: 'api_captcha', methods: ['GET'],
        requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Route('/api/projects/{projectUuid}/captcha', name: 'api_captcha_alt', methods: ['GET'],
        requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function captcha(string $projectUuid, Request $request): JsonResponse
    {
        // Vérifier que le projet existe
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse([
                'error' => $this->trans('captcha.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        // Rate limiting
        $identifier = $request->getClientIp() ?: '127.0.0.1';
        $limiter = $this->captchaLimiter->create($identifier);
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
            return new JsonResponse([
                'error' => $this->trans('captcha.rate_limit_exceeded', [], 'Too many requests. Please retry later.'),
                'code'  => 'RATE_LIMIT_EXCEEDED',
            ], 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit->getLimit(),
                'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
            ]);
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
