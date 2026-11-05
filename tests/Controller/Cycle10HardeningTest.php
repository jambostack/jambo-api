<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\Comment;
use App\Entity\ContentEntry;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\Webhook;
use App\Entity\Automation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class Cycle10HardeningTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Project $project;
    private User $adminUser;
    private User $viewerUser;
    private Collection $collection;
    private ContentEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->project = new Project();
        $this->project->name = 'Cycle10 Test ' . bin2hex(random_bytes(4));
        $this->project->publicApi = true;
        $this->em->persist($this->project);

        $this->adminUser = new User();
        $this->adminUser->email = 'admin_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->adminUser->name = 'Admin';
        $this->adminUser->roles = ['ROLE_SUPER_ADMIN'];
        $this->adminUser->password = 'hashed';
        $this->em->persist($this->adminUser);

        $this->viewerUser = new User();
        $this->viewerUser->email = 'viewer_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->viewerUser->name = 'Viewer';
        $this->viewerUser->roles = ['ROLE_USER'];
        $this->viewerUser->password = 'hashed';
        $this->em->persist($this->viewerUser);

        $viewerRole = new Role();
        $viewerRole->name = 'viewer_' . bin2hex(random_bytes(4));
        $viewerRole->label = 'Viewer';
        $this->em->persist($viewerRole);

        $member = new ProjectMember();
        $member->project = $this->project;
        $member->user = $this->viewerUser;
        $member->email = $this->viewerUser->email;
        $member->role = $viewerRole;
        $this->em->persist($member);

        $this->collection = new Collection();
        $this->collection->name = 'Articles';
        $this->collection->slug = 'articles-' . bin2hex(random_bytes(3));
        $this->collection->project = $this->project;
        $this->em->persist($this->collection);

        $this->entry = new ContentEntry();
        $this->entry->project = $this->project;
        $this->entry->collection = $this->collection;
        $this->entry->status = 'published';
        $this->entry->locale = 'en';
        $this->entry->slug = 'slug-' . bin2hex(random_bytes(4));
        $this->em->persist($this->entry);

        $this->em->flush();
        self::ensureKernelShutdown();
    }

    public function testForgotPasswordAntiEnumeration(): void
    {
        $client = static::createClient();

        // Non-existent email should return 200 via Inertia without leaking account non-existence
        $client->request('POST', '/forgot-password', [
            'email' => 'doesnotexist_' . bin2hex(random_bytes(6)) . '@example.com',
        ], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Aucun compte trouvé', (string) $client->getResponse()->getContent());
        $content = (string) $client->getResponse()->getContent();
        $this->assertTrue(str_contains($content, 'Si cette adresse correspond') || str_contains($content, 'If this address corresponds'));
    }

    public function testCommentLengthLimitAndCrossTenantIsolation(): void
    {
        $client = static::createClient();
        $client->loginUser($this->adminUser);

        $projectUuid = $this->project->uuid->toRfc4122();
        $colSlug = $this->collection->slug;
        $entryId = $this->entry->id;

        // 1. Comment exceeding 10,000 characters
        $client->request('POST', "/api/projects/{$projectUuid}/collections/{$colSlug}/content/{$entryId}/comments", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'body' => str_repeat('a', 10001),
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 2. Create a valid comment
        $client->request('POST', "/api/projects/{$projectUuid}/collections/{$colSlug}/content/{$entryId}/comments", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'body' => 'Valid test comment',
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $commentId = $data['data']['id'];

        // 3. Mismatched project or entry in update must return 404
        $fakeUuid = '00000000-0000-0000-0000-000000000000';
        $client->request('PUT', "/api/projects/{$fakeUuid}/collections/{$colSlug}/content/{$entryId}/comments/{$commentId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['body' => 'Hacked body']));
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        // 4. Mismatched project in delete must return 404
        $client->request('DELETE', "/api/projects/{$fakeUuid}/collections/{$colSlug}/content/{$entryId}/comments/{$commentId}");
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testViewerCannotMutateMediaOrFolders(): void
    {
        $client = static::createClient();
        $client->loginUser($this->viewerUser);

        $projectUuid = $this->project->uuid->toRfc4122();

        // 1. Viewer cannot create a media folder
        $client->request('POST', "/api/projects/{$projectUuid}/media-folders", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Secret Folder']));
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 2. Viewer cannot bulk delete media
        $client->request('DELETE', "/api/projects/{$projectUuid}/media/bulk-destroy", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [1, 2]]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testViewerCannotMutateWebhooksOrAutomations(): void
    {
        $client = static::createClient();
        $client->loginUser($this->viewerUser);

        $projectUuid = $this->project->uuid->toRfc4122();

        // 1. Viewer cannot create webhook
        $client->request('POST', "/api/projects/{$projectUuid}/webhooks", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'name' => 'Viewer Webhook',
            'url' => 'https://example.com/hook',
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 2. Viewer cannot create automation
        $client->request('POST', "/api/projects/{$projectUuid}/automations", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'name' => 'Viewer Automation',
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testEmbedJsSecurityGuards(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forms/embed.js');
        $this->assertResponseIsSuccessful();
        $js = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('javascript:', $js);
        $this->assertStringContainsString('data:', $js);
        $this->assertStringContainsString('^[a-zA-Z0-9_\-]+$', $js);
    }

    public function testImageTransformSvgCspHeaders(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $this->project->id);

        $svgMedia = new Media();
        $svgMedia->project = $project;
        $svgMedia->originalName = 'icon.svg';
        $svgMedia->fileName = 'icon.svg';
        $svgMedia->mimeType = 'image/svg+xml';
        $svgMedia->fileSize = 100;
        $em->persist($svgMedia);
        $em->flush();
        $svgUuid = $svgMedia->uuid->toRfc4122();

        // Create dummy file on disk for the test
        $tmpDir = self::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/media';
        @mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>');

        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', "/cdn/media/{$svgUuid}?w=200&h=200");
        $this->assertResponseIsSuccessful();
        $this->assertSame('image/svg+xml', $client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

        @unlink($tmpDir . '/icon.svg');
    }
}
