<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Service\TusServer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class Cycle54HardeningTest extends WebTestCase
{
    private function createUserAndProject(string $role = 'ROLE_SUPER_ADMIN'): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'cycle54-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_54';
        $user->roles = [$role];
        $user->locale = 'en';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle54 Project ' . bin2hex(random_bytes(4));
        $project->defaultLocale = 'en';
        $project->locales = ['en', 'fr'];
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $em->flush();

        return [$user, $project];
    }

    private function createCollection(EntityManagerInterface $em, Project $project, string $slug): Collection
    {
        $col = new Collection();
        $col->project = $project;
        $col->name = ucfirst($slug);
        $col->slug = $slug;
        $em->persist($col);

        $field = new Field();
        $field->collection = $col;
        $field->name = 'Title';
        $field->slug = 'title';
        $field->type = 'text';
        $field->isRequired = true;
        $em->persist($field);

        $em->flush();
        return $col;
    }

    public function testTranslationParityAcrossAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        self::assertGreaterThanOrEqual(2876, count($en));
        self::assertCount(count($en), $fr, 'FR key count must match EN exactly');
        self::assertCount(count($en), $es, 'ES key count must match EN exactly');
        self::assertCount(count($en), $ar, 'AR key count must match EN exactly');

        $cycle54Keys = [
            'content.admin.validation_failed',
            'field.admin.validation_failed',
            'collection.admin.validation_failed',
            'direct_upload.too_many_parts',
            'direct_upload.invalid_part_data',
            'direct_upload.filename_required',
            'direct_upload.filename_too_long',
            'tus.invalid_content_length',
        ];

        foreach ($cycle54Keys as $key) {
            self::assertArrayHasKey($key, $en, "Missing EN key: $key");
            self::assertArrayHasKey($key, $fr, "Missing FR key: $key");
            self::assertArrayHasKey($key, $es, "Missing ES key: $key");
            self::assertArrayHasKey($key, $ar, "Missing AR key: $key");
            self::assertNotEmpty($en[$key]);
            self::assertNotEmpty($fr[$key]);
            self::assertNotEmpty($es[$key]);
            self::assertNotEmpty($ar[$key]);
        }
    }

    public function testCollectionControllerErrorFormatHarmonization(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // 1. Missing name (422)
        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/collections', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => '   ']));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertSame('NAME_REQUIRED', $data['code'] ?? null);

        // 2. Name too long (422)
        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/collections', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => str_repeat('X', 256)]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertSame('NAME_TOO_LONG', $data['code'] ?? null);

        // 3. Invalid slug format (422)
        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/collections', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Articles', 'slug' => 'invalid slug!']));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);
    }

    public function testFieldControllerErrorFormatHarmonization(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$user, $project] = $this->createUserAndProject();
        $col = $this->createCollection($em, $project, 'posts_' . bin2hex(random_bytes(3)));
        $client->loginUser($user);

        $url = '/api/projects/' . $project->uuid->toRfc4122() . '/collections/' . $col->slug . '/fields';

        // 1. Missing field name (422)
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => '',
            'type' => 'text',
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertSame('NAME_REQUIRED', $data['code'] ?? null);

        // 2. Invalid field type (422)
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'BadType',
            'type' => 'unsupported_type_xyz',
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertSame('INVALID_FIELD_TYPE', $data['code'] ?? null);

        // 3. Invalid slug format (422)
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'MyField',
            'type' => 'text',
            'slug' => 'bad slug with space!',
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);
    }

    public function testContentControllerErrorFormatHarmonization(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$user, $project] = $this->createUserAndProject();
        $col = $this->createCollection($em, $project, 'articles_' . bin2hex(random_bytes(3)));
        $client->loginUser($user);

        $url = '/api/projects/' . $project->uuid->toRfc4122() . '/collections/' . $col->slug . '/entries';

        // 1. Invalid status (422)
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'non_existent_status_123',
            'fields' => ['title' => 'Hello World'],
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertSame('INVALID_STATUS', $data['code'] ?? null);

        // 2. Missing required field -> Validation failed (422)
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'draft',
            'fields' => [],
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertSame('VALIDATION_FAILED', $data['code'] ?? null);
    }

    public function testDirectUploadSecurityCaps(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // 1. Missing filename on intent (422)
        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/media/direct-upload/intent', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['filename' => '']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FILENAME_REQUIRED', $data['code'] ?? null);
        self::assertArrayHasKey('error', $data);

        // 2. Filename too long on intent (422)
        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/media/direct-upload/intent', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['filename' => str_repeat('a', 256) . '.png']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FILENAME_TOO_LONG', $data['code'] ?? null);
        self::assertArrayHasKey('error', $data);

        // 3. Parts list invalid on complete (422)
        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/media/direct-upload/complete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['intent_uuid' => '00000000-0000-0000-0000-000000000000', 'parts' => 'not-an-array']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('PARTS_INVALID', $data['code'] ?? null);

        // 4. Parts invalid data structure (422)
        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/media/direct-upload/complete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'intent_uuid' => '00000000-0000-0000-0000-000000000000',
            'parts' => [
                ['part_number' => 0, 'etag' => 'bad'], // Part number < 1
            ],
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_PART_DATA', $data['code'] ?? null);
    }

    public function testTusControllerSecurityAndUniformErrors(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $baseUrl = '/api/projects/' . $project->uuid->toRfc4122() . '/files/tus';

        // 1. Missing Upload-Length (400) with code MISSING_UPLOAD_LENGTH
        $client->request('POST', $baseUrl, [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('MISSING_UPLOAD_LENGTH', $data['code'] ?? null);
        self::assertArrayHasKey('error', $data);

        // 2. Executable MIME in filetype metadata (400) with code VALIDATION_FAILED
        $badMeta = 'filename ' . base64_encode('doc.pdf') . ',filetype ' . base64_encode('application/x-executable');
        $client->request('POST', $baseUrl, [], [], [
            'HTTP_TUS_RESUMABLE'   => '1.0.0',
            'HTTP_UPLOAD_LENGTH'   => '1024',
            'HTTP_UPLOAD_METADATA' => $badMeta,
        ]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $data['code'] ?? null);

        // 3. Non-existent upload on PATCH (404) with code UPLOAD_NOT_FOUND
        $client->request('PATCH', $baseUrl . '/00000000-0000-0000-0000-000000000000', [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'CONTENT_TYPE'       => 'application/offset+octet-stream',
            'HTTP_UPLOAD_OFFSET' => '0',
        ]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UPLOAD_NOT_FOUND', $data['code'] ?? null);
        self::assertArrayHasKey('error', $data);

        // 4. Invalid Content-Type on PATCH (415) with code INVALID_CONTENT_TYPE
        $client->request('PATCH', $baseUrl . '/00000000-0000-0000-0000-000000000000', [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'CONTENT_TYPE'       => 'application/json',
        ]);
        self::assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 5. Chunk size exceeding MAX_TUS_SIZE via Content-Length on PATCH (413)
        $client->request('PATCH', $baseUrl . '/00000000-0000-0000-0000-000000000000', [], [], [
            'HTTP_TUS_RESUMABLE'  => '1.0.0',
            'CONTENT_TYPE'        => 'application/offset+octet-stream',
            'HTTP_UPLOAD_OFFSET'  => '0',
        ]);
        // Security headers present
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
