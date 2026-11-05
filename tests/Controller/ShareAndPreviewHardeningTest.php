<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\Share;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Enum\ShareDuration;
use App\Service\Share\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ShareAndPreviewHardeningTest extends WebTestCase
{
    /** @return array{User, Project, ContentEntry} */
    private function seedMember(KernelBrowser $client, bool $canManage = true): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'hardened-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hashed_pass';
        $em->persist($user);

        $project = new Project();
        $project->name = 'SecProject ' . bin2hex(random_bytes(4));
        $project->previewEnabled = true;
        $em->persist($project);

        $permUpdate = $em->getRepository(Permission::class)->findOneBy(['name' => 'content.update']);
        if ($permUpdate === null) {
            $permUpdate = new Permission();
            $permUpdate->name = 'content.update';
            $permUpdate->label = 'Update Content';
            $permUpdate->group = 'content';
            $em->persist($permUpdate);
        }

        $permView = $em->getRepository(Permission::class)->findOneBy(['name' => 'project.view']);
        if ($permView === null) {
            $permView = new Permission();
            $permView->name = 'project.view';
            $permView->label = 'View Project';
            $permView->group = 'project';
            $em->persist($permView);
        }

        $role = new Role();
        $role->name = 'Role_' . bin2hex(random_bytes(3));
        $role->permissions->add($permUpdate);
        $role->permissions->add($permView);
        $em->persist($role);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $member->role = $role;
        $em->persist($member);

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;
        $em->persist($collection);

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->status = 'draft';
        $em->persist($entry);
        $em->flush();

        return [$user, $project, $entry];
    }

    public function testSharePaginationAndMeta(): void
    {
        $client = static::createClient();
        [$user, $project, $entry] = $this->seedMember($client);
        $client->loginUser($user);
        $uuid = $project->uuid->toString();
        $entryUuid = $entry->uuid->toString();

        // Create 3 shares
        for ($i = 0; $i < 3; $i++) {
            $client->request('POST', "/api/projects/{$uuid}/shares", server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['entryUuid' => $entryUuid, 'duration' => '7d']));
            self::assertSame(201, $client->getResponse()->getStatusCode());
        }

        // Request with limit 2, page 1
        $client->request('GET', "/api/projects/{$uuid}/shares?entryUuid={$entryUuid}&limit=2&page=1");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);

        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);
        self::assertCount(2, $body['data']);
        self::assertSame(3, $body['meta']['total']);
        self::assertSame(1, $body['meta']['page']);
        self::assertSame(2, $body['meta']['limit']);

        // Request page 2
        $client->request('GET', "/api/projects/{$uuid}/shares?entryUuid={$entryUuid}&limit=2&page=2");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body2 = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $body2['data']);
    }

    public function testShareCapLimitEnforcement(): void
    {
        $client = static::createClient();
        [$user, $project, $entry] = $this->seedMember($client);
        $client->loginUser($user);
        $uuid = $project->uuid->toString();
        $entryUuid = $entry->uuid->toString();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Manually seed 50 active shares for this entry
        for ($i = 0; $i < 50; $i++) {
            $share = new Share();
            $share->tokenHash = hash('sha256', 'token_' . $i . '_' . bin2hex(random_bytes(8)));
            $share->entry = $entry;
            $share->project = $project;
            $share->createdBy = $user;
            $share->expiresAt = (new \DateTimeImmutable())->modify('+7 days');
            $em->persist($share);
        }
        $em->flush();

        // 51st attempt via API should fail with 422
        $client->request('POST', "/api/projects/{$uuid}/shares", server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['entryUuid' => $entryUuid, 'duration' => '7d']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('MAX_ACTIVE_SHARES_EXCEEDED', $body['code'] ?? null);
    }

    public function testPreviewTokenAndUrlValidation(): void
    {
        $client = static::createClient();
        [$user, $project, $entry] = $this->seedMember($client);
        $client->loginUser($user);
        $uuid = $project->uuid->toString();
        $entryUuid = $entry->uuid->toString();

        // 1. Generate preview token
        $client->request('GET', "/api/projects/{$uuid}/preview/token/{$entryUuid}?ttl=1800");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $tokenData = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $tokenData);
        self::assertSame(1800, $tokenData['expires_in']);

        // 2. Validate safe target URL
        $client->request('POST', "/api/projects/{$uuid}/preview/validate-url", server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://jambostack.site/preview/test']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $valData = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($valData['valid']);

        // 3. Reject SSRF / private IP target URL
        $client->request('POST', "/api/projects/{$uuid}/preview/validate-url", server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'http://127.0.0.1:8000/secret']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $valDataErr = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($valDataErr['valid']);
    }

    public function testApiDocsCspHeaders(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->seedMember($client);
        $uuid = $project->uuid->toString();

        // Public API docs
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project->publicApi = true;
        $em->flush();

        $client->request('GET', "/api/docs/{$uuid}");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $response = $client->getResponse();

        self::assertTrue($response->headers->has('Content-Security-Policy'));
        $csp = $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("https://unpkg.com", $csp);
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testColumnSettingRateLimitAndSanitization(): void
    {
        $client = static::createClient();
        [$user, $project, $entry] = $this->seedMember($client);
        $client->loginUser($user);
        $uuid = $project->uuid->toString();
        $slug = $entry->collection->slug;

        // Update column settings with HTML stripped
        $client->request('PUT', "/api/projects/{$uuid}/collections/{$slug}/column-settings", server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['visible_columns' => ['<script>title</script>', 'status', 'created_at']]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertContains('title', $data['visibleColumns']);
        self::assertNotContains('<script>title</script>', $data['visibleColumns']);

        // Read column settings
        $client->request('GET', "/api/projects/{$uuid}/collections/{$slug}/column-settings");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $readData = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(3, $readData['visibleColumns']);
    }
}
