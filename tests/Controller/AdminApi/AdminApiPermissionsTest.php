<?php

namespace App\Tests\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminApiPermissionsTest extends WebTestCase
{
    public function testViewerCannotMutateCollectionsOrProjects(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Create user with Viewer role (no project.manage permission)
        $user = new User();
        $user->email = 'viewer-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $role = new Role();
        $role->name = 'viewer_' . bin2hex(random_bytes(4));
        $role->label = 'Viewer';
        $em->persist($role);

        $project = new Project();
        $project->name = 'AdminApi Perm Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->role = $role;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        // Generate PAT for this user with schema:write and projects:write scopes
        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 'pat-viewer';
        $pat->user = $user;
        $pat->scopes = ['schema:write', 'projects:write'];
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);

        $em->flush();

        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plain,
            'CONTENT_TYPE' => 'application/json',
        ];

        // 1. Viewer CAN show project
        $client->request('GET', '/admin-api/projects/' . $project->uuid->toString(), server: $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // 2. Viewer CANNOT delete project (requires MANAGE)
        $client->request('DELETE', '/admin-api/projects/' . $project->uuid->toString(), server: $headers);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        // 3. Viewer CANNOT create collection (requires MANAGE)
        $client->request('POST', '/admin-api/projects/' . $project->uuid->toString() . '/collections', server: $headers, content: json_encode([
            'name' => 'Articles',
        ]));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
