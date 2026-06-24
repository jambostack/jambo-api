<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Redirect;
use App\Repository\ProjectRepository;
use App\Repository\RedirectRepository;
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
    ) {}

    // ── Liste des redirections ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects', name: 'admin_redirects_list', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

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

    // ── Créer ─────────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects', name: 'admin_redirects_create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $data = $request->toArray();

        $redirect = new Redirect();
        $redirect->project = $project;
        $redirect->fromPath = $data['fromPath'] ?? '';
        $redirect->toPath = $data['toPath'] ?? '';
        $redirect->httpCode = (int) ($data['httpCode'] ?? 301);
        $redirect->isPattern = (bool) ($data['isPattern'] ?? false);
        $redirect->isEnabled = (bool) ($data['isEnabled'] ?? true);
        $redirect->createdBy = $this->getUser();

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

        $data = $request->toArray();

        if (array_key_exists('fromPath', $data)) {
            $redirect->fromPath = $data['fromPath'];
        }
        if (array_key_exists('toPath', $data)) {
            $redirect->toPath = $data['toPath'];
        }
        if (array_key_exists('httpCode', $data)) {
            $redirect->httpCode = (int) $data['httpCode'];
        }
        if (array_key_exists('isPattern', $data)) {
            $redirect->isPattern = (bool) $data['isPattern'];
        }
        if (array_key_exists('isEnabled', $data)) {
            $redirect->isEnabled = (bool) $data['isEnabled'];
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

        $redirect->isEnabled = !$redirect->isEnabled;
        $redirect->updatedBy = $this->getUser();
        $this->em->flush();

        return $this->json($this->serializeRedirect($redirect));
    }

    // ── Helper ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
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
