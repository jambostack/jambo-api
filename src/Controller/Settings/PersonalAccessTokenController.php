<?php

namespace App\Controller\Settings;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\User;
use App\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des Personal Access Tokens depuis le dashboard (session utilisateur).
 *
 * Indispensable pour « bootstrapper » l'Admin API et le MCP : l'endpoint
 * /admin-api/tokens est lui-même protégé par un PAT (problème œuf-poule), donc
 * la création du tout premier jeton doit passer par une route en session.
 *
 * Les permissions (scopes) reconnues par l'Admin API sont `schema:write`
 * (collections & champs) et `projects:write` (création/édition de projets).
 */
#[Route('/api/settings/personal-access-tokens', name: 'api_settings_pat_')]
class PersonalAccessTokenController extends AbstractController
{
    /** Scopes proposés / acceptés côté UI. */
    private const ALLOWED_SCOPES = ['schema:write', 'projects:write'];

    public function __construct(
        private PersonalAccessTokenRepository $repo,
        private EntityManagerInterface $em,
        private string $appSecret,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->requireUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $tokens = $this->repo->findByUser($user);

        return $this->json(['data' => array_map($this->serialize(...), $tokens)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $data = $request->toArray();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['errors' => ['name' => 'Name is required.']], 422);
        }

        $scopes = array_values(array_intersect(self::ALLOWED_SCOPES, (array) ($data['scopes'] ?? [])));
        if ($scopes === []) {
            $scopes = ['schema:write'];
        }

        $expiresAt = null;
        if (!empty($data['expires_at'])) {
            try {
                $expiresAt = new \DateTimeImmutable((string) $data['expires_at']);
            } catch (\Exception) {
                return $this->json(['errors' => ['expires_at' => 'Invalid date.']], 422);
            }
        }

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $token = new PersonalAccessToken();
        $token->name = $name;
        $token->user = $user;
        $token->scopes = $scopes;
        $token->tokenHash = ApiToken::hashToken($plain, $this->appSecret);
        $token->tokenVersion = 2;
        $token->expiresAt = $expiresAt;

        $this->em->persist($token);
        $this->em->flush();

        // Le jeton en clair n'est renvoyé qu'à la création — jamais stocké en clair.
        return $this->json(['data' => [...$this->serialize($token), 'token' => $plain]], 201);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->requireUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $token = $this->repo->find($id);
        if (!$token || $token->user !== $user) {
            return $this->json(['error' => 'Token not found.'], 404);
        }

        $this->em->remove($token);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    private function requireUser(): User|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthenticated.'], 401);
        }
        return $user;
    }

    /** @return array<string, mixed> */
    private function serialize(PersonalAccessToken $t): array
    {
        return [
            'id'         => $t->id,
            'name'       => $t->name,
            'scopes'     => $t->scopes,
            'lastUsedAt' => $t->lastUsedAt?->format(DATE_ATOM),
            'expiresAt'  => $t->expiresAt?->format(DATE_ATOM),
            'createdAt'  => $t->createdAt->format(DATE_ATOM),
        ];
    }
}
