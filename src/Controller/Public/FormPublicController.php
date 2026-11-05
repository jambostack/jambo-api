<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\ProjectRepository;
use App\Service\Form\FormBuilder;
use App\Service\Form\SubmitHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class FormPublicController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const SLUG_REGEX = '/^[a-zA-Z0-9_\-]{1,128}$/';
    private const MAX_CONTENT_LENGTH = 1048576; // 1 MB
    private const MAX_FIELDS_COUNT = 100;
    private const MAX_FIELD_VALUE_LENGTH = 65536; // 64 KB

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Cache-Control' => 'no-cache, private',
        'Referrer-Policy' => 'no-referrer',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none';",
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepo,
        private readonly FormRepository $formRepo,
        private readonly FormBuilder $formBuilder,
        private readonly SubmitHandler $submitHandler,
        #[Autowire(service: 'limiter.form_submit')]
        private readonly ?RateLimiterFactory $formSubmitLimiter = null,
        #[Autowire(service: 'limiter.form_view_limiter')]
        private readonly ?RateLimiterFactory $formViewLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?FormSubmissionRepository $submissionRepo = null,
    ) {}

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

    #[Route('/{projectUuid}/forms/{slug}', name: 'public_form_show')]
    #[Route('/api/projects/{projectUuid}/forms/{slug}', name: 'api_public_form_show')]
    public function show(string $projectUuid, string $slug, ?Request $request = null): JsonResponse
    {
        if ($request !== null && $this->formViewLimiter !== null) {
            $limiter = $this->formViewLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('form.view_rate_limit_exceeded', [], 'Too many form view requests. Please try again later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('form.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (!preg_match(self::SLUG_REGEX, $slug)) {
            return $this->secureJson([
                'error' => $this->trans('form.invalid_slug', [], 'Invalid form slug.'),
                'code' => 'INVALID_SLUG',
            ], 400);
        }

        try {
            $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('form.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if (!$project) {
            return $this->secureJson([
                'error' => $this->trans('form.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        $form = $this->formRepo->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$form) {
            return $this->secureJson([
                'error' => $this->trans('form.not_found', [], 'Form not found.'),
                'code' => 'FORM_NOT_FOUND',
            ], 404);
        }

        // Validation de la définition du formulaire
        $definition = $this->formBuilder->validateDefinition($form->fields);

        return $this->secureJson([
            'id' => $form->id,
            'name' => $form->name,
            'slug' => $form->slug,
            'fields' => $form->fields,
            'steps' => $form->steps,
            'definition_valid' => $definition['valid'],
            'definition_errors' => $definition['errors'],
        ]);
    }

    #[Route('/{projectUuid}/forms/{slug}/submit', name: 'public_form_submit', methods: ['POST'])]
    #[Route('/api/projects/{projectUuid}/forms/{slug}/submit', name: 'api_public_form_submit', methods: ['POST'])]
    public function submit(string $projectUuid, string $slug, Request $request): JsonResponse
    {
        if ($this->formSubmitLimiter !== null) {
            $limiter = $this->formSubmitLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('form.rate_limit_exceeded', [], 'Too many form submissions. Try again later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (strlen($request->getContent()) > self::MAX_CONTENT_LENGTH) {
            return $this->secureJson([
                'error' => $this->trans('form.payload_too_large', [], 'Payload too large (max 1MB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('form.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (!preg_match(self::SLUG_REGEX, $slug)) {
            return $this->secureJson([
                'error' => $this->trans('form.invalid_slug', [], 'Invalid form slug.'),
                'code' => 'INVALID_SLUG',
            ], 400);
        }

        try {
            $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('form.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if (!$project) {
            return $this->secureJson([
                'error' => $this->trans('form.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        $form = $this->formRepo->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$form) {
            return $this->secureJson([
                'error' => $this->trans('form.not_found', [], 'Form not found.'),
                'code' => 'FORM_NOT_FOUND',
            ], 404);
        }

        // Vérification de l'état actif, date limite et quota de soumissions
        if (isset($form->settings['active']) && $form->settings['active'] === false) {
            return $this->secureJson([
                'error' => $this->trans('form.inactive', [], 'This form is currently inactive.'),
                'code' => 'FORM_INACTIVE',
            ], 403);
        }

        if (!empty($form->settings['closes_at'])) {
            try {
                $closesAt = new \DateTimeImmutable((string) $form->settings['closes_at']);
                if ($closesAt < new \DateTimeImmutable()) {
                    return $this->secureJson([
                        'error' => $this->trans('form.expired', [], 'This form is no longer accepting submissions.'),
                        'code' => 'FORM_EXPIRED',
                    ], 403);
                }
            } catch (\Throwable) {}
        }

        if (!empty($form->settings['max_submissions']) && $this->submissionRepo !== null) {
            $limit = (int) $form->settings['max_submissions'];
            if ($limit > 0 && $this->submissionRepo->countByForm($form) >= $limit) {
                return $this->secureJson([
                    'error' => $this->trans('form.submission_limit_reached', [], 'This form has reached its submission limit.'),
                    'code' => 'FORM_LIMIT_REACHED',
                ], 403);
            }
        }

        $contentType = (string) $request->headers->get('Content-Type');
        if (str_contains($contentType, 'application/json') || empty($contentType)) {
            try {
                $data = $request->toArray();
            } catch (\Throwable) {
                $data = $request->request->all();
            }
        } else {
            $data = $request->request->all();
        }

        if (!is_array($data)) {
            return $this->secureJson([
                'error' => $this->trans('form.validation_failed', [], 'Validation failed.'),
                'code' => 'INVALID_PAYLOAD',
            ], 400);
        }

        $safetyError = $this->validatePayloadSafety($data);
        if ($safetyError !== null) {
            return $safetyError;
        }

        try {
            $submission = $this->submitHandler->handle($form, $data, $request);
        } catch (ValidationFailedException $e) {
            $errors = [];
            foreach ($e->getViolations() as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return $this->secureJson([
                'error' => $this->trans('form.validation_failed', [], 'Validation failed.'),
                'errors' => $errors,
                'validation_errors' => $errors,
                'code' => 'VALIDATION_FAILED',
            ], 422);
        } catch (\InvalidArgumentException $e) {
            $rawMsg = $e->getMessage();
            $parts = explode('|', $rawMsg);
            $key = $parts[0];
            $params = [];
            if ($key === 'form.too_many_attachments') {
                $params = ['%max%' => $parts[1] ?? '5'];
            } elseif ($key === 'form.attachments_total_too_large') {
                $params = ['%max%' => $parts[1] ?? '25'];
            } elseif ($key === 'form.attachment_too_large') {
                $params = ['%filename%' => $parts[1] ?? '', '%max%' => $parts[2] ?? '10'];
            } elseif ($key === 'form.attachment_upload_error' || $key === 'form.attachment_invalid') {
                $params = ['%filename%' => $parts[1] ?? ''];
            }

            return $this->secureJson([
                'error' => $this->trans($key, $params, $rawMsg),
                'code' => 'ATTACHMENT_ERROR',
            ], 422);
        } catch (\RuntimeException) {
            return $this->secureJson([
                'error' => $this->trans('form.submission_failed', [], 'Form submission failed. Please try again.'),
                'code' => 'SUBMISSION_FAILED',
            ], 422);
        }

        return $this->secureJson([
            'success' => true,
            'submission_id' => $submission->id,
            'is_spam' => $submission->isSpam,
        ], $submission->isSpam ? 200 : 201);
    }

    /**
     * Valide récursivement la sécurité des clés et valeurs de données soumises.
     */
    private function validatePayloadSafety(mixed $data, int $depth = 0): ?JsonResponse
    {
        if ($depth > 3) {
            return $this->secureJson([
                'error' => $this->trans('form.invalid_payload_structure', [], 'Invalid form payload structure or excessive nesting depth.'),
                'code' => 'INVALID_PAYLOAD_STRUCTURE',
            ], 400);
        }

        if (is_array($data)) {
            if (count($data) > self::MAX_FIELDS_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('form.too_many_fields', [], 'Too many form fields submitted.'),
                    'code' => 'TOO_MANY_FIELDS',
                ], 400);
            }

            foreach ($data as $k => $v) {
                if (!is_string($k) && !is_int($k)) {
                    return $this->secureJson([
                        'error' => $this->trans('form.invalid_payload_structure', [], 'Invalid form payload structure.'),
                        'code' => 'INVALID_PAYLOAD_STRUCTURE',
                    ], 400);
                }

                $keyStr = (string) $k;
                if (strlen($keyStr) > 64 || !preg_match('/^[a-zA-Z0-9_\-\.]{1,64}$/', $keyStr)) {
                    return $this->secureJson([
                        'error' => $this->trans('form.invalid_field_name', ['%field%' => $keyStr], sprintf('Invalid field name "%s".', $keyStr)),
                        'code' => 'INVALID_FIELD_NAME',
                    ], 400);
                }

                if (is_string($v)) {
                    if (str_contains($v, "\0") || !mb_check_encoding($v, 'UTF-8')) {
                        return $this->secureJson([
                            'error' => $this->trans('form.invalid_encoding', [], 'Field value contains invalid UTF-8 encoding or null bytes.'),
                            'code' => 'INVALID_ENCODING',
                        ], 400);
                    }
                    if (strlen($v) > self::MAX_FIELD_VALUE_LENGTH) {
                        return $this->secureJson([
                            'error' => $this->trans('form.field_value_too_large', ['%field%' => $keyStr], 'Field value too large.'),
                            'code' => 'FIELD_VALUE_TOO_LARGE',
                        ], 400);
                    }
                } elseif (is_array($v)) {
                    $nestedErr = $this->validatePayloadSafety($v, $depth + 1);
                    if ($nestedErr !== null) {
                        return $nestedErr;
                    }
                }
            }
        }

        return null;
    }
}
