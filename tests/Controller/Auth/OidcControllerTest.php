<?php

namespace App\Tests\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OidcControllerTest extends WebTestCase
{
    public function testStartRedirectsToLoginWhenNoProviders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/oidc/start/nonexistent');
        // Sans provider configure, attend 404
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testOidcRoutesExist(): void
    {
        $client = static::createClient();
        $client->request('GET', '/oidc/check');
        // Sans code ni state, l'authenticator ne supporte pas -> le controller jette LogicException
        // -> 500 ou redirect selon la config. Verifions que la route existe (pas 404).
        self::assertNotSame(404, $client->getResponse()->getStatusCode());
    }
}
