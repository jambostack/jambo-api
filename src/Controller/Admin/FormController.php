<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\ProjectRepository;
use App\Service\Form\FormBuilder;
use App\Service\Security\CsvSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private readonly ?CsvSanitizer $csvSanitizer = null,
    ) {}

    // ── Liste des formulaires ──────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms', name: 'admin_forms_list', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
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
            'submissions_count' => $this->submissionRepo->countByForm($f),
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

        // Valider la définition des champs
        $fields = $data['fields'] ?? [];
        if (!is_array($fields) || count($fields) > 100) {
            return $this->json(['error' => 'Fields must be an array of at most 100 fields.'], 422);
        }
        $validation = $this->formBuilder->validateDefinition($fields);
        if (empty($validation['valid'])) {
            return $this->json(['error' => 'Invalid field definition.', 'field_errors' => $validation['errors'] ?? []], 422);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => 'Form name is required.'], 422);
        }
        if (mb_strlen($name) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $name)) {
            return $this->json(['error' => 'Form name must not exceed 255 characters and cannot contain control characters.'], 422);
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
            if ($slug === '') {
                $slug = 'form-' . bin2hex(random_bytes(3));
            }
        }
        if (strlen($slug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return $this->json(['error' => 'Form slug must be lowercase alphanumeric with hyphens (max 128 characters).'], 422);
        }

        $steps = $data['steps'] ?? null;
        if ($steps !== null) {
            if (!is_array($steps) || count($steps) > 50) {
                return $this->json(['error' => 'Form steps must be an array of at most 50 steps.'], 422);
            }
        }

        $settings = $data['settings'] ?? [];
        if (!is_array($settings) || strlen(json_encode($settings)) > 65536) {
            return $this->json(['error' => 'Form settings must be an array and not exceed 64KB.'], 422);
        }

        $existing = $this->formRepo->findOneBy(['project' => $project, 'slug' => $slug]);
        if ($existing !== null) {
            $slug .= '-' . bin2hex(random_bytes(2));
        }

        $form = new Form();
        $form->project = $project;
        $form->name = $name;
        $form->slug = $slug;
        $form->fields = $fields;
        $form->steps = $steps;
        $form->settings = $settings;

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
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        if (array_key_exists('name', $data)) {
            $newName = trim((string) $data['name']);
            if ($newName === '') {
                return $this->json(['error' => 'Form name cannot be empty.'], 422);
            }
            if (mb_strlen($newName) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $newName)) {
                return $this->json(['error' => 'Form name must not exceed 255 characters and cannot contain control characters.'], 422);
            }
            $form->name = $newName;
        }
        if (array_key_exists('slug', $data)) {
            $newSlug = trim((string) $data['slug']);
            if ($newSlug === '') {
                return $this->json(['error' => 'Form slug cannot be empty.'], 422);
            }
            if (strlen($newSlug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $newSlug)) {
                return $this->json(['error' => 'Form slug must be lowercase alphanumeric with hyphens (max 128 characters).'], 422);
            }
            $existing = $this->formRepo->findOneBy(['project' => $form->project, 'slug' => $newSlug]);
            if ($existing !== null && $existing->id !== $form->id) {
                return $this->json(['error' => 'A form with this slug already exists in this project.'], 422);
            }
            $form->slug = $newSlug;
        }
        if (array_key_exists('fields', $data)) {
            if (!is_array($data['fields']) || count($data['fields']) > 100) {
                return $this->json(['error' => 'Fields must be an array of at most 100 fields.'], 422);
            }
            $validation = $this->formBuilder->validateDefinition($data['fields']);
            if (empty($validation['valid'])) {
                return $this->json(['error' => 'Invalid field definition.', 'field_errors' => $validation['errors'] ?? []], 422);
            }
            $form->fields = $data['fields'];
        }
        if (array_key_exists('steps', $data)) {
            if ($data['steps'] !== null && (!is_array($data['steps']) || count($data['steps']) > 50)) {
                return $this->json(['error' => 'Form steps must be an array of at most 50 steps.'], 422);
            }
            $form->steps = $data['steps'];
        }
        if (array_key_exists('settings', $data)) {
            if (!is_array($data['settings']) || strlen(json_encode($data['settings'])) > 65536) {
                return $this->json(['error' => 'Form settings must be an array and not exceed 64KB.'], 422);
            }
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
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        // Supprimer d'abord les soumissions en une requête directe (évite OOM)
        $this->submissionRepo->deleteByForm($form);

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

        $total = $this->submissionRepo->countByForm($form, $spamFilter);
        $items = $this->submissionRepo->findPaginatedByForm($form, $page, $perPage, $spamFilter);

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

    // ── Exporter les soumissions en CSV (protégé contre CSV Formula Injection) ───

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/export-csv', name: 'admin_forms_submissions_export_csv', methods: ['GET'])]
    public function exportSubmissionsCsv(string $projectUuid, int $id, Request $request): Response
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Form not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $spamFilter = $request->query->get('spam'); // 'spam', 'ham', or omit for all

        // Extraire la liste ordonnée des noms / labels de champs
        $fieldKeys = [];
        $fieldLabels = [];
        if (is_array($form->fields)) {
            foreach ($form->fields as $fieldDef) {
                if (!is_array($fieldDef)) {
                    continue;
                }
                $key = $fieldDef['name'] ?? $fieldDef['id'] ?? null;
                if ($key !== null && $key !== '') {
                    $fieldKeys[] = (string) $key;
                    $fieldLabels[] = (string) ($fieldDef['label'] ?? $key);
                }
            }
        }

        $sanitizer = $this->csvSanitizer ?? new CsvSanitizer();
        $slug = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $form->slug ?: 'form');
        $filename = sprintf('submissions_%s_%s.csv', $slug, date('Ymd_His'));

        $response = new StreamedResponse(function () use ($form, $spamFilter, $fieldKeys, $fieldLabels, $sanitizer) {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            // Émission du BOM UTF-8 pour Excel
            fwrite($output, CsvSanitizer::BOM_UTF8);

            // En-tête du fichier CSV
            $header = ['id', 'created_at', 'is_spam', 'is_read', ...$fieldLabels];
            fputcsv($output, $sanitizer->sanitizeRow($header), ',', '"', "\\");
            flush();

            $page = 1;
            $perPage = 100;

            while (true) {
                $submissions = $this->submissionRepo->findPaginatedByForm($form, $page, $perPage, $spamFilter);
                if (empty($submissions)) {
                    break;
                }

                foreach ($submissions as $s) {
                    $submittedData = is_array($s->data) ? $s->data : [];
                    $fieldValues = [];
                    foreach ($fieldKeys as $k) {
                        $fieldValues[] = $submittedData[$k] ?? '';
                    }

                    $row = [
                        $s->id,
                        $s->createdAt->format(\DateTimeInterface::ATOM),
                        $s->isSpam ? 'true' : 'false',
                        $s->isRead ? 'true' : 'false',
                        ...$fieldValues,
                    ];

                    fputcsv($output, $sanitizer->sanitizeRow($row), ',', '"', "\\");
                }

                flush();

                // Libérer la mémoire
                $this->em->clear();
                // Recharger l'entité formulaire pour la prochaine page
                $form = $this->formRepo->find($form->id);
                if (!$form) {
                    break;
                }

                $page++;
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    // ── Supprimer une soumission ───────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}', name: 'admin_forms_submission_delete', methods: ['DELETE'])]
    public function deleteSubmission(string $projectUuid, int $id, int $submissionId): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Form not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->json(['error' => 'Submission not found.'], 404);
        }

        $this->em->remove($submission);
        $this->em->flush();

        return $this->json(null, 204);
    }

    // ── Marquer comme lu / spam ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}', name: 'admin_forms_submission_update', methods: ['PATCH'])]
    public function updateSubmission(string $projectUuid, int $id, int $submissionId, Request $request): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Form not found.'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->json(['error' => 'Submission not found.'], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

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
    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        try {
            return $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }

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
            'submissions_count' => $this->submissionRepo->countByForm($f),
            'unread_count' => $this->submissionRepo->countUnread($f),
            'createdAt' => $f->createdAt->format('c'),
            'updatedAt' => $f->updatedAt->format('c'),
        ];
    }
}
