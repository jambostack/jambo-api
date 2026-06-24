<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\ProjectRepository;
use App\Service\Form\FormBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class FormController extends AbstractController
{
    public function __construct(
        private readonly FormRepository $formRepo,
        private readonly FormSubmissionRepository $submissionRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly FormBuilder $formBuilder,
        private readonly EntityManagerInterface $em,
    ) {}

    // ── Liste des formulaires ──────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms', name: 'admin_forms_list', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        $forms = $this->formRepo->findByProject($project);

        $data = array_map(fn (Form $f) => [
            'id' => $f->id,
            'name' => $f->name,
            'slug' => $f->slug,
            'fields_count' => count($f->fields),
            'has_steps' => $f->steps !== null,
            'submissions_count' => count($this->submissionRepo->findByForm($f)),
            'unread_count' => $this->submissionRepo->countUnread($f),
            'createdAt' => $f->createdAt->format('c'),
            'updatedAt' => $f->updatedAt->format('c'),
        ], $forms);

        return $this->json(['data' => $data]);
    }

    // ── Créer un formulaire ────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms', name: 'admin_forms_create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        $data = $request->toArray();

        // Valider la définition des champs
        $fields = $data['fields'] ?? [];
        $validation = $this->formBuilder->validateDefinition($fields);
        if (!$validation['valid']) {
            return $this->json(['error' => 'Invalid field definition.', 'field_errors' => $validation['errors']], 422);
        }

        $form = new Form();
        $form->project = $project;
        $form->name = $data['name'] ?? '';
        $form->slug = $data['slug'] ?? '';
        $form->fields = $fields;
        $form->steps = $data['steps'] ?? null;
        $form->settings = $data['settings'] ?? [];

        $this->em->persist($form);
        $this->em->flush();

        return $this->json($this->serializeForm($form), 201);
    }

    // ── Lire un formulaire ─────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_show', methods: ['GET'])]
    public function show(string $projectUuid, int $id): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Form not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        return $this->json($this->serializeForm($form));
    }

    // ── Mettre à jour un formulaire ────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_update', methods: ['PUT'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Form not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $data = $request->toArray();

        if (array_key_exists('name', $data)) {
            $form->name = $data['name'];
        }
        if (array_key_exists('slug', $data)) {
            $form->slug = $data['slug'];
        }
        if (array_key_exists('fields', $data)) {
            $validation = $this->formBuilder->validateDefinition($data['fields']);
            if (!$validation['valid']) {
                return $this->json(['error' => 'Invalid field definition.', 'field_errors' => $validation['errors']], 422);
            }
            $form->fields = $data['fields'];
        }
        if (array_key_exists('steps', $data)) {
            $form->steps = $data['steps'];
        }
        if (array_key_exists('settings', $data)) {
            $form->settings = $data['settings'];
        }

        $this->em->flush();

        return $this->json($this->serializeForm($form));
    }

    // ── Supprimer un formulaire ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, int $id): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Form not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $this->em->remove($form);
        $this->em->flush();

        return $this->json(null, 204);
    }

    // ── Soumissions ────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions', name: 'admin_forms_submissions', methods: ['GET'])]
    public function submissions(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Form not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 25)));
        $spamFilter = $request->query->get('spam'); // 'spam', 'ham', or omit for all

        $all = $this->submissionRepo->findByForm($form);

        if ($spamFilter === 'spam') {
            $all = array_filter($all, fn (FormSubmission $s) => $s->isSpam);
        } elseif ($spamFilter === 'ham') {
            $all = array_filter($all, fn (FormSubmission $s) => !$s->isSpam);
        }

        $total = count($all);
        $items = array_slice(array_values($all), ($page - 1) * $perPage, $perPage);

        $data = array_map(fn (FormSubmission $s) => [
            'id' => $s->id,
            'data' => $s->data,
            'metadata' => $s->metadata,
            'isComplete' => $s->isComplete,
            'isSpam' => $s->isSpam,
            'isRead' => $s->isRead,
            'createdAt' => $s->createdAt->format('c'),
        ], $items);

        return $this->json([
            'data' => $data,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    // ── Marquer comme lu / spam ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}', name: 'admin_forms_submission_update', methods: ['PATCH'])]
    public function updateSubmission(string $projectUuid, int $id, int $submissionId, Request $request): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Form not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->json(['error' => 'Submission not found.'], 404);
        }

        $data = $request->toArray();

        if (array_key_exists('isRead', $data)) {
            $submission->isRead = (bool) $data['isRead'];
        }
        if (array_key_exists('isSpam', $data)) {
            $submission->isSpam = (bool) $data['isSpam'];
        }

        $this->em->flush();

        return $this->json([
            'id' => $submission->id,
            'isRead' => $submission->isRead,
            'isSpam' => $submission->isSpam,
        ]);
    }

    // ── Helper ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function serializeForm(Form $f): array
    {
        $definition = $this->formBuilder->validateDefinition($f->fields);

        return [
            'id' => $f->id,
            'projectUuid' => $f->project?->uuid?->toRfc4122(),
            'name' => $f->name,
            'slug' => $f->slug,
            'fields' => $f->fields,
            'steps' => $f->steps,
            'settings' => $f->settings,
            'definition_valid' => $definition['valid'],
            'definition_errors' => $definition['errors'],
            'submissions_count' => count($this->submissionRepo->findByForm($f)),
            'unread_count' => $this->submissionRepo->countUnread($f),
            'createdAt' => $f->createdAt->format('c'),
            'updatedAt' => $f->updatedAt->format('c'),
        ];
    }
}
