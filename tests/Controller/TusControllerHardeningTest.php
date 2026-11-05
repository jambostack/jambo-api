<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TusControllerHardeningTest extends WebTestCase
{
    private function createAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Tus Admin';
        $user->email = 'admin_tus_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em, array $settings = []): Project
    {
        $project = new Project();
        $project->name = 'Tus Project ' . bin2hex(random_bytes(4));
        $project->settings = $settings;
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testMissingFilenameInMetadataRejected(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $client->request('POST', "/api/projects/{$projectUuid}/files/tus", [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'HTTP_UPLOAD_LENGTH' => '1024',
            'HTTP_UPLOAD_METADATA' => 'filetype ' . base64_encode('image/png'),
        ]);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('MISSING_FILENAME', $data['code']);
    }

    public function testUploadLengthExceedsProjectLimitRejected(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Project limits uploads to 2 MB
        $project = $this->createProject($em, ['upload_max_file_size_mb' => 2]);
        $projectUuid = $project->uuid->toString();

        // Attempting to upload 5 MB
        $fiveMb = 5 * 1024 * 1024;
        $client->request('POST', "/api/projects/{$projectUuid}/files/tus", [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'HTTP_UPLOAD_LENGTH' => (string) $fiveMb,
            'HTTP_UPLOAD_METADATA' => 'filename ' . base64_encode('test.png') . ',filetype ' . base64_encode('image/png'),
        ]);

        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('UPLOAD_LENGTH_EXCEEDS_PROJECT_LIMIT', $data['code']);
    }
}
