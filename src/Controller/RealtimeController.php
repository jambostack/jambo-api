<?php

namespace App\Controller;

use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint temps réel.
 *
 * - /realtime?since=N        → short-polling (fallback, lecture JSONL)
 * - /realtime/token          → génère un JWT de souscription Mercure (SSE primaire)
 */
#[Route('/api/projects/{projectUuid}/realtime', name: 'api_realtime_')]
class RealtimeController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectMemberRepository $memberRepo,
    ) {}

    /**
     * Génère un token JWT pour souscrire au hub Mercure.
     */
    #[Route('/token', name: 'token', methods: ['GET'])]
    public function token(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyProjectAccess($project);

        $mercureSecret = $this->getParameter('mercure_jwt_secret') ?? '';
        if ($mercureSecret === '') {
            return $this->json(['error' => 'Mercure not configured'], 503);
        }

        $jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($mercureSecret),
        );

        $now = new \DateTimeImmutable();
        $token = $jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 hour'))
            ->withClaim('mercure', [
                'subscribe' => [
                    "projects/{$projectUuid}",
                    "projects/{$projectUuid}/content",
                    "projects/{$projectUuid}/media",
                    "projects/{$projectUuid}/status",
                ],
                'publish' => [],
            ])
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey())
            ->toString();

        $hubUrl = $this->getParameter('mercure_public_url')
            ?? $request->getSchemeAndHttpHost() . '/.well-known/mercure';

        return $this->json([
            'token'   => $token,
            'hub_url' => $hubUrl,
            'topics'  => [
                "projects/{$projectUuid}",
                "projects/{$projectUuid}/content",
                "projects/{$projectUuid}/media",
                "projects/{$projectUuid}/status",
            ],
        ]);
    }

    #[Route('', name: 'poll', methods: ['GET'])]
    public function poll(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyProjectAccess($project);

        $since = max(0, (int) $request->query->get('since', '0'));

        $eventFile = $this->getParameter('kernel.project_dir') . '/var/realtime/' . $projectUuid . '.jsonl';

        $events = [];
        $lastId = $since;

        if (file_exists($eventFile)) {
            $lines = file($eventFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false && count($lines) > $since) {
                for ($i = $since; $i < count($lines); $i++) {
                    $data = json_decode($lines[$i], true);
                    if ($data !== null) {
                        $data['_id'] = $i;
                        $events[] = $data;
                        $lastId = $i + 1;
                    }
                }
            }

            // Nettoyage périodique (garder max 500 lignes)
            if ($lastId > 500 && count($lines) > 500) {
                $this->trimEventFile($eventFile, $lines);
            }
        }

        return $this->json([
            'events' => $events,
            'since'  => $lastId,
            'time'   => time(),
        ]);
    }

    // ─── Private ─────────────────────────────────────────────────────────

    private function denyProjectAccess(\App\Entity\Project $project): void
    {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException('Authentication required');
        }
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return;
        }
        if ($this->memberRepo->findActiveByUserAndProject($user, $project) === null) {
            throw $this->createAccessDeniedException();
        }
    }

    private function trimEventFile(string $path, array $lines): void
    {
        $keep = array_slice($lines, -200);
        file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
    }
}
