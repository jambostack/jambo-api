<?php

namespace App\Tests\Controller;

use App\Controller\ProjectOAuthController;
use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\ProjectAuthClient;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use App\Service\EndUserJwtService;
use App\Service\ProjectAuthLockoutService;
use App\Service\ProjectOAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProjectOAuthControllerTest extends TestCase
{
    private ProjectRepository $projectRepo;
    private EndUserRepository $endUserRepo;
    private ProjectOAuthService $oauthService;
    private EndUserJwtService $jwtService;
    private ProjectAuthLockoutService $lockoutService;
    private UserPasswordHasherInterface $passwordHasher;
    private ProjectOAuthController $controller;

    protected function setUp(): void
    {
        $this->projectRepo = $this->createMock(ProjectRepository::class);
        $this->endUserRepo = $this->createMock(EndUserRepository::class);
        $this->oauthService = $this->createMock(ProjectOAuthService::class);
        $this->jwtService = $this->createMock(EndUserJwtService::class);
        $this->lockoutService = $this->createMock(ProjectAuthLockoutService::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $this->controller = new ProjectOAuthController(
            $this->projectRepo,
            $this->endUserRepo,
            $this->oauthService,
            $this->jwtService,
            $this->lockoutService,
            $this->passwordHasher
        );
    }

    public function testAuthorizeWithInvalidProjectReturns404(): void
    {
        $this->projectRepo->method('findOneBy')->willReturn(null);

        $request = new Request();
        $response = $this->controller->authorize('invalid-uuid', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAuthorizeWithoutClientIdReturns400(): void
    {
        $project = new Project();
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $request = new Request();
        $response = $this->controller->authorize('proj-uuid', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAuthorizeUnsupportedResponseTypeRedirectsWithError(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->redirectUris = ['https://myapp.test/callback'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $request = new Request([
            'client_id' => 'client_123',
            'redirect_uri' => 'https://myapp.test/callback',
            'response_type' => 'token', // invalid
            'state' => 'xyz123',
        ]);

        $response = $this->controller->authorize('proj-uuid', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('error=unsupported_response_type', $response->getTargetUrl());
        $this->assertStringContainsString('state=xyz123', $response->getTargetUrl());
    }

    public function testAuthorizeUnauthenticatedJsonReturnsLoginRequired401(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->name = 'My App';
        $client->redirectUris = ['https://myapp.test/callback'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $request = new Request([
            'client_id' => 'client_123',
            'redirect_uri' => 'https://myapp.test/callback',
            'response_type' => 'code',
            'format' => 'json',
        ]);

        $response = $this->controller->authorize('proj-uuid', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('login_required', $data['error']);
    }

    public function testAuthorizeWithValidCredentialsIssuesCodeAndRedirects(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->name = 'My App';
        $client->redirectUris = ['https://myapp.test/callback'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $endUser = new EndUser($project, 'user@example.com');
        $endUser->id = 42;
        $endUser->status = 'active';

        $this->endUserRepo->method('findOneByProjectAndEmail')->willReturn($endUser);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $this->oauthService->expects($this->once())
            ->method('createAuthorizationCode')
            ->with($client, $endUser, 'https://myapp.test/callback', ['openid', 'profile', 'email'])
            ->willReturn('auth_code_secret_xyz');

        $request = new Request(
            query: [
                'client_id' => 'client_123',
                'redirect_uri' => 'https://myapp.test/callback',
                'response_type' => 'code',
                'state' => 'secret_state_value',
            ],
            request: [
                'email' => 'user@example.com',
                'password' => 'SecurePass123!',
                'action' => 'authorize',
            ]
        );
        $request->setMethod('POST');

        $response = $this->controller->authorize('proj-uuid', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            'https://myapp.test/callback?code=auth_code_secret_xyz&state=secret_state_value',
            $response->getTargetUrl()
        );
    }

    public function testAuthorizeWithDenyActionRedirectsWithAccessDenied(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->name = 'My App';
        $client->redirectUris = ['https://myapp.test/callback'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $request = new Request(
            query: [
                'client_id' => 'client_123',
                'redirect_uri' => 'https://myapp.test/callback',
                'response_type' => 'code',
                'state' => 'xyz',
            ],
            request: [
                'action' => 'deny',
            ]
        );
        $request->setMethod('POST');

        $response = $this->controller->authorize('proj-uuid', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('error=access_denied', $response->getTargetUrl());
        $this->assertStringContainsString('state=xyz', $response->getTargetUrl());
    }

    public function testAuthorizeWithAuthenticatedBearerToken(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->redirectUris = ['https://myapp.test/callback'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $endUser = new EndUser($project, 'bearer@example.com');
        $endUser->id = 7;
        $endUser->status = 'active';

        $this->jwtService->method('validateToken')->with('valid_jwt_token')->willReturn(['sub' => 7]);
        $this->endUserRepo->method('find')->with(7)->willReturn($endUser);

        $this->oauthService->expects($this->once())
            ->method('createAuthorizationCode')
            ->willReturn('code_via_bearer');

        $request = new Request([
            'client_id' => 'client_123',
            'redirect_uri' => 'https://myapp.test/callback',
            'response_type' => 'code',
            'format' => 'json',
        ]);
        $request->headers->set('Authorization', 'Bearer valid_jwt_token');

        $response = $this->controller->authorize('proj-uuid', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertSame('code_via_bearer', $data['code']);
    }
}
