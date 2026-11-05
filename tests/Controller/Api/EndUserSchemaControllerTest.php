<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\EndUserField;
use App\Entity\Project;
use App\Entity\User;
use App\Service\EndUserSchemaSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class EndUserSchemaControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $nonMember;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);
        $seeder = $this->client->getContainer()->get(EndUserSchemaSeeder::class);

        $suffix = bin2hex(random_bytes(4));

        $this->admin = new User();
        $this->admin->name = 'Schema Admin';
        $this->admin->email = "schema_admin_{$suffix}@test.com";
        $this->admin->password = $hasher->hashPassword($this->admin, 'AdminPass123!');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->nonMember = new User();
        $this->nonMember->name = 'Non Member';
        $this->nonMember->email = "nonmember_schema_{$suffix}@test.com";
        $this->nonMember->password = $hasher->hashPassword($this->nonMember, 'AdminPass123!');
        $this->nonMember->roles = ['ROLE_USER'];
        $this->em->persist($this->nonMember);

        $this->project = new Project();
        $this->project->name = "Schema Project {$suffix}";
        $this->em->persist($this->project);
        $this->em->flush();

        // Seed system fields for this project
        $seeder->seed($this->project);
        $this->em->flush();
    }

    public function testListFieldsReturnsSystemFields(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request('GET', "/api/projects/{$projectUuid}/end-users/fields");

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('data', $data);
        $this->assertGreaterThanOrEqual(4, count($data['data']));

        $slugs = array_column($data['data'], 'slug');
        $this->assertContains('email', $slugs);
        $this->assertContains('password', $slugs);
        $this->assertContains('status', $slugs);
    }

    public function testCreateCustomFieldSuccess(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/end-users/fields",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name'        => 'Bio Description',
                'type'        => 'text',
                'is_required' => false,
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('data', $data);
        $this->assertSame('bioDescription', $data['data']['name']);
        $this->assertSame('bio_description', $data['data']['slug']);
        $this->assertSame('text', $data['data']['type']);
        $this->assertFalse($data['data']['is_system']);
    }

    public function testCreateEnumerationFieldSuccess(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/end-users/fields",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name'    => 'Subscription Tier',
                'type'    => 'enumeration',
                'options' => [
                    'choices' => ['free', 'pro', 'enterprise'],
                ],
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('subscriptionTier', $data['data']['name']);
        $this->assertSame('subscription_tier', $data['data']['slug']);
        $this->assertSame(['free', 'pro', 'enterprise'], $data['data']['options']['choices']);
    }

    public function testCreateFieldRejectsUnsupportedType(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/end-users/fields",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Dangerous Field',
                'type' => 'malicious_code_type',
            ])
        );

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('type', $data['errors']);
    }

    public function testCreateFieldRejectsReservedSlug(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/end-users/fields",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Custom Password',
                'slug' => 'password',
                'type' => 'text',
            ])
        );

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('slug', $data['errors']);
    }

    public function testCreateFieldRejectsInvalidSlugFormat(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/end-users/fields",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Invalid Slug Test',
                'slug' => '123_invalid-slug!',
                'type' => 'text',
            ])
        );

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('slug', $data['errors']);
    }

    public function testCreateFieldRejectsNameTooLong(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/end-users/fields",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => str_repeat('a', 150),
                'type' => 'text',
            ])
        );

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('name', $data['errors']);
    }

    public function testSystemFieldCannotBeModifiedOrDeleted(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        // 1. Try modifying system field 'email'
        $this->client->request(
            'PATCH',
            "/api/projects/{$projectUuid}/end-users/fields/email",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Hacked Email'])
        );
        $this->assertResponseStatusCodeSame(403);

        // 2. Try deleting system field 'email'
        $this->client->request(
            'DELETE',
            "/api/projects/{$projectUuid}/end-users/fields/email"
        );
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUpdateAndDeleteCustomField(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        // 1. Create a custom field
        $field = new EndUserField();
        $field->project = $this->project;
        $field->name = 'phoneNumber';
        $field->slug = 'phone_number';
        $field->type = 'text';
        $field->order = 10;
        $field->isRequired = false;
        $field->isSystem = false;
        $this->em->persist($field);
        $this->em->flush();

        // 2. Update it
        $this->client->request(
            'PATCH',
            "/api/projects/{$projectUuid}/end-users/fields/phone_number",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name'        => 'Telephone Number',
                'is_required' => true,
                'order'       => 15,
            ])
        );
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('telephoneNumber', $data['data']['name']);
        $this->assertTrue($data['data']['required']);
        $this->assertSame(15, $data['data']['order']);

        // 3. Delete it
        $this->client->request(
            'DELETE',
            "/api/projects/{$projectUuid}/end-users/fields/phone_number"
        );
        $this->assertResponseStatusCodeSame(204);

        // Verify it was removed
        $deleted = $this->em->getRepository(EndUserField::class)->findOneBy([
            'project' => $this->project,
            'slug'    => 'phone_number',
        ]);
        $this->assertNull($deleted);
    }

    public function testReorderFields(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $repo = $this->em->getRepository(EndUserField::class);
        $emailField = $repo->findOneBy(['project' => $this->project, 'slug' => 'email']);
        $statusField = $repo->findOneBy(['project' => $this->project, 'slug' => 'status']);

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/end-users/fields/reorder",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fields' => [
                    ['id' => $emailField->id, 'order' => 5],
                    ['id' => $statusField->id, 'order' => 6],
                ],
            ])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        $this->em->clear();
        $updatedEmail = $repo->find($emailField->id);
        $this->assertSame(5, $updatedEmail->order);
    }

    public function testNonMemberCannotAccessSchema(): void
    {
        $this->client->loginUser($this->nonMember);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request('GET', "/api/projects/{$projectUuid}/end-users/fields");
        $this->assertResponseStatusCodeSame(403);
    }
}
