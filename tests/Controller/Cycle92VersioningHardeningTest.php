<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentVersion;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\ContentVersionRepository;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle92VersioningHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private const NEW_TRANSLATION_KEYS = [
        'versioning.project_not_found',
        'versioning.payload_too_large',
        'versioning.prune_params_required',
        'versioning.days_invalid',
        'versioning.project_versions_pruned_successfully',
        'versioning.invalid_json',
        'versioning.label_invalid_type',
        'versioning.label_updated_successfully',
        'versioning.quota_exceeded',
        'versioning.snapshot_too_large',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private VersioningService $versioningService;
    private ContentVersionRepository $versionRepo;
    private Project $project;
    private User $admin;
    private Collection $collection;
    private ContentEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->versioningService = self::getContainer()->get(VersioningService::class);
        $this->versionRepo = self::getContainer()->get(ContentVersionRepository::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->name = 'Cycle92 Admin';
        $this->admin->email = 'admin_c92_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secure123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = 'Cycle92 Project ' . bin2hex(random_bytes(4));
        $this->project->publicApi = false;
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->user = $this->admin;
        $member->project = $this->project;
        $member->status = ProjectMemberStatus::Active;
        $this->em->persist($member);

        $this->collection = new Collection();
        $this->collection->name = 'Posts';
        $this->collection->slug = 'posts_' . bin2hex(random_bytes(3));
        $this->collection->project = $this->project;
        $this->em->persist($this->collection);

        $this->entry = new ContentEntry();
        $this->entry->collection = $this->collection;
        $this->entry->project = $this->project;
        $this->entry->status = 'published';
        $this->em->persist($this->entry);

        $this->em->flush();
    }

    public function testTranslationParityInAllLocales(): void
    {
        foreach (self::LOCALES as $locale) {
            $path = __DIR__ . "/../../translations/messages.{$locale}.php";
            $this->assertFileExists($path, "Translation file for '{$locale}' must exist.");
            $dict = require $path;

            foreach (self::NEW_TRANSLATION_KEYS as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale '{$locale}' is missing key '{$key}'");
                $this->assertNotEmpty($dict[$key], "Locale '{$locale}' key '{$key}' must not be empty");
            }
        }
    }

    public function testLabelSanitization(): void
    {
        // 1. Label with HTML tags and control characters
        $dirtyLabel = "<script>alert('xss')</script> Release \x00\x08 1.0 <b>Final</b> \r\n";
        $clean = $this->versioningService->sanitizeLabel($dirtyLabel);

        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringNotContainsString('<b>', $clean);
        $this->assertStringNotContainsString("\x00", $clean);
        $this->assertSame("alert('xss') Release  1.0 Final", $clean);

        // 2. Oversized label truncated to MAX_LABEL_LENGTH (150 chars)
        $hugeLabel = str_repeat('A', 300);
        $truncated = $this->versioningService->sanitizeLabel($hugeLabel);
        $this->assertSame(150, mb_strlen($truncated));

        // 3. Null or whitespace-only returns null
        $this->assertNull($this->versioningService->sanitizeLabel(null));
        $this->assertNull($this->versioningService->sanitizeLabel('   '));
    }

    public function testSnapshotDataOversizedThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Snapshot payload exceeds maximum allowed size');

        $hugeData = ['content' => str_repeat('X', VersioningService::MAX_SNAPSHOT_BYTES + 100)];
        $this->versioningService->validateSnapshotData($hugeData);
    }

    public function testEntryVersionAutoRetentionPruning(): void
    {
        // Configure project with max_versions_per_entry = 3
        $this->project->settings = ['max_versions_per_entry' => 3];
        $this->em->flush();

        // Create 5 versions for the entry
        for ($i = 1; $i <= 5; $i++) {
            $this->versioningService->createSnapshot($this->entry, ['step' => $i], "Version {$i}");
            $this->em->flush();
        }

        // Auto-retention should maintain at most 3 versions
        $count = $this->versionRepo->countByEntry($this->entry);
        $this->assertLessThanOrEqual(3, $count);

        $versions = $this->versionRepo->findByEntry($this->entry);
        $versionNumbers = array_map(fn($v) => $v->versionNumber, $versions);

        // Should retain the latest versions (4 and 5, and possibly 3)
        $this->assertContains(5, $versionNumbers);
        $this->assertContains(4, $versionNumbers);
        $this->assertNotContains(1, $versionNumbers);
    }

    public function testRepositoryCountMethods(): void
    {
        // Count before
        $initialEntryCount = $this->versionRepo->countByEntry($this->entry);
        $initialProjCount = $this->versionRepo->countByProject($this->project);

        // Add 2 versions
        $this->versioningService->createSnapshot($this->entry, ['test' => 'a'], 'vA');
        $this->versioningService->createSnapshot($this->entry, ['test' => 'b'], 'vB');
        $this->em->flush();

        $this->assertSame($initialEntryCount + 2, $this->versionRepo->countByEntry($this->entry));
        $this->assertSame($initialProjCount + 2, $this->versionRepo->countByProject($this->project));
    }

    public function testProjectVersionsPruneEndpointRequiresAuth(): void
    {
        $projectUuid = $this->project->uuid->toRfc4122();
        $this->client->request('POST', "/api/projects/{$projectUuid}/versions/prune");
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testProjectVersionsPruneEndpointValidation(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toRfc4122();

        // 1. Missing both days and keep parameters
        $this->client->request('POST', "/api/projects/{$projectUuid}/versions/prune", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Either days or keep parameter is required.', $data['error']);

        // 2. Invalid days parameter (out of range)
        $this->client->request('POST', "/api/projects/{$projectUuid}/versions/prune", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['days' => 500]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid days parameter (must be between 1 and 365).', $data['error']);

        // 3. Invalid keep parameter (out of range)
        $this->client->request('POST', "/api/projects/{$projectUuid}/versions/prune", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['keep' => 0]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid keep count parameter (must be between 1 and 100).', $data['error']);
    }

    public function testProjectVersionsPruneEndpointSuccess(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toRfc4122();

        // Seed 4 versions
        for ($i = 1; $i <= 4; $i++) {
            $this->versioningService->createSnapshot($this->entry, ['num' => $i], "Test {$i}");
        }
        $this->em->flush();

        // Prune keeping 2
        $this->client->request('POST', "/api/projects/{$projectUuid}/versions/prune", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['keep' => 2]));

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['pruned']);
        $this->assertStringContainsString('Project versions pruned successfully (2 removed).', $data['message']);

        // Verify remaining count
        $this->assertSame(2, $this->versionRepo->countByEntry($this->entry));
    }

    public function testVersionLabelUpdateEndpoint(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toRfc4122();
        $colSlug = $this->collection->slug;
        $entryUuid = $this->entry->uuid->toRfc4122();

        $version = $this->versioningService->createSnapshot($this->entry, ['initial' => true], 'Draft 1');
        $this->em->flush();
        $vNum = $version->versionNumber;

        // 1. Update label with HTML tags (should be sanitized)
        $this->client->request('PATCH', "/api/projects/{$projectUuid}/collections/{$colSlug}/entries/{$entryUuid}/versions/{$vNum}/label", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['label' => '<b>Production</b> Ready']));

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Production Ready', $data['label']);

        // 2. Non-existent version returns 404
        $this->client->request('PATCH', "/api/projects/{$projectUuid}/collections/{$colSlug}/entries/{$entryUuid}/versions/9999/label", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['label' => 'New']));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
