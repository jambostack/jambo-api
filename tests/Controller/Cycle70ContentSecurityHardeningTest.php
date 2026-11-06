<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Field;
use App\Entity\Media;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Service\Security\HtmlContentSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class Cycle70ContentSecurityHardeningTest extends WebTestCase
{
    private function createAdmin(EntityManagerInterface $em): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->name = 'Cycle70 Super Admin';
        $user->email = 'c70_admin_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'Password123!');
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function createProject(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project();
        $project->name = 'Cycle70 Hardened Project ' . bin2hex(random_bytes(4));
        $project->defaultLocale = 'en';
        $project->locales = ['en', 'fr'];
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $owner;
        $member->email = $owner->email;
        $member->status = ProjectMemberStatus::Active;
        $em->persist($member);

        $em->flush();
        return $project;
    }

    private function createCollectionWithFields(EntityManagerInterface $em, Project $project, string $slug): Collection
    {
        $col = new Collection();
        $col->name = ucfirst($slug);
        $col->slug = $slug;
        $col->project = $project;
        $em->persist($col);

        $fTitle = new Field();
        $fTitle->name = 'Title';
        $fTitle->slug = 'title';
        $fTitle->type = 'text';
        $fTitle->isRequired = true;
        $fTitle->collection = $col;
        $col->fields->add($fTitle);
        $em->persist($fTitle);

        $fContent = new Field();
        $fContent->name = 'Content';
        $fContent->slug = 'content';
        $fContent->type = 'wysiwyg';
        $fContent->isRequired = false;
        $fContent->collection = $col;
        $col->fields->add($fContent);
        $em->persist($fContent);

        $fMedia = new Field();
        $fMedia->name = 'Cover';
        $fMedia->slug = 'cover';
        $fMedia->type = 'media';
        $fMedia->isRequired = false;
        $fMedia->collection = $col;
        $col->fields->add($fMedia);
        $em->persist($fMedia);

        $em->flush();
        return $col;
    }

    public function testHtmlSanitizerRemovesMaliciousTagsAndPreservesSafeJsonLd(): void
    {
        $sanitizer = new HtmlContentSanitizer();

        // 1. Script tag stripped
        $input = '<p>Hello <script>alert("xss")</script>World</p>';
        $output = $sanitizer->sanitize($input);
        $this->assertStringNotContainsString('<script>alert', $output);
        $this->assertStringContainsString('<p>Hello World</p>', $output);

        // 2. Iframe stripped
        $input = '<div>Check <iframe src="https://evil.com"></iframe> this</div>';
        $output = $sanitizer->sanitize($input);
        $this->assertStringNotContainsString('iframe', $output);

        // 3. Event handler stripped
        $input = '<img src="/valid.png" onerror="alert(1)" alt="test">';
        $output = $sanitizer->sanitize($input);
        $this->assertStringNotContainsString('onerror', $output);
        $this->assertStringContainsString('src="/valid.png"', $output);

        // 4. Javascript pseudo-protocol neutralized
        $input = '<a href="javascript:alert(1)">Click</a>';
        $output = $sanitizer->sanitize($input);
        $this->assertStringNotContainsString('javascript:', $output);
        $this->assertStringContainsString('href="#"', $output);

        // 5. Target _blank gets rel="noopener noreferrer"
        $input = '<a href="https://example.com" target="_blank">External</a>';
        $output = $sanitizer->sanitize($input);
        $this->assertStringContainsString('rel="noopener noreferrer"', $output);

        // 6. Safe JSON-LD preserved and escaped
        $input = '<p>Article</p><script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"Clean"}</script>';
        $output = $sanitizer->sanitize($input);
        $this->assertStringContainsString('type="application/ld+json"', $output);
        $this->assertStringContainsString('https://schema.org', $output);

        // 7. Malicious JSON-LD with breakout script sanitized safely
        $input = '<script type="application/ld+json">{"bad":"</script><script>alert(1)</script>"}</script>';
        $output = $sanitizer->sanitize($input);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $output);
        $this->assertTrue($sanitizer->isValidJsonLd($output));
    }

    public function testUnauthenticatedRequestsAreRejectedOnAllContentEndpoints(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $collection = $this->createCollectionWithFields($em, $project, 'posts');

        $baseUrl = '/api/projects/' . $project->uuid->toString() . '/collections/' . $collection->slug . '/entries';

        // 1. GET index without auth -> 401
        $client->request('GET', $baseUrl);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $body['code']);

        // 2. GET trash without auth -> 401
        $client->request('GET', $baseUrl . '/trash');
        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $body['code']);

        // 3. POST create without auth -> 401
        $client->jsonRequest('POST', $baseUrl, ['fields' => ['title' => 'Unauth']]);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $body['code']);

        // 4. GET show without auth -> 401
        $fakeUuid = Uuid::v4()->toString();
        $client->request('GET', $baseUrl . '/' . $fakeUuid);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $body['code']);

        // 5. PUT update without auth -> 401
        $client->jsonRequest('PUT', $baseUrl . '/' . $fakeUuid, ['fields' => ['title' => 'Unauth']]);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $body['code']);

        // 6. DELETE without auth -> 401
        $client->request('DELETE', $baseUrl . '/' . $fakeUuid);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $body['code']);
    }

    public function testGranularRbacPermissionsOnContentMutations(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $collection = $this->createCollectionWithFields($em, $project, 'docs');

        // Create restricted user with only project.view (no content.create, update, or delete)
        $restrictedUser = new User();
        $restrictedUser->name = 'Viewer Only';
        $restrictedUser->email = 'viewer_' . bin2hex(random_bytes(4)) . '@test.com';
        $restrictedUser->password = $hasher->hashPassword($restrictedUser, 'Password123!');
        $restrictedUser->roles = ['ROLE_USER'];
        $em->persist($restrictedUser);

        $roleViewer = new Role();
        $roleViewer->name = 'viewer_' . bin2hex(random_bytes(4));
        $roleViewer->label = 'Viewer';
        $em->persist($roleViewer);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $restrictedUser;
        $member->email = $restrictedUser->email;
        $member->status = ProjectMemberStatus::Active;
        $member->role = $roleViewer;
        $em->persist($member);

        $em->flush();

        $client->loginUser($restrictedUser);
        $baseUrl = '/api/projects/' . $project->uuid->toString() . '/collections/' . $collection->slug . '/entries';

        // 1. Viewer can list entries (project.view) -> 200
        $client->request('GET', $baseUrl);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // 2. Viewer cannot create content -> 403
        $client->jsonRequest('POST', $baseUrl, [
            'status' => 'draft',
            'fields' => ['title' => 'Forbidden Create'],
        ]);
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // Now grant content.create to roleViewer using current client container EM
        $clientEm = $client->getContainer()->get(EntityManagerInterface::class);
        $roleInDb = $clientEm->getRepository(Role::class)->find($roleViewer->id);
        $permCreate = $clientEm->getRepository(Permission::class)->findOneBy(['name' => 'content.create']);
        if (!$permCreate) {
            $permCreate = new Permission();
            $permCreate->name = 'content.create';
            $permCreate->label = 'Create Content';
            $permCreate->group = 'content';
            $clientEm->persist($permCreate);
        }
        $roleInDb->permissions->add($permCreate);
        $clientEm->flush();

        // 3. User can now create content -> 201
        $client->jsonRequest('POST', $baseUrl, [
            'status' => 'draft',
            'fields' => ['title' => 'Allowed Create'],
        ]);
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $createdBody = json_decode($client->getResponse()->getContent(), true);
        $entryUuid = $createdBody['data']['uuid'];

        // 4. But viewer still cannot update without content.update -> 403
        $client->jsonRequest('PUT', $baseUrl . '/' . $entryUuid, [
            'fields' => ['title' => 'Forbidden Update'],
        ]);
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 5. Nor soft-delete without content.delete or content.trash -> 403
        $client->request('DELETE', $baseUrl . '/' . $entryUuid);
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testPaginationCapsAndInvalidDateRangeValidation(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $collection = $this->createCollectionWithFields($em, $project, 'news');

        $client->loginUser($admin);
        $baseUrl = '/api/projects/' . $project->uuid->toString() . '/collections/' . $collection->slug . '/entries';

        // 1. Invalid date range (date_from > date_to) -> 422
        $client->request('GET', $baseUrl . '?date_from=2026-11-10&date_to=2026-11-01');
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_DATE_RANGE', $body['code']);

        // 2. Capped page: huge page param (e.g. 999999) is capped to 10000 without crashing
        $client->request('GET', $baseUrl . '?page=999999&per_page=500');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertLessThanOrEqual(10000, $body['current_page']);
        $this->assertLessThanOrEqual(100, $body['per_page']);
    }

    public function testMultiTenantRelationAndMediaValidation(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $projectA = $this->createProject($em, $admin);
        $projectB = $this->createProject($em, $admin);

        $colAuthorsA = $this->createCollectionWithFields($em, $projectA, 'authors_a');
        $colArticlesA = $this->createCollectionWithFields($em, $projectA, 'articles_a');

        $colAuthorsB = $this->createCollectionWithFields($em, $projectB, 'authors_b');

        // Add relation field from articles_a pointing to authors_a
        $relField = new Field();
        $relField->name = 'Author';
        $relField->slug = 'author';
        $relField->type = 'relation';
        $relField->options = [
            'relation' => [
                'type' => 1,
                'collection' => $colAuthorsA->id,
            ],
        ];
        $relField->collection = $colArticlesA;
        $colArticlesA->fields->add($relField);
        $em->persist($relField);

        // Add an author entry in Project B (other tenant)
        $entryAuthorB = new ContentEntry();
        $entryAuthorB->collection = $colAuthorsB;
        $entryAuthorB->project = $projectB;
        $entryAuthorB->locale = 'en';
        $entryAuthorB->status = 'published';
        $entryAuthorB->createdBy = $admin;
        $entryAuthorB->updatedBy = $admin;
        $em->persist($entryAuthorB);

        // Add a media in Project B (other tenant)
        $mediaB = new Media();
        $mediaB->uuid = Uuid::v4();
        $mediaB->project = $projectB;
        $mediaB->createdAt = new \DateTimeImmutable();
        $em->persist($mediaB);

        $em->flush();

        $client->loginUser($admin);
        $baseUrl = '/api/projects/' . $projectA->uuid->toString() . '/collections/' . $colArticlesA->slug . '/entries';

        // 1. Cross-tenant relation referencing author in Project B -> 422 VALIDATION_FAILED
        $client->jsonRequest('POST', $baseUrl, [
            'status' => 'draft',
            'fields' => [
                'title' => 'Article referencing tenant B author',
                'author' => $entryAuthorB->id,
            ],
        ]);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $body['code']);
        $this->assertArrayHasKey('fields.author', $body['errors']);

        // 2. Cross-tenant media referencing media in Project B -> 422 VALIDATION_FAILED
        $client->jsonRequest('POST', $baseUrl, [
            'status' => 'draft',
            'fields' => [
                'title' => 'Article referencing tenant B media',
                'cover' => $mediaB->uuid->toString(),
            ],
        ]);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $body['code']);
        $this->assertArrayHasKey('fields.cover', $body['errors']);
    }

    public function testFieldControllerValidationRulesHardening(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $collection = $this->createCollectionWithFields($em, $project, 'products');

        $client->loginUser($admin);
        $fieldsUrl = '/api/projects/' . $project->uuid->toString() . '/collections/' . $collection->slug . '/fields';

        // 1. Invalid options format in update -> 422
        $client->jsonRequest('PUT', $fieldsUrl . '/title', [
            'options' => 'not-an-array',
        ]);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_OPTIONS', $body['code']);

        // 2. Invalid validationRules format -> 422
        $client->jsonRequest('PUT', $fieldsUrl . '/title', [
            'validationRules' => 'not-an-array',
        ]);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_VALIDATION_RULES', $body['code']);

        // 3. Excessively long regex rule -> 422
        $client->jsonRequest('PUT', $fieldsUrl . '/title', [
            'validationRules' => [
                'regex' => '/' . str_repeat('a', 300) . '/',
            ],
        ]);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('REGEX_TOO_LONG', $body['code']);
    }

    public function testI18nParityForContentFieldCollectionV1_26_133(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $prefixes = ['content.admin.', 'field.admin.', 'collection.admin.'];

        foreach ($prefixes as $prefix) {
            $enKeys = array_filter(array_keys($en), fn(string $k) => str_starts_with($k, $prefix));
            $this->assertNotEmpty($enKeys, 'No keys found for prefix ' . $prefix);

            foreach ($enKeys as $key) {
                $this->assertArrayHasKey($key, $fr, "Missing key in messages.fr.php: $key");
                $this->assertArrayHasKey($key, $es, "Missing key in messages.es.php: $key");
                $this->assertArrayHasKey($key, $ar, "Missing key in messages.ar.php: $key");

                $this->assertNotEmpty($fr[$key], "Empty translation in messages.fr.php: $key");
                $this->assertNotEmpty($es[$key], "Empty translation in messages.es.php: $key");
                $this->assertNotEmpty($ar[$key], "Empty translation in messages.ar.php: $key");
            }
        }
    }
}
