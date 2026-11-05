<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\Redirect;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RedirectControllerAdminHardeningTest extends WebTestCase
{
    private function createAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Redirect Admin';
        $user->email = 'admin_redirect_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Redirect Project ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testCreateRedirectSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $payload = [
            'fromPath' => '/old-about-us',
            'toPath' => '/about-us',
            'httpCode' => 301,
            'isEnabled' => true,
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('/old-about-us', $data['fromPath']);
        $this->assertSame('/about-us', $data['toPath']);
        $this->assertSame(301, $data['httpCode']);
        $this->assertTrue($data['isEnabled']);
    }

    public function testCreateRedirectSsrfLoopbackAndPrivateIpBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // 1. Loopback SSRF
        $payload1 = [
            'fromPath' => '/internal-admin',
            'toPath' => 'http://127.0.0.1:8000/admin',
            'httpCode' => 302,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload1));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data1 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('SSRF protection', $data1['error']);

        // 2. Private RFC 1918 SSRF
        $payload2 = [
            'fromPath' => '/private-corp',
            'toPath' => 'http://10.0.0.1/console',
            'httpCode' => 302,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload2));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('SSRF protection', $data2['error']);

        // 3. Cloud Metadata SSRF
        $payload3 = [
            'fromPath' => '/cloud-meta',
            'toPath' => 'http://169.254.169.254/latest/meta-data',
            'httpCode' => 302,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload3));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data3 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('SSRF protection', $data3['error']);
    }

    public function testCreateRedirectDangerousSchemeAndProtocolRelativeBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // Javascript scheme
        $payload1 = [
            'fromPath' => '/xss',
            'toPath' => 'javascript:alert(document.cookie)',
            'httpCode' => 301,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload1));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data1 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Dangerous URI schemes', $data1['error']);

        // Protocol-relative URL
        $payload2 = [
            'fromPath' => '/proto-relative',
            'toPath' => '//evil.com/phishing',
            'httpCode' => 301,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload2));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Protocol-relative', $data2['error']);
    }

    public function testCreateRedirectUserinfoAndPortRestricted(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // Credentials / userinfo in URL
        $payload1 = [
            'fromPath' => '/login-redir',
            'toPath' => 'https://admin:secret@example.com/login',
            'httpCode' => 302,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload1));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data1 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Credentials or userinfo', $data1['error']);

        // Restricted port (e.g. SMTP port 25 or SSH port 22)
        $payload2 = [
            'fromPath' => '/ssh-redir',
            'toPath' => 'https://example.com:22/ssh',
            'httpCode' => 302,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload2));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('restricted', $data2['error']);
    }

    public function testCreateRedirectDuplicateFromPathBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $r = new Redirect();
        $r->project = $project;
        $r->fromPath = '/products';
        $r->toPath = '/catalog';
        $r->httpCode = 301;
        $r->createdBy = $admin;
        $em->persist($r);
        $em->flush();

        $payload = [
            'fromPath' => '/products',
            'toPath' => '/shop',
            'httpCode' => 302,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('already exists in this project', $data['error']);
    }

    public function testCreateRedirectIndirectLoopBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $r1 = new Redirect();
        $r1->project = $project;
        $r1->fromPath = '/page-1';
        $r1->toPath = '/page-2';
        $r1->httpCode = 301;
        $r1->createdBy = $admin;
        $em->persist($r1);

        $r2 = new Redirect();
        $r2->project = $project;
        $r2->fromPath = '/page-2';
        $r2->toPath = '/page-3';
        $r2->httpCode = 301;
        $r2->createdBy = $admin;
        $em->persist($r2);

        $em->flush();

        // Adding /page-3 -> /page-1 would form a loop
        $payload = [
            'fromPath' => '/page-3',
            'toPath' => '/page-1',
            'httpCode' => 301,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('redirect loop was detected', $data['error']);
    }

    public function testCreateRedirectChainDepthLimitExceeded(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // Create 5 existing hops: /h1 -> /h2 -> /h3 -> /h4 -> /h5 -> /h6
        for ($i = 1; $i <= 5; $i++) {
            $r = new Redirect();
            $r->project = $project;
            $r->fromPath = "/h{$i}";
            $r->toPath = '/h' . ($i + 1);
            $r->httpCode = 301;
            $r->createdBy = $admin;
            $em->persist($r);
        }
        $em->flush();

        // Adding /h6 -> /h7 would make a 6-hop chain (exceeding MAX_CHAIN_HOPS = 5)
        $payload = [
            'fromPath' => '/h6',
            'toPath' => '/h7',
            'httpCode' => 301,
        ];
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('exceeds the maximum allowed depth', $data['error']);
    }

    public function testRedirectLifecycleAndToggle(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $r = new Redirect();
        $r->project = $project;
        $r->fromPath = '/initial-from';
        $r->toPath = '/initial-to';
        $r->httpCode = 301;
        $r->isEnabled = true;
        $r->createdBy = $admin;
        $em->persist($r);
        $em->flush();

        // Show
        $client->request('GET', "/admin-api/{$projectUuid}/redirects/{$r->id}");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('/initial-from', $data['fromPath']);

        // Update
        $client->request('PUT', "/admin-api/{$projectUuid}/redirects/{$r->id}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/updated-from',
            'toPath' => '/updated-to',
            'httpCode' => 308,
        ]));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $updatedData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('/updated-from', $updatedData['fromPath']);
        $this->assertSame(308, $updatedData['httpCode']);

        // Toggle
        $client->request('POST', "/admin-api/{$projectUuid}/redirects/{$r->id}/toggle");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $toggledData = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($toggledData['isEnabled']);

        // Delete
        $client->request('DELETE', "/admin-api/{$projectUuid}/redirects/{$r->id}");
        $this->assertSame(204, $client->getResponse()->getStatusCode());
    }
}
