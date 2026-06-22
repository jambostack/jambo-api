<?php

namespace App\Tests\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthenticationTest extends WebTestCase
{
    private function makeUser(EntityManagerInterface $em): User
    {
        $user = new User();
        $user->email = 'pat-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);
        return $user;
    }

    private function makePat(EntityManagerInterface $em, User $user, array $scopes = ['schema:write']): string
    {
        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 'test';
        $pat->user = $user;
        $pat->scopes = $scopes;
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();
        return $plain;
    }

    public function testAnonymousIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin-api/_ping');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testInvalidTokenIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin-api/_ping', server: ['HTTP_AUTHORIZATION' => 'Bearer jbo_pat_nope']);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testValidPatAuthenticatesAsOwner(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em);
        $plain = $this->makePat($em, $user);

        $client->request('GET', '/admin-api/_ping', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame($user->email, $body['data']['user']);
    }
}
