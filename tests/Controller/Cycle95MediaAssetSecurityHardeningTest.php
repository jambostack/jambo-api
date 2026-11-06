<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Media;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class Cycle95MediaAssetSecurityHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private const NEW_TRANSLATION_KEYS = [
        'upload.error.script_injection_detected',
        'upload.error.corrupted_avif',
        'upload.error.corrupted_bmp',
        'upload.error.corrupted_ico',
        'upload.error.corrupted_tiff',
        'upload.error.corrupted_mp4',
        'upload.error.corrupted_matroska',
        'upload.error.corrupted_avi',
        'upload.error.corrupted_wav',
        'upload.error.corrupted_ogg',
        'upload.error.corrupted_flac',
        'upload.error.corrupted_mp3',
        'upload.error.corrupted_archive',
        'upload.error.corrupted_gzip',
        'upload.error.corrupted_7z',
        'upload.error.mime_mismatch_image',
        'upload.error.mime_mismatch_audio',
        'upload.error.mime_mismatch_video',
        'upload.error.mime_mismatch_pdf',
        'upload.error.server_disk_full',
        'media.quarantined',
        'cli.prune_orphans.title',
        'cli.prune_orphans.dry_run_mode',
        'cli.prune_orphans.cleaning_intents',
        'cli.prune_orphans.success',
        'cli.prune_orphans.purging_soft_deleted',
        'cli.prune_orphans.scanning_disk',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->name = 'Cycle95 Admin';
        $this->admin->email = 'admin_c95_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secure123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->member = new User();
        $this->member->name = 'Cycle95 Member';
        $this->member->email = 'member_c95_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->member->password = $hasher->hashPassword($this->member, 'secure123');
        $this->member->roles = ['ROLE_USER'];
        $this->em->persist($this->member);

        $this->project = new Project();
        $this->project->name = 'Cycle95 Test Project';
        $this->project->defaultLocale = 'en';
        $this->project->locales = ['en', 'fr'];
        $this->project->publicApi = false; // Private by default to test signed URLs
        $this->em->persist($this->project);

        $membershipAdmin = new ProjectMember();
        $membershipAdmin->project = $this->project;
        $membershipAdmin->user = $this->admin;
        $membershipAdmin->email = $this->admin->email;
        $membershipAdmin->status = ProjectMemberStatus::Active;
        $membershipAdmin->joinedAt = new \DateTimeImmutable();
        $this->em->persist($membershipAdmin);

        $membershipMember = new ProjectMember();
        $membershipMember->project = $this->project;
        $membershipMember->user = $this->member;
        $membershipMember->email = $this->member->email;
        $membershipMember->status = ProjectMemberStatus::Active;
        $membershipMember->joinedAt = new \DateTimeImmutable();
        $this->em->persist($membershipMember);

        $this->em->flush();
    }

    public function testTranslationParityAcrossAllFourLocales(): void
    {
        $rootDir = self::getContainer()->getParameter('kernel.project_dir');
        $dictionaries = [];

        foreach (self::LOCALES as $locale) {
            $path = sprintf('%s/translations/messages.%s.php', $rootDir, $locale);
            $this->assertFileExists($path, sprintf('Translation dictionary for "%s" must exist', $locale));
            $dict = require $path;
            $this->assertIsArray($dict, sprintf('Dictionary for "%s" must return an array', $locale));
            $dictionaries[$locale] = $dict;
        }

        $baseCount = count($dictionaries['en']);
        $this->assertGreaterThan(3490, $baseCount, 'Dictionary must contain at least 3490 keys');

        foreach (self::LOCALES as $locale) {
            $this->assertCount(
                $baseCount,
                $dictionaries[$locale],
                sprintf('Locale "%s" must have exactly %d keys, got %d', $locale, $baseCount, count($dictionaries[$locale]))
            );
        }

        $enKeys = array_keys($dictionaries['en']);
        foreach (['fr', 'es', 'ar'] as $locale) {
            $diff1 = array_diff($enKeys, array_keys($dictionaries[$locale]));
            $diff2 = array_diff(array_keys($dictionaries[$locale]), $enKeys);
            $this->assertEmpty($diff1, sprintf('Keys present in EN but missing in %s: %s', $locale, implode(', ', $diff1)));
            $this->assertEmpty($diff2, sprintf('Keys present in %s but missing in EN: %s', $locale, implode(', ', $diff2)));
        }

        foreach (self::NEW_TRANSLATION_KEYS as $key) {
            foreach (self::LOCALES as $locale) {
                $this->assertArrayHasKey(
                    $key,
                    $dictionaries[$locale],
                    sprintf('Key "%s" must exist in dictionary for "%s"', $key, $locale)
                );
                $this->assertNotEmpty(
                    trim((string) $dictionaries[$locale][$key]),
                    sprintf('Key "%s" must not be empty in dictionary for "%s"', $key, $locale)
                );
            }
        }
    }

    public function testUploadSecurityValidatorMagicBytesAndScriptInspection(): void
    {
        /** @var UploadSecurityValidator $validator */
        $validator = self::getContainer()->get(UploadSecurityValidator::class);

        // 1. Rejet polyglotte PHP dans un faux JPEG
        $fakeJpegWithPhp = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00<?php phpinfo(); ?>";
        $this->expectException(\InvalidArgumentException::class);
        $validator->validateBufferContent($fakeJpegWithPhp, 'jpg');
    }

    public function testUploadSecurityValidatorRejectsScriptInText(): void
    {
        /** @var UploadSecurityValidator $validator */
        $validator = self::getContainer()->get(UploadSecurityValidator::class);

        // 2. Rejet balise script dans un fichier texte / csv
        $maliciousTxt = "name,role\nAlice,<script>alert('xss')</script>";
        $this->expectException(\InvalidArgumentException::class);
        $validator->validateBufferContent($maliciousTxt, 'csv');
    }

    public function testUploadSecurityValidatorCorruptedHeaders(): void
    {
        /** @var UploadSecurityValidator $validator */
        $validator = self::getContainer()->get(UploadSecurityValidator::class);

        // 3. Header PDF corrompu
        try {
            $validator->validateBufferContent("NOT_A_PDF_HEADER", 'pdf');
            $this->fail('Corrupted PDF must throw InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // 4. Header PNG valide
        $validPng = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR";
        $validator->validateBufferContent($validPng, 'png');

        // 5. Header JPEG valide
        $validJpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01";
        $validator->validateBufferContent($validJpeg, 'jpg');

        // 6. Header WebP valide
        $validWebp = "RIFF\x20\x00\x00\x00WEBPVP8 ";
        $validator->validateBufferContent($validWebp, 'webp');

        // 7. Header GIF valide
        $validGif = "GIF89a\x01\x00\x01\x00";
        $validator->validateBufferContent($validGif, 'gif');

        // 8. Header ZIP valide
        $validZip = "PK\x03\x04\x14\x00\x00\x00";
        $validator->validateBufferContent($validZip, 'zip');
    }

    public function testMediaQuarantineEndpoints(): void
    {
        // Créer un Media de test
        $media = new Media();
        $media->project = $this->project;
        $media->fileName = 'test_image.png';
        $media->originalName = 'test_image.png';
        $media->mimeType = 'image/png';
        $media->fileSize = 1024;
        $media->isQuarantined = false;
        $this->em->persist($media);
        $this->em->flush();

        $projectUuid = $this->project->uuid->toRfc4122();
        $mediaUuid = $media->uuid->toRfc4122();

        // 1. Mettre le média en quarantaine
        $this->client->loginUser($this->admin);
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/media/{$mediaUuid}/quarantine",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['reason' => 'Suspicious MIME mismatch'])
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true)['data'];
        $this->assertTrue($data['is_quarantined']);
        $this->assertSame('Suspicious MIME mismatch', $data['quarantine_reason']);

        // Vérifier persistence en base
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $reloaded = $em->getRepository(Media::class)->find($media->id);
        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->isQuarantined);
        $this->assertSame('Suspicious MIME mismatch', $reloaded->quarantineReason);

        // 2. Lever la quarantaine
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/media/{$mediaUuid}/release-quarantine",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json']
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true)['data'];
        $this->assertFalse($data['is_quarantined']);
        $this->assertNull($data['quarantine_reason']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $reloaded = $em->getRepository(Media::class)->find($media->id);
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->isQuarantined);
        $this->assertNull($reloaded->quarantineReason);
    }

    public function testQuarantinedMediaBlockedInImageTransformController(): void
    {
        $publicProject = new Project();
        $publicProject->name = 'Public Project';
        $publicProject->defaultLocale = 'en';
        $publicProject->locales = ['en'];
        $publicProject->publicApi = true;
        $this->em->persist($publicProject);

        $media = new Media();
        $media->project = $publicProject;
        $media->fileName = 'quarantined.png';
        $media->originalName = 'quarantined.png';
        $media->mimeType = 'image/png';
        $media->fileSize = 512;
        $media->isQuarantined = true;
        $media->quarantineReason = 'Automated malware suspicion';
        $this->em->persist($media);
        $this->em->flush();

        $mediaUuid = $media->uuid->toRfc4122();
        $projectUuid = $publicProject->uuid->toRfc4122();

        // Requête non authentifiée vers /cdn/media/{uuid} -> 403 (quarantined)
        $this->client->request('GET', "/cdn/media/{$mediaUuid}");
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // Requête vers /cdn/media/{uuid}/info -> 403 (quarantined)
        $this->client->request('GET', "/cdn/media/{$mediaUuid}/info");
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // Requête vers API publique files -> 404 (hidden because quarantined)
        $this->client->request('GET', "/api/{$projectUuid}/files/{$mediaUuid}");
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTemporarySignedUrlGenerationAndAccess(): void
    {
        // Créer un média dans un projet privé
        $media = new Media();
        $media->project = $this->project;
        $media->fileName = 'private_doc.png';
        $media->originalName = 'private_doc.png';
        $media->mimeType = 'image/png';
        $media->fileSize = 1024;
        $media->isQuarantined = false;
        $this->em->persist($media);
        $this->em->flush();

        $projectUuid = $this->project->uuid->toRfc4122();
        $mediaUuid = $media->uuid->toRfc4122();

        // 1. Accès direct anonyme sans signature refusé car projet privé (publicApi = false)
        $this->client->request('GET', "/cdn/media/{$mediaUuid}");
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // 2. Génération d'une URL signée par un membre du projet
        $this->client->loginUser($this->member);
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/media/{$mediaUuid}/signed-url",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ttl' => 1800]) // 30 minutes
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('signed_url', $body);
        $this->assertArrayHasKey('expires_at', $body);
        $this->assertSame(1800, $body['ttl']);

        $signedUrl = $body['signed_url'];
        $this->assertStringContainsString('expires=', $signedUrl);
        $this->assertStringContainsString('sig=', $signedUrl);

        // 3. Tentative avec signature altérée -> 403
        $parsed = parse_url($signedUrl);
        parse_str($parsed['query'] ?? '', $queryParams);
        $tamperedSig = $queryParams['sig'] . 'tampered';
        $tamperedUrl = sprintf('/cdn/media/%s?expires=%s&sig=%s', $mediaUuid, $queryParams['expires'], $tamperedSig);

        $this->client->restart();
        $this->client->request('GET', $tamperedUrl);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // 4. Tentative avec expiration passée -> 403
        $expiredUrl = sprintf('/cdn/media/%s?expires=%d&sig=%s', $mediaUuid, time() - 3600, $queryParams['sig']);
        $this->client->request('GET', $expiredUrl);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSignedUrlDeniedForQuarantinedMedia(): void
    {
        $media = new Media();
        $media->project = $this->project;
        $media->fileName = 'quarantined_doc.png';
        $media->originalName = 'quarantined_doc.png';
        $media->mimeType = 'image/png';
        $media->fileSize = 1024;
        $media->isQuarantined = true;
        $media->quarantineReason = 'Under investigation';
        $this->em->persist($media);
        $this->em->flush();

        $projectUuid = $this->project->uuid->toRfc4122();
        $mediaUuid = $media->uuid->toRfc4122();

        $this->client->loginUser($this->member);
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/media/{$mediaUuid}/signed-url",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json']
        );

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('MEDIA_QUARANTINED', $body['code']);
    }

    public function testPruneOrphanUploadsCommandExecution(): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $command = $application->find('app:media:prune-orphans');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        $tester->execute([
            '--dry-run' => true,
            '--retention-days' => '15',
        ]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Orphaned Uploads & Media Retention Pruning', $output);
        $this->assertStringContainsString('DRY RUN MODE', $output);
    }
}
