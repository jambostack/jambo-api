<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EnsureProjectMemberSubscriberTest extends WebTestCase
{
    public function testNonMemberCannotAccessProjectRouteWithUuidParam(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'subscriber-nonmember-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Protected Proj ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        $client->loginUser($user);

        // Access route with {uuid} instead of {projectUuid}
        $client->request('GET', '/api/projects/' . $project->uuid->toString() . '/search');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Access denied to this project', (string) $client->getResponse()->getContent());
    }

    public function testActiveMemberCanAccessProjectRouteWithUuidParam(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'subscriber-member-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Member Proj ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();

        $client->loginUser($user);

        // Access route with {uuid} - should not be rejected with 403 Access denied
        $client->request('GET', '/api/projects/' . $project->uuid->toString() . '/search');
        self::assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Anon Proj ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        $client->request('GET', '/api/projects/' . $project->uuid->toString() . '/search');
        self::assertContains($client->getResponse()->getStatusCode(), [401, 302, 403]);
    }
}
