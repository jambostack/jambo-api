<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiDocsSecurityTest extends WebTestCase
{
    public function testEscapesProjectNameAgainstXss(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = '<script>alert("xss")</script> & "quotes"';
        $project->publicApi = true;
        $em->persist($project);
        $em->flush();

        $client->request('GET', '/api/docs/' . $project->uuid->toRfc4122());

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // Must not contain raw script tag
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $content);
        // Must contain escaped HTML entities
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $content);

        // Must have security headers
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $client->getResponse()->headers->get('X-Frame-Options'));
    }

    public function testRejectsUnauthenticatedAccessWhenProjectIsPrivate(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Private Project ' . bin2hex(random_bytes(4));
        $project->publicApi = false;
        $em->persist($project);
        $em->flush();

        $client->request('GET', '/api/docs/' . $project->uuid->toRfc4122());

        $this->assertResponseStatusCodeSame(401);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('Authentication required', $json['error'] ?? null);
    }

    public function testAllowsPublicAccessWhenProjectIsPublic(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Public Project ' . bin2hex(random_bytes(4));
        $project->publicApi = true;
        $em->persist($project);
        $em->flush();

        $client->request('GET', '/api/docs/' . $project->uuid->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('SwaggerUIBundle', (string) $client->getResponse()->getContent());
    }
}
