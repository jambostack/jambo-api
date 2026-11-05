<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class InvitationControllerTest extends WebTestCase
{
    private function createMemberWithInvitation(
        string $email = 'invited@example.com',
        bool $expired = false,
        ProjectMemberStatus $status = ProjectMemberStatus::Pending
    ): array {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Invitation Test Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->email = $email;
        $member->role = null;
        $member->status = $status;
        $token = bin2hex(random_bytes(16)); // 32 hex chars
        $member->invitationToken = $token;
        $member->tokenExpiresAt = $expired
            ? new \DateTimeImmutable('-1 hour')
            : new \DateTimeImmutable('+72 hours');

        $em->persist($member);
        $em->flush();

        return [$project, $member, $token];
    }

    private function createUser(string $email): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = $email;
        $user->password = 'hashed_pass_invitation';
        $user->roles = ['ROLE_USER'];
        $user->locale = 'en';
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function assertSecurityHeaders(Response $response): void
    {
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testAcceptPayloadTooLarge(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/invitations/abcdef123456/accept',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            str_repeat('a', 16385)
        );

        $response = $client->getResponse();
        self::assertSame(413, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testAcceptInvalidTokenFormat(): void
    {
        $client = static::createClient();

        // Token too short (< 8 chars)
        $client->request('POST', '/api/invitations/short/accept', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // Token with invalid characters
        $client->request('POST', '/api/invitations/invalid$token!here/accept', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testAcceptTokenNotFound(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/invitations/nonexistent-token-123456/accept', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
    }

    public function testAcceptExpiredToken(): void
    {
        $client = static::createClient();
        [$project, $member, $token] = $this->createMemberWithInvitation('expired@example.com', true);

        $client->request('POST', '/api/invitations/' . $token . '/accept', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $client->getResponse();
        self::assertSame(410, $response->getStatusCode());
        $this->assertSecurityHeaders($response);

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertSame($project->name, $data['project_name']);
    }

    public function testAcceptUnauthenticatedJson(): void
    {
        $client = static::createClient();
        [$project, $member, $token] = $this->createMemberWithInvitation('unauth@example.com');

        $client->request('POST', '/api/invitations/' . $token . '/accept', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testAcceptUnauthenticatedHtmlRedirect(): void
    {
        $client = static::createClient();
        [$project, $member, $token] = $this->createMemberWithInvitation('unauth-html@example.com');

        $client->request('GET', '/invitation/accept/' . $token);

        $response = $client->getResponse();
        self::assertTrue($response->isRedirection());
        self::assertStringContainsString('/login', (string) $response->headers->get('Location'));
    }

    public function testAcceptWrongRecipient(): void
    {
        $client = static::createClient();
        [$project, $member, $token] = $this->createMemberWithInvitation('intended@example.com');

        $otherUser = $this->createUser('other-user-' . bin2hex(random_bytes(3)) . '@example.com');
        $client->loginUser($otherUser);

        $client->request('POST', '/api/invitations/' . $token . '/accept', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testAcceptAlreadyMember(): void
    {
        $client = static::createClient();
        $email = 'existing-member-' . bin2hex(random_bytes(3)) . '@example.com';
        [$project, $pendingMember, $token] = $this->createMemberWithInvitation($email);

        $user = $this->createUser($email);

        // Add active member directly with a different email (e.g. prior alias) to satisfy uniq_project_email
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $activeMember = new ProjectMember();
        $activeMember->project = $project;
        $activeMember->user = $user;
        $activeMember->email = 'alias-' . $email;
        $activeMember->status = ProjectMemberStatus::Active;
        $activeMember->joinedAt = new \DateTimeImmutable();
        $em->persist($activeMember);
        $em->flush();

        $client->loginUser($user);

        $client->request('POST', '/api/invitations/' . $token . '/accept', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('message', $data);
        self::assertArrayHasKey('project', $data);
    }

    public function testAcceptSuccessJson(): void
    {
        $client = static::createClient();
        $email = 'success-member-' . bin2hex(random_bytes(3)) . '@example.com';
        [$project, $member, $token] = $this->createMemberWithInvitation($email);

        $user = $this->createUser($email);
        $client->loginUser($user);

        $client->request('POST', '/api/invitations/' . $token . '/accept', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('message', $data);
        self::assertSame($project->id, $data['project']['id']);

        // Verify entity updated in DB
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $updatedMember = $em->getRepository(ProjectMember::class)->find($member->id);
        self::assertSame(ProjectMemberStatus::Active, $updatedMember->status);
        self::assertNull($updatedMember->invitationToken);
        self::assertNull($updatedMember->tokenExpiresAt);
        self::assertSame($user->id, $updatedMember->user->id);
    }
}
