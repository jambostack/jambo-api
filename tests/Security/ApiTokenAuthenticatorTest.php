<?php

namespace App\Tests\Security;

use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Security\ApiTokenAuthenticator;
use App\Security\PersonalAccessTokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class ApiTokenAuthenticatorTest extends TestCase
{
    public function testRejectsEmptyBearerToken(): void
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $authenticator = new ApiTokenAuthenticator($repo);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer ');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('auth.api_token.not_provided');

        $authenticator->authenticate($req);
    }

    public function testRejectsOversizedToken(): void
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $authenticator = new ApiTokenAuthenticator($repo);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer ' . str_repeat('a', 1025));

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('auth.api_token.invalid');

        $authenticator->authenticate($req);
    }

    public function testRejectsExpiredToken(): void
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $token = new ApiToken();
        $token->expiresAt = new \DateTimeImmutable('-1 hour');

        $repo->method('findByPlainToken')->willReturn($token);
        $authenticator = new ApiTokenAuthenticator($repo);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer valid_looking_token');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('auth.api_token.expired');

        $authenticator->authenticate($req);
    }

    public function testAuthenticationFailureReturnsTranslatedMessage(): void
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->with('auth.api_token.expired')->willReturn('Le jeton API a expiré.');

        $authenticator = new ApiTokenAuthenticator($repo, $translator);

        $response = $authenticator->onAuthenticationFailure(
            new Request(),
            new CustomUserMessageAuthenticationException('auth.api_token.expired')
        );

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Le jeton API a expiré.', $data['error']);
    }

    public function testPersonalAccessTokenRejectsOversizedToken(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $authenticator = new PersonalAccessTokenAuthenticator($repo, $em, 'secret');

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer ' . str_repeat('b', 1025));

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('auth.pat.invalid_or_expired');

        $authenticator->authenticate($req);
    }
}
