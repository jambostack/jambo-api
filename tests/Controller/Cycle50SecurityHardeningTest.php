<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class Cycle50SecurityHardeningTest extends WebTestCase
{
    private function createUserAndProject(string $role = 'ROLE_SUPER_ADMIN'): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'cycle50-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_50';
        $user->roles = [$role];
        $user->locale = 'en';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle50 Project ' . bin2hex(random_bytes(4));
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

    public function testTranslationParityAcrossAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        self::assertGreaterThanOrEqual(2797, count($en));
        self::assertCount(count($en), $fr, 'FR key count must match EN exactly');
        self::assertCount(count($en), $es, 'ES key count must match EN exactly');
        self::assertCount(count($en), $ar, 'AR key count must match EN exactly');

        $newKeys = [
            'direct_upload.intent_failed',
            'direct_upload.sign_part_failed',
            'direct_upload.complete_failed',
            'direct_upload.abort_failed',
            'direct_upload.cleanup_failed',
            'media.pixel_limit_exceeded',
            'media.source_too_large',
            'media.invalid_image_source',
            'media.dimensions_too_large',
            'studio.rate_limit_exceeded',
            'studio.project_not_found',
            'studio.invalid_json',
            'studio.prompt_required',
            'studio.no_actions_provided',
            'studio.collection_and_entry_required',
            'studio.collection_and_entries_required',
            'studio.collection_not_found',
            'studio.collection_required',
            'studio.uuids_and_patch_required',
            'studio.collection_and_locales_required',
            'studio.invalid_target_locales',
            'studio.no_source_entries_found',
            'studio.no_collections_provided',
            'studio.no_prompts_provided',
            'studio.image_generation_failed',
            'studio.ai_generation_failed',
            'studio.ai_json_parse_failed',
            'studio.end_user_email_required',
            'studio.end_users_created_note',
        ];

        foreach ($newKeys as $key) {
            self::assertArrayHasKey($key, $en, "Missing {$key} in EN");
            self::assertArrayHasKey($key, $fr, "Missing {$key} in FR");
            self::assertArrayHasKey($key, $es, "Missing {$key} in ES");
            self::assertArrayHasKey($key, $ar, "Missing {$key} in AR");
            self::assertNotEmpty($en[$key], "Empty {$key} in EN");
            self::assertNotEmpty($fr[$key], "Empty {$key} in FR");
            self::assertNotEmpty($es[$key], "Empty {$key} in ES");
            self::assertNotEmpty($ar[$key], "Empty {$key} in AR");
        }
    }

    public function testHealthControllerVersion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(\App\Controller\HealthController::VERSION, $data['version']);
    }

    public function testDirectUploadSecurityHeadersAndInvalidUuid(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // Invalid project UUID
        $client->request('POST', '/api/projects/invalid-uuid/media/direct-upload/intent', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $response = $client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testDirectUploadPayloadTooLarge(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // Payload > 512 KB
        $hugePayload = json_encode(['data' => str_repeat('A', 600000)]);
        $client->request(
            'POST',
            '/api/projects/' . $project->uuid->toRfc4122() . '/media/direct-upload/intent',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $hugePayload
        );
        $response = $client->getResponse();
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testDirectUploadSignPartNumberBounds(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // Invalid part number < 1
        $client->request(
            'POST',
            '/api/projects/' . $project->uuid->toRfc4122() . '/media/direct-upload/sign-part',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['intent_uuid' => '00000000-0000-0000-0000-000000000001', 'part_number' => 0])
        );
        $response = $client->getResponse();
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // Invalid part number > 10000
        $client->request(
            'POST',
            '/api/projects/' . $project->uuid->toRfc4122() . '/media/direct-upload/sign-part',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['intent_uuid' => '00000000-0000-0000-0000-000000000001', 'part_number' => 10001])
        );
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testImageTransformPixelLimitExceeded(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $project->publicApi = true;
        $client->loginUser($user);

        // Créer un Media factice
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($project);
        $media = new \App\Entity\Media();
        $media->project = $project;
        $media->fileName = 'test_sample.jpg';
        $media->mimeType = 'image/jpeg';
        $em->persist($media);
        $em->flush();

        // Créer le fichier source physique
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $mediaDir = $projectDir . '/public/uploads/media/' . $project->uuid->toRfc4122();
        @mkdir($mediaDir, 0755, true);
        $testFile = $mediaDir . '/test_sample.jpg';
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $testFile);
        imagedestroy($img);

        try {
            // Demande 4000x4000 = 16 MP (> 12 MP limit)
            $client->request('GET', '/cdn/media/' . $media->uuid->toRfc4122() . '?w=4000&h=4000');
            $response = $client->getResponse();
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertStringContainsString('12 MP', (string) $response->getContent());
        } finally {
            @unlink($testFile);
            @rmdir($mediaDir);
        }
    }

    public function testImageTransformInfoEndpointSecurityHeaders(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $project->publicApi = true;
        $client->loginUser($user);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($project);
        $media = new \App\Entity\Media();
        $media->project = $project;
        $media->fileName = 'info_sample.jpg';
        $media->mimeType = 'image/jpeg';
        $em->persist($media);
        $em->flush();

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $mediaDir = $projectDir . '/public/uploads/media/' . $project->uuid->toRfc4122();
        @mkdir($mediaDir, 0755, true);
        $testFile = $mediaDir . '/info_sample.jpg';
        $img = imagecreatetruecolor(50, 50);
        imagejpeg($img, $testFile);
        imagedestroy($img);

        try {
            $client->request('GET', '/cdn/media/' . $media->uuid->toRfc4122() . '/info');
            $response = $client->getResponse();
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $data = json_decode((string) $response->getContent(), true);
            self::assertSame(50, $data['width']);
            self::assertSame(50, $data['height']);
        } finally {
            @unlink($testFile);
            @rmdir($mediaDir);
        }
    }

    public function testPreviewValidateUrlAntiSsrf(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $urlEndpoint = '/api/projects/' . $project->uuid->toRfc4122() . '/preview/validate-url';

        // Rejette les adresses privées / SSRF
        $client->request('POST', $urlEndpoint, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => 'https://127.0.0.1/admin']));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        $client->request('POST', $urlEndpoint, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => 'https://169.254.169.254/metadata']));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Rejette les credentials dans l'URL
        $client->request('POST', $urlEndpoint, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => 'https://user:pass@example.com']));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Rejette les ports interdits
        $client->request('POST', $urlEndpoint, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => 'https://example.com:22/ssh']));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Accepte une URL publique valide
        $client->request('POST', $urlEndpoint, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => 'https://example.com/preview']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testPreviewTtlValidation(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $col = new Collection();
        $col->project = $project;
        $col->name = 'Posts';
        $col->slug = 'posts';
        $em->persist($col);

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $col;
        $entry->locale = 'en';
        $entry->status = 'draft';
        $em->persist($entry);
        $em->flush();

        $tokenUrl = '/api/projects/' . $project->uuid->toRfc4122() . '/preview/token/' . $entry->uuid->toRfc4122();

        // TTL trop court (< 60)
        $client->request('GET', $tokenUrl . '?ttl=30');
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // TTL trop long (> 86400)
        $client->request('GET', $tokenUrl . '?ttl=90000');
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // TTL non numérique
        $client->request('GET', $tokenUrl . '?ttl=abc');
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // TTL valide (3600)
        $client->request('GET', $tokenUrl . '?ttl=3600');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(3600, $data['expires_in']);
        self::assertNotEmpty($data['token']);
    }

    public function testStudioGenerateTypesTenantIsolation(): void
    {
        $client = static::createClient();
        [$user1, $project1] = $this->createUserAndProject('ROLE_USER');
        [$user2, $project2] = $this->createUserAndProject('ROLE_USER');

        // User1 tente d'accéder aux types du Project2 (non membre)
        $client->loginUser($user1);
        $client->request('GET', '/api/projects/' . $project2->uuid->toRfc4122() . '/studio/generate-types');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        // User2 accède légitimement à son projet
        $client->loginUser($user2);
        $client->request('GET', '/api/projects/' . $project2->uuid->toRfc4122() . '/studio/generate-types');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testStudioInvalidUuidRejected(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $client->request('GET', '/api/projects/invalid-uuid/studio/collections');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }
}
