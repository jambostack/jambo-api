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
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class FormController extends AbstractController
{
    private readonly SsrfProtectionService $ssrfProtection;

    public function __construct(
        private readonly FormRepository $formRepo,
        private readonly FormSubmissionRepository $submissionRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly FormBuilder $formBuilder,
        private readonly EntityManagerInterface $em,
        private readonly ?CsvSanitizer $csvSanitizer = null,
        ?SsrfProtectionService $ssrfProtection = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    // ── Liste des formulaires ──────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms', name: 'admin_forms_list', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('form.admin.project_not_found', [], 'Project not found.')], 404);
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
            return $this->json(['error' => $this->trans('form.admin.project_not_found', [], 'Project not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('form.admin.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        // Valider la définition des champs
        $fields = $data['fields'] ?? [];
        if (!is_array($fields) || count($fields) > 100) {
            return $this->json(['error' => $this->trans('form.admin.fields_must_be_array', [], 'Fields must be an array of at most 100 fields.')], 422);
        }
        $validation = $this->formBuilder->validateDefinition($fields);
        if (empty($validation['valid'])) {
            return $this->json(['error' => $this->trans('form.admin.invalid_field_definition', [], 'Invalid field definition.'), 'field_errors' => $validation['errors'] ?? []], 422);
        }

        $fieldSecError = $this->validateFieldsSecurity($fields);
        if ($fieldSecError !== null) {
            return $fieldSecError;
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => $this->trans('form.admin.name_required', [], 'Form name is required.')], 422);
        }
        if (mb_strlen($name) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $name)) {
            return $this->json(['error' => $this->trans('form.admin.name_invalid', [], 'Form name must not exceed 255 characters and cannot contain control characters.')], 422);
        }
        if (str_contains($name, '<') || str_contains($name, '>') || $this->containsXssPayload($name)) {
            return $this->json(['error' => $this->trans('form.admin.name_xss_detected', [], 'Form name contains disallowed HTML tags or script elements.')], 422);
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
            if ($slug === '') {
                $slug = 'form-' . bin2hex(random_bytes(3));
            }
        }
        if (strlen($slug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return $this->json(['error' => $this->trans('form.admin.slug_invalid', [], 'Form slug must be lowercase alphanumeric with hyphens (max 128 characters).')], 422);
        }

        $steps = $data['steps'] ?? null;
        if ($steps !== null) {
            if (!is_array($steps) || count($steps) > 50) {
                return $this->json(['error' => $this->trans('form.admin.steps_invalid', [], 'Form steps must be an array of at most 50 steps.')], 422);
            }
            if ($this->arrayContainsXss($steps)) {
                return $this->json(['error' => $this->trans('form.admin.step_xss_detected', [], 'Form steps contain disallowed HTML or script elements (anti-XSS protection).')], 422);
            }
        }

        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            return $this->json(['error' => $this->trans('form.admin.settings_invalid', [], 'Form settings must be an array and not exceed 64KB.')], 422);
        }
        $settingsError = $this->validateSettings($settings);
        if ($settingsError !== null) {
            return $settingsError;
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

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $projectUuid, int $id): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        return $this->json($this->serializeForm($form));
    }

    // ── Mettre à jour un formulaire ────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('form.admin.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        if (array_key_exists('name', $data)) {
            $newName = trim((string) $data['name']);
            if ($newName === '') {
                return $this->json(['error' => $this->trans('form.admin.name_empty', [], 'Form name cannot be empty.')], 422);
            }
            if (mb_strlen($newName) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $newName)) {
                return $this->json(['error' => $this->trans('form.admin.name_invalid', [], 'Form name must not exceed 255 characters and cannot contain control characters.')], 422);
            }
            if (str_contains($newName, '<') || str_contains($newName, '>') || $this->containsXssPayload($newName)) {
                return $this->json(['error' => $this->trans('form.admin.name_xss_detected', [], 'Form name contains disallowed HTML tags or script elements.')], 422);
            }
            $form->name = $newName;
        }

        if (array_key_exists('slug', $data)) {
            $newSlug = trim((string) $data['slug']);
            if ($newSlug === '') {
                return $this->json(['error' => $this->trans('form.admin.slug_empty', [], 'Form slug cannot be empty.')], 422);
            }
            if (strlen($newSlug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $newSlug)) {
                return $this->json(['error' => $this->trans('form.admin.slug_invalid', [], 'Form slug must be lowercase alphanumeric with hyphens (max 128 characters).')], 422);
            }
            $existing = $this->formRepo->findOneBy(['project' => $form->project, 'slug' => $newSlug]);
            if ($existing !== null && $existing->id !== $form->id) {
                return $this->json(['error' => $this->trans('form.admin.slug_already_exists', [], 'A form with this slug already exists in this project.')], 422);
            }
            $form->slug = $newSlug;
        }

        if (array_key_exists('fields', $data)) {
            if (!is_array($data['fields']) || count($data['fields']) > 100) {
                return $this->json(['error' => $this->trans('form.admin.fields_must_be_array', [], 'Fields must be an array of at most 100 fields.')], 422);
            }
            $validation = $this->formBuilder->validateDefinition($data['fields']);
            if (empty($validation['valid'])) {
                return $this->json(['error' => $this->trans('form.admin.invalid_field_definition', [], 'Invalid field definition.'), 'field_errors' => $validation['errors'] ?? []], 422);
            }
            $fieldSecError = $this->validateFieldsSecurity($data['fields']);
            if ($fieldSecError !== null) {
                return $fieldSecError;
            }
            $form->fields = $data['fields'];
        }

        if (array_key_exists('steps', $data)) {
            if ($data['steps'] !== null) {
                if (!is_array($data['steps']) || count($data['steps']) > 50) {
                    return $this->json(['error' => $this->trans('form.admin.steps_invalid', [], 'Form steps must be an array of at most 50 steps.')], 422);
                }
                if ($this->arrayContainsXss($data['steps'])) {
                    return $this->json(['error' => $this->trans('form.admin.step_xss_detected', [], 'Form steps contain disallowed HTML or script elements (anti-XSS protection).')], 422);
                }
            }
            $form->steps = $data['steps'];
        }

        if (array_key_exists('settings', $data)) {
            if (!is_array($data['settings'])) {
                return $this->json(['error' => $this->trans('form.admin.settings_invalid', [], 'Form settings must be an array and not exceed 64KB.')], 422);
            }
            $settingsError = $this->validateSettings($data['settings']);
            if ($settingsError !== null) {
                return $settingsError;
            }
            $form->settings = $data['settings'];
        }

        $this->em->flush();

        return $this->json($this->serializeForm($form));
    }

    // ── Supprimer un formulaire ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(string $projectUuid, int $id): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        // Supprimer d'abord les soumissions en une requête directe (évite OOM)
        $this->submissionRepo->deleteByForm($form);

        $this->em->remove($form);
        $this->em->flush();

        return $this->json(null, 204);
    }

    // ── Soumissions ────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions', name: 'admin_forms_submissions', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function submissions(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.')], 404);
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

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/export-csv', name: 'admin_forms_submissions_export_csv', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportSubmissionsCsv(string $projectUuid, int $id, Request $request): Response
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.')], 404);
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

    // ── Consulter une soumission ───────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}', name: 'admin_forms_submission_show', requirements: ['id' => '\d+', 'submissionId' => '\d+'], methods: ['GET'])]
    public function showSubmission(string $projectUuid, int $id, int $submissionId): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->json(['error' => $this->trans('form.admin.submission_not_found', [], 'Submission not found.')], 404);
        }

        return $this->json([
            'id' => $submission->id,
            'formId' => $form->id,
            'data' => $submission->data,
            'metadata' => $submission->metadata,
            'isComplete' => $submission->isComplete,
            'isSpam' => $submission->isSpam,
            'isRead' => $submission->isRead,
            'createdAt' => $submission->createdAt->format('c'),
        ]);
    }

    // ── Supprimer une soumission ───────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}', name: 'admin_forms_submission_delete', requirements: ['id' => '\d+', 'submissionId' => '\d+'], methods: ['DELETE'])]
    public function deleteSubmission(string $projectUuid, int $id, int $submissionId): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->json(['error' => $this->trans('form.admin.submission_not_found', [], 'Submission not found.')], 404);
        }

        $this->em->remove($submission);
        $this->em->flush();

        return $this->json(null, 204);
    }

    // ── Marquer comme lu / spam ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}', name: 'admin_forms_submission_update', requirements: ['id' => '\d+', 'submissionId' => '\d+'], methods: ['PATCH'])]
    public function updateSubmission(string $projectUuid, int $id, int $submissionId, Request $request): JsonResponse
    {
        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->json(['error' => $this->trans('form.admin.submission_not_found', [], 'Submission not found.')], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('form.admin.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        if (!array_key_exists('isRead', $data) && !array_key_exists('isSpam', $data)) {
            return $this->json(['error' => $this->trans('form.admin.submission_update_invalid', [], 'No valid submission attributes provided for update.')], 422);
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

    // ── Validation Helpers & Anti-XSS ──────────────────────────────────────

    /**
     * Checks whether a string contains dangerous XSS or HTML injection patterns.
     */
    private function containsXssPayload(string $value): bool
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Check for HTML tags
        if (preg_match('/<\s*(?:script|iframe|object|embed|svg|img|form|input|button|link|meta|style|body|html|video|audio|details|base)/i', $decoded)) {
            return true;
        }

        // Check for dangerous inline event handlers like onload=, onerror=, onclick=, onfocus=, onmouseover=
        if (preg_match('/on[a-z]{3,20}\s*=/i', $decoded)) {
            return true;
        }

        // Check for URI schemes like javascript:, vbscript:, data:text/html
        if (preg_match('/(?:javascript|vbscript)\s*:/i', $decoded)) {
            return true;
        }

        if (preg_match('/data\s*:\s*text\/html/i', $decoded)) {
            return true;
        }

        return false;
    }

    /**
     * Recursively verifies that an array does not contain XSS payloads in any of its string elements.
     */
    private function arrayContainsXss(array $data): bool
    {
        foreach ($data as $val) {
            if (is_string($val)) {
                if ($this->containsXssPayload($val)) {
                    return true;
                }
            } elseif (is_array($val)) {
                if ($this->arrayContainsXss($val)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Validates field definitions for identifier safety and anti-XSS.
     */
    private function validateFieldsSecurity(array $fields): ?JsonResponse
    {
        foreach ($fields as $fieldDef) {
            if (!is_array($fieldDef)) {
                continue;
            }
            $fieldName = $fieldDef['name'] ?? $fieldDef['id'] ?? null;
            if ($fieldName !== null && !preg_match('/^[a-zA-Z0-9_\-\.]{1,64}$/', (string) $fieldName)) {
                return $this->json([
                    'error' => $this->trans(
                        'form.admin.field_name_invalid',
                        ['%name%' => (string) $fieldName],
                        sprintf('Field name "%s" is invalid. Only alphanumeric characters, dashes, dots and underscores are allowed (max 64 characters).', $fieldName)
                    )
                ], 422);
            }
        }

        if ($this->arrayContainsXss($fields)) {
            return $this->json(['error' => $this->trans('form.admin.field_xss_detected', [], 'Form fields contain disallowed HTML or script elements (anti-XSS protection).')], 422);
        }

        return null;
    }

    /**
     * Validates form settings against XSS and SSRF (if redirectUrl is provided).
     */
    private function validateSettings(array $settings): ?JsonResponse
    {
        if (strlen(json_encode($settings)) > 65536) {
            return $this->json(['error' => $this->trans('form.admin.settings_invalid', [], 'Form settings must be an array and not exceed 64KB.')], 422);
        }

        // If redirectUrl is configured, check for safe URL and anti-SSRF first
        if (isset($settings['redirectUrl']) && is_string($settings['redirectUrl']) && trim($settings['redirectUrl']) !== '') {
            $redirectUrl = trim($settings['redirectUrl']);
            $lower = strtolower($redirectUrl);

            if (str_starts_with($redirectUrl, '//') || str_starts_with($redirectUrl, '/\\') || str_starts_with($redirectUrl, '\\\\')) {
                return $this->json(['error' => $this->trans('form.admin.settings_unsafe_redirect_url', [], 'Form settings redirect URL is unsafe or uses a forbidden scheme.')], 422);
            }

            if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
                return $this->json(['error' => $this->trans('form.admin.settings_unsafe_redirect_url', [], 'Form settings redirect URL is unsafe or uses a forbidden scheme.')], 422);
            }

            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $redirectUrl, $matches)) {
                $scheme = strtolower(rtrim($matches[0], ':'));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    return $this->json(['error' => $this->trans('form.admin.settings_unsafe_redirect_url', [], 'Form settings redirect URL is unsafe or uses a forbidden scheme.')], 422);
                }

                $host = parse_url($redirectUrl, PHP_URL_HOST);
                if ($host !== null && trim($host) !== '') {
                    $reason = null;
                    if (!$this->ssrfProtection->isSafeHost($host, $reason)) {
                        return $this->json(['error' => $this->trans('form.admin.settings_unsafe_redirect_url', [], 'Form settings redirect URL is unsafe or uses a forbidden scheme.')], 422);
                    }
                }
            }
        }

        if ($this->arrayContainsXss($settings)) {
            return $this->json(['error' => $this->trans('form.admin.settings_xss_detected', [], 'Form settings contain disallowed HTML or script elements (anti-XSS protection).')], 422);
        }

        return null;
    }

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
