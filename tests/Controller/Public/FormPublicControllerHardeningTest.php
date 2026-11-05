<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FormPublicControllerHardeningTest extends WebTestCase
{
    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Form Public Security ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function createForm(EntityManagerInterface $em, Project $project, string $slug, array $fields = [], array $settings = []): Form
    {
        $form = new Form();
        $form->project = $project;
        $form->name = 'Test Form ' . $slug;
        $form->slug = $slug;
        $form->fields = !empty($fields) ? $fields : [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ];
        $form->settings = $settings;

        $em->persist($form);
        $em->flush();

        return $form;
    }

    public function testSubmitToInactiveFormReturns403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'inactive-form', [], ['active' => false]);

        $projectUuid = $project->uuid->toString();
        $client->request(
            'POST',
            "/{$projectUuid}/forms/{$form->slug}/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Alice', 'email' => 'alice@example.com'])
        );

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('FORM_INACTIVE', $data['code']);
    }

    public function testSubmitToExpiredFormReturns403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $yesterday = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);
        $form = $this->createForm($em, $project, 'expired-form', [], ['closes_at' => $yesterday]);

        $projectUuid = $project->uuid->toString();
        $client->request(
            'POST',
            "/{$projectUuid}/forms/{$form->slug}/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Bob', 'email' => 'bob@example.com'])
        );

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('FORM_EXPIRED', $data['code']);
    }

    public function testSubmitToMaxSubmissionsReachedReturns403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'capped-form', [], ['max_submissions' => 1]);

        $sub = new FormSubmission();
        $sub->form = $form;
        $sub->data = ['name' => 'Existing', 'email' => 'existing@example.com'];
        $em->persist($sub);
        $em->flush();

        $projectUuid = $project->uuid->toString();
        $client->request(
            'POST',
            "/{$projectUuid}/forms/{$form->slug}/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Second', 'email' => 'second@example.com'])
        );

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('FORM_LIMIT_REACHED', $data['code']);
    }

    public function testSubmitInvalidFieldNameRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'valid-form');

        $projectUuid = $project->uuid->toString();
        $client->request(
            'POST',
            "/{$projectUuid}/forms/{$form->slug}/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['<script>evil</script>' => 'value'])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_FIELD_NAME', $data['code']);
    }

    public function testSubmitNullByteInValueRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'null-byte-form');

        $projectUuid = $project->uuid->toString();
        $client->request(
            'POST',
            "/{$projectUuid}/forms/{$form->slug}/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => "John\0Doe", 'email' => 'john@example.com'])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_ENCODING', $data['code']);
    }

    public function testSubmitExcessiveNestingRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'nesting-form');

        $deepPayload = ['lvl1' => ['lvl2' => ['lvl3' => ['lvl4' => ['lvl5' => 'deep']]]]];

        $projectUuid = $project->uuid->toString();
        $client->request(
            'POST',
            "/{$projectUuid}/forms/{$form->slug}/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($deepPayload)
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_PAYLOAD_STRUCTURE', $data['code']);
    }

    public function testSubmitSuccessfulStandardPayload(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'standard-form');

        $projectUuid = $project->uuid->toString();
        $client->request(
            'POST',
            "/{$projectUuid}/forms/{$form->slug}/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Charlie', 'email' => 'charlie@example.com'])
        );

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('submission_id', $data);
    }
}
