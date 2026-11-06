<?php

namespace App\Service\Form;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Event\FormSubmittedEvent;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SubmitHandler
{
    public function __construct(
        private readonly FormBuilder $formBuilder,
        private readonly AntiSpamService $antiSpamService,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
        private readonly ?UploadSecurityValidator $uploadValidator = null,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly ?string $projectDir = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    /**
     * Handle a form submission: validate, check anti-spam, persist, trigger workflows.
     *
     * @param Form    $form
     * @param array   $data   Submitted field values (field name => value)
     * @param Request $request
     * @return FormSubmission
     *
     * @throws \RuntimeException on validation failure or spam detection
     */
    public function handle(Form $form, array $data, Request $request): FormSubmission
    {
        // 0. Traitement sécurisé des pièces jointes et fichiers téléversés
        $data = $this->processUploadedFiles($form, $data, $request);

        // 1. Resolve conditional visibility: skip validation for hidden fields
        $visibleFields = $this->formBuilder->resolveConditions($form->fields, $data);
        $visibleFieldNames = array_flip($visibleFields);

        // 2. Build schema and validate visible fields only
        $fieldConstraints = $this->formBuilder->buildFormSchema($form);
        $fieldsByName = [];
        foreach ($form->fields as $f) {
            if (is_array($f)) {
                $fName = $f['name'] ?? $f['id'] ?? null;
                if ($fName) {
                    $fieldsByName[$fName] = $f;
                }
            }
        }
        $wrappedConstraints = [];
        foreach ($fieldConstraints as $fieldName => $constraints) {
            $isReq = !empty($fieldsByName[$fieldName]['required']);
            $wrappedConstraints[$fieldName] = $isReq
                ? new Assert\Required($constraints)
                : new Assert\Optional($constraints);
        }
        $collection = new Assert\Collection(fields: $wrappedConstraints, allowExtraFields: true);
        $violations = $this->validator->validate($data, $collection);

        // Filter out violations for non-visible fields
        $relevantViolations = [];
        foreach ($violations as $violation) {
            // Collection constraint uses [fieldName] notation
            $fieldName = trim($violation->getPropertyPath(), '[]');
            if (isset($visibleFieldNames[$fieldName])) {
                $relevantViolations[] = $violation;
            }
        }

        if (count($relevantViolations) > 0) {
            throw new ValidationFailedException($data, new ConstraintViolationList($relevantViolations));
        }

        // 3. Prepare metadata
        $ip = $request->getClientIp() ?? 'unknown';
        $userAgent = $request->headers->get('User-Agent', 'unknown');
        $referrer = $request->headers->get('Referer', '');

        // 4. Anti-spam checks
        $isSpam = false;
        $spamReasons = [];

        // Honeypot
        if ($this->antiSpamService->checkHoneypot($data)) {
            $isSpam = true;
            $spamReasons[] = 'honeypot';
        }

        // Rate limit
        if ($this->antiSpamService->checkRateLimit($ip)) {
            $isSpam = true;
            $spamReasons[] = 'rate_limit';
        }

        // Captcha (if configured in form settings)
        $captchaConfig = $form->settings['captcha'] ?? [];
        if (!empty($captchaConfig['enabled']) && !empty($captchaConfig['secret'])) {
            $captchaToken = $data['_captcha'] ?? $data['cf-turnstile-response'] ?? '';
            if (!$this->antiSpamService->verifyCaptcha($captchaToken, $captchaConfig)) {
                $isSpam = true;
                $spamReasons[] = 'captcha';
            }
        }

        // Blocklisted domain (check email fields)
        foreach ($data as $fieldName => $value) {
            if (is_string($value) && str_contains($value, '@')) {
                if ($this->antiSpamService->checkBlocklistedDomain($value)) {
                    $isSpam = true;
                    $spamReasons[] = 'blocklisted_domain';
                    break;
                }
            }
        }

        // Spam pattern detection
        $spamScore = $this->antiSpamService->detectSpamPatterns($data);
        if ($spamScore > 0.7) {
            $isSpam = true;
            $spamReasons[] = 'spam_patterns';
        }

        // 5. Create FormSubmission
        $submission = new FormSubmission();
        $submission->form = $form;
        $submission->data = $data;
        $submission->metadata = [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'referrer' => $referrer,
            'is_spam' => $isSpam,
            'spam_reasons' => $spamReasons,
            'spam_score' => $spamScore,
        ];
        $submission->isComplete = true;
        $submission->isSpam = $isSpam;

        // 6. Persist
        $this->em->persist($submission);
        $this->em->flush();

        // 7. Trigger post-submit workflows if the submission is not spam
        if (!$isSpam) {
            $this->triggerWorkflows($form, $submission, $data);
            if ($this->eventDispatcher !== null && $form->project !== null) {
                $this->eventDispatcher->dispatch(
                    new FormSubmittedEvent($form, $submission, $form->project, $data),
                    FormSubmittedEvent::SUBMITTED
                );
            }
        }

        return $submission;
    }

    /**
     * Trigger post-submit workflows: notification emails, webhooks, ContentEntry creation.
     *
     * @param Form           $form
     * @param FormSubmission $submission
     * @param array          $data
     */
    private function triggerWorkflows(Form $form, FormSubmission $submission, array $data): void
    {
        $settings = $form->settings;

        // Notification email
        if (!empty($settings['notifications']['email']['enabled'])) {
            // The actual email sending is handled by a separate Messenger message/dispatch
            // or by a dedicated form submission listener; this is a placeholder
            // for the workflow trigger point.
        }

        // Webhook
        if (!empty($settings['webhook']['enabled']) && !empty($settings['webhook']['url'])) {
            // Webhook dispatch would be dispatched asynchronously via Messenger
            // or handled by an event subscriber.
        }

        // ContentEntry creation
        if (!empty($settings['create_entry']['enabled']) && !empty($settings['create_entry']['collection'])) {
            // ContentEntry creation from form data would be handled
            // by a dedicated service/event subscriber.
        }
    }

    /**
     * Traite et assainit les fichiers joints envoyés dans la requête multipart.
     *
     * @param Form    $form
     * @param array   $data
     * @param Request $request
     * @return array
     *
     * @throws \InvalidArgumentException si les quotas ou types MIME ne sont pas respectés
     */
    private function processUploadedFiles(Form $form, array $data, Request $request): array
    {
        if ($request->files->count() === 0) {
            return $data;
        }

        $allUploadedFiles = [];
        foreach ($request->files->all() as $fieldName => $fileOrFiles) {
            if (is_array($fileOrFiles)) {
                foreach ($fileOrFiles as $file) {
                    if ($file instanceof UploadedFile) {
                        $allUploadedFiles[] = ['field' => (string) $fieldName, 'file' => $file, 'multiple' => true];
                    }
                }
            } elseif ($fileOrFiles instanceof UploadedFile) {
                $allUploadedFiles[] = ['field' => (string) $fieldName, 'file' => $fileOrFiles, 'multiple' => false];
            }
        }

        if (empty($allUploadedFiles)) {
            return $data;
        }

        $maxAttachments = min(10, max(1, (int) ($form->settings['max_attachments'] ?? 5)));
        if (count($allUploadedFiles) > $maxAttachments) {
            throw new \InvalidArgumentException(sprintf('form.too_many_attachments|%d', $maxAttachments));
        }

        $maxFileSizeMb = min(25, max(1, (int) ($form->settings['max_file_size_mb'] ?? 10)));
        $maxSizeBytes = $maxFileSizeMb * 1024 * 1024;
        $maxTotalBytes = min(50 * 1024 * 1024, $maxSizeBytes * $maxAttachments);

        $totalBytes = 0;
        foreach ($allUploadedFiles as $item) {
            /** @var UploadedFile $file */
            $file = $item['file'];
            if (!$file->isValid()) {
                throw new \InvalidArgumentException(sprintf('form.attachment_upload_error|%s', $file->getClientOriginalName()));
            }
            $size = $file->getSize();
            if ($size <= 0 || $size > $maxSizeBytes) {
                throw new \InvalidArgumentException(sprintf('form.attachment_too_large|%s|%d', $file->getClientOriginalName(), $maxFileSizeMb));
            }
            $totalBytes += $size;
        }

        if ($totalBytes > $maxTotalBytes) {
            throw new \InvalidArgumentException(sprintf('form.attachments_total_too_large|%d', (int) ceil($maxTotalBytes / (1024 * 1024))));
        }

        $projectUuid = $form->project?->uuid?->toRfc4122() ?? 'global';
        $baseDir = $this->projectDir ?? sys_get_temp_dir();
        $targetDir = $baseDir . '/public/uploads/forms/' . $projectUuid;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $filesByField = [];
        foreach ($allUploadedFiles as $item) {
            /** @var UploadedFile $file */
            $file = $item['file'];
            $fieldName = (string) $item['field'];

            $cleanName = $this->uploadValidator !== null
                ? $this->uploadValidator->sanitizeAndValidateFilename($file->getClientOriginalName())
                : basename($file->getClientOriginalName());

            $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));

            $mime = 'application/octet-stream';
            if ($this->uploadValidator !== null) {
                try {
                    $mime = $this->uploadValidator->validateFileContent($file->getPathname(), $ext);
                    if ($form->project !== null) {
                        $this->uploadValidator->validateSizeAndQuota($form->project, $file->getSize());
                    }
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException(sprintf('form.attachment_invalid|%s', $cleanName));
                }
            }

            $safeFilename = bin2hex(random_bytes(16)) . '.' . $ext;
            $file->move($targetDir, $safeFilename);

            $fileDescriptor = [
                'original_name' => $cleanName,
                'filename'      => $safeFilename,
                'path'          => '/uploads/forms/' . $projectUuid . '/' . $safeFilename,
                'mime_type'     => $mime,
                'size'          => $file->getSize(),
            ];

            if ($item['multiple']) {
                $filesByField[$fieldName][] = $fileDescriptor;
            } else {
                $filesByField[$fieldName] = $fileDescriptor;
            }
        }

        foreach ($filesByField as $fieldName => $descriptor) {
            $data[$fieldName] = $descriptor;
        }

        return $data;
    }
}
