<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/settings', name: 'api_project_settings_')]
class ProjectSettingsController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ApiTokenRepository $apiTokenRepository,
        private ProjectMemberRepository $memberRepo,
        private EntityManagerInterface $em,
    ) {}

    // ─── Localization ──────────────────────────────────────────────────────

    #[Route('/localization', name: 'localization_update', methods: ['PATCH'])]
    public function updateLocalization(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->toArray();

        if (isset($data['locales'])) {
            $newLocales = (array) $data['locales'];
            // Always keep the current defaultLocale in the list to avoid inconsistent state.
            if (!in_array($project->defaultLocale, $newLocales, true)) {
                $newLocales[] = $project->defaultLocale;
            }
            $project->locales = array_values($newLocales);
        }

        if (isset($data['default_locale'])) {
            if (!in_array($data['default_locale'], $project->locales, true)) {
                return $this->json(['error' => 'default_locale must be present in the locales list.'], 422);
            }
            $project->defaultLocale = $data['default_locale'];
        }

        $this->em->flush();

        return $this->json(['defaultLocale' => $project->defaultLocale, 'locales' => $project->locales]);
    }

    #[Route('/locales', name: 'locale_add', methods: ['POST'])]
    public function addLocale(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $locale = $request->toArray()['locale'] ?? null;
        if (!$locale) {
            return $this->json(['error' => 'locale is required'], 422);
        }

        if (!in_array($locale, $project->locales, true)) {
            $project->locales = [...$project->locales, $locale];
            $this->em->flush();
        }

        return $this->json($this->serializeLocales($project));
    }

    #[Route('/locale', name: 'locale_set_default', methods: ['PUT'])]
    public function setDefaultLocale(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $locale = $request->toArray()['locale'] ?? null;
        if (!$locale) {
            return $this->json(['error' => 'locale is required'], 422);
        }

        if (!in_array($locale, $project->locales, true)) {
            return $this->json(['error' => 'Locale not in project locales list'], 422);
        }

        $project->defaultLocale = $locale;
        $this->em->flush();

        return $this->json($this->serializeLocales($project));
    }

    #[Route('/locales/{locale}', name: 'locale_delete', methods: ['DELETE'])]
    public function deleteLocale(string $projectUuid, string $locale): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($locale === $project->defaultLocale) {
            return $this->json(['error' => 'Cannot delete the default locale'], 422);
        }

        $project->locales = array_values(array_filter($project->locales, fn ($l) => $l !== $locale));
        $this->em->flush();

        return $this->json($this->serializeLocales($project));
    }

    private function serializeLocales(\App\Entity\Project $project): array
    {
        return [
            'default_locale' => $project->defaultLocale,
            'locales'        => $project->locales,
        ];
    }

    // ─── User Access ───────────────────────────────────────────────────────

    #[Route('/members', name: 'members_index', methods: ['GET'])]
    public function listMembers(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $members = $this->memberRepo->findByProject($project);

        return $this->json([
            'data' => array_map(fn ($m) => [
                'id'        => $m->id,
                'user'      => $m->user ? [
                    'id'    => $m->user->id,
                    'name'  => $m->user->name,
                    'email' => $m->user->email,
                ] : null,
                'role'      => $m->role ? [
                    'id'    => $m->role->id,
                    'name'  => $m->role->name,
                    'label' => $m->role->label,
                ] : null,
                'email'     => $m->email,
                'status'    => $m->status,
                'joined_at' => $m->joinedAt?->format(\DateTimeInterface::ATOM),
                'created_at' => $m->createdAt->format(\DateTimeInterface::ATOM),
            ], $members),
        ]);
    }

    // ─── API Tokens ────────────────────────────────────────────────────────

    #[Route('/tokens', name: 'tokens_index', methods: ['GET'])]
    public function listTokens(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $tokens = $this->apiTokenRepository->findByProject($project);

        return $this->json([
            'data' => array_map(fn ($t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'abilities'  => $t->abilities,
                'lastUsedAt' => $t->lastUsedAt?->format(\DateTimeInterface::ATOM),
                'expiresAt'  => $t->expiresAt?->format(\DateTimeInterface::ATOM),
                'createdAt'  => $t->createdAt->format(\DateTimeInterface::ATOM),
            ], $tokens),
        ]);
    }

    #[Route('/tokens', name: 'tokens_create', methods: ['POST'])]
    public function createToken(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->toArray();

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], 422);
        }

        $plainToken = ApiToken::generatePlainToken();

        $token = new ApiToken();
        $token->project   = $project;
        $token->name      = $data['name'];
        $token->tokenHash = ApiToken::hashToken($plainToken);
        $token->abilities = $data['abilities'] ?? ['read'];
        if (!empty($data['expires_at'])) {
            $token->expiresAt = new \DateTimeImmutable($data['expires_at']);
        }

        $this->em->persist($token);
        $this->em->flush();

        return $this->json([
            'id'         => $token->id,
            'name'       => $token->name,
            'token'      => $plainToken, // Only shown once
            'abilities'  => $token->abilities,
            'createdAt'  => $token->createdAt->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/tokens/{id}', name: 'tokens_update', methods: ['PATCH'])]
    public function updateToken(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $token = $this->apiTokenRepository->findOneBy(['id' => $id, 'project' => $project]);
        if ($token === null) {
            return $this->json(['error' => 'Token not found'], 404);
        }

        $data = $request->toArray();
        if (isset($data['name'])) {
            $token->name = $data['name'];
        }
        if (isset($data['abilities'])) {
            $token->abilities = $data['abilities'];
        }
        $this->em->flush();

        return $this->json([
            'id'        => $token->id,
            'name'      => $token->name,
            'abilities' => $token->abilities,
        ]);
    }

    #[Route('/public-api', name: 'toggle_public_api', methods: ['POST'])]
    public function togglePublicApi(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $project->publicApi = !$project->publicApi;
        $this->em->flush();

        return $this->json(['public_api' => $project->publicApi]);
    }

    #[Route('/tokens/{id}', name: 'tokens_delete', methods: ['DELETE'])]
    public function deleteToken(string $projectUuid, int $id): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $token = $this->apiTokenRepository->findOneBy(['id' => $id, 'project' => $project]);
        if ($token === null) {
            return $this->json(['error' => 'Token not found'], 404);
        }

        $this->em->remove($token);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function resolveProject(string $projectUuid): \App\Entity\Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $user = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) && !$project->hasMember($user)) {
            return $this->json(['error' => 'Access denied'], 403);
        }
        return $project;
    }
}
