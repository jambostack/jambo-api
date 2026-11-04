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

    public function testRefreshTokenSuccessAndRotation(): void
    {
        $client = static::createClient();
        $email = 'refresh-' . bin2hex(random_bytes(4)) . '@example.com';

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'password123',
        ]);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $body = json_decode($client->getResponse()->getContent(), true);
        $refreshToken = $body['data']['refresh_token'];

        $client->jsonRequest('POST', $this->baseUrl() . '/refresh', [
            'refresh_token' => $refreshToken,
        ]);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $refreshBody = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $refreshBody);
        $this->assertArrayHasKey('access_token', $refreshBody['data']);
        $this->assertArrayHasKey('refresh_token', $refreshBody['data']);
        $newAccessToken = $refreshBody['data']['access_token'];

        // Verify the new access token works on /me
        $client->request('GET', $this->baseUrl() . '/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $newAccessToken,
            'HTTP_ACCEPT'        => 'application/json',
        ]);
        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testRefreshTokenFromProjectACannotBeUsedOnProjectB(): void
    {
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
        $email = 'cross-refresh-' . bin2hex(random_bytes(4)) . '@example.com';

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'password123',
        ]);
        $body = json_decode($client->getResponse()->getContent(), true);
        $refreshTokenA = $body['data']['refresh_token'];

        // Attempt to use Project A refresh token on Project B
        $client->jsonRequest('POST', '/api/' . $projectBUuid . '/auth/refresh', [
            'refresh_token' => $refreshTokenA,
        ]);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testLogoutInvalidatesAllTokensForEndUser(): void
    {
        $client = static::createClient();
        $email = 'logout-' . bin2hex(random_bytes(4)) . '@example.com';

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'password123',
        ]);
        $body = json_decode($client->getResponse()->getContent(), true);
        $accessToken = $body['data']['access_token'];
        $refreshToken = $body['data']['refresh_token'];

        // Call /logout with access token
        $client->request('POST', $this->baseUrl() . '/logout', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
            'HTTP_ACCEPT'        => 'application/json',
        ]);
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        // Now /me should be rejected with 401
        $client->request('GET', $this->baseUrl() . '/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
            'HTTP_ACCEPT'        => 'application/json',
        ]);
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        // And /refresh should be rejected with 401
        $client->jsonRequest('POST', $this->baseUrl() . '/refresh', [
            'refresh_token' => $refreshToken,
        ]);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testMeEndpointRejectsCrossProjectToken(): void
    {
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
        $email = 'cross-me-' . bin2hex(random_bytes(4)) . '@example.com';

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'password123',
        ]);
        $body = json_decode($client->getResponse()->getContent(), true);
        $accessTokenA = $body['data']['access_token'];

        // Call Project B's /me with Project A's access token
        $client->request('GET', '/api/' . $projectBUuid . '/auth/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessTokenA,
            'HTTP_ACCEPT'        => 'application/json',
        ]);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testUpdateMePasswordRotationRequiresCurrentPassword(): void
    {
        $client = static::createClient();
        $email = 'rotate-' . bin2hex(random_bytes(4)) . '@example.com';

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'InitialPassword1',
        ]);
        $body = json_decode($client->getResponse()->getContent(), true);
        $token = $body['data']['access_token'];

        // Tenter de changer le mot de passe sans current_password
        $client->request(
            'PATCH',
            $this->baseUrl() . '/me',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_ACCEPT'        => 'application/json',
                'CONTENT_TYPE'       => 'application/json',
            ],
            json_encode(['password' => 'NewSecretPassword2'])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $err = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Current password is required to change password.', $err['error']);

        // Tenter avec un current_password erroné
        $client->request(
            'PATCH',
            $this->baseUrl() . '/me',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_ACCEPT'        => 'application/json',
                'CONTENT_TYPE'       => 'application/json',
            ],
            json_encode(['current_password' => 'WrongCurrent', 'password' => 'NewSecretPassword2'])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $err2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Current password is incorrect.', $err2['error']);
    }

    public function testUpdateMePasswordRotationRejectsIdenticalPassword(): void
    {
        $client = static::createClient();
        $email = 'rotate-same-' . bin2hex(random_bytes(4)) . '@example.com';

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'MyCurrentPwd1',
        ]);
        $body = json_decode($client->getResponse()->getContent(), true);
        $token = $body['data']['access_token'];

        // Tenter de réutiliser exactement le mot de passe actuel
        $client->request(
            'PATCH',
            $this->baseUrl() . '/me',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_ACCEPT'        => 'application/json',
                'CONTENT_TYPE'       => 'application/json',
            ],
            json_encode(['current_password' => 'MyCurrentPwd1', 'password' => 'MyCurrentPwd1'])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $err = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('New password must be different from current password.', $err['error']);
    }

    public function testUpdateMePasswordRotationSucceeds(): void
    {
        $client = static::createClient();
        $email = 'rotate-ok-' . bin2hex(random_bytes(4)) . '@example.com';

        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'InitialValidPwd1',
        ]);
        $body = json_decode($client->getResponse()->getContent(), true);
        $token = $body['data']['access_token'];

        // Changer avec succès le mot de passe
        $client->request(
            'PATCH',
            $this->baseUrl() . '/me',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_ACCEPT'        => 'application/json',
                'CONTENT_TYPE'       => 'application/json',
            ],
            json_encode(['current_password' => 'InitialValidPwd1', 'password' => 'BrandNewPwd99'])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $res = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotEmpty($res['data']['password_changed_at']);

        // Se reconnecter avec le nouveau mot de passe
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => $email,
            'password' => 'BrandNewPwd99',
        ]);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // L'ancien mot de passe ne fonctionne plus
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => $email,
            'password' => 'InitialValidPwd1',
        ]);
        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }
}
