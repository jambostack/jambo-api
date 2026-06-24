<?php

namespace App\Service\Form;

use App\Entity\Form;
use App\Entity\FormSubmission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SubmitHandler
{
    public function __construct(
        private readonly FormBuilder $formBuilder,
        private readonly AntiSpamService $antiSpamService,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
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
        // 1. Resolve conditional visibility: skip validation for hidden fields
        $visibleFields = $this->formBuilder->resolveConditions($form->fields, $data);
        $visibleFieldNames = array_flip($visibleFields);

        // 2. Build schema and validate visible fields only
        $fieldConstraints = $this->formBuilder->buildFormSchema($form);
        $collection = new Assert\Collection(fields: $fieldConstraints, allowExtraFields: true);
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
            $errors = [];
            foreach ($relevantViolations as $violation) {
                $fieldName = trim($violation->getPropertyPath(), '[]');
                $errors[$fieldName] = $violation->getMessage();
            }
            throw new \RuntimeException(json_encode(['validation_errors' => $errors]));
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
        $captchaToken = $data['_captcha'] ?? '';
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
}
