<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class InsightsControllerTest extends WebTestCase
{
    private function makeMemberAndProject(KernelBrowser $client): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'i-' . bin2hex(random_bytes(4)) . '@e.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Ins ' . bin2hex(random_bytes(4));
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

    public function testProjectInsightsReturnsJson(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->makeMemberAndProject($client);
        $client->loginUser($user);

        $client->request('GET', "/insights/projects/{$project->id}");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('30d', $body['data']['range']);
        self::assertArrayHasKey('content', $body['data']);
        self::assertArrayHasKey('media', $body['data']);
        self::assertArrayHasKey('activity', $body['data']);
    }

    public function testInvalidRangeReturns400(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->makeMemberAndProject($client);
        $client->loginUser($user);

        $client->request('GET', "/insights/projects/{$project->id}?range=99d");
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testNonMemberReturns403(): void
    {
        $client = static::createClient();
        [, $project] = $this->makeMemberAndProject($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $intruder = new User();
        $intruder->email = 'x-' . bin2hex(random_bytes(4)) . '@e.com';
        $intruder->password = 'x';
        $em->persist($intruder);
        $em->flush();
        $client->loginUser($intruder);

        $client->request('GET', "/insights/projects/{$project->id}");
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testSummaryReturnsJson(): void
    {
        $client = static::createClient();
        [$user] = $this->makeMemberAndProject($client);
        $client->loginUser($user);

        $client->request('GET', '/insights/summary');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('projects', $body['data']);
    }
}
