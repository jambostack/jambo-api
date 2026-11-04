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

    public function testContentAiStreamRequiresAuth(): void
    {
        $client = static::createClient();

        // 1. Anonymous access to admin ai stream endpoint is denied (redirects to login or 401/403)
        $client->request('POST', '/admin/api/content-ai/stream', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'text' => 'Hello AI world',
        ]));
        $status = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status, [302, 401, 403], true));

        // 2. Anonymous access to project-scoped ai stream without auth is denied
        $client->request('POST', '/api/projects/' . $this->projectUuid . '/ai/stream', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'text' => 'Hello AI world',
        ]));
        $status2 = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status2, [302, 401, 403], true));
    }

    public function testRedirectLoopProtectionAndValidation(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'redirectuser-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        // 1. Self loop attempt
        $client->request('POST', '/admin-api/' . $this->projectUuid . '/redirects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/page-a',
            'toPath' => '/page-a',
            'httpCode' => 301,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('A redirect cannot point to itself.', $data['error']);

        // 2. Invalid HTTP code
        $client->request('POST', '/admin-api/' . $this->projectUuid . '/redirects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/page-a',
            'toPath' => '/page-b',
            'httpCode' => 200,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 3. Valid redirect creation (/page-a -> /page-b)
        $client->request('POST', '/admin-api/' . $this->projectUuid . '/redirects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/page-a',
            'toPath' => '/page-b',
            'httpCode' => 301,
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // 4. Circular loop attempt (/page-b -> /page-a)
        $client->request('POST', '/admin-api/' . $this->projectUuid . '/redirects', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/page-b',
            'toPath' => '/page-a',
            'httpCode' => 301,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $loopData = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('loop was detected', $loopData['error']);

        // 5. Chains detection endpoint
        $client->request('GET', '/admin-api/' . $this->projectUuid . '/redirects/chains');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $chainData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $chainData);
    }

    public function testFormSubmissionsPaginationAndSingleDelete(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);

        $form = new \App\Entity\Form();
        $form->project = $project;
        $form->name = 'Contact Form';
        $form->slug = 'contact';
        $form->fields = [
            ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
        ];
        $em->persist($form);

        $sub1 = new \App\Entity\FormSubmission();
        $sub1->form = $form;
        $sub1->data = ['email' => 'one@example.com'];
        $em->persist($sub1);

        $sub2 = new \App\Entity\FormSubmission();
        $sub2->form = $form;
        $sub2->data = ['email' => 'two@example.com'];
        $em->persist($sub2);

        $user = new User();
        $user->email = 'formuser-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);

        $em->flush();

        $client->loginUser($user);

        // 1. List submissions with pagination
        $client->request('GET', '/admin-api/' . $this->projectUuid . '/forms/' . $form->id . '/submissions?page=1&per_page=1');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $res = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(1, $res['data']);
        $this->assertSame(2, $res['meta']['total']);

        // 2. Delete single submission
        $client->request('DELETE', '/admin-api/' . $this->projectUuid . '/forms/' . $form->id . '/submissions/' . $sub1->id);
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        // 3. Verify total is now 1
        $client->request('GET', '/admin-api/' . $this->projectUuid . '/forms/' . $form->id . '/submissions');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $resAfter = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $resAfter['meta']['total']);
    }

    public function testProjectStorageProfileTestingEndpoint(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);

        $profile = new \App\Entity\ProjectStorageProfile();
        $profile->project = $project;
        $profile->name = 'Local Storage Test';
        $profile->driver = 'local';
        $profile->isDefault = true;
        $em->persist($profile);

        $user = new User();
        $user->email = 'storageuser-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);

        $em->flush();

        $client->loginUser($user);

        $client->request('POST', '/api/admin/projects/' . $this->projectUuid . '/storage/profiles/' . $profile->id . '/test');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('successfully', $data['message']);
    }

    public function testImageTransformAccessControlAndQueryToken(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);
        $project->publicApi = false;

        $media = new \App\Entity\Media();
        $media->project = $project;
        $media->fileName = 'test-image.jpg';
        $media->originalName = 'test-image.jpg';
        $media->mimeType = 'image/jpeg';
        $media->fileSize = 100;
        $em->persist($media);
        $em->flush();

        // 1. Anonymous access to private project media info is blocked (403)
        $client->request('GET', '/cdn/media/' . $media->uuid->toRfc4122() . '/info');
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 2. Access with token in query string is permitted (or 404 if physical file doesn't exist on disk, but NOT 403!)
        $client->request('GET', '/cdn/media/' . $media->uuid->toRfc4122() . '/info?token=' . $this->tokenSecret);
        $status = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status, [200, 404], true));
        $this->assertNotSame(403, $status);
    }

    public function testDirectUploadAuthorization(): void
    {
        $client = static::createClient();

        // 1. Unauthenticated intent creation is rejected
        $client->request('POST', '/api/projects/' . $this->projectUuid . '/media/direct-upload/intent');
        $status = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status, [401, 403], true));

        // 2. Authenticated intent creation with ApiToken proceeds to payload processing
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/media/direct-upload/intent',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['filename' => 'pic.png'])
        );
        $statusAuth = $client->getResponse()->getStatusCode();
        $this->assertNotSame(401, $statusAuth);
        $this->assertNotSame(403, $statusAuth);
    }

    public function testTemplateControllersAuthorization(): void
    {
        $client = static::createClient();

        // 1. Save from project requires authorization
        $client->request('POST', '/api/project-templates/from-project/' . $this->projectUuid, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Stolen Template',
        ]));
        $status = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status, [302, 401, 403], true));

        // 2. Save from collection requires authorization
        $client->request('POST', '/api/collection-templates/from-collection/' . $this->projectUuid . '/articles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Stolen Collection',
        ]));
        $statusCol = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($statusCol, [302, 401, 403], true));
    }

    public function testCaptchaControllerAltRoute(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/projects/' . $this->projectUuid . '/captcha');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('image', $data);
    }

    public function testProjectImportRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/projects/import');
        $status1 = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status1, [302, 401, 403], true));

        $client->request('POST', '/api/projects/import/preview');
        $status2 = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status2, [302, 401, 403], true));
    }

    public function testUserEmailDuplicateConflictOnUpdate(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-user-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);

        $user1 = new User();
        $user1->name = 'User One';
        $user1->email = 'user1-' . bin2hex(random_bytes(4)) . '@example.com';
        $user1->password = 'hashed';
        $em->persist($user1);

        $user2 = new User();
        $user2->name = 'User Two';
        $user2->email = 'user2-' . bin2hex(random_bytes(4)) . '@example.com';
        $user2->password = 'hashed';
        $em->persist($user2);

        $em->flush();

        $client->loginUser($admin);

        // Try updating user2 email to user1 email -> 409 Conflict
        $client->request(
            'PUT',
            '/api/users/' . $user2->id,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $user1->email])
        );

        $this->assertSame(409, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('Email already taken.', $data['errors']['email']);
    }

    public function testRoleNameDuplicateConflict(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'role-admin-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        $uniqueRole = 'Role_' . bin2hex(random_bytes(4));

        // Create initial role
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => $uniqueRole,
            'label' => 'First Role',
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // Attempt creating duplicate role name -> 422
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => $uniqueRole,
            'label' => 'Duplicate Role',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Role name already exists.', $data['errors']['name']);
    }

    public function testPersonalAccessTokenPastExpirationRejected(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'pat-user-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_USER'];
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        $client->request('POST', '/api/settings/personal-access-tokens', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Expired PAT',
            'expires_at' => '2020-01-01T00:00:00Z',
        ]));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Expiration date must be in the future.', $data['errors']['expires_at']);
    }

    public function testRegisteredUserDuplicateEmailReturns422(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $email = 'reg-' . bin2hex(random_bytes(4)) . '@example.com';
        $user = new User();
        $user->name = 'Existing User';
        $user->email = $email;
        $user->password = 'hashed';
        $user->roles = ['ROLE_USER'];
        $em->persist($user);
        $em->flush();

        $client->request('POST', '/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'New Guy',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('This email is already in use.', $data['errors']['email']);
    }

    public function testProjectImporterZipSlipProtection(): void
    {
        $importer = self::getContainer()->get(\App\Service\ExportImport\ProjectImporter::class);
        $zipPath = sys_get_temp_dir() . '/zipslip_test_' . uniqid() . '.zip';

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('../../../etc/evil.txt', 'evil content');
        $zip->close();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('directory traversal detected');
            $importer->extractZip($zipPath);
        } finally {
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    public function testLocaleControllerInvalidJsonReturns400(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'locale-user-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_USER'];
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        $client->request('POST', '/settings/locale', [], [], ['CONTENT_TYPE' => 'application/json'], 'invalid json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testMercureFallbackReturnsEventStreamOnGet(): void
    {
        $client = static::createClient();

        // Must be accessible publicly without 302 redirect to /login
        $client->request('GET', '/.well-known/mercure');
        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);
        ob_start();
        ($response->getCallback())();
        $content = ob_get_clean();
        $this->assertStringContainsString('event: close', $content);
    }

    public function testRealtimeTokenEndpoint(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-rt-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        $client->request('GET', '/api/projects/' . $this->projectUuid . '/realtime/token');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('hub_url', $data);
        $this->assertArrayHasKey('topics', $data);
    }

    public function testRealtimeTokenWhenMercureDisabledReturns200WithNullToken(): void
    {
        $projectRepo = self::getContainer()->get(\App\Repository\ProjectRepository::class);
        $memberRepo = self::getContainer()->get(\App\Repository\ProjectMemberRepository::class);
        $controller = new \App\Controller\RealtimeController(
            $projectRepo,
            $memberRepo,
            mercureEnabled: false,
        );
        $controller->setContainer(self::getContainer());

        $request = new \Symfony\Component\HttpFoundation\Request();
        $project = $projectRepo->findOneBy(['uuid' => $this->projectUuid]);
        $request->attributes->set('_project', $project);

        $response = $controller->token($this->projectUuid, $request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['enabled']);
        $this->assertNull($data['token']);
        $this->assertNull($data['hub_url']);
    }

    public function testInsightsSummaryUnauthenticatedReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/insights/summary');
        $status = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status, [302, 401], true));
    }

    public function testCommentValidationAndJsonSafety(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-cmt-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);

        $entry = new \App\Entity\ContentEntry();
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);
        $collection = $em->getRepository(Collection::class)->findOneBy(['project' => $project, 'slug' => 'articles']);
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->locale = 'en';
        $entry->slug = 'test-comment-slug-' . bin2hex(random_bytes(3));
        $em->persist($entry);
        $em->flush();

        $client->loginUser($admin);

        // 1. Malformed JSON -> 400
        $client->request('POST', "/api/projects/{$this->projectUuid}/collections/articles/content/{$entry->id}/comments", [], [], ['CONTENT_TYPE' => 'application/json'], 'malformed json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        // 2. Empty body -> 422
        $client->request('POST', "/api/projects/{$this->projectUuid}/collections/articles/content/{$entry->id}/comments", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['body' => '   ']));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Comment body cannot be empty.', $data['error']);

        // 3. Valid body -> 201
        $client->request('POST', "/api/projects/{$this->projectUuid}/collections/articles/content/{$entry->id}/comments", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['body' => 'Super content!']));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testProjectAndCollectionMalformedJsonSafety(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-pj-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        // Project create malformed JSON -> 400
        $client->request('POST', '/api/projects', [], [], ['CONTENT_TYPE' => 'application/json'], 'bad json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        // Collection create malformed JSON -> 400
        $client->request('POST', "/api/projects/{$this->projectUuid}/collections", [], [], ['CONTENT_TYPE' => 'application/json'], 'bad json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testProjectSettingsTokenValidationAndJsonSafety(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-settings-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        // 1. Malformed JSON on tokens endpoint -> 400
        $client->request('POST', "/api/projects/{$this->projectUuid}/settings/tokens", [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        // 2. Past expires_at -> 422
        $client->request('POST', "/api/projects/{$this->projectUuid}/settings/tokens", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Expired Token',
            'expires_at' => '2020-01-01T00:00:00Z',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 3. Invalid expires_at format -> 422
        $client->request('POST', "/api/projects/{$this->projectUuid}/settings/tokens", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Invalid Date Token',
            'expires_at' => 'invalid-date-string',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testEndUserAuthMalformedJsonReturns400(): void
    {
        $client = static::createClient();

        // Register malformed JSON -> 400
        $client->request('POST', "/api/{$this->projectUuid}/auth/register", [], [], ['CONTENT_TYPE' => 'application/json'], 'bad json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        // Login malformed JSON -> 400
        $client->request('POST', "/api/{$this->projectUuid}/auth/login", [], [], ['CONTENT_TYPE' => 'application/json'], 'bad json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTusUploadIdPathTraversalProtection(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-tus-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        // Path traversal in uploadId must return 404
        $client->request('HEAD', "/api/projects/{$this->projectUuid}/files/tus/../../etc/passwd");
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('POST', "/api/projects/{$this->projectUuid}/files/tus/../../etc/passwd/finalize");
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testImageTransformAccessControl(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // 1. Create a private project
        $privateProject = new Project();
        $privateProject->name = 'Private Project ' . bin2hex(random_bytes(3));
        $privateProject->publicApi = false;
        $privateProject->defaultLocale = 'fr';
        $privateProject->locales = ['fr'];
        $em->persist($privateProject);

        // 2. Create media for this private project
        $media = new \App\Entity\Media();
        $media->project = $privateProject;
        $media->fileName = 'test.jpg';
        $media->originalName = 'test.jpg';
        $media->mimeType = 'image/jpeg';
        $media->fileSize = 100;
        $em->persist($media);
        $em->flush();

        $mediaUuid = $media->uuid->toRfc4122();

        // 3. Unauthenticated user -> 403
        $client->request('GET', "/cdn/media/{$mediaUuid}");
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 4. Authenticated non-member user -> 403
        $nonMember = new User();
        $nonMember->email = 'outsider-' . bin2hex(random_bytes(4)) . '@example.com';
        $nonMember->password = 'hashed';
        $nonMember->roles = ['ROLE_USER'];
        $em->persist($nonMember);
        $em->flush();

        $client->loginUser($nonMember);
        $client->request('GET', "/cdn/media/{$mediaUuid}");
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 5. Super admin -> access allowed (404 because file doesn't exist on disk, but NOT 403)
        $admin = new User();
        $admin->email = 'admin-media-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', "/cdn/media/{$mediaUuid}");
        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    public function testVersioningMalformedUuidAndDiff404(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-version-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        // Malformed UUID string in versioning should return 404, not 500
        $client->request('GET', "/api/projects/{$this->projectUuid}/collections/articles/entries/not-a-uuid/versions");
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('GET', "/api/projects/{$this->projectUuid}/collections/articles/entries/not-a-uuid/versions/diff?v1=1&v2=2");
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCollectionTemplateAuthRequirement(): void
    {
        $client = static::createClient();

        // Anonymous creation must be rejected (401)
        $client->request('POST', '/api/collection-templates', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Public Template Attempt',
        ]));
        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAdminFormValidationAndUniqueSlug(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-form-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        // 1. Missing name -> 422
        $client->request('POST', "/admin-api/{$this->projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => '',
            'fields' => [],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 2. Valid name with omitted slug -> auto-slug and 201
        $client->request('POST', "/admin-api/{$this->projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Contact Us',
            'fields' => [],
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('contact-us', $data['slug']);

        // 3. Second form with same name -> slug deduplication
        $client->request('POST', "/admin-api/{$this->projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Contact Us',
            'fields' => [],
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $data2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotSame($data['slug'], $data2['slug']);
    }

    public function testStudioInvalidJsonSafety(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-studio-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        // Invalid JSON body -> 400
        $client->request('POST', "/api/projects/{$this->projectUuid}/studio/schema", [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $client->request('POST', "/api/projects/{$this->projectUuid}/studio/apply-entries", [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $client->request('POST', "/api/projects/{$this->projectUuid}/studio/ai-schema", [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSearchValidationAndQuerySanitization(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-search-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        // Empty query -> 400
        $client->request('GET', "/api/projects/{$this->projectUuid}/search?q=");
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        // Valid search -> 200
        $client->request('GET', "/api/projects/{$this->projectUuid}/search?q=test");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('hits', $data);
    }

    public function testEavFieldHelperSafeHandling(): void
    {
        $helper = self::getContainer()->get(\App\Service\EavFieldHelperService::class);
        $cfv = new \App\Entity\ContentFieldValue();

        // 1. Boolean normalization
        $helper->setFieldValue($cfv, 'boolean', '1');
        $this->assertTrue($cfv->booleanValue);

        $helper->setFieldValue($cfv, 'boolean', '0');
        $this->assertFalse($cfv->booleanValue);

        $helper->setFieldValue($cfv, 'checkbox', true);
        $this->assertTrue($cfv->booleanValue);

        $helper->setFieldValue($cfv, 'boolean', null);
        $this->assertNull($cfv->booleanValue);

        // 2. Date exception safety
        $helper->setFieldValue($cfv, 'date', 'invalid-date-string');
        $this->assertNull($cfv->dateValue);

        $helper->setFieldValue($cfv, 'date', '2026-11-04');
        $this->assertInstanceOf(\DateTimeInterface::class, $cfv->dateValue);

        // 3. Json parsing
        $helper->setFieldValue($cfv, 'json', '{"key":"val"}');
        $this->assertSame(['key' => 'val'], $cfv->jsonValue);

        $helper->setFieldValue($cfv, 'json', 'malformed json');
        $this->assertNull($cfv->jsonValue);

        // 4. Default array to string safety
        $helper->setFieldValue($cfv, 'text', ['nested' => 'data']);
        $this->assertSame('{"nested":"data"}', $cfv->textValue);
    }

    public function testFieldValueHydratorSafeHandling(): void
    {
        $hydrator = self::getContainer()->get(\App\Service\FieldValueHydrator::class);
        $cfv = new \App\Entity\ContentFieldValue();

        // 1. Decimal / number as string
        $hydrator->hydrate($cfv, 42.5, 'decimal');
        $this->assertSame('42.5', $cfv->numberValue);

        // 2. Boolean
        $hydrator->hydrate($cfv, '1', 'boolean');
        $this->assertTrue($cfv->booleanValue);

        // 3. Date malformed safety
        $hydrator->hydrate($cfv, 'not-a-date', 'date');
        $this->assertNull($cfv->dateValue);

        // 4. Time
        $hydrator->hydrate($cfv, '14:30', 'time');
        $this->assertSame('14:30', $cfv->textValue);
    }

    public function testProjectAuthLockoutIncrementAndLockout(): void
    {
        $lockout = self::getContainer()->get(\App\Service\ProjectAuthLockoutService::class);
        $id = 'test-user-' . bin2hex(random_bytes(4)) . '@example.com';

        $this->assertFalse($lockout->isLockedOut($id));

        $a1 = $lockout->recordFailedAttempt($id);
        $this->assertSame(1, $a1);
        $this->assertFalse($lockout->isLockedOut($id));

        $a2 = $lockout->recordFailedAttempt($id);
        $this->assertSame(2, $a2);

        $lockout->recordFailedAttempt($id); // 3
        $lockout->recordFailedAttempt($id); // 4
        $a5 = $lockout->recordFailedAttempt($id); // 5

        $this->assertSame(5, $a5);
        $this->assertTrue($lockout->isLockedOut($id));
        $this->assertGreaterThan(0, $lockout->getRemainingLockoutSeconds($id));

        $lockout->clearAttempts($id);
        $this->assertFalse($lockout->isLockedOut($id));
    }

    public function testRealtimeControllerAuthenticationAndFallback(): void
    {
        $client = static::createClient();

        // 1. Unauthenticated token request -> 401 JSON
        $client->request('GET', "/api/projects/{$this->projectUuid}/realtime/token");
        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Authentication required', $data['error']);

        // 2. Unauthenticated poll request -> 401 JSON
        $client->request('GET', "/api/projects/{$this->projectUuid}/realtime");
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        // 3. Authenticated member -> 200 JSON
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = new User();
        $admin->email = 'admin-rt-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        $client->request('GET', "/api/projects/{$this->projectUuid}/realtime/token");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $tokenData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('enabled', $tokenData);

        $client->request('GET', "/api/projects/{$this->projectUuid}/realtime");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $pollData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('events', $pollData);
    }

    public function testLinkCheckerHtmlAndMarkdownExtraction(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entryRepo = $em->getRepository(\App\Entity\ContentEntry::class);
        $linkChecker = new \App\Service\Redirect\LinkChecker($entryRepo);

        $entry = new \App\Entity\ContentEntry();
        $cfv1 = new \App\Entity\ContentFieldValue();
        $cfv1->fieldType = 'richtext';
        $cfv1->textValue = '<p>Check <a href="https://example.com/double">Double</a> and <a href=\'/single\'>Single</a></p>';
        $entry->fieldValues->add($cfv1);

        $cfv2 = new \App\Entity\ContentFieldValue();
        $cfv2->fieldType = 'markdown';
        $cfv2->textValue = 'Visit [My Page](/my-page) or [External](https://google.com)';
        $entry->fieldValues->add($cfv2);

        $scanned = $linkChecker->scanEntry($entry);
        $this->assertCount(4, $scanned);

        $urls = array_column($scanned, 'url');
        $this->assertContains('https://example.com/double', $urls);
        $this->assertContains('/single', $urls);
        $this->assertContains('/my-page', $urls);
        $this->assertContains('https://google.com', $urls);
    }

    public function testShareControllerInvalidJsonSafety(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-share-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        $client->request('POST', "/api/projects/{$this->projectUuid}/shares", [], [], ['CONTENT_TYPE' => 'application/json'], 'not a json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }

    public function testDeploymentsUnauthenticatedRejection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/deployments');
        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }
}
