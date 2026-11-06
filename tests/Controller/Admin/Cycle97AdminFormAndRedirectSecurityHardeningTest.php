<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\FormController;
use App\Controller\Admin\RedirectController;
use App\Entity\AuditLog;
use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Entity\Project;
use App\Entity\Redirect;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle97AdminFormAndRedirectSecurityHardeningTest extends WebTestCase
{
    private function createAdmin(EntityManagerInterface $em): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Cycle 97 Admin';
        $user->email = 'admin_cycle97_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'SecretPassword123!');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Cycle 97 Project ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testFormQuotaEnforcement(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // Seed forms up to MAX_FORMS_PER_PROJECT (50)
        for ($i = 1; $i <= FormController::MAX_FORMS_PER_PROJECT; $i++) {
            $f = new Form();
            $f->project = $project;
            $f->name = "Form {$i}";
            $f->slug = "form-{$i}-" . bin2hex(random_bytes(2));
            $f->fields = [['name' => 'field_1', 'type' => 'text', 'label' => 'Field 1']];
            $em->persist($f);
        }
        $em->flush();

        // Attempting to create the 51st form must be blocked by quota
        $payload = [
            'name' => 'Excess Form 51',
            'slug' => 'excess-form-51',
            'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'Email']],
        ];

        $client->request(
            'POST',
            "/admin-api/{$projectUuid}/forms",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('PROJECT_QUOTA_EXCEEDED', $data['code'] ?? null);
        $this->assertStringContainsString('quota', strtolower($data['error'] ?? ''));
        $this->assertStringContainsString((string) FormController::MAX_FORMS_PER_PROJECT, $data['error'] ?? '');
    }

    public function testFormAuditLoggingLifecycle(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // 1. Create Form -> logs admin_form_create
        $payload = [
            'name' => 'Audit Test Form',
            'slug' => 'audit-test-form',
            'fields' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text'],
                ['name' => 'email_addr', 'label' => 'Email Address', 'type' => 'email'],
            ],
            'settings' => ['active' => true],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $formCreated = json_decode($client->getResponse()->getContent(), true);
        $formId = $formCreated['id'];

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_form_create',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('success', $auditLog->status);
        $this->assertSame('admin_api', $auditLog->source);
        $this->assertSame('Audit Test Form', $auditLog->input['name'] ?? null);

        // 2. Update Form -> logs admin_form_update
        $updatePayload = [
            'name' => 'Updated Audit Test Form',
        ];
        $client->request('PUT', "/admin-api/{$projectUuid}/forms/{$formId}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($updatePayload));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $auditLogUpdate = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_form_update',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogUpdate);
        $this->assertSame('success', $auditLogUpdate->status);

        // 3. Export Submissions -> logs admin_form_submissions_export
        $client->request('GET', "/admin-api/{$projectUuid}/forms/{$formId}/submissions/export-csv");
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $auditLogExport = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_form_submissions_export',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogExport);
        $this->assertSame('success', $auditLogExport->status);

        // 4. Delete Form -> logs admin_form_delete
        $client->request('DELETE', "/admin-api/{$projectUuid}/forms/{$formId}");
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        $auditLogDelete = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_form_delete',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogDelete);
        $this->assertSame('success', $auditLogDelete->status);
        $this->assertSame($formId, $auditLogDelete->input['id'] ?? null);
    }

    public function testFormSubmissionAuditLoggingOnUpdateAndDelete(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $form = new Form();
        $form->project = $project;
        $form->name = 'Feedback Form';
        $form->slug = 'feedback-form-' . bin2hex(random_bytes(2));
        $form->fields = [['name' => 'message', 'type' => 'text', 'label' => 'Message']];
        $em->persist($form);

        $submission = new FormSubmission();
        $submission->form = $form;
        $submission->data = ['message' => 'Great product!'];
        $submission->isRead = false;
        $submission->isSpam = false;
        $em->persist($submission);
        $em->flush();

        $formId = $form->id;
        $submissionId = $submission->id;

        // 1. Update Submission (mark as read & spam)
        $client->request(
            'PATCH',
            "/admin-api/{$projectUuid}/forms/{$formId}/submissions/{$submissionId}",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['isRead' => true, 'isSpam' => true])
        );
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $auditLogSubUpdate = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_form_submission_update',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogSubUpdate);
        $this->assertSame('success', $auditLogSubUpdate->status);
        $this->assertSame($submissionId, $auditLogSubUpdate->input['submission_id'] ?? null);

        // 2. Delete Submission
        $client->request('DELETE', "/admin-api/{$projectUuid}/forms/{$formId}/submissions/{$submissionId}");
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        $auditLogSubDelete = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_form_submission_delete',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogSubDelete);
        $this->assertSame('success', $auditLogSubDelete->status);
        $this->assertSame($submissionId, $auditLogSubDelete->input['submission_id'] ?? null);
    }

    public function testRedirectQuotaEnforcement(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // Seed redirects up to MAX_REDIRECTS_PER_PROJECT (500)
        // Using batch insert for test execution speed
        for ($i = 1; $i <= RedirectController::MAX_REDIRECTS_PER_PROJECT; $i++) {
            $r = new Redirect();
            $r->project = $project;
            $r->fromPath = "/old-route-{$i}";
            $r->toPath = "/new-route-{$i}";
            $r->httpCode = 301;
            $r->isEnabled = true;
            $r->createdBy = $admin;
            $em->persist($r);
            if ($i % 100 === 0) {
                $em->flush();
            }
        }
        $em->flush();

        // 501st redirect attempt must be blocked by quota
        $payload = [
            'fromPath' => '/excess-redirect-501',
            'toPath' => '/destination-501',
            'httpCode' => 301,
        ];

        $client->request(
            'POST',
            "/admin-api/{$projectUuid}/redirects",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('PROJECT_QUOTA_EXCEEDED', $data['code'] ?? null);
        $this->assertStringContainsString('quota', strtolower($data['error'] ?? ''));
        $this->assertStringContainsString((string) RedirectController::MAX_REDIRECTS_PER_PROJECT, $data['error'] ?? '');
    }

    public function testRedirectAuditLoggingLifecycle(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // 1. Create Redirect -> logs admin_redirect_create
        $createPayload = [
            'fromPath' => '/source-audit',
            'toPath' => '/target-audit',
            'httpCode' => 301,
            'isEnabled' => true,
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($createPayload));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $created = json_decode($client->getResponse()->getContent(), true);
        $redirectId = $created['id'];

        $auditLogCreate = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_redirect_create',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogCreate);
        $this->assertSame('success', $auditLogCreate->status);
        $this->assertSame('/source-audit', $auditLogCreate->input['fromPath'] ?? null);

        // 2. Update Redirect -> logs admin_redirect_update
        $updatePayload = [
            'toPath' => '/target-audit-v2',
            'httpCode' => 308,
        ];
        $client->request('PUT', "/admin-api/{$projectUuid}/redirects/{$redirectId}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($updatePayload));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $auditLogUpdate = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_redirect_update',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogUpdate);
        $this->assertSame('success', $auditLogUpdate->status);
        $this->assertSame('/target-audit-v2', $auditLogUpdate->input['toPath'] ?? null);

        // 3. Toggle Redirect -> logs admin_redirect_toggle
        $client->request('POST', "/admin-api/{$projectUuid}/redirects/{$redirectId}/toggle");
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $auditLogToggle = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_redirect_toggle',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogToggle);
        $this->assertSame('success', $auditLogToggle->status);
        $this->assertFalse($auditLogToggle->output['isEnabled'] ?? true);

        // 4. Delete Redirect -> logs admin_redirect_delete
        $client->request('DELETE', "/admin-api/{$projectUuid}/redirects/{$redirectId}");
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        $auditLogDelete = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'admin_redirect_delete',
            'project' => $project,
        ], ['id' => 'DESC']);
        $this->assertNotNull($auditLogDelete);
        $this->assertSame('success', $auditLogDelete->status);
        $this->assertSame($redirectId, $auditLogDelete->input['id'] ?? null);
    }

    public function testRedirectAndFormSecurityHeaders(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // Form index endpoint security headers
        $client->request('GET', "/admin-api/{$projectUuid}/forms");
        $formResp = $client->getResponse();
        $this->assertSame(200, $formResp->getStatusCode());
        $this->assertSame('nosniff', $formResp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $formResp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $formResp->headers->get('Cache-Control'));

        // Redirect index endpoint security headers
        $client->request('GET', "/admin-api/{$projectUuid}/redirects");
        $redirectResp = $client->getResponse();
        $this->assertSame(200, $redirectResp->getStatusCode());
        $this->assertSame('nosniff', $redirectResp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $redirectResp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $redirectResp->headers->get('Cache-Control'));
    }

    public function testTranslationCompletenessAndSymmetryCycle97(): void
    {
        $en = require __DIR__ . '/../../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../../translations/messages.ar.php';

        $keys = [
            'form.admin.rate_limit_exceeded',
            'form.admin.project_quota_exceeded',
            'form.admin.export_rate_limit_exceeded',
            'redirect.admin.rate_limit_exceeded',
            'redirect.admin.project_quota_exceeded',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "Missing {$key} in EN");
            $this->assertArrayHasKey($key, $fr, "Missing {$key} in FR");
            $this->assertArrayHasKey($key, $es, "Missing {$key} in ES");
            $this->assertArrayHasKey($key, $ar, "Missing {$key} in AR");

            $this->assertNotEmpty($en[$key], "Empty {$key} in EN");
            $this->assertNotEmpty($fr[$key], "Empty {$key} in FR");
            $this->assertNotEmpty($es[$key], "Empty {$key} in ES");
            $this->assertNotEmpty($ar[$key], "Empty {$key} in AR");
        }

        // Exact count symmetry across all four dictionaries
        $this->assertSame(count($en), count($fr), 'Symmetry mismatch EN vs FR');
        $this->assertSame(count($en), count($es), 'Symmetry mismatch EN vs ES');
        $this->assertSame(count($en), count($ar), 'Symmetry mismatch EN vs AR');
    }
}
