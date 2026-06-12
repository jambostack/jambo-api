<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Garde global de l'API admin (/api/projects/*) via EnsureProjectMemberSubscriber :
 * - les requêtes anonymes sont refusées (401) ;
 * - les jetons API valides passent, avec capacité requise selon la méthode HTTP
 *   (read → GET, create → POST, update → PATCH/PUT, delete → DELETE, write → tout) ;
 * - les jetons d'un autre projet sont refusés.
 */
class AdminApiSecurityTest extends WebTestCase
{
    private string $projectUuid;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'AdminApi ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $collection = new Collection();
        $collection->project = $project;
        $collection->name = 'Posts';
        $collection->slug = 'posts';
        $collection->order = 0;
        $em->persist($collection);
        $em->flush();

        $this->projectUuid = $project->uuid->toString();
        $this->projectId = $project->id;

        self::ensureKernelShutdown();
    }

    private function fieldsUrl(): string
    {
        return '/api/projects/' . $this->projectUuid . '/collections/posts/fields';
    }

    private function createToken(array $abilities, ?int $projectId = null): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $projectId ?? $this->projectId);

        $plain = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name = 'sec-' . implode('-', $abilities);
        $token->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $token->tokenVersion = 2;
        $token->abilities = $abilities;
        $token->project = $project;
        $em->persist($token);
        $em->flush();

        return $plain;
    }

    private function bearer(KernelBrowser $client, string $method, string $url, string $plain, array $payload = []): void
    {
        $client->jsonRequest($method, $url, $payload, ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain]);
    }

    public function testAnonymousGetIsRejected(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', $this->fieldsUrl());

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAnonymousPostIsRejected(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', $this->fieldsUrl(), ['name' => 'Hacked', 'type' => 'text']);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testReadTokenCanGet(): void
    {
        $client = static::createClient();
        $this->bearer($client, 'GET', $this->fieldsUrl(), $this->createToken(['read']));

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testReadTokenCannotPost(): void
    {
        $client = static::createClient();
        $this->bearer($client, 'POST', $this->fieldsUrl(), $this->createToken(['read']), [
            'name' => 'Champ', 'type' => 'text',
        ]);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCreateTokenCanPost(): void
    {
        $client = static::createClient();
        $this->bearer($client, 'POST', $this->fieldsUrl(), $this->createToken(['create']), [
            'name' => 'Champ', 'type' => 'text',
        ]);

        $this->assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testTokenOfAnotherProjectIsRejected(): void
    {
        $client = static::createClient();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $other = new Project();
        $other->name = 'OtherSec ' . bin2hex(random_bytes(4));
        $em->persist($other);
        $em->flush();

        $this->bearer($client, 'GET', $this->fieldsUrl(), $this->createToken(['write'], $other->id));

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    /** Les routes publiques hors /api/projects/ ne doivent pas être affectées. */
    public function testPublicCaptchaRouteStaysAccessible(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/' . $this->projectUuid . '/captcha');

        $this->assertNotSame(401, $client->getResponse()->getStatusCode());
    }
}
