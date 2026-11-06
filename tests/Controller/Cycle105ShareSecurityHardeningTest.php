<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
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

class Cycle105ShareSecurityHardeningTest extends WebTestCase
{
    /** @return array{User, Project, ContentEntry} */
    private function seedMember(KernelBrowser $client): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'c105-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hashed_pwd';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle105 Proj ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $perm = $em->getRepository(Permission::class)->findOneBy(['name' => 'content.update']);
        if ($perm === null) {
            $perm = new Permission();
            $perm->name = 'content.update';
            $perm->label = 'Update Content';
            $perm->group = 'content';
            $em->persist($perm);
        }

        $role = new Role();
        $role->name = 'Role_' . bin2hex(random_bytes(3));
        $role->permissions->add($perm);
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
        $entry->status = 'published';
        $em->persist($entry);
        $em->flush();

        return [$user, $project, $entry];
    }

    public function testContentTypeValidationOnStore(): void
    {
        $client = static::createClient();
        [$user, $project, $entry] = $this->seedMember($client);
        $client->loginUser($user);
        $uuid = $project->uuid->toString();

        // 1. Sending text/plain should trigger 415 Unsupported Media Type
        $client->request(
            'POST',
            "/api/projects/{$uuid}/shares",
            server: ['CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['entryUuid' => $entry->uuid->toString(), 'duration' => '7d'])
        );
        self::assertSame(415, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Content-Type must be application/json.', $body['error']);

        // 2. Sending application/json should succeed with 201
        $client->request(
            'POST',
            "/api/projects/{$uuid}/shares",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['entryUuid' => $entry->uuid->toString(), 'duration' => '7d'])
        );
        self::assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testAuditLoggingOnShareLifecycle(): void
    {
        $client = static::createClient();
        [$user, $project, $entry] = $this->seedMember($client);
        $client->loginUser($user);
        $uuid = $project->uuid->toString();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // 1. Create share
        $client->request(
            'POST',
            "/api/projects/{$uuid}/shares",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['entryUuid' => $entry->uuid->toString(), 'duration' => '7d'])
        );
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $createRes = json_decode((string) $client->getResponse()->getContent(), true);
        $shareId = $createRes['data']['id'];

        $createLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'share_create',
            'project'  => $project,
            'status'   => 'success',
        ], ['id' => 'DESC']);
        self::assertNotNull($createLog);
        self::assertSame('share_controller', $createLog->source);

        // 2. List shares
        $client->request('GET', "/api/projects/{$uuid}/shares?entryUuid={$entry->uuid->toString()}");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $listLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'share_list',
            'project'  => $project,
            'status'   => 'success',
        ], ['id' => 'DESC']);
        self::assertNotNull($listLog);

        // 3. Revoke share
        $client->request('DELETE', "/api/projects/{$uuid}/shares/{$shareId}");
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $revokeLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'share_revoke',
            'project'  => $project,
            'status'   => 'success',
        ], ['id' => 'DESC']);
        self::assertNotNull($revokeLog);

        // 4. Revoke non-existent share logs failure
        $client->request('DELETE', "/api/projects/{$uuid}/shares/999999");
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $failLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'share_revoke',
            'project'  => $project,
            'status'   => 'failure',
        ], ['id' => 'DESC']);
        self::assertNotNull($failLog);
    }

    public function testPublicShareAccessAndSecurityHeaders(): void
    {
        $client = static::createClient();
        [, $project, $entry] = $this->seedMember($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $shareService = self::getContainer()->get(ShareService::class);

        ['plainToken' => $token] = $shareService->create($entry, ShareDuration::D7, null);

        $client->request('GET', "/share/{$token}");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $response = $client->getResponse();
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('noindex, nofollow, noarchive, nosnippet', $response->headers->get('X-Robots-Tag'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));

        $accessLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'public_share_access',
            'project'  => $project,
            'status'   => 'success',
        ], ['id' => 'DESC']);
        self::assertNotNull($accessLog);
    }

    public function testPublicShareRevokedLogsAudit(): void
    {
        $client = static::createClient();
        [, $project, $entry] = $this->seedMember($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $shareService = self::getContainer()->get(ShareService::class);

        ['share' => $share, 'plainToken' => $token] = $shareService->create($entry, ShareDuration::D7, null);
        $share->revokedAt = new \DateTimeImmutable();
        $em->flush();

        $client->request('GET', "/share/{$token}");
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $revokedLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'public_share_revoked',
            'project'  => $project,
            'status'   => 'failure',
        ], ['id' => 'DESC']);
        self::assertNotNull($revokedLog);
    }

    public function testPublicShareExpiredLogsAudit(): void
    {
        $client = static::createClient();
        [, $project, $entry] = $this->seedMember($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $shareService = self::getContainer()->get(ShareService::class);

        ['share' => $share, 'plainToken' => $token] = $shareService->create($entry, ShareDuration::D7, null);
        $share->expiresAt = new \DateTimeImmutable('-1 hour');
        $em->flush();

        $client->request('GET', "/share/{$token}");
        self::assertSame(410, $client->getResponse()->getStatusCode());

        $expiredLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'public_share_expired',
            'project'  => $project,
            'status'   => 'failure',
        ], ['id' => 'DESC']);
        self::assertNotNull($expiredLog);
    }

    public function testPublicShareNotFoundAndInvalidFormatAudit(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // 1. Non-existent token logs public_share_not_found
        $client->request('GET', '/share/jbo_share_non_existent_token_12345');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $notFoundLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'public_share_not_found',
            'status'   => 'failure',
        ], ['id' => 'DESC']);
        self::assertNotNull($notFoundLog);

        // 2. Invalid format token logs public_share_invalid_attempt
        $client->request('GET', '/share/bad');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $invalidLog = $em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'public_share_invalid_attempt',
            'status'   => 'failure',
        ], ['id' => 'DESC']);
        self::assertNotNull($invalidLog);
    }

    public function testShareServiceBoundsAndConstantTimeValidation(): void
    {
        $shareService = self::getContainer()->get(ShareService::class);

        // Token too short (< 8 chars)
        self::assertNull($shareService->resolve('abc'));

        // Token with invalid characters
        self::assertNull($shareService->resolve('jbo_share_<script>'));

        // Token too long (> 128 chars)
        self::assertNull($shareService->resolve(str_repeat('a', 129)));
    }
}
