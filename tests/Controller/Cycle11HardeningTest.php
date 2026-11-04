<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Service\TusServer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class Cycle11HardeningTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private Project $project;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $this->project = new Project();
        $this->project->name = 'Cycle11 Test ' . bin2hex(random_bytes(4));
        $this->project->publicApi = true;
        $this->em->persist($this->project);

        $this->adminUser = new User();
        $this->adminUser->email = 'admin_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->adminUser->name = 'Admin';
        $this->adminUser->roles = ['ROLE_SUPER_ADMIN'];
        $this->adminUser->password = 'hashed';
        $this->em->persist($this->adminUser);

        $this->em->flush();
    }

    public function testTusOptionsReturnsStandardHeaders(): void
    {
        $this->client->request('OPTIONS', sprintf('/api/projects/%s/files/tus', $this->project->uuid->toString()));

        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('1.0.0', $this->client->getResponse()->headers->get('Tus-Resumable'));
        $this->assertSame('1.0.0', $this->client->getResponse()->headers->get('Tus-Version'));
        $this->assertNotEmpty($this->client->getResponse()->headers->get('Tus-Max-Size'));
    }

    public function testTusCreateRejectsInvalidTusResumable(): void
    {
        $this->client->loginUser($this->adminUser);

        // Missing Tus-Resumable
        $this->client->request('POST', sprintf('/api/projects/%s/files/tus', $this->project->uuid->toString()), [], [], [
            'HTTP_UPLOAD_LENGTH' => '1024',
        ]);

        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertSame('1.0.0', $this->client->getResponse()->headers->get('Tus-Version'));
    }

    public function testTusCreateRejectsMissingOrZeroUploadLength(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('POST', sprintf('/api/projects/%s/files/tus', $this->project->uuid->toString()), [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'HTTP_UPLOAD_LENGTH' => '0',
        ]);

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testTusPatchRejectsWrongContentType(): void
    {
        $this->client->loginUser($this->adminUser);

        // First create an upload
        $tusServer = $this->client->getContainer()->get(TusServer::class);
        $uploadId = $tusServer->create($this->project->uuid->toString(), 100, ['filename' => 'test.txt']);

        // Send PATCH with wrong Content-Type
        $this->client->request(
            'PATCH',
            sprintf('/api/projects/%s/files/tus/%s', $this->project->uuid->toString(), $uploadId),
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE' => '1.0.0',
                'HTTP_UPLOAD_OFFSET' => '0',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"not": "stream"}'
        );

        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
    }

    public function testTusPatchRejectsOffsetMismatch(): void
    {
        $this->client->loginUser($this->adminUser);

        $tusServer = $this->client->getContainer()->get(TusServer::class);
        $uploadId = $tusServer->create($this->project->uuid->toString(), 100, ['filename' => 'test.txt']);

        // Current offset is 0, client sends Upload-Offset: 50
        $this->client->request(
            'PATCH',
            sprintf('/api/projects/%s/files/tus/%s', $this->project->uuid->toString(), $uploadId),
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE' => '1.0.0',
                'HTTP_UPLOAD_OFFSET' => '50',
                'CONTENT_TYPE' => 'application/offset+octet-stream',
            ],
            'chunk'
        );

        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame('0', $this->client->getResponse()->headers->get('Upload-Offset'));
    }

    public function testTusPatchRejectsChunkExceedingUploadLength(): void
    {
        $this->client->loginUser($this->adminUser);

        $tusServer = $this->client->getContainer()->get(TusServer::class);
        $uploadId = $tusServer->create($this->project->uuid->toString(), 5, ['filename' => 'test.txt']);

        // Send 10 bytes for a 5 byte upload
        $this->client->request(
            'PATCH',
            sprintf('/api/projects/%s/files/tus/%s', $this->project->uuid->toString(), $uploadId),
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE' => '1.0.0',
                'HTTP_UPLOAD_OFFSET' => '0',
                'CONTENT_TYPE' => 'application/offset+octet-stream',
            ],
            '0123456789'
        );

        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
    }

    public function testTusFinalizeRejectsIncompleteUpload(): void
    {
        $this->client->loginUser($this->adminUser);

        $tusServer = $this->client->getContainer()->get(TusServer::class);
        $uploadId = $tusServer->create($this->project->uuid->toString(), 100, ['filename' => 'test.txt']);

        // Finalize without uploading all bytes
        $this->client->request(
            'POST',
            sprintf('/api/projects/%s/files/tus/%s/finalize', $this->project->uuid->toString(), $uploadId),
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE' => '1.0.0',
            ]
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not complete', $data['error']);
    }
}
