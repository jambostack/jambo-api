<?php

namespace App\Tests\Security;

use App\Dto\SocialUser;
use App\Entity\User;
use App\Security\SocialLoginAuthenticator;
use App\Service\SocialLoginService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class SocialLoginAuthenticatorTest extends TestCase
{
    private SocialLoginService $socialLogin;
    private UrlGeneratorInterface $urlGenerator;
    private SocialLoginAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->socialLogin = $this->createMock(SocialLoginService::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->authenticator = new SocialLoginAuthenticator($this->socialLogin, $this->urlGenerator);
    }

    public function testSupportsRequiresConnectCheckRouteAndCodeParam(): void
    {
        $req1 = new Request([], [], ['_route' => 'connect_check']);
        $this->assertFalse($this->authenticator->supports($req1));

        $req2 = new Request(['code' => 'auth-123'], [], ['_route' => 'connect_check']);
        $this->assertTrue($this->authenticator->supports($req2));

        $req3 = new Request(['code' => 'auth-123'], [], ['_route' => 'other_route']);
        $this->assertFalse($this->authenticator->supports($req3));
    }

    public function testAuthenticateRejectsMismatchedCsrfState(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2state_github', 'valid-csrf-token-123');

        $request = new Request(
            query: ['code' => 'gh-code', 'state' => 'wrong-state'],
            attributes: ['_route' => 'connect_check', 'provider' => 'github']
        );
        $request->setSession($session);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired OAuth CSRF state parameter.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateConsumesStateAndIssuesPassportOnMatch(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2state_google', 'secure-state-token');

        $request = new Request(
            query: ['code' => 'google-code-abc', 'state' => 'secure-state-token'],
            attributes: ['_route' => 'connect_check', 'provider' => 'google']
        );
        $request->setSession($session);

        $this->urlGenerator->method('generate')->willReturn('https://test.site/connect/google/check');

        $socialUser = new SocialUser(
            providerId: 'google-sub-123',
            email: 'admin@test.site',
            username: 'Test Admin',
            avatarUrl: null
        );
        $this->socialLogin->method('getUserFromProvider')->willReturn($socialUser);

        $user = new User();
        $user->email = 'admin@test.site';
        $user->googleId = 'google-sub-123';
        $this->socialLogin->method('findOrCreateAdminUser')->willReturn($user);

        $passport = $this->authenticator->authenticate($request);

        $this->assertNotNull($passport);
        $this->assertSame($user, $passport->getUser());
        // Verify state was consumed from session (one-time protection against replay)
        $this->assertNull($session->get('oauth2state_google'));
    }
}
