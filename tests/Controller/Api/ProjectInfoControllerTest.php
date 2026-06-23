<?php

namespace App\Tests\Controller\Api;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProjectInfoControllerTest extends WebTestCase
{
    private string $publicUuid;
    private string $privateUuid;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $public = new Project();
        $public->name      = 'Info Test ' . bin2hex(random_bytes(4));
        $public->publicApi = true;
        $em->persist($public);

        $collection = new Collection();
        $collection->name    = 'Posts';
        $collection->slug    = 'posts';
        $collection->project = $public;
        $em->persist($collection);

        $field = new Field();
        $field->name       = 'Title';
        $field->slug       = 'title';
        $field->type       = 'text';
        $field->isRequired = true;
        $field->collection = $collection;
        $em->persist($field);

        $private = new Project();
        $private->name      = 'Private Test ' . bin2hex(random_bytes(4));
        $private->publicApi = false;
        $em->persist($private);

        $em->flush();

        $this->publicUuid  = $public->uuid->toRfc4122();
        $this->privateUuid = $private->uuid->toRfc4122();

        self::ensureKernelShutdown();
    }

    public function testReturnsProjectInfoAndCollections(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/' . $this->publicUuid);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('project', $body);
        $this->assertSame($this->publicUuid, $body['project']['uuid']);
        $this->assertArrayHasKey('name', $body['project']);
        $this->assertArrayHasKey('locales', $body['project']);

        $this->assertSame('posts', $body['collections'][0]['slug']);
        $this->assertSame('title', $body['collections'][0]['fields'][0]['slug']);
    }

    public function testReturns403WhenPublicApiDisabled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/' . $this->privateUuid);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testReturns404ForUnknownProject(): void
    {
        $client = static::createClient();
        // UUID valide (format) mais inexistant
        $client->request('GET', '/api/00000000-0000-4000-8000-000000000000');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }
}
