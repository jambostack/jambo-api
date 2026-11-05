<?php

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * v1.26.89 — CollectionController & ContentController Hardening Test Suite:
 * - Granular RBAC permissions
 * - Input length bounds & type safety
 * - Anti-SSRF / URL scheme validation
 * - JSON depth and payload size limits
 * - Strict I18N in 4 languages
 * - Audit trail verification
 */
class ContentAndCollectionHardeningTest extends WebTestCase
{
    private function createAdmin(EntityManagerInterface $em): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->name = 'Super Admin';
        $user->email = 'sa_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'AdminPass123!');
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function createProject(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project();
        $project->name = 'Hardened ' . bin2hex(random_bytes(4));
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

    private function createCollectionWithFields(EntityManagerInterface $em, Project $project, string $slug, bool $isSingleton = false): Collection
    {
        $col = new Collection();
        $col->name = ucfirst($slug);
        $col->slug = $slug;
        $col->project = $project;
        $col->isSingleton = $isSingleton;
        $em->persist($col);

        $fTitle = new Field();
        $fTitle->name = 'Title';
        $fTitle->slug = 'title';
        $fTitle->type = 'text';
        $fTitle->isRequired = true;
        $fTitle->collection = $col;
        $col->fields->add($fTitle);
        $em->persist($fTitle);

        $fUrl = new Field();
        $fUrl->name = 'Website';
        $fUrl->slug = 'website';
        $fUrl->type = 'url';
        $fUrl->isRequired = false;
        $fUrl->collection = $col;
        $col->fields->add($fUrl);
        $em->persist($fUrl);

        $fJson = new Field();
        $fJson->name = 'Metadata';
        $fJson->slug = 'metadata';
        $fJson->type = 'json';
        $fJson->isRequired = false;
        $fJson->collection = $col;
        $col->fields->add($fJson);
        $em->persist($fJson);

        $fUnique = new Field();
        $fUnique->name = 'Code';
        $fUnique->slug = 'code';
        $fUnique->type = 'text';
        $fUnique->isRequired = false;
        $fUnique->validationRules = ['unique' => true];
        $fUnique->collection = $col;
        $col->fields->add($fUnique);
        $em->persist($fUnique);

        $em->flush();
        return $col;
    }

    // =========================================================================
    // COLLECTION CONTROLLER TESTS
    // =========================================================================

    public function testCreateCollectionNameRequired(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $client->loginUser($admin);

        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/collections', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => '   ']));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('name', $data['errors']);
    }

    public function testCreateCollectionNameTooLong(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $client->loginUser($admin);

        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/collections', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => str_repeat('A', 256)]));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('name', $data['errors']);
    }

    public function testCreateCollectionInvalidSlugFormat(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $client->loginUser($admin);

        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/collections', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Valid Name', 'slug' => 'invalid slug with spaces!']));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('slug', $data['errors']);
    }

    public function testUpdateCollectionDuplicateSlug(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col1 = $this->createCollectionWithFields($em, $project, 'col_one_' . bin2hex(random_bytes(3)));
        $col2 = $this->createCollectionWithFields($em, $project, 'col_two_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $client->request('PUT', '/api/projects/' . $project->uuid->toString() . '/collections/' . $col2->slug, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['slug' => $col1->slug]));

        $this->assertSame(409, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('slug', $data['errors']);
    }

    public function testReorderCollectionsValidation(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $client->loginUser($admin);

        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/collections/reorder', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['collections' => 'not an array']));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    // =========================================================================
    // CONTENT CONTROLLER TESTS
    // =========================================================================

    public function testContentInvalidStatus(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'posts_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'status' => 'malicious_nonexistent_status',
            'fields' => ['title' => 'Sample'],
        ]));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('status', $data['errors']);
    }

    public function testContentLocaleNotEnabled(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'posts_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'locale' => 'zh-CN', // Not in ['en', 'fr']
            'fields' => ['title' => 'Sample'],
        ]));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testContentSingletonConstraint(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'config_' . bin2hex(random_bytes(3)), isSingleton: true);
        $client->loginUser($admin);

        $url = '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries';

        // 1st entry -> 201
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['title' => 'Singleton 1'],
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // 2nd entry -> 409
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['title' => 'Singleton 2'],
        ]));
        $this->assertSame(409, $client->getResponse()->getStatusCode());
    }

    public function testContentTextFieldTooLong(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'posts_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $url = '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries';

        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['title' => str_repeat('T', 65536)],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('fields.title', $data['errors']);
    }

    public function testContentUrlFieldAntiSsrfAndScheme(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'posts_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $url = '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries';

        // Dangerous javascript: URI
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['title' => 'Post', 'website' => 'javascript:alert(1)'],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('fields.website', $data['errors']);
    }

    public function testContentJsonFieldDepthLimit(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'posts_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $url = '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries';

        // Deeply nested JSON > 10 levels
        $deep = ['a' => 1];
        for ($i = 0; $i < 12; $i++) {
            $deep = ['nested' => $deep];
        }

        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['title' => 'Post', 'metadata' => $deep],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('fields.metadata', $data['errors']);
    }

    public function testContentUniqueFieldConstraint(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'items_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $url = '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries';

        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['title' => 'Item 1', 'code' => 'UNIQUE_CODE_ABC'],
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['title' => 'Item 2', 'code' => 'UNIQUE_CODE_ABC'],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('fields.code', $data['errors']);
    }

    public function testContentSearchQueryTooLong(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'items_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $url = '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries?search=' . str_repeat('X', 256);
        $client->request('GET', $url);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testContentI18nLocalizedError(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'items_' . bin2hex(random_bytes(3)));

        // Request in French
        $admin->locale = 'fr';
        $em->flush();
        $client->loginUser($admin);

        $url = '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries';

        $client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => 'fr',
        ], json_encode(['status' => 'invalid_status']));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('Statut invalide pour cette collection.', $data['errors']['status']);

        // Request in Spanish
        $admin->locale = 'es';
        $em->flush();
        $client->loginUser($admin);

        $client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => 'es',
        ], json_encode(['status' => 'invalid_status']));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Estado no válido para esta colección.', $data['errors']['status']);
    }

    public function testAuditTrailLoggedOnContentCreation(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollectionWithFields($em, $project, 'audited_' . bin2hex(random_bytes(3)));
        $client->loginUser($admin);

        $url = '/api/projects/' . $project->uuid->toString() . '/collections/' . $col->slug . '/entries';

        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => ['title' => 'Audited Post'],
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'content_create']);
        $this->assertNotNull($auditLog);
        $this->assertSame('success', $auditLog->status);
        $this->assertSame('content_management', $auditLog->source);
    }

    public function testCollectionAndContentGranularRbac(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);

        // Create standard user who is not a member of project
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $outsider = new User();
        $outsider->name = 'Outsider';
        $outsider->email = 'out_' . bin2hex(random_bytes(4)) . '@test.com';
        $outsider->password = $hasher->hashPassword($outsider, 'Pass123!');
        $outsider->roles = ['ROLE_USER'];
        $em->persist($outsider);
        $em->flush();

        $client->loginUser($outsider);

        // Outsider cannot access project collections
        $client->request('GET', '/api/projects/' . $project->uuid->toString() . '/collections');
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // Outsider cannot create collection
        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/collections', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Hacked Collection']));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCollectionAndContentI18nParityV1_26_89(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $prefixes = ['collection.admin.', 'content.admin.'];

        foreach ($prefixes as $prefix) {
            $enKeys = array_keys(array_filter($en, fn ($v, $k) => str_starts_with($k, $prefix), ARRAY_FILTER_USE_BOTH));
            $this->assertNotEmpty($enKeys, "No keys found for prefix: $prefix");

            foreach ($enKeys as $key) {
                $this->assertArrayHasKey($key, $fr, "Missing FR key: $key");
                $this->assertArrayHasKey($key, $es, "Missing ES key: $key");
                $this->assertArrayHasKey($key, $ar, "Missing AR key: $key");
                $this->assertNotEmpty($en[$key], "Empty EN value: $key");
                $this->assertNotEmpty($fr[$key], "Empty FR value: $key");
                $this->assertNotEmpty($es[$key], "Empty ES value: $key");
                $this->assertNotEmpty($ar[$key], "Empty AR value: $key");
            }
        }
    }
}
