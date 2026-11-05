<?php

namespace App\Tests\Controller;

use App\Entity\MediaFolder;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class Cycle49SecurityHardeningTest extends WebTestCase
{
    private function createUserAndProject(string $role = 'ROLE_SUPER_ADMIN'): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'cycle49-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_49';
        $user->roles = [$role];
        $user->locale = 'en';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle49 Project ' . bin2hex(random_bytes(4));
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

        self::assertGreaterThanOrEqual(2768, count($en));
        self::assertCount(count($en), $fr, 'FR key count must match EN exactly');
        self::assertCount(count($en), $es, 'ES key count must match EN exactly');
        self::assertCount(count($en), $ar, 'AR key count must match EN exactly');

        $newKeys = [
            'media.rate_limit_exceeded',
            'media.alt_control_chars',
            'media.caption_control_chars',
            'media_folder.rate_limit_exceeded',
            'media_folder.max_folders_exceeded',
            'tus.rate_limit_exceeded',
            'tus.chunk_out_of_bounds',
            'tus.conflict',
            'tus.runtime_error',
            'tus.validation_failed',
            'tus.filename_control_chars',
            'seo.rate_limit_exceeded',
            'form.embed.loading',
            'form.embed.submit',
            'form.embed.submitting',
            'form.embed.success',
            'form.embed.error_prefix',
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
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(\App\Controller\HealthController::VERSION, $data['version']);
    }

    public function testEmbedWidgetSecurityHeadersAndI18n(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forms/embed.js');
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('application/javascript', (string) $response->headers->get('Content-Type'));
        $content = $response->getContent();
        self::assertStringContainsString('var I18N =', $content);
        self::assertStringContainsString('Loading form', $content);
    }

    public function testMediaFolderControlCharactersRejected(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid->toRfc4122() . '/media-folders',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => "Bad\x00Folder"])
        );
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testMediaFolderMaxQuotaExceeded(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        for ($i = 0; $i < 200; $i++) {
            $folder = new MediaFolder();
            $folder->name = 'Folder ' . $i;
            $folder->slug = 'folder-' . $i;
            $folder->project = $project;
            $em->persist($folder);
        }
        $em->flush();

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid->toRfc4122() . '/media-folders',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Overflow Folder'])
        );
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('MAX_FOLDERS_EXCEEDED', $data['code'] ?? null);
    }

    public function testMediaUpdateControlCharactersRejected(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $media = new \App\Entity\Media();
        $media->project = $project;
        $media->fileName = 'test.jpg';
        $media->originalName = 'test.jpg';
        $media->mimeType = 'image/jpeg';
        $media->fileSize = 1024;
        $em->persist($media);
        $em->flush();

        $client->request(
            'PATCH',
            '/api/projects/' . $project->uuid->toRfc4122() . '/media/' . $media->uuid->toRfc4122(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['alt' => "Alt with \x08 control char"])
        );
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testTusCreateFilenameControlCharactersRejected(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $badMetadata = 'filename ' . base64_encode("test\x00file.png") . ',filetype ' . base64_encode('image/png');

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid->toRfc4122() . '/files/tus',
            [],
            [],
            [
                'HTTP_Tus-Resumable' => '1.0.0',
                'HTTP_Upload-Length' => '1024',
                'HTTP_Upload-Metadata' => $badMetadata,
            ]
        );
        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testSeoPublicSitemapSecurityHeaders(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();

        $client->request('GET', '/api/projects/' . $project->uuid->toRfc4122() . '/robots.txt');
        self::assertResponseIsSuccessful();
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }
}
