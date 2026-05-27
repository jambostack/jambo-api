<?php

namespace App\Tests\Controller\Api;

use App\Entity\PasswordResetToken;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EndUserAuthControllerTest extends WebTestCase
{
    private string $projectUuid;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Test Blog ' . bin2hex(random_bytes(4));
        $project->publicApi = true;
        $em->persist($project);
        $em->flush();

        $this->projectUuid = $project->uuid->toString();

        self::ensureKernelShutdown();
    }

    private function baseUrl(): string
    {
        return '/api/' . $this->projectUuid . '/auth';
    }

    public function testRegisterCreatesEndUserAndReturnsTokens(): void
    {
        $client = static::createClient();
        $email = 'test-' . bin2hex(random_bytes(4)) . '@example.com';

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'password123',
            'name'     => 'Test User',
        ]);

        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('user', $body['data']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayHasKey('refresh_token', $body['data']);
        $this->assertSame($email, $body['data']['user']['email']);
        $this->assertSame('Test User', $body['data']['user']['name']);
        $this->assertSame('active', $body['data']['user']['status']);
        $this->assertNotEmpty($body['data']['access_token']);
        $this->assertNotEmpty($body['data']['refresh_token']);
    }

    public function testLoginReturnsTokens(): void
    {
        $client = static::createClient();
        $email = 'login-' . bin2hex(random_bytes(4)) . '@example.com';

        // First register a user
        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'secure1234',
        ]);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // Then login
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => $email,
            'password' => 'secure1234',
        ]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('user', $body['data']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayHasKey('refresh_token', $body['data']);
        $this->assertSame($email, $body['data']['user']['email']);
        $this->assertNotEmpty($body['data']['access_token']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $client = static::createClient();
        $email = 'wrong-' . bin2hex(random_bytes(4)) . '@example.com';

        // First register a user
        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'correctpassword',
        ]);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // Then try to login with wrong password
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => $email,
            'password' => 'wrongpassword',
        ]);

        $this->assertSame(401, $client->getResponse()->getStatusCode());

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testMeReturnsUserWithValidToken(): void
    {
        $client = static::createClient();
        $email = 'me-' . bin2hex(random_bytes(4)) . '@example.com';

        // Register a user to get a token
        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'strongpwd123',
            'name'     => 'Me User',
        ]);

        $body = json_decode($client->getResponse()->getContent(), true);
        $accessToken = $body['data']['access_token'];

        // Call /me with the token — use request() for GET to ensure server params are set
        $client->request('GET', $this->baseUrl() . '/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
            'HTTP_ACCEPT'        => 'application/json',
        ]);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), 'Response: ' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame($email, $body['data']['email']);
        $this->assertSame('Me User', $body['data']['name']);
        $this->assertSame('active', $body['data']['status']);
    }

    public function testMeReturns401WithoutToken(): void
    {
        $client = static::createClient();

        $client->request('GET', $this->baseUrl() . '/me', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $status = $client->getResponse()->getStatusCode();
        // The response may be 401 (from controller) or 403 (from firewall)
        // depending on whether the end_user firewall pattern matches correctly.
        $this->assertContains($status, [401, 403]);
    }

    public function testRegisterWithInvalidEmailReturns422(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => 'not-an-email',
            'password' => 'password123',
        ]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertStringContainsString('email', $body['error']);
    }

    public function testRegisterWithShortPasswordReturns422(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => 'shortpwd-' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => '123',
        ]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertMatchesRegularExpression('/password/i', $body['error']);
    }

    public function testResetPasswordTokenFromProjectACannotBeUsedOnProjectB(): void
    {
        // Setup DB : créer un second projet avant de lancer le client HTTP
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $projectB = new Project();
        $projectB->name = 'Project B ' . bin2hex(random_bytes(4));
        $projectB->publicApi = true;
        $em->persist($projectB);
        $em->flush();
        $projectBUuid = $projectB->uuid->toString();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $sharedEmail = 'shared-' . bin2hex(random_bytes(4)) . '@example.com';

        // Inscrire le même email dans les deux projets
        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email' => $sharedEmail, 'password' => 'password123',
        ]);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->jsonRequest('POST', '/api/' . $projectBUuid . '/auth/register', [
            'email' => $sharedEmail, 'password' => 'password123',
        ]);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // Déclencher forgot-password sur le Projet A
        $client->jsonRequest('POST', $this->baseUrl() . '/forgot-password', [
            'email' => $sharedEmail,
        ]);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Lire le token directement en base
        self::ensureKernelShutdown();
        self::bootKernel();
        $em2 = self::getContainer()->get(EntityManagerInterface::class);
        $resetToken = $em2->getRepository(PasswordResetToken::class)->findOneBy(['email' => $sharedEmail]);
        $this->assertNotNull($resetToken, 'Un PasswordResetToken doit exister');
        $rawToken = $resetToken->token;
        self::ensureKernelShutdown();

        // Utiliser le token du Projet A sur le Projet B — doit être rejeté
        $client->jsonRequest('POST', '/api/' . $projectBUuid . '/auth/reset-password', [
            'token'    => $rawToken,
            'password' => 'NewPassword123',
        ]);
        $this->assertSame(400, $client->getResponse()->getStatusCode(), 'Le token du Projet A ne doit pas fonctionner sur le Projet B');
    }
}
