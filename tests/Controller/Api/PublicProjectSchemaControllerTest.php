<?php

namespace App\Tests\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PublicProjectSchemaControllerTest extends WebTestCase
{
    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em        = self::getContainer()->get(EntityManagerInterface::class);
        $appSecret = self::getContainer()->getParameter('kernel.secret');

        $project = new Project();
        $project->name      = 'Schema Test ' . bin2hex(random_bytes(4));
        $project->publicApi = true;
        $em->persist($project);

        $collection = new Collection();
        $collection->name    = 'Posts';
        $collection->slug    = 'posts';
        $collection->project = $project;
        $em->persist($collection);

        $field = new Field();
        $field->name       = 'Title';
        $field->slug       = 'title';
        $field->type       = 'text';
        $field->isRequired = true;
        $field->collection = $collection;
        $em->persist($field);

        $this->plainToken = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name         = 'schema-token';
        $token->abilities    = ['read'];
        $token->tokenHash    = ApiToken::hashToken($this->plainToken, $appSecret);
        $token->tokenVersion = 2;
        $token->project      = $project;
        $em->persist($token);

        $em->flush();
        self::ensureKernelShutdown();
    }

    public function testSchemaReturnsProjectAndCollections(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public-api', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('project', $body);
        $this->assertArrayHasKey('collections', $body);

        $project = $body['project'];
        $this->assertArrayHasKey('uuid', $project);
        $this->assertArrayHasKey('name', $project);
        $this->assertArrayHasKey('defaultLocale', $project);
        $this->assertArrayHasKey('locales', $project);

        $this->assertIsArray($body['collections']);
        $col = $body['collections'][0];
        $this->assertSame('posts', $col['slug']);
        $this->assertArrayHasKey('fields', $col);

        $titleField = $col['fields'][0];
        $this->assertSame('title', $titleField['slug']);
        $this->assertSame('text', $titleField['type']);
        $this->assertTrue($titleField['isRequired']);
    }

    public function testSchemaReturns401WithoutToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public-api');

        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testSchemaReturns401WithInvalidToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public-api', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalidtoken123',
        ]);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }
}
