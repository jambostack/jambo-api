<?php

namespace App\Tests\Controller;

use App\Controller\HealthController;
use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CoreApiHardeningTest extends WebTestCase
{
    private string $projectUuid;
    private string $tokenSecret;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Hardening ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $collection = new Collection();
        $collection->project = $project;
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->order = 0;
        $em->persist($collection);

        $token = new ApiToken();
        $token->project = $project;
        $token->name = 'WriteToken';
        $this->tokenSecret = bin2hex(random_bytes(24));
        $token->tokenHash = hash('sha256', $this->tokenSecret);
        $token->abilities = ['write'];
        $em->persist($token);

        $em->flush();

        $this->projectUuid = $project->uuid->toString();

        self::ensureKernelShutdown();
    }

    public function testSystemInfoReturnsExpectedVersion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/system/info');

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('JamboApi CMS', $data['name']);
        $this->assertSame(HealthController::VERSION, $data['version']);
    }

    public function testHealthEndpointReturnsCurrentVersion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('pass', $data['status']);
        $this->assertSame(HealthController::VERSION, $data['version']);
    }

    public function testWebhookSsrfValidation(): void
    {
        $client = static::createClient();

        // Test with invalid URL
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/webhooks',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'name' => 'Bad Webhook',
                'url' => 'not-a-valid-url',
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);

        // Test with non-http scheme
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/webhooks',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'name' => 'Bad Scheme',
                'url' => 'ftp://example.com/webhook',
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testWebhookCreationAndTestPing(): void
    {
        $client = static::createClient();

        // 1. Create valid webhook
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/webhooks',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'name' => 'CI Webhook',
                'url' => 'https://example.com/webhook-receiver',
                'events' => ['content.created', 'content.updated'],
            ])
        );

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $created);
        $webhookUuid = $created['data']['uuid'];
        $this->assertNotEmpty($webhookUuid);

        // 2. Trigger test ping
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/webhooks/' . $webhookUuid . '/test',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ]
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $testResult = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($testResult['success']);
        $this->assertSame('ping', $testResult['payload']['event']);
    }

    public function testCommentControllerRejectsMismatchedProjectOrCollection(): void
    {
        $client = static::createClient();

        // Trying to post comment to a non-existent entry or mismatched collection returns 404
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/collections/articles/entries/00000000-0000-0000-0000-000000000000/comments',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'content' => 'Test comment',
            ])
        );

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testTusOptionsDiscovery(): void
    {
        $client = static::createClient();

        $client->request('OPTIONS', '/api/projects/' . $this->projectUuid . '/files/tus');
        $response = $client->getResponse();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('1.0.0', $response->headers->get('Tus-Resumable'));
        $this->assertSame('1.0.0', $response->headers->get('Tus-Version'));
        $this->assertTrue($response->headers->has('Tus-Extension'));
    }

    public function testMediaFolderCircularMovePrevention(): void
    {
        $client = static::createClient();

        // 1. Create parent folder A
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/media-folders',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['name' => 'Folder A'])
        );
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $folderA = json_decode($client->getResponse()->getContent(), true)['data'];

        // 2. Create child folder B inside A
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/media-folders',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['name' => 'Folder B', 'parent_id' => $folderA['id']])
        );
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $folderB = json_decode($client->getResponse()->getContent(), true)['data'];

        // 3. Attempt to move folder A into folder B (illegal circular move)
        $client->request(
            'PATCH',
            '/api/projects/' . $this->projectUuid . '/media-folders/' . $folderA['id'] . '/move',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['parent_id' => $folderB['id']])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $error = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Cannot move a folder into one of its descendants', $error['error']);
    }

    public function testGraphQLWithApiToken(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'query' => '{ __typename }',
            ])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('Query', $data['data']['__typename']);
    }

    public function testColumnSettingRejectsNotFoundCollection(): void
    {
        $client = static::createClient();

        // 1. ApiToken alone without user session returns 401 Authentication required
        $client->request(
            'GET',
            '/api/projects/' . $this->projectUuid . '/collections/nonexistent-collection/column-settings',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
            ]
        );
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        // 2. Logged-in user returns 404 on missing collection
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $user = new \App\Entity\User();
        $user->email = 'coluser-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(
            'GET',
            '/api/projects/' . $this->projectUuid . '/collections/nonexistent-collection/column-settings'
        );

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Collection not found', $data['error']);
    }

    public function testRedirectPublicReturnsJsonOnWildcardAccept(): void
    {
        $client = static::createClient();

        // Clients like curl or fetch send Accept: */* or application/json, */*
        $client->request(
            'GET',
            '/' . $this->projectUuid . '/redirects/resolve?path=/old-url',
            [],
            [],
            [
                'HTTP_ACCEPT' => '*/*',
            ]
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertTrue($client->getResponse()->headers->contains('content-type', 'application/json'));
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['redirected']);
    }

    public function testNotificationControllerRequiresAuth(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/notifications');
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/notifications/unread-count');
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/notifications/read-all');
        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testInvitationAcceptReturnsJson(): void
    {
        $client = static::createClient();

        // Unknown token with Accept: application/json returns 404 JSON
        $client->request(
            'GET',
            '/invitation/accept/unknown-token-123',
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ]
        );

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertTrue($client->getResponse()->headers->contains('content-type', 'application/json'));
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);

        // API route returns JSON
        $client->request(
            'POST',
            '/api/invitations/unknown-token-123/accept'
        );

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertTrue($client->getResponse()->headers->contains('content-type', 'application/json'));
    }

    public function testMediaBulkDestroyAcceptsEmptyOrUuidList(): void
    {
        $client = static::createClient();

        $client->request(
            'DELETE',
            '/api/projects/' . $this->projectUuid . '/media/bulk-destroy',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'uuids' => ['00000000-0000-0000-0000-000000000000'],
            ])
        );

        $this->assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testAuditLogsWithApiToken(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/projects/' . $this->projectUuid . '/audit-logs',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
            ]
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('logs', $data);
        $this->assertArrayHasKey('total_today', $data);
    }
}
