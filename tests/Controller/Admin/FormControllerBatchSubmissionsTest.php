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

class FormControllerBatchSubmissionsTest extends WebTestCase
{
    private function createAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Form Batch Admin';
        $user->email = 'admin_batch_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProjectAndForm(EntityManagerInterface $em): array
    {
        $project = new Project();
        $project->name = 'Batch Form Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $form = new Form();
        $form->project = $project;
        $form->name = 'Contact Form ' . bin2hex(random_bytes(3));
        $form->slug = 'contact-' . bin2hex(random_bytes(3));
        $form->fields = [
            ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
            ['name' => 'message', 'label' => 'Message', 'type' => 'text'],
        ];
        $em->persist($form);

        $submissions = [];
        for ($i = 1; $i <= 5; $i++) {
            $sub = new FormSubmission();
            $sub->form = $form;
            $sub->data = ['email' => "user{$i}@example.com", 'message' => "Hello {$i}"];
            $sub->isComplete = true;
            $sub->isSpam = ($i % 2 === 0); // 2 and 4 are spam
            $sub->isRead = false;
            $em->persist($sub);
            $submissions[] = $sub;
        }

        $em->flush();

        return [$project, $form, $submissions];
    }

    public function testSubmissionsFilterByReadStatus(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Project $project */
        /** @var Form $form */
        /** @var FormSubmission[] $submissions */
        [$project, $form, $submissions] = $this->createProjectAndForm($em);
        $projectUuid = $project->uuid->toString();

        // Mark one as read
        $submissions[0]->isRead = true;
        $em->flush();

        // Test unread filter
        $client->request('GET', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions?read=unread");
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(4, $data['meta']['total']);

        // Test read filter
        $client->request('GET', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions?read=read");
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['meta']['total']);
        $this->assertSame($submissions[0]->id, $data['data'][0]['id']);
    }

    public function testBatchMarkReadAndUnread(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$project, $form, $submissions] = $this->createProjectAndForm($em);
        $projectUuid = $project->uuid->toString();
        $ids = [$submissions[0]->id, $submissions[1]->id];

        // Batch mark as read
        $client->request('POST', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/batch", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'mark_read',
            'ids' => $ids,
        ]));
        $this->assertResponseIsSuccessful();
        $res = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($res['success']);
        $this->assertSame('mark_read', $res['action']);
        $this->assertSame(2, $res['affected']);

        // Verify in DB
        $em->clear();
        $sub0 = $em->find(FormSubmission::class, $submissions[0]->id);
        $sub1 = $em->find(FormSubmission::class, $submissions[1]->id);
        $this->assertTrue($sub0->isRead);
        $this->assertTrue($sub1->isRead);

        // Batch mark as unread
        $client->request('POST', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/batch", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'mark_unread',
            'ids' => [$submissions[0]->id],
        ]));
        $this->assertResponseIsSuccessful();
        $res = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $res['affected']);

        $em->clear();
        $sub0 = $em->find(FormSubmission::class, $submissions[0]->id);
        $this->assertFalse($sub0->isRead);
    }

    public function testBatchMarkSpamAndHam(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$project, $form, $submissions] = $this->createProjectAndForm($em);
        $projectUuid = $project->uuid->toString();

        // sub 0 was not spam initially, mark as spam
        $client->request('POST', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/batch", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'mark_spam',
            'ids' => [$submissions[0]->id],
        ]));
        $this->assertResponseIsSuccessful();

        $em->clear();
        $sub0 = $em->find(FormSubmission::class, $submissions[0]->id);
        $this->assertTrue($sub0->isSpam);

        // sub 1 was spam initially, mark as ham (valid)
        $client->request('POST', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/batch", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'mark_ham',
            'ids' => [$submissions[1]->id],
        ]));
        $this->assertResponseIsSuccessful();

        $em->clear();
        $sub1 = $em->find(FormSubmission::class, $submissions[1]->id);
        $this->assertFalse($sub1->isSpam);
    }

    public function testBatchDelete(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$project, $form, $submissions] = $this->createProjectAndForm($em);
        $projectUuid = $project->uuid->toString();
        $idsToDelete = [$submissions[0]->id, $submissions[1]->id];

        $client->request('POST', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/batch", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'delete',
            'ids' => $idsToDelete,
        ]));
        $this->assertResponseIsSuccessful();
        $res = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(2, $res['affected']);

        $em->clear();
        $this->assertNull($em->find(FormSubmission::class, $submissions[0]->id));
        $this->assertNull($em->find(FormSubmission::class, $submissions[1]->id));
        $this->assertNotNull($em->find(FormSubmission::class, $submissions[2]->id));
    }

    public function testBatchInvalidActionAndEmptyIds(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$project, $form, $submissions] = $this->createProjectAndForm($em);
        $projectUuid = $project->uuid->toString();

        // Invalid action
        $client->request('POST', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/batch", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'invalid_action',
            'ids' => [1],
        ]));
        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_BATCH_ACTION', $data['code']);

        // Empty IDs
        $client->request('POST', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/batch", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'mark_read',
            'ids' => [],
        ]));
        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('EMPTY_BATCH_IDS', $data['code']);
    }

    public function testExportCsvWithReadFilter(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$project, $form, $submissions] = $this->createProjectAndForm($em);
        $projectUuid = $project->uuid->toString();

        $client->request('GET', "/admin-api/{$projectUuid}/forms/{$form->id}/submissions/export-csv?read=unread&spam=ham");
        $this->assertResponseIsSuccessful();
        $this->assertStringStartsWith('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
    }
}
