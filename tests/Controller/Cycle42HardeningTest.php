<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle42HardeningTest extends WebTestCase
{
    private function createAdmin(EntityManagerInterface $em): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->name = 'Super Admin Cycle 42';
        $user->email = 'sa_cycle42_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'AdminPass123!');
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function createProject(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project();
        $project->name = 'Cycle 42 Project ' . bin2hex(random_bytes(4));
        $project->defaultLocale = 'en';
        $project->locales = ['en', 'fr'];
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $owner;
        $member->status = ProjectMemberStatus::Active;
        $em->persist($member);

        $em->flush();
        return $project;
    }

    private function createCollection(EntityManagerInterface $em, Project $project, string $slug): Collection
    {
        $col = new Collection();
        $col->name = ucfirst($slug);
        $col->slug = $slug;
        $col->project = $project;
        $em->persist($col);
        $em->flush();
        return $col;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. FieldController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testFieldControllerInvalidProjectUuidReturns404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        $client->request('GET', '/api/projects/not-a-valid-uuid/collections/posts/fields');
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code'] ?? null);
        $this->assertNotEmpty($data['error']);
    }

    public function testFieldControllerAuthRequired(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'articles');

        $client->request('GET', sprintf('/api/projects/%s/collections/%s/fields', $project->uuid->toRfc4122(), $col->slug));
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $data['code'] ?? null);
    }

    public function testFieldControllerPayloadTooLargeReturns413(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'posts');

        $hugePayload = json_encode([
            'name' => 'BigField',
            'type' => 'text',
            'padding' => str_repeat('X', 600000),
        ]);

        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections/%s/fields', $project->uuid->toRfc4122(), $col->slug),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $hugePayload
        );

        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testFieldControllerCreateValidation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'news');

        $url = sprintf('/api/projects/%s/collections/%s/fields', $project->uuid->toRfc4122(), $col->slug);

        // 1. Missing name
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['type' => 'text']));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('NAME_REQUIRED', $data['code'] ?? null);

        // 2. Name too long (> 255)
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => str_repeat('a', 256), 'type' => 'text']));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('NAME_TOO_LONG', $data['code'] ?? null);

        // 3. Missing type
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Summary']));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('TYPE_REQUIRED', $data['code'] ?? null);

        // 4. Invalid field type
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Summary', 'type' => 'malicious_sql_type']));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_FIELD_TYPE', $data['code'] ?? null);

        // 5. Invalid slug format
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Summary', 'type' => 'text', 'slug' => 'invalid slug!@#']));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);

        // 6. Options depth too deep (> 6)
        $deepArray = [1 => [2 => [3 => [4 => [5 => [6 => [7 => 'too_deep']]]]]]];
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'DeepField', 'type' => 'json', 'options' => $deepArray]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('OPTIONS_TOO_DEEP', $data['code'] ?? null);

        // 7. Successful creation & audit logging
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Headline', 'type' => 'text', 'is_required' => true]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $res = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertNotEmpty($res['data']['id']);
        $this->assertSame('text', $res['data']['type']);

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'field_create', 'project' => $project]);
        $this->assertNotNull($auditLog);
    }

    public function testFieldControllerReorderAndSoftDelete(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'pages');

        $f1 = new Field();
        $f1->name = 'Title';
        $f1->slug = 'title';
        $f1->type = 'text';
        $f1->collection = $col;
        $f1->order = 1;
        $em->persist($f1);

        $f2 = new Field();
        $f2->name = 'Body';
        $f2->slug = 'body';
        $f2->type = 'richtext';
        $f2->collection = $col;
        $f2->order = 2;
        $em->persist($f2);

        $em->flush();

        // 1. Invalid reorder payload (not an array)
        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections/%s/fields/reorder', $project->uuid->toRfc4122(), $col->slug),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['fields' => 'not-an-array'])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_REORDER_LIST', $data['code'] ?? null);

        // 2. Valid reorder
        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections/%s/fields/reorder', $project->uuid->toRfc4122(), $col->slug),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['fields' => [
                ['id' => $f1->id, 'order' => 2],
                ['id' => $f2->id, 'order' => 1],
            ]])
        );
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $freshF1 = $freshEm->getRepository(Field::class)->find($f1->id);
        $freshF2 = $freshEm->getRepository(Field::class)->find($f2->id);
        $this->assertSame(2, $freshF1->order);
        $this->assertSame(1, $freshF2->order);

        // 3. Soft delete
        $client->request(
            'DELETE',
            sprintf('/api/projects/%s/collections/%s/fields/%s', $project->uuid->toRfc4122(), $col->slug, $f1->slug)
        );
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        $deleteEm = static::getContainer()->get(EntityManagerInterface::class);
        $deletedF1 = $deleteEm->getRepository(Field::class)->find($f1->id);
        $this->assertNotNull($deletedF1->deletedAt);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. CollectionController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testCollectionControllerBoundsAndReorder(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);
        $project = $this->createProject($em, $admin);

        // Invalid UUID format
        $client->request('GET', '/api/projects/invalid-uuid-1234/collections');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code'] ?? null);

        // Payload too large
        $huge = json_encode(['name' => 'Col', 'description' => str_repeat('D', 600000)]);
        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections', $project->uuid->toRfc4122()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $huge
        );
        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);

        // Reorder invalid list
        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections/reorder', $project->uuid->toRfc4122()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['collections' => 'invalid'])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('REORDER_INVALID_LIST', $data['code'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. ContentController Hardening Tests (Sort Whitelisting, Date Filtering, Bounds)
    // ─────────────────────────────────────────────────────────────────────────

    public function testContentControllerSortAndDateValidation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'blog');

        $baseUrl = sprintf('/api/projects/%s/collections/%s/entries', $project->uuid->toRfc4122(), $col->slug);

        // 1. Invalid sort clause (injected SQL or disallowed field)
        $client->request('GET', $baseUrl . '?sort=credit_card_number:asc');
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SORT_CLAUSE', $data['code'] ?? null);

        // 2. Invalid date_from filter (garbage string)
        $client->request('GET', $baseUrl . '?date_from=not-a-date');
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_DATE_FILTER', $data['code'] ?? null);

        // 3. Valid sort and valid date filters
        $client->request('GET', $baseUrl . '?sort=created_at:desc&date_from=2026-01-01&date_to=2026-12-31');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);

        // 4. Content payload too large (> 1MB)
        $hugeContent = json_encode(['fields' => ['text' => str_repeat('A', 1200000)]]);
        $client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], $hugeContent);
        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testContentControllerFieldTypesValidation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'products');

        $fEmail = new Field();
        $fEmail->name = 'Contact';
        $fEmail->slug = 'contact_email';
        $fEmail->type = 'email';
        $fEmail->collection = $col;
        $em->persist($fEmail);

        $fPrice = new Field();
        $fPrice->name = 'Price';
        $fPrice->slug = 'price';
        $fPrice->type = 'number';
        $fPrice->collection = $col;
        $em->persist($fPrice);

        $em->flush();

        $baseUrl = sprintf('/api/projects/%s/collections/%s/entries', $project->uuid->toRfc4122(), $col->slug);

        // Invalid email format
        $client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['contact_email' => 'invalid-email-address', 'price' => 10],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $data['code'] ?? null);
        $this->assertArrayHasKey('fields.contact_email', $data['errors']);

        // Invalid number format
        $client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['contact_email' => 'valid@test.com', 'price' => 'not-a-number'],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $data['code'] ?? null);
        $this->assertArrayHasKey('fields.price', $data['errors']);

        // Valid creation
        $client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['contact_email' => 'valid@test.com', 'price' => 49.99],
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Strict I18N Parity Test
    // ─────────────────────────────────────────────────────────────────────────

    public function testStrictI18nParityAcrossFourLanguages(): void
    {
        $baseDir = self::getContainer()->getParameter('kernel.project_dir');
        $en = require $baseDir . '/translations/messages.en.php';
        $fr = require $baseDir . '/translations/messages.fr.php';
        $es = require $baseDir . '/translations/messages.es.php';
        $ar = require $baseDir . '/translations/messages.ar.php';

        $this->assertSame(count($en), count($fr), 'FR count must match EN');
        $this->assertSame(count($en), count($es), 'ES count must match EN');
        $this->assertSame(count($en), count($ar), 'AR count must match EN');
        $this->assertGreaterThanOrEqual(2516, count($en), 'Must have at least 2516 keys');

        $this->assertEmpty(array_diff(array_keys($en), array_keys($fr)), 'Missing keys in FR');
        $this->assertEmpty(array_diff(array_keys($en), array_keys($es)), 'Missing keys in ES');
        $this->assertEmpty(array_diff(array_keys($en), array_keys($ar)), 'Missing keys in AR');
    }
}
