<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class Cycle52SecurityHardeningTest extends WebTestCase
{
    private function createUserAndProject(string $role = 'ROLE_SUPER_ADMIN', bool $publicApi = false): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'cycle52-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_52';
        $user->roles = [$role];
        $user->locale = 'en';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle52 Project ' . bin2hex(random_bytes(4));
        $project->publicApi = $publicApi;
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

        self::assertGreaterThanOrEqual(2842, count($en));
        self::assertCount(count($en), $fr, 'FR key count must match EN exactly');
        self::assertCount(count($en), $es, 'ES key count must match EN exactly');
        self::assertCount(count($en), $ar, 'AR key count must match EN exactly');

        $newKeys = [
            'project.permission_denied',
            'project.rate_limit_exceeded',
            'project.payload_too_large',
            'project.name_too_long',
            'project.invalid_default_locale',
            'project.invalid_locales_list',
            'project.invalid_disk',
            'project.max_members_reached',
            'audit.auth_required',
            'audit.super_admin_required',
            'audit.rate_limit_exceeded',
        ];

        foreach ($newKeys as $key) {
            self::assertArrayHasKey($key, $en, "Missing {$key} in EN");
            self::assertArrayHasKey($key, $fr, "Missing {$key} in FR");
            self::assertArrayHasKey($key, $es, "Missing {$key} in ES");
            self::assertArrayHasKey($key, $ar, "Missing {$key} in AR");
        }
    }

    public function testProjectControllerUnauthenticatedCreationBlocked(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/projects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Unauth Project',
        ]));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('X-Content-Type-Options'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testProjectControllerInputValidation(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject('ROLE_USER');
        $client->loginUser($user);

        // Empty name
        $client->request('POST', '/api/projects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => '   ',
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        // Name too long
        $client->request('POST', '/api/projects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => str_repeat('A', 256),
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        // Invalid default locale
        $client->request('POST', '/api/projects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Valid Name',
            'default_locale' => 'invalid_locale_format!',
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        // Invalid disk
        $client->request('POST', '/api/projects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Valid Name',
            'disk' => 'unsupported_disk_driver',
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        // Invalid JSON body
        $client->request('POST', '/api/projects', [], [], ['CONTENT_TYPE' => 'application/json'], 'invalid json');
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        // Payload too large
        $huge = json_encode(['name' => 'Big', 'description' => str_repeat('X', 70000)]);
        $client->request('POST', '/api/projects', [], [], ['CONTENT_TYPE' => 'application/json'], $huge);
        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $client->getResponse()->getStatusCode());
    }

    public function testProjectControllerNonManagerForbiddenToUpdateOrDelete(): void
    {
        $client = static::createClient();
        [$superAdmin, $project] = $this->createUserAndProject('ROLE_SUPER_ADMIN');

        // Create a regular user who is member without project.manage permission
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $viewer = new User();
        $viewer->email = 'viewer-' . bin2hex(random_bytes(4)) . '@example.com';
        $viewer->password = 'hashed';
        $viewer->roles = ['ROLE_USER'];
        $em->persist($viewer);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $viewer;
        $member->email = $viewer->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();

        $client->loginUser($viewer);

        // Attempt to update project settings without project.manage
        $client->request('PUT', '/api/projects/' . $project->uuid->toRfc4122(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Hacked Project Name',
        ]));
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);

        // Attempt to delete project without project.manage
        $client->request('DELETE', '/api/projects/' . $project->uuid->toRfc4122());
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        // Attempt to clone project without project.manage
        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/clone', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Cloned Project',
        ]));
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testAuditControllerSecurityHardening(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject('ROLE_USER');

        // Unauthenticated list audit logs
        $client->request('GET', '/api/projects/' . $project->uuid->toRfc4122() . '/audit-logs');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('X-Content-Type-Options'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

        // Unauthenticated errors endpoint
        $client->request('GET', '/api/admin/audit-logs/errors');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        // Authenticated as regular user
        $client->loginUser($user);

        // Invalid project UUID
        $client->request('GET', '/api/projects/not-a-valid-uuid/audit-logs');
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());

        // Regular user forbidden from errors endpoint
        $client->request('GET', '/api/admin/audit-logs/errors');
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);

        // Super admin authorized
        [$superAdmin] = $this->createUserAndProject('ROLE_SUPER_ADMIN');
        $client->loginUser($superAdmin);
        $client->request('GET', '/api/admin/audit-logs/errors');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('X-Content-Type-Options'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }
}
