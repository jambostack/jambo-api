<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Redirect;
use App\Repository\ProjectRepository;
use App\Repository\RedirectRepository;
use App\Service\Redirect\RedirectChainDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
class RedirectController extends AbstractController
{
    public function __construct(
        private readonly RedirectRepository $redirectRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly EntityManagerInterface $em,
        private readonly RedirectChainDetector $chainDetector,
    ) {}

    // ── Liste des redirections ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects', name: 'admin_redirects_list', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 25)));
        $search = $request->query->get('search');

        $all = $this->redirectRepo->findByProject($project);

        if ($search) {
            $all = array_filter($all, fn (Redirect $r) =>
                stripos($r->fromPath, $search) !== false || stripos($r->toPath, $search) !== false);
        }

        $total = count($all);
        $items = array_slice(array_values($all), ($page - 1) * $perPage, $perPage);

        $data = array_map(fn (Redirect $r) => $this->serializeRedirect($r), $items);

        return $this->json([
            'data' => $data,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    // ── Détection des chaînes ─────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/chains', name: 'admin_redirects_chains', methods: ['GET'])]
    public function chains(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        $all = $this->redirectRepo->findByProject($project);
        $chains = $this->chainDetector->detectChains($all);

        $formatted = array_map(fn (array $c) => [
            'shortcut' => $c['shortcut'],
            'length' => count($c['links']),
            'links' => array_map(fn (Redirect $r) => $this->serializeRedirect($r), $c['links']),
        ], $chains);

        return $this->json(['data' => $formatted]);
    }

    // ── Créer ─────────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects', name: 'admin_redirects_create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $fromPath = trim((string) ($data['fromPath'] ?? ''));
        $toPath = trim((string) ($data['toPath'] ?? ''));

        if ($fromPath === '' || $toPath === '') {
            return $this->json(['error' => 'Both fromPath and toPath are required.'], 422);
        }

        $lowerTo = strtolower($toPath);
        if (str_starts_with($lowerTo, 'javascript:') || str_starts_with($lowerTo, 'data:') || str_starts_with($lowerTo, 'vbscript:')) {
            return $this->json(['error' => 'Dangerous URI schemes (javascript:, data:, vbscript:) are not allowed.'], 422);
        }
        if (str_starts_with($toPath, '//')) {
            return $this->json(['error' => 'Protocol-relative URLs starting with // are not allowed.'], 422);
        }

        $isPattern = (bool) ($data['isPattern'] ?? false);
        if ($isPattern) {
            $testRegex = '#' . str_replace('#', '\#', $fromPath) . '#';
            if (@preg_match($testRegex, '') === false) {
                return $this->json(['error' => 'Invalid regular expression syntax for fromPath pattern.'], 422);
            }
        }

        // Normaliser les chemins relatifs avec un slash initial
        if (!str_starts_with($fromPath, 'http://') && !str_starts_with($fromPath, 'https://')) {
            $fromPath = '/' . ltrim($fromPath, '/');
        }
        if (!str_starts_with($toPath, 'http://') && !str_starts_with($toPath, 'https://')) {
            $toPath = '/' . ltrim($toPath, '/');
        }

        if ($fromPath === $toPath) {
            return $this->json(['error' => 'A redirect cannot point to itself.'], 422);
        }

        $httpCode = (int) ($data['httpCode'] ?? 301);
        if (!\in_array($httpCode, [301, 302, 307, 308], true)) {
            return $this->json(['error' => 'Invalid HTTP redirect code. Allowed codes: 301, 302, 307, 308.'], 422);
        }

        $redirect = new Redirect();
        $redirect->project = $project;
        $redirect->fromPath = $fromPath;
        $redirect->toPath = $toPath;
        $redirect->httpCode = $httpCode;
        $redirect->isPattern = $isPattern;
        $redirect->isEnabled = (bool) ($data['isEnabled'] ?? true);
        $redirect->createdBy = $this->getUser();

        // Détection de boucle
        $existing = $this->redirectRepo->findByProject($project);
        if ($this->chainDetector->detectLoopWithContext($redirect, $existing)) {
            return $this->json(['error' => 'A redirect loop was detected (fromPath directly or indirectly redirects to itself).'], 422);
        }

        $this->em->persist($redirect);
        $this->em->flush();

        return $this->json($this->serializeRedirect($redirect), 201);
    }

    // ── Lire ───────────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/{id}', name: 'admin_redirects_show', methods: ['GET'])]
    public function show(string $projectUuid, int $id): JsonResponse
    {
        $redirect = $this->redirectRepo->find($id);
        if (!$redirect || $redirect->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Redirect not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $redirect->project);

        return $this->json($this->serializeRedirect($redirect));
    }

    // ── Mettre à jour ─────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/{id}', name: 'admin_redirects_update', methods: ['PUT'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $redirect = $this->redirectRepo->find($id);
        if (!$redirect || $redirect->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Redirect not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $redirect->project);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $fromPath = array_key_exists('fromPath', $data) ? trim((string) $data['fromPath']) : $redirect->fromPath;
        $toPath = array_key_exists('toPath', $data) ? trim((string) $data['toPath']) : $redirect->toPath;

        if ($fromPath === '' || $toPath === '') {
            return $this->json(['error' => 'Both fromPath and toPath must not be empty.'], 422);
        }

        $lowerTo = strtolower($toPath);
        if (str_starts_with($lowerTo, 'javascript:') || str_starts_with($lowerTo, 'data:') || str_starts_with($lowerTo, 'vbscript:')) {
            return $this->json(['error' => 'Dangerous URI schemes (javascript:, data:, vbscript:) are not allowed.'], 422);
        }
        if (str_starts_with($toPath, '//')) {
            return $this->json(['error' => 'Protocol-relative URLs starting with // are not allowed.'], 422);
        }

        $isPattern = array_key_exists('isPattern', $data) ? (bool) $data['isPattern'] : $redirect->isPattern;
        if ($isPattern) {
            $testRegex = '#' . str_replace('#', '\#', $fromPath) . '#';
            if (@preg_match($testRegex, '') === false) {
                return $this->json(['error' => 'Invalid regular expression syntax for fromPath pattern.'], 422);
            }
        }

        if (!str_starts_with($fromPath, 'http://') && !str_starts_with($fromPath, 'https://')) {
            $fromPath = '/' . ltrim($fromPath, '/');
        }
        if (!str_starts_with($toPath, 'http://') && !str_starts_with($toPath, 'https://')) {
            $toPath = '/' . ltrim($toPath, '/');
        }

        if ($fromPath === $toPath) {
            return $this->json(['error' => 'A redirect cannot point to itself.'], 422);
        }

        if (array_key_exists('httpCode', $data)) {
            $code = (int) $data['httpCode'];
            if (!\in_array($code, [301, 302, 307, 308], true)) {
                return $this->json(['error' => 'Invalid HTTP redirect code. Allowed codes: 301, 302, 307, 308.'], 422);
            }
            $redirect->httpCode = $code;
        }

        $redirect->fromPath = $fromPath;
        $redirect->toPath = $toPath;
        $redirect->isPattern = $isPattern;

        if (array_key_exists('isEnabled', $data)) {
            $redirect->isEnabled = (bool) $data['isEnabled'];
        }

        // Détection de boucle en excluant la redirection en cours de modification
        $existing = array_filter(
            $this->redirectRepo->findByProject($redirect->project),
            fn (Redirect $r) => $r->id !== $redirect->id
        );
        if ($this->chainDetector->detectLoopWithContext($redirect, $existing)) {
            return $this->json(['error' => 'A redirect loop was detected (fromPath directly or indirectly redirects to itself).'], 422);
        }

        $redirect->updatedBy = $this->getUser();

        $this->em->flush();

        return $this->json($this->serializeRedirect($redirect));
    }

    // ── Supprimer ──────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/{id}', name: 'admin_redirects_delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, int $id): JsonResponse
    {
        $redirect = $this->redirectRepo->find($id);
        if (!$redirect || $redirect->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Redirect not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $redirect->project);

        $this->em->remove($redirect);
        $this->em->flush();

        return $this->json(null, 204);
    }

    // ── Toggle enabled ─────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/{id}/toggle', name: 'admin_redirects_toggle', methods: ['POST'])]
    public function toggle(string $projectUuid, int $id): JsonResponse
    {
        $redirect = $this->redirectRepo->find($id);
        if (!$redirect || $redirect->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Redirect not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $redirect->project);

        $redirect->isEnabled = !$redirect->isEnabled;
        $redirect->updatedBy = $this->getUser();
        $this->em->flush();

        return $this->json($this->serializeRedirect($redirect));
    }

    // ── Helper ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        try {
            return $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function serializeRedirect(Redirect $r): array
    {
        return [
            'id' => $r->id,
            'uuid' => $r->uuid?->toRfc4122(),
            'projectUuid' => $r->project?->uuid?->toRfc4122(),
            'fromPath' => $r->fromPath,
            'toPath' => $r->toPath,
            'httpCode' => $r->httpCode,
            'isPattern' => $r->isPattern,
            'isEnabled' => $r->isEnabled,
            'hits' => $r->hits,
            'lastHitAt' => $r->lastHitAt?->format('c'),
            'isAuto' => $r->isAuto,
            'sourceEntryUuid' => $r->sourceEntry?->uuid?->toRfc4122(),
            'createdBy' => $r->createdBy?->email,
            'updatedBy' => $r->updatedBy?->email,
            'createdAt' => $r->createdAt->format('c'),
            'updatedAt' => $r->updatedAt->format('c'),
        ];
    }
}
