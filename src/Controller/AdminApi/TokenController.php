<?php

namespace App\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin-api/tokens', name: 'admin_api_token_')]
class TokenController extends AbstractController
{
    use AdminApiControllerTrait;

    public function __construct(
        private PersonalAccessTokenRepository $repo,
        private EntityManagerInterface $em,
        private string $appSecret,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $tokens = $this->repo->findByUser($this->getUser());
        return $this->json(['data' => array_map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'scopes' => $t->scopes,
            'lastUsedAt' => $t->lastUsedAt?->format(DATE_ATOM),
            'createdAt' => $t->createdAt->format(DATE_ATOM),
        ], $tokens)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $t = new PersonalAccessToken();
        $t->name = $data['name'] ?? 'token';
        $t->user = $this->getUser();
        if (isset($data['scopes'])) {
            if (!is_array($data['scopes'])) {
                return $this->json(['error' => 'Scopes must be an array.'], 422);
            }
            $t->scopes = $data['scopes'];
        } else {
            $t->scopes = ['schema:write'];
        }
        $t->tokenHash = ApiToken::hashToken($plain, $this->appSecret);
        $t->tokenVersion = 2;
        if (!empty($data['expires_at'])) {
            try {
                $expiresAt = new \DateTimeImmutable($data['expires_at']);
            } catch (\Exception) {
                return $this->json(['error' => 'Invalid expires_at date format.'], 422);
            }
            if ($expiresAt <= new \DateTimeImmutable()) {
                return $this->json(['error' => 'Expiration date must be in the future.'], 422);
            }
            $t->expiresAt = $expiresAt;
        }
        $this->em->persist($t);
        $this->em->flush();

        return $this->json(['data' => [
            'id' => $t->id,
            'name' => $t->name,
            'scopes' => $t->scopes,
            'token' => $plain, // affiché UNE SEULE FOIS
        ]], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $t = $this->repo->find($id);
        if (!$t || $t->user !== $this->getUser()) {
            return $this->json(['error' => 'Token not found'], 404);
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        if (isset($data['name'])) {
            $t->name = $data['name'];
        }
        if (isset($data['scopes'])) {
            if (!is_array($data['scopes'])) {
                return $this->json(['error' => 'Scopes must be an array.'], 422);
            }
            $t->scopes = $data['scopes'];
        }
        $this->em->flush();
        return $this->json(['data' => ['id' => $t->id, 'name' => $t->name, 'scopes' => $t->scopes]]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $t = $this->repo->find($id);
        if (!$t || $t->user !== $this->getUser()) {
            return $this->json(['error' => 'Token not found'], 404);
        }
        $this->em->remove($t);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }
}
