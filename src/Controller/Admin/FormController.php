<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\Form\FormBuilder;
use App\Service\Security\CsvSanitizer;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class FormController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    public const MAX_FORMS_PER_PROJECT = 50;
    public const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    public const MAX_EXPORT_RECORDS = 50000;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

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
        #[Autowire(service: 'limiter.admin_api_limiter')]
        private readonly ?RateLimiterFactory $adminApiLimiter = null,
        private readonly ?AuditService $auditService = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->adminApiLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?: ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->adminApiLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('form.admin.rate_limit_exceeded', [], 'Too many form requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    // ── Liste des formulaires ──────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms', name: 'admin_forms_list', methods: ['GET'])]
    public function index(string $projectUuid, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimit = $this->checkRateLimit($request))) {
            return $rateLimit;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('form.admin.project_not_found', [], 'Project not found.'), 'code' => 'PROJECT_NOT_FOUND'], 404);
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

        return $this->secureJson(['data' => $data]);
    }

    // ── Créer un formulaire ────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms', name: 'admin_forms_create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('form.admin.project_not_found', [], 'Project not found.'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        $currentCount = $this->formRepo->count(['project' => $project]);
        if ($currentCount >= self::MAX_FORMS_PER_PROJECT) {
            return $this->secureJson([
                'error' => $this->trans('form.admin.project_quota_exceeded', ['%max%' => (string) self::MAX_FORMS_PER_PROJECT], sprintf('Project form quota exceeded (maximum %d forms allowed).', self::MAX_FORMS_PER_PROJECT)),
                'code' => 'PROJECT_QUOTA_EXCEEDED',
            ], 422);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('form.admin.payload_too_large', [], 'Form payload exceeds maximum size limit (512 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('form.admin.invalid_json', [], 'Invalid JSON payload.'), 'code' => 'INVALID_JSON'], 400);
        }

        // Valider la définition des champs
        $fields = $data['fields'] ?? [];
        if (!is_array($fields) || count($fields) > 100) {
            return $this->secureJson(['error' => $this->trans('form.admin.fields_must_be_array', [], 'Fields must be an array of at most 100 fields.'), 'code' => 'FIELDS_MUST_BE_ARRAY'], 422);
        }
        $validation = $this->formBuilder->validateDefinition($fields);
        if (empty($validation['valid'])) {
            return $this->secureJson(['error' => $this->trans('form.admin.invalid_field_definition', [], 'Invalid field definition.'), 'code' => 'INVALID_FIELD_DEFINITION', 'field_errors' => $validation['errors'] ?? []], 422);
        }

        $fieldSecError = $this->validateFieldsSecurity($fields);
        if ($fieldSecError !== null) {
            return $fieldSecError;
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->secureJson(['error' => $this->trans('form.admin.name_required', [], 'Form name is required.'), 'code' => 'NAME_REQUIRED'], 422);
        }
        if (mb_strlen($name) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $name)) {
            return $this->secureJson(['error' => $this->trans('form.admin.name_invalid', [], 'Form name must not exceed 255 characters and cannot contain control characters.'), 'code' => 'NAME_INVALID'], 422);
        }
        if (str_contains($name, '<') || str_contains($name, '>') || $this->containsXssPayload($name)) {
            return $this->secureJson(['error' => $this->trans('form.admin.name_xss_detected', [], 'Form name contains disallowed HTML tags or script elements.'), 'code' => 'NAME_XSS_DETECTED'], 422);
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
            if ($slug === '') {
                $slug = 'form-' . bin2hex(random_bytes(3));
            }
        }
        if (strlen($slug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return $this->secureJson(['error' => $this->trans('form.admin.slug_invalid', [], 'Form slug must be lowercase alphanumeric with hyphens (max 128 characters).')], 422);
        }

        $steps = $data['steps'] ?? null;
        if ($steps !== null) {
            if (!is_array($steps) || count($steps) > 50) {
                return $this->secureJson(['error' => $this->trans('form.admin.steps_invalid', [], 'Form steps must be an array of at most 50 steps.'), 'code' => 'STEPS_INVALID'], 422);
            }
            if ($this->arrayContainsXss($steps)) {
                return $this->secureJson(['error' => $this->trans('form.admin.step_xss_detected', [], 'Form steps contain disallowed HTML or script elements (anti-XSS protection).'), 'code' => 'STEP_XSS_DETECTED'], 422);
            }
        }

        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            return $this->secureJson(['error' => $this->trans('form.admin.settings_invalid', [], 'Form settings must be an array and not exceed 64KB.'), 'code' => 'SETTINGS_INVALID'], 422);
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

        $this->auditService?->log(
            toolName: 'admin_form_create',
            project: $project,
            input: ['name' => $name, 'slug' => $slug, 'fields_count' => count($fields)],
            output: ['id' => $form->id, 'slug' => $slug],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson($this->serializeForm($form), 201);
    }

    // ── Lire un formulaire ─────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $projectUuid, int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimit = $this->checkRateLimit($request))) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        return $this->secureJson($this->serializeForm($form));
    }

    // ── Mettre à jour un formulaire ────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('form.admin.payload_too_large', [], 'Form payload exceeds maximum size limit (512 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('form.admin.invalid_json', [], 'Invalid JSON payload.'), 'code' => 'INVALID_JSON'], 400);
        }

        if (array_key_exists('name', $data)) {
            $newName = trim((string) $data['name']);
            if ($newName === '') {
                return $this->secureJson(['error' => $this->trans('form.admin.name_empty', [], 'Form name cannot be empty.'), 'code' => 'NAME_EMPTY'], 422);
            }
            if (mb_strlen($newName) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $newName)) {
                return $this->secureJson(['error' => $this->trans('form.admin.name_invalid', [], 'Form name must not exceed 255 characters and cannot contain control characters.'), 'code' => 'NAME_INVALID'], 422);
            }
            if (str_contains($newName, '<') || str_contains($newName, '>') || $this->containsXssPayload($newName)) {
                return $this->secureJson(['error' => $this->trans('form.admin.name_xss_detected', [], 'Form name contains disallowed HTML tags or script elements.'), 'code' => 'NAME_XSS_DETECTED'], 422);
            }
            $form->name = $newName;
        }

        if (array_key_exists('slug', $data)) {
            $newSlug = trim((string) $data['slug']);
            if ($newSlug === '') {
                return $this->secureJson(['error' => $this->trans('form.admin.slug_empty', [], 'Form slug cannot be empty.'), 'code' => 'SLUG_EMPTY'], 422);
            }
            if (strlen($newSlug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $newSlug)) {
                return $this->secureJson(['error' => $this->trans('form.admin.slug_invalid', [], 'Form slug must be lowercase alphanumeric with hyphens (max 128 characters).'), 'code' => 'SLUG_INVALID'], 422);
            }
            $existing = $this->formRepo->findOneBy(['project' => $form->project, 'slug' => $newSlug]);
            if ($existing !== null && $existing->id !== $form->id) {
                return $this->secureJson(['error' => $this->trans('form.admin.slug_already_exists', [], 'A form with this slug already exists in this project.'), 'code' => 'SLUG_ALREADY_EXISTS'], 422);
            }
            $form->slug = $newSlug;
        }

        if (array_key_exists('fields', $data)) {
            if (!is_array($data['fields']) || count($data['fields']) > 100) {
                return $this->secureJson(['error' => $this->trans('form.admin.fields_must_be_array', [], 'Fields must be an array of at most 100 fields.'), 'code' => 'FIELDS_MUST_BE_ARRAY'], 422);
            }
            $validation = $this->formBuilder->validateDefinition($data['fields']);
            if (empty($validation['valid'])) {
                return $this->secureJson(['error' => $this->trans('form.admin.invalid_field_definition', [], 'Invalid field definition.'), 'code' => 'INVALID_FIELD_DEFINITION', 'field_errors' => $validation['errors'] ?? []], 422);
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
                    return $this->secureJson(['error' => $this->trans('form.admin.steps_invalid', [], 'Form steps must be an array of at most 50 steps.'), 'code' => 'STEPS_INVALID'], 422);
                }
                if ($this->arrayContainsXss($data['steps'])) {
                    return $this->secureJson(['error' => $this->trans('form.admin.step_xss_detected', [], 'Form steps contain disallowed HTML or script elements (anti-XSS protection).'), 'code' => 'STEP_XSS_DETECTED'], 422);
                }
            }
            $form->steps = $data['steps'];
        }

        if (array_key_exists('settings', $data)) {
            if (!is_array($data['settings'])) {
                return $this->secureJson(['error' => $this->trans('form.admin.settings_invalid', [], 'Form settings must be an array and not exceed 64KB.'), 'code' => 'SETTINGS_INVALID'], 422);
            }
            $settingsError = $this->validateSettings($data['settings']);
            if ($settingsError !== null) {
                return $settingsError;
            }
            $form->settings = $data['settings'];
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_form_update',
            project: $form->project,
            input: ['id' => $form->id, 'modified_keys' => array_keys($data)],
            output: ['id' => $form->id, 'slug' => $form->slug],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson($this->serializeForm($form));
    }

    // ── Supprimer un formulaire ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}', name: 'admin_forms_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(string $projectUuid, int $id, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimit = $this->checkRateLimit($request))) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        $formName = $form->name;
        $formSlug = $form->slug;
        $project = $form->project;

        // Supprimer d'abord les soumissions en une requête directe (évite OOM)
        $this->submissionRepo->deleteByForm($form);

        $this->em->remove($form);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_form_delete',
            project: $project,
            input: ['id' => $id, 'slug' => $formSlug, 'name' => $formName],
            output: ['deleted' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    // ── Soumissions ────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions', name: 'admin_forms_submissions', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function submissions(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 25)));
        $spamFilter = $request->query->get('spam'); // 'spam', 'ham', or omit for all
        if ($spamFilter !== null && !in_array($spamFilter, ['spam', 'ham'], true)) {
            $spamFilter = null;
        }
        $readFilter = $request->query->get('read'); // 'read', 'unread', or omit for all
        if ($readFilter !== null && !in_array($readFilter, ['read', 'unread'], true)) {
            $readFilter = null;
        }

        $total = $this->submissionRepo->countByForm($form, $spamFilter, $readFilter);
        $items = $this->submissionRepo->findPaginatedByForm($form, $page, $perPage, $spamFilter, $readFilter);

        $data = array_map(fn (FormSubmission $s) => [
            'id' => $s->id,
            'data' => $s->data,
            'metadata' => $s->metadata,
            'isComplete' => $s->isComplete,
            'isSpam' => $s->isSpam,
            'isRead' => $s->isRead,
            'createdAt' => $s->createdAt->format('c'),
        ], $items);

        return $this->secureJson([
            'data' => $data,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    // ── Exporter les soumissions en CSV (protégé contre CSV Formula Injection) ───

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/export-csv', name: 'admin_forms_submissions_export_csv', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportSubmissionsCsv(string $projectUuid, int $id, Request $request): Response
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $this->auditService?->log(
            toolName: 'admin_form_submissions_export',
            project: $form->project,
            input: [
                'id' => $form->id,
                'slug' => $form->slug,
                'spam_filter' => $request->query->get('spam'),
                'read_filter' => $request->query->get('read'),
            ],
            output: ['exported' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        $spamFilter = $request->query->get('spam'); // 'spam', 'ham', or omit for all
        if ($spamFilter !== null && !in_array($spamFilter, ['spam', 'ham'], true)) {
            $spamFilter = null;
        }
        $readFilter = $request->query->get('read'); // 'read', 'unread', or omit for all
        if ($readFilter !== null && !in_array($readFilter, ['read', 'unread'], true)) {
            $readFilter = null;
        }

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

        $response = new StreamedResponse(function () use ($form, $spamFilter, $readFilter, $fieldKeys, $fieldLabels, $sanitizer) {
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
            $maxExportRecords = 50000;
            $exportedCount = 0;

            while (true) {
                if ($exportedCount >= $maxExportRecords) {
                    break;
                }
                $submissions = $this->submissionRepo->findPaginatedByForm($form, $page, $perPage, $spamFilter, $readFilter);
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
                    $exportedCount++;
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
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    // ── Consulter une soumission ───────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}', name: 'admin_forms_submission_show', requirements: ['id' => '\d+', 'submissionId' => '\d+'], methods: ['GET'])]
    public function showSubmission(string $projectUuid, int $id, int $submissionId, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimit = $this->checkRateLimit($request))) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->secureJson(['error' => $this->trans('form.admin.submission_not_found', [], 'Submission not found.'), 'code' => 'SUBMISSION_NOT_FOUND'], 404);
        }

        return $this->secureJson([
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
    public function deleteSubmission(string $projectUuid, int $id, int $submissionId, ?Request $request = null): JsonResponse
    {
        if ($request !== null && ($rateLimit = $this->checkRateLimit($request))) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->secureJson(['error' => $this->trans('form.admin.submission_not_found', [], 'Submission not found.'), 'code' => 'SUBMISSION_NOT_FOUND'], 404);
        }

        $this->em->remove($submission);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_form_submission_delete',
            project: $form->project,
            input: ['form_id' => $form->id, 'submission_id' => $submissionId],
            output: ['deleted' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    // ── Marquer comme lu / spam ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/{submissionId}', name: 'admin_forms_submission_update', requirements: ['id' => '\d+', 'submissionId' => '\d+'], methods: ['PATCH'])]
    public function updateSubmission(string $projectUuid, int $id, int $submissionId, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        $submission = $this->submissionRepo->find($submissionId);
        if (!$submission || $submission->form?->id !== $form->id) {
            return $this->secureJson(['error' => $this->trans('form.admin.submission_not_found', [], 'Submission not found.'), 'code' => 'SUBMISSION_NOT_FOUND'], 404);
        }

        if (strlen($request->getContent()) > 65536) {
            return $this->secureJson(['error' => $this->trans('form.admin.payload_too_large', [], 'Form payload exceeds maximum size limit (512 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('form.admin.invalid_json', [], 'Invalid JSON payload.'), 'code' => 'INVALID_JSON'], 400);
        }

        if (!array_key_exists('isRead', $data) && !array_key_exists('isSpam', $data)) {
            return $this->secureJson(['error' => $this->trans('form.admin.submission_update_invalid', [], 'No valid submission attributes provided for update.'), 'code' => 'SUBMISSION_UPDATE_INVALID'], 422);
        }

        if (array_key_exists('isRead', $data)) {
            $submission->isRead = (bool) $data['isRead'];
        }
        if (array_key_exists('isSpam', $data)) {
            $submission->isSpam = (bool) $data['isSpam'];
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_form_submission_update',
            project: $form->project,
            input: ['form_id' => $form->id, 'submission_id' => $submissionId, 'isRead' => $submission->isRead, 'isSpam' => $submission->isSpam],
            output: ['id' => $submission->id, 'success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'id' => $submission->id,
            'isRead' => $submission->isRead,
            'isSpam' => $submission->isSpam,
        ]);
    }

    // ── Actions groupées sur les soumissions ────────────────────────────────

    #[Route('/admin-api/{projectUuid}/forms/{id}/submissions/batch', name: 'admin_forms_submissions_batch', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function batchSubmissionsAction(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $form = $this->formRepo->find($id);
        if (!$form || $form->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('form.admin.form_not_found', [], 'Form not found.'), 'code' => 'FORM_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $form->project);

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('form.admin.payload_too_large', [], 'Form payload exceeds maximum size limit (512 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('form.admin.invalid_json', [], 'Invalid JSON payload.'), 'code' => 'INVALID_JSON'], 400);
        }

        $action = $data['action'] ?? null;
        $ids = $data['ids'] ?? null;

        if (!is_string($action) || !in_array($action, ['mark_read', 'mark_unread', 'mark_spam', 'mark_ham', 'delete'], true)) {
            return $this->secureJson(['error' => $this->trans('form.admin.invalid_batch_action', [], 'Invalid batch action specified.'), 'code' => 'INVALID_BATCH_ACTION'], 422);
        }

        if (!is_array($ids) || empty($ids)) {
            return $this->secureJson(['error' => $this->trans('form.admin.empty_batch_ids', [], 'No submission IDs provided for batch operation.'), 'code' => 'EMPTY_BATCH_IDS'], 422);
        }

        if (count($ids) > 500) {
            return $this->secureJson(['error' => $this->trans('form.admin.batch_limit_exceeded', [], 'Batch operation exceeds maximum 500 items limit.'), 'code' => 'BATCH_LIMIT_EXCEEDED'], 422);
        }

        $sanitizedIds = [];
        foreach ($ids as $val) {
            if (is_int($val) || (is_string($val) && ctype_digit($val))) {
                $sanitizedIds[] = (int) $val;
            }
        }

        if (empty($sanitizedIds)) {
            return $this->secureJson(['error' => $this->trans('form.admin.empty_batch_ids', [], 'No submission IDs provided for batch operation.'), 'code' => 'EMPTY_BATCH_IDS'], 422);
        }

        $affected = 0;
        switch ($action) {
            case 'mark_read':
                $affected = $this->submissionRepo->batchMarkRead($form, $sanitizedIds, true);
                break;
            case 'mark_unread':
                $affected = $this->submissionRepo->batchMarkRead($form, $sanitizedIds, false);
                break;
            case 'mark_spam':
                $affected = $this->submissionRepo->batchMarkSpam($form, $sanitizedIds, true);
                break;
            case 'mark_ham':
                $affected = $this->submissionRepo->batchMarkSpam($form, $sanitizedIds, false);
                break;
            case 'delete':
                $affected = $this->submissionRepo->batchDelete($form, $sanitizedIds);
                break;
        }

        $this->auditService?->log(
            toolName: 'admin_form_submissions_batch',
            project: $form->project,
            input: ['form_id' => $form->id, 'action' => $action, 'count' => count($sanitizedIds)],
            output: ['affected' => $affected, 'success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'success' => true,
            'action' => $action,
            'affected' => $affected,
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
     * Validates field definitions for identifier safety, anti-XSS and duplicate detection.
     */
    private function validateFieldsSecurity(array $fields): ?JsonResponse
    {
        $seen = [];
        foreach ($fields as $fieldDef) {
            if (!is_array($fieldDef)) {
                continue;
            }
            $fieldName = $fieldDef['name'] ?? $fieldDef['id'] ?? null;
            if ($fieldName !== null) {
                $lower = strtolower((string) $fieldName);
                if (isset($seen[$lower])) {
                    return $this->secureJson([
                        'error' => $this->trans('form.admin.duplicate_field_name', ['%name%' => (string) $fieldName], sprintf('Duplicate field name "%s" detected in form definition.', $fieldName)),
                        'code' => 'DUPLICATE_FIELD_NAME',
                    ], 422);
                }
                $seen[$lower] = true;
            }
            if ($fieldName !== null && !preg_match('/^[a-zA-Z0-9_\-\.]{1,64}$/', (string) $fieldName)) {
                return $this->secureJson([
                    'error' => $this->trans(
                        'form.admin.field_name_invalid',
                        ['%name%' => (string) $fieldName],
                        sprintf('Field name "%s" is invalid. Only alphanumeric characters, dashes, dots and underscores are allowed (max 64 characters).', $fieldName)
                    ),
                    'code' => 'FIELD_NAME_INVALID',
                ], 422);
            }
        }

        if ($this->arrayContainsXss($fields)) {
            return $this->secureJson(['error' => $this->trans('form.admin.field_xss_detected', [], 'Form fields contain disallowed HTML or script elements (anti-XSS protection).'), 'code' => 'FIELD_XSS_DETECTED'], 422);
        }

        return null;
    }

    /**
     * Validates form settings against XSS and SSRF (if redirectUrl or webhookUrl is provided).
     */
    private function validateSettings(array $settings): ?JsonResponse
    {
        if (strlen(json_encode($settings)) > 65536) {
            return $this->secureJson(['error' => $this->trans('form.admin.settings_invalid', [], 'Form settings must be an array and not exceed 64KB.'), 'code' => 'SETTINGS_INVALID'], 422);
        }

        // Validate notification emails against CRLF injection and invalid format
        if (!empty($settings['notifications']['email']['to'])) {
            $toEmails = is_array($settings['notifications']['email']['to'])
                ? $settings['notifications']['email']['to']
                : [$settings['notifications']['email']['to']];

            foreach ($toEmails as $email) {
                $emailStr = trim((string) $email);
                if ($emailStr !== '' && (!filter_var($emailStr, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $emailStr))) {
                    return $this->secureJson([
                        'error' => $this->trans('form.admin.invalid_notification_email', ['%email%' => $emailStr], sprintf('Invalid notification email address "%s".', $emailStr)),
                        'code' => 'INVALID_NOTIFICATION_EMAIL',
                    ], 422);
                }
            }
        }

        // If redirectUrl or webhookUrl is configured, check for safe URL and anti-SSRF first
        foreach (['redirectUrl', 'webhookUrl', 'webhook_url'] as $urlKey) {
            if (isset($settings[$urlKey]) && is_string($settings[$urlKey]) && trim($settings[$urlKey]) !== '') {
                $targetUrl = trim($settings[$urlKey]);
                $lower = strtolower($targetUrl);

                if (str_starts_with($targetUrl, '//') || str_starts_with($targetUrl, '/\\') || str_starts_with($targetUrl, '\\\\')) {
                    return $this->secureJson(['error' => $this->trans('form.admin.settings_unsafe_redirect_url', [], 'Form settings redirect URL is unsafe or uses a forbidden scheme.'), 'code' => 'SETTINGS_UNSAFE_URL'], 422);
                }

                if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
                    return $this->secureJson(['error' => $this->trans('form.admin.settings_unsafe_redirect_url', [], 'Form settings redirect URL is unsafe or uses a forbidden scheme.'), 'code' => 'SETTINGS_UNSAFE_URL'], 422);
                }

                if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $targetUrl, $matches)) {
                    $scheme = strtolower(rtrim($matches[0], ':'));
                    if (!in_array($scheme, ['http', 'https'], true)) {
                        return $this->secureJson(['error' => $this->trans('form.admin.settings_unsafe_redirect_url', [], 'Form settings redirect URL is unsafe or uses a forbidden scheme.'), 'code' => 'SETTINGS_UNSAFE_URL'], 422);
                    }

                    $host = parse_url($targetUrl, PHP_URL_HOST);
                    if ($host !== null && trim($host) !== '') {
                        $reason = null;
                        if (!$this->ssrfProtection->isSafeHost($host, $reason)) {
                            return $this->secureJson(['error' => $this->trans('form.admin.settings_unsafe_redirect_url', [], 'Form settings redirect URL is unsafe or uses a forbidden scheme.'), 'code' => 'SETTINGS_SSRF_DETECTED'], 422);
                        }
                    }
                }
            }
        }

        if ($this->arrayContainsXss($settings)) {
            return $this->secureJson(['error' => $this->trans('form.admin.settings_xss_detected', [], 'Form settings contain disallowed HTML or script elements (anti-XSS protection).'), 'code' => 'SETTINGS_XSS_DETECTED'], 422);
        }

        return null;
    }

    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid) || !Uuid::isValid($projectUuid)) {
            return null;
        }

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
