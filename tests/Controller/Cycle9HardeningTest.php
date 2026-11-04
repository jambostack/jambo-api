<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Form;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Redirect;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\RedirectRepository;
use App\Service\Redirect\RedirectResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class Cycle9HardeningTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Project $project;
    private User $adminUser;
    private User $viewerUser;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->project = new Project();
        $this->project->name = 'Cycle9 Hardening ' . bin2hex(random_bytes(4));
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

        $this->em->flush();
        self::ensureKernelShutdown();
    }

    public function testRedirectDangerousSchemesRejectedOnCreate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->adminUser);

        $projectUuid = $this->project->uuid->toRfc4122();

        // 1. javascript: scheme
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'fromPath' => '/old-page',
            'toPath' => 'javascript:alert(1)',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Dangerous URI schemes', (string) $client->getResponse()->getContent());

        // 2. data: scheme
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'fromPath' => '/old-data',
            'toPath' => 'data:text/html,<script>alert(1)</script>',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 3. Protocol-relative URL //
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'fromPath' => '/old-rel',
            'toPath' => '//evil.com/phishing',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Protocol-relative', (string) $client->getResponse()->getContent());

        // 4. Invalid regex pattern
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'fromPath' => '/unclosed-(group',
            'toPath' => '/new-target',
            'isPattern' => true,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Invalid regular expression', (string) $client->getResponse()->getContent());
    }

    public function testRedirectPublicBlocksDangerousSchemes(): void
    {
        $client = static::createClient();

        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $this->project->id);

        $redirect = new Redirect();
        $redirect->project = $project;
        $redirect->fromPath = '/unsafe-redirect';
        $redirect->toPath = 'javascript:window.location="https://attacker.com"';
        $redirect->httpCode = 301;
        $redirect->isEnabled = true;
        $redirect->createdBy = $em->find(User::class, $this->adminUser->id);
        $em->persist($redirect);
        $em->flush();
        self::ensureKernelShutdown();

        $projectUuid = $this->project->uuid->toRfc4122();

        // Request HTML redirect -> should block with 400
        $client->request('GET', "/api/projects/{$projectUuid}/redirects/resolve?path=/unsafe-redirect", [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);
        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Unsafe redirect destination', (string) $client->getResponse()->getContent());
    }

    public function testRedirectResolverPatternSafeDelimiters(): void
    {
        $redirect = new Redirect();
        $redirect->fromPath = '/tag/(#\w+)';
        $redirect->toPath = '/search?q=$1';
        $redirect->isPattern = true;
        $redirect->isEnabled = true;

        $repo = $this->createStub(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$redirect]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/tag/#security', new Project());

        $this->assertNotNull($result);
        $this->assertSame('/search?q=$1', $result->toPath);
    }

    public function testFormPublicSubmitPayloadLimit(): void
    {
        $client = static::createClient();

        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $this->project->id);

        $form = new Form();
        $form->project = $project;
        $form->name = 'Test Form';
        $form->slug = 'test-form';
        $form->fields = [
            ['name' => 'message', 'type' => 'text', 'required' => false],
        ];
        $form->settings = [];
        $em->persist($form);
        $em->flush();
        self::ensureKernelShutdown();

        $projectUuid = $this->project->uuid->toRfc4122();

        // 1MB + 10 bytes payload
        $hugePayload = json_encode(['message' => str_repeat('A', 1048580)]);
        $client->request('POST', "/api/projects/{$projectUuid}/forms/test-form/submit", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $hugePayload);

        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Payload too large', (string) $client->getResponse()->getContent());
    }

    public function testSearchFilterValidation(): void
    {
        $client = static::createClient();
        $client->loginUser($this->adminUser);

        $projectUuid = $this->project->uuid->toRfc4122();

        // 1. Invalid collection slug
        $client->request('GET', "/api/projects/{$projectUuid}/search?q=test&collection=invalid%22%20OR%201=1");
        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Invalid collection format', (string) $client->getResponse()->getContent());

        // 2. Invalid locale
        $client->request('GET', "/api/projects/{$projectUuid}/search?q=test&locale=invalid%3Balert");
        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Invalid locale format', (string) $client->getResponse()->getContent());
    }

    public function testDeploymentsRestrictedToAdmins(): void
    {
        $client = static::createClient();

        // 1. Anonyme -> 401
        $client->request('GET', '/api/deployments');
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        // 2. Simple viewer -> 403
        $client->loginUser($this->viewerUser);
        $client->request('GET', '/api/deployments');
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 3. Super admin -> 200
        $client->loginUser($this->adminUser);
        $client->request('GET', '/api/deployments');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testProjectVoterPropagatesManagePermission(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = $em->find(Project::class, $this->project->id);

        $permManage = $em->getRepository(Permission::class)->findOneBy(['name' => 'project.manage']);
        if (!$permManage) {
            $permManage = new Permission();
            $permManage->name = 'project.manage';
            $permManage->label = 'Manage Project';
            $permManage->group = 'project';
            $em->persist($permManage);
        }

        $managerRole = new Role();
        $managerRole->name = 'manager_' . bin2hex(random_bytes(4));
        $managerRole->label = 'Manager';
        $managerRole->permissions->add($permManage);
        $em->persist($managerRole);

        $managerUser = new User();
        $managerUser->email = 'mgr_' . bin2hex(random_bytes(4)) . '@test.com';
        $managerUser->name = 'Manager User';
        $managerUser->roles = ['ROLE_USER'];
        $managerUser->password = 'hashed';
        $em->persist($managerUser);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $managerUser;
        $member->email = $managerUser->email;
        $member->role = $managerRole;
        $em->persist($member);

        $em->flush();

        $voter = self::getContainer()->get(\App\Security\ProjectVoter::class);
        $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
            $managerUser,
            'main',
            $managerUser->getRoles()
        );

        // A user with project.manage should be granted collection.create and content.update
        $voteCreate = $voter->vote($token, $project, ['collection.create']);
        $this->assertSame(\Symfony\Component\Security\Core\Authorization\Voter\VoterInterface::ACCESS_GRANTED, $voteCreate);

        $voteContent = $voter->vote($token, $project, ['content.update']);
        $this->assertSame(\Symfony\Component\Security\Core\Authorization\Voter\VoterInterface::ACCESS_GRANTED, $voteContent);

        self::ensureKernelShutdown();
    }

    public function testCollectionAndFieldMutationRequiresManage(): void
    {
        $client = static::createClient();

        // 1. Viewer cannot create a collection -> 403
        $client->loginUser($this->viewerUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $client->request('POST', "/api/projects/{$projectUuid}/collections", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Articles']));
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 2. Admin can create a collection -> 201
        $client->loginUser($this->adminUser);
        $client->request('POST', "/api/projects/{$projectUuid}/collections", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Articles']));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // 3. Viewer cannot create a field -> 403
        $client->loginUser($this->viewerUser);
        $client->request('POST', "/api/projects/{$projectUuid}/collections/articles/fields", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Title', 'type' => 'text']));
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 4. Admin can create a field -> 201
        $client->loginUser($this->adminUser);
        $client->request('POST', "/api/projects/{$projectUuid}/collections/articles/fields", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Title', 'type' => 'text']));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
    }
}
