<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ShareControllerTest extends WebTestCase
{
    /** @return array{User, Project, ContentEntry} */
    private function seedMember(KernelBrowser $client, bool $canUpdate = true): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'sh-' . bin2hex(random_bytes(4)) . '@e.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'P ' . bin2hex(random_bytes(4));
        $em->persist($project);

        // Adaptation: Role has ManyToMany Permission collection (not array), no $project field.
        // We look up the Permission first (seeded by PermissionFixture) or create it.
        if ($canUpdate) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['name' => 'content.update']);
            if ($perm === null) {
                $perm = new Permission();
                $perm->name = 'content.update';
                $perm->label = 'Modifier le contenu';
                $perm->group = 'content';
                $em->persist($perm);
            }

            $role = new Role();
            $role->name = 'Editor ' . bin2hex(random_bytes(3));
            $role->permissions->add($perm);
            $em->persist($role);
        }

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        if ($canUpdate) {
            $member->role = $role;
        }
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

    public function testCreateListRevoke(): void
    {
        $client = static::createClient();
        [$user, $project, $entry] = $this->seedMember($client);
        $client->loginUser($user);
        $uuid = $project->uuid->toString();

        // create
        $client->request('POST', "/api/projects/$uuid/shares", server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['entryUuid' => $entry->uuid->toString(), 'duration' => '7d']));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('url', $body['data']);
        self::assertStringContainsString('/share/jbo_share_', $body['data']['url']);
        $id = $body['data']['id'];

        // list (no token leaked)
        $client->request('GET', "/api/projects/$uuid/shares?entryUuid=" . $entry->uuid->toString());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $list['data']);
        self::assertArrayNotHasKey('token', $list['data'][0]);
        self::assertArrayNotHasKey('tokenHash', $list['data'][0]);

        // revoke
        $client->request('DELETE', "/api/projects/$uuid/shares/$id");
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testNonMemberForbidden(): void
    {
        $client = static::createClient();
        [, $project, $entry] = $this->seedMember($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $intruder = new User();
        $intruder->email = 'x-' . bin2hex(random_bytes(4)) . '@e.com';
        $intruder->password = 'x';
        $em->persist($intruder);
        $em->flush();
        $client->loginUser($intruder);
        $uuid = $project->uuid->toString();

        $client->request('POST', "/api/projects/$uuid/shares", server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['entryUuid' => $entry->uuid->toString(), 'duration' => '7d']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
