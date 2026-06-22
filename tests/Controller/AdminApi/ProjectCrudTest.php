<?php

namespace App\Tests\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProjectCrudTest extends WebTestCase
{
    /** @return array{string,User} */
    private function patFor(array $scopes): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'u-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 't';
        $pat->user = $user;
        $pat->scopes = $scopes;
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();

        return [$plain, $user];
    }

    public function testCreateProjectRequiresScope(): void
    {
        $client = static::createClient();
        [$plain] = $this->patFor(['schema:write']); // pas projects:write
        $client->request('POST', '/admin-api/projects', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['name' => 'X']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCreateProjectSucceeds(): void
    {
        $client = static::createClient();
        [$plain] = $this->patFor(['projects:write']);
        $client->request('POST', '/admin-api/projects', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['name' => 'Eureka', 'default_locale' => 'fr']));

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('uuid', $body['data']);
    }
}
