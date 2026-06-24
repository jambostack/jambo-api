<?php

namespace App\Tests\Security\Oidc;

use App\Dto\SocialUser;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Repository\EndUserRepository;
use App\Repository\UserRepository;
use App\Security\Oidc\OidcAuthenticator;
use App\Security\Oidc\OidcProviderManager;
use App\Service\EndUserJwtService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class OidcAuthenticatorTest extends TestCase
{
    private OidcProviderManager $oidcManager;
    private UserRepository $userRepo;
    private EndUserRepository $endUserRepo;
    private EntityManagerInterface $em;
    private UrlGeneratorInterface $urlGenerator;
    private AppSettingsRepository $appSettingsRepo;
    private WebhookSecretService $secretService;
    private OidcAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->oidcManager = $this->createMock(OidcProviderManager::class);
        $this->userRepo = $this->createMock(UserRepository::class);
        $this->endUserRepo = $this->createMock(EndUserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $this->secretService = $this->createMock(WebhookSecretService::class);

        $this->authenticator = new OidcAuthenticator(
            $this->oidcManager,
            $this->userRepo,
            $this->endUserRepo,
            $this->em,
            $this->urlGenerator,
            $this->appSettingsRepo,
            $this->secretService,
            null,
        );
    }

    public function testSupportsOnlyOidcCheckRouteWithCode(): void
    {
        $request = new Request(['code' => 'abc123', 'state' => 'xyz']);
        $request->attributes->set('_route', 'oidc_check');
        self::assertTrue($this->authenticator->supports($request));
    }

    public function testDoesNotSupportWithoutCode(): void
    {
        $request = new Request(['state' => 'xyz']);
        $request->attributes->set('_route', 'oidc_check');
        self::assertFalse($this->authenticator->supports($request));
    }

    public function testDoesNotSupportOtherRoute(): void
    {
        $request = new Request(['code' => 'abc123', 'state' => 'xyz']);
        $request->attributes->set('_route', 'app_login');
        self::assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateThrowsOnMissingParams(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'oidc_check');
        $request->setSession(new Session());

        $this->expectException(AuthenticationException::class);
        $this->authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $result = $this->authenticator->onAuthenticationSuccess(
            new Request(),
            $token,
            'main',
        );
        self::assertNull($result);
    }

    public function testOnAuthenticationFailureRedirectsToLogin(): void
    {
        $this->urlGenerator
            ->method('generate')
            ->with('app_login')
            ->willReturn('/login');

        $result = $this->authenticator->onAuthenticationFailure(
            new Request(),
            new AuthenticationException('test'),
        );

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertStringContainsString('/login?error=oidc_failed', $result->getTargetUrl());
    }
}
