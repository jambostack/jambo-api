<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsPassingJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->contains('content-type', 'application/json'));

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame('pass', $data['status']);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('checks', $data);
        $this->assertSame('pass', $data['checks']['database']['status']);
    }

    public function testApiHealthEndpointIsIdentical(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('pass', $data['status']);
    }
}
