<?php

namespace App\Tests\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TokenManagementTest extends WebTestCase
{
    public function testListTokensOfAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'tk-' . bin2hex(random_bytes(4)) . '@e.com';
        $user->password = 'x';
        $em->persist($user);
        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 'primary';
        $pat->user = $user;
        $pat->scopes = ['schema:write'];
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();

        $client->request('GET', '/admin-api/tokens', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('primary', $body['data'][0]['name']);
        self::assertArrayNotHasKey('tokenHash', $body['data'][0]);
    }

    public function testCreateTokenReturnsPlaintextOnce(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'tk2-' . bin2hex(random_bytes(4)) . '@e.com';
        $user->password = 'x';
        $em->persist($user);
        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 'bootstrap';
        $pat->user = $user;
        $pat->scopes = ['projects:write', 'schema:write'];
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();

        $client->request('POST', '/admin-api/tokens', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['name' => 'ci', 'scopes' => ['schema:write']]));

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertStringStartsWith('jbo_pat_', $body['data']['token']);
    }
}
