<?php

namespace App\Tests\Controller\Settings;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PersonalAccessTokenControllerTest extends WebTestCase
{
    private const ENDPOINT = '/api/settings/personal-access-tokens';

    private function createUserAndLogin(KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'PAT Test User';
        $user->email = 'pat_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_USER'];

        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    public function testAdminApiOpenApiSpecIsServed(): void
    {
        $client = static::createClient();
        $this->createUserAndLogin($client);

        $client->request('GET', '/api/settings/admin-api/openapi.json');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $spec = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('PersonalAccessToken', $spec['components']['securitySchemes']);
        // Couvre les endpoints collections/champs (ceux qui posaient problème) + projets/jetons.
        $this->assertArrayHasKey('/projects/{uuid}/collections', $spec['paths']);
        $this->assertArrayHasKey('post', $spec['paths']['/projects/{uuid}/collections']);
        $this->assertArrayHasKey('get', $spec['paths']['/projects/{uuid}/collections']);
        $this->assertArrayHasKey('/projects/{uuid}/collections/{slug}/fields/{fieldSlug}', $spec['paths']);
        $this->assertArrayHasKey('/tokens', $spec['paths']);
    }

    public function testCreateListAndRevokeFlow(): void
    {
        $client = static::createClient();
        $this->createUserAndLogin($client);

        // Création — le jeton en clair est renvoyé une seule fois
        $client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'CI deploy', 'scopes' => ['schema:write']]));

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode($client->getResponse()->getContent(), true)['data'];
        $this->assertArrayHasKey('token', $created);
        $this->assertStringStartsWith('jbo_pat_', $created['token']);
        $this->assertSame(['schema:write'], $created['scopes']);
        $id = $created['id'];

        // Liste — sans le clair
        $client->request('GET', self::ENDPOINT);
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode($client->getResponse()->getContent(), true)['data'];
        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('token', $list[0]);
        $this->assertSame('CI deploy', $list[0]['name']);

        // Révocation
        $client->request('DELETE', self::ENDPOINT . '/' . $id);
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', self::ENDPOINT);
        $list = json_decode($client->getResponse()->getContent(), true)['data'];
        $this->assertCount(0, $list);
    }

    public function testCreateRequiresName(): void
    {
        $client = static::createClient();
        $this->createUserAndLogin($client);

        $client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => '']));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', self::ENDPOINT);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }
}
