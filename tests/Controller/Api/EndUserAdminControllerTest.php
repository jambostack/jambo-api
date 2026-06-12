<?php

namespace App\Tests\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\EndUser;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vérifie le mapping capacité de jeton ↔ méthode HTTP de ProjectAwareControllerTrait :
 * un jeton ne doit pouvoir effectuer que les actions couvertes par ses capacités
 * (read → GET, create → POST, update → PATCH, delete → DELETE, write → tout).
 */
class EndUserAdminControllerTest extends WebTestCase
{
    private string $projectUuid;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Token Test ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        $this->projectUuid = $project->uuid->toString();
        $this->projectId = $project->id;

        self::ensureKernelShutdown();
    }

    private function baseUrl(): string
    {
        return '/api/projects/' . $this->projectUuid . '/end-users';
    }

    /** Crée un ApiToken pour le projet de test et retourne le jeton en clair. */
    private function createToken(array $abilities): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $this->projectId);

        $plain = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name = 'test-' . implode('-', $abilities);
        $token->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $token->tokenVersion = 2;
        $token->abilities = $abilities;
        $token->project = $project;
        $em->persist($token);
        $em->flush();

        return $plain;
    }

    /** Crée un EndUser directement en base et retourne son uuid. */
    private function createEndUser(): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $this->projectId);

        $user = new EndUser($project, 'eu-' . bin2hex(random_bytes(4)) . '@example.com');
        $user->password = 'irrelevant-hash';
        $em->persist($user);
        $em->flush();

        return $user->uuid->toString();
    }

    private function request(KernelBrowser $client, string $method, string $url, string $plainToken, array $payload = []): void
    {
        $client->jsonRequest($method, $url, $payload, [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainToken,
        ]);
    }

    public function testReadTokenCanListEndUsers(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['read']);

        $this->request($client, 'GET', $this->baseUrl(), $token);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testDeleteTokenCannotCreateEndUser(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['delete']);

        $this->request($client, 'POST', $this->baseUrl(), $token, [
            'email'    => 'created-' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'password123',
        ]);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCreateTokenCannotDeleteEndUser(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['create']);
        $endUserUuid = $this->createEndUser();

        $this->request($client, 'DELETE', $this->baseUrl() . '/' . $endUserUuid, $token);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCreateTokenCanCreateEndUser(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['create']);

        $this->request($client, 'POST', $this->baseUrl(), $token, [
            'email'    => 'created-' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'password123',
        ]);

        $this->assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testDeleteTokenCanDeleteEndUser(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['delete']);
        $endUserUuid = $this->createEndUser();

        $this->request($client, 'DELETE', $this->baseUrl() . '/' . $endUserUuid, $token);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testUpdateTokenCanPatchEndUser(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['update']);
        $endUserUuid = $this->createEndUser();

        $this->request($client, 'PATCH', $this->baseUrl() . '/' . $endUserUuid, $token, [
            'name' => 'Renamed',
        ]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testLegacyWriteTokenHasFullAccess(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['write']);

        $this->request($client, 'GET', $this->baseUrl(), $token);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->request($client, 'POST', $this->baseUrl(), $token, [
            'email'    => 'legacy-' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'password123',
        ]);
        $this->assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testTokenOfAnotherProjectIsRejected(): void
    {
        $client = static::createClient();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $other = new Project();
        $other->name = 'Other ' . bin2hex(random_bytes(4));
        $em->persist($other);
        $em->flush();

        $plain = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name = 'other-project';
        $token->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $token->tokenVersion = 2;
        $token->abilities = ['write'];
        $token->project = $other;
        $em->persist($token);
        $em->flush();

        $this->request($client, 'GET', $this->baseUrl(), $plain);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * Le filtre uuids[] est utilisé par RelationField pour hydrater les
     * utilisateurs liés d'un champ relation : seuls les uuids demandés
     * doivent être retournés, indépendamment de la pagination.
     */
    public function testIndexFiltersByUuids(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['read']);

        $uuid1 = $this->createEndUser();
        $uuid2 = $this->createEndUser();
        $uuid3 = $this->createEndUser();

        $query = http_build_query(['uuids' => [$uuid1, $uuid3]]);
        $this->request($client, 'GET', $this->baseUrl() . '?' . $query, $token);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);

        $returned = array_column($body['data'], 'uuid');
        sort($returned);
        $expected = [$uuid1, $uuid3];
        sort($expected);

        $this->assertSame($expected, $returned);
        $this->assertNotContains($uuid2, $returned);
    }

    /**
     * Les routes de schéma end-user exigent project.manage : un jeton d'écriture
     * ne doit jamais pouvoir y créer un champ (peu importe le code exact —
     * 403 du trait ou redirection login du firewall).
     */
    public function testWriteTokenCannotManageEndUserSchema(): void
    {
        $client = static::createClient();
        $token = $this->createToken(['write']);

        $this->request($client, 'POST', $this->baseUrl() . '/fields', $token, [
            'name' => 'Phone',
            'type' => 'text',
        ]);

        $this->assertNotSame(200, $client->getResponse()->getStatusCode());
        $this->assertNotSame(201, $client->getResponse()->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $count = $em->getRepository(\App\Entity\EndUserField::class)->count([
            'project' => $em->find(Project::class, $this->projectId),
        ]);
        $this->assertSame(0, $count, 'Aucun champ de schéma ne doit être créé par un jeton API.');
    }
}
