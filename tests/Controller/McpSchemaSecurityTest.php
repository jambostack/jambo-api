<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Régression de la faille : un utilisateur membre du projet A ne doit pas
 * pouvoir créer une collection dans le projet B via le tool MCP create_collection
 * (le handler ne vérifiait pas l'appartenance au projet ciblé).
 */
class McpSchemaSecurityTest extends WebTestCase
{
    public function testCrossProjectCreateCollectionDenied(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $projectA = new Project();
        $projectA->name = 'A ' . bin2hex(random_bytes(4));
        $em->persist($projectA);

        $projectB = new Project();
        $projectB->name = 'B ' . bin2hex(random_bytes(4));
        $em->persist($projectB);

        // L'utilisateur est membre de A uniquement.
        $member = new ProjectMember();
        $member->project = $projectA;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();
        $projectBId = $projectB->id;

        $client->loginUser($user);

        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_collection', 'arguments' => [
                'project_uuid' => $projectB->uuid->toString(), 'name' => 'Hacked',
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        // La collection NE DOIT PAS exister dans le projet B.
        $em->clear();
        $exists = $em->getRepository(Collection::class)->findOneBy(['project' => $projectBId, 'slug' => 'hacked']);
        self::assertNull($exists, 'cross-project write must be denied');
    }

    public function testMemberCanCreateInOwnProject(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp2-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Own ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();
        $projectId = $project->id;

        $client->loginUser($user);

        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_collection', 'arguments' => [
                'project_uuid' => $project->uuid->toString(), 'name' => 'Articles',
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        $em->clear();
        $exists = $em->getRepository(Collection::class)->findOneBy(['project' => $projectId, 'slug' => 'articles']);
        self::assertNotNull($exists, 'member must be able to create in own project');
    }

    public function testCreateProjectViaMcp(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp3-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        $name = 'MCPProj' . bin2hex(random_bytes(4));
        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_project', 'arguments' => ['name' => $name]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        $em->clear();
        $project = $em->getRepository(Project::class)->findOneBy(['name' => $name]);
        self::assertNotNull($project, 'create_project must create the project');
        $member = $em->getRepository(\App\Entity\ProjectMember::class)->findOneBy(['project' => $project->id, 'email' => $user->email]);
        self::assertNotNull($member, 'creator must be a member');
    }
}
