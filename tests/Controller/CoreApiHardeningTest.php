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
}
