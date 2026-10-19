<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserManagementSecurityTest extends WebTestCase
{
    public function testAnonymousUsersEndpointIsDenied(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/users');
        $this->assertContains($client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testAnonymousRolesEndpointIsDenied(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/roles');
        $this->assertContains($client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testAnonymousPermissionsEndpointIsDenied(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/permissions');
        $this->assertContains($client->getResponse()->getStatusCode(), [302, 401, 403]);
    }
}
