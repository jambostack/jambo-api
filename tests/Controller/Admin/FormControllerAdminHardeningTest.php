<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class FormControllerAdminHardeningTest extends WebTestCase
{
    private function createAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Form Admin';
        $user->email = 'admin_form_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Form Project ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testCreateFormSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $payload = [
            'name' => 'Newsletter Sign-Up',
            'slug' => 'newsletter-sign-up',
            'fields' => [
                ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true],
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text'],
            ],
            'settings' => [
                'redirectUrl' => 'https://example.com/thank-you',
            ],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Newsletter Sign-Up', $data['name']);
        $this->assertSame('newsletter-sign-up', $data['slug']);
        $this->assertTrue($data['definition_valid']);
    }

    public function testCreateFormAntiXssNameBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $payload = [
            'name' => '<script>alert("xss")</script> Contact Form',
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
            ],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('HTML tags or script elements', $data['error']);
    }

    public function testCreateFormAntiXssFieldLabelBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $payload = [
            'name' => 'Feedback Form',
            'fields' => [
                ['name' => 'rating', 'label' => '<img src=x onerror=alert(1)>', 'type' => 'text'],
            ],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('anti-XSS', $data['error']);
    }

    public function testCreateFormInvalidFieldNameBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $payload = [
            'name' => 'Feedback Form',
            'fields' => [
                ['name' => 'user name!#', 'label' => 'Username', 'type' => 'text'],
            ],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('is invalid', $data['error']);
    }

    public function testCreateFormSettingsAntiXssBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $payload = [
            'name' => 'Contact Form',
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
            ],
            'settings' => [
                'successMessage' => '<iframe src="javascript:alert(1)"></iframe>',
            ],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('anti-XSS', $data['error']);
    }

    public function testCreateFormSettingsAntiSsrfRedirectUrlBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // 1. Private IP (SSRF)
        $payload1 = [
            'name' => 'Survey Form 1',
            'fields' => [['name' => 'q1', 'label' => 'Question', 'type' => 'text']],
            'settings' => ['redirectUrl' => 'http://127.0.0.1:8080/internal-callback'],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload1));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data1 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('redirect URL is unsafe', $data1['error']);

        // 2. Dangerous scheme
        $payload2 = [
            'name' => 'Survey Form 2',
            'fields' => [['name' => 'q1', 'label' => 'Question', 'type' => 'text']],
            'settings' => ['redirectUrl' => 'javascript:alert(document.cookie)'],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload2));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('redirect URL is unsafe', $data2['error']);
    }

    public function testUpdateFormSlugConflictAndAntiXss(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $form1 = new Form();
        $form1->project = $project;
        $form1->name = 'Form One';
        $form1->slug = 'form-one';
        $form1->fields = [['name' => 'f1', 'label' => 'Field 1', 'type' => 'text']];
        $em->persist($form1);

        $form2 = new Form();
        $form2->project = $project;
        $form2->name = 'Form Two';
        $form2->slug = 'form-two';
        $form2->fields = [['name' => 'f2', 'label' => 'Field 2', 'type' => 'text']];
        $em->persist($form2);

        $em->flush();

        // 1. Conflict on existing slug
        $client->request('PUT', "/admin-api/{$projectUuid}/forms/{$form2->id}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['slug' => 'form-one']));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('already exists in this project', $data['error']);

        // 2. Anti-XSS on new name
        $client->request('PUT', "/admin-api/{$projectUuid}/forms/{$form2->id}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => '<svg onload=alert(1)>']));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('disallowed HTML tags or script elements', $data['error']);
    }

    public function testSubmissionLifecycle(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $form = new Form();
        $form->project = $project;
        $form->name = 'Contact Form Lifecycle';
        $form->slug = 'contact-lifecycle';
        $form->fields = [['name' => 'message', 'label' => 'Message', 'type' => 'text']];
        $em->persist($form);

        $sub = new FormSubmission();
        $sub->form = $form;
        $sub->data = ['message' => 'Hello team'];
        $sub->isComplete = true;
        $sub->isRead = false;
        $sub->isSpam = false;
        $em->persist($sub);

        $em->flush();

        // Show submission
        $client->request('GET', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/{$sub->id}");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $subData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Hello team', $subData['data']['message']);
        $this->assertFalse($subData['isRead']);

        // Update submission
        $client->request('PATCH', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/{$sub->id}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['isRead' => true, 'isSpam' => true]));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $updatedData = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($updatedData['isRead']);
        $this->assertTrue($updatedData['isSpam']);

        // Update submission empty payload returns 422
        $client->request('PATCH', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/{$sub->id}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Delete submission
        $client->request('DELETE', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/{$sub->id}");
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        // Delete form
        $client->request('DELETE', "/admin-api/{$projectUuid}/forms/{$form->id}");
        $this->assertSame(204, $client->getResponse()->getStatusCode());
    }
}
