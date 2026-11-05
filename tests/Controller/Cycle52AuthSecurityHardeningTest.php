<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\TwoFactorMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle52AuthSecurityHardeningTest extends WebTestCase
{
    private function createUser(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->name = 'Cycle52 Auth User';
        $user->email = 'cycle52-auth-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = '$2y$13$abcdefghijklmnopqrstuvwxyzu37'; // mock hash
        $user->roles = ['ROLE_USER'];
        $user->locale = 'en';
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function assertSecurityHeaders($response): void
    {
        self::assertTrue(
            $response->headers->contains('X-Content-Type-Options', 'nosniff'),
            'Missing X-Content-Type-Options: nosniff'
        );
        self::assertTrue(
            $response->headers->contains('X-Frame-Options', 'DENY'),
            'Missing X-Frame-Options: DENY'
        );
        self::assertTrue(
            str_contains((string) $response->headers->get('Cache-Control'), 'no-store'),
            'Missing Cache-Control: no-store'
        );
    }

    public function testAuthTranslationParityAcrossAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        self::assertIsArray($en);
        self::assertIsArray($fr);
        self::assertIsArray($es);
        self::assertIsArray($ar);

        $keys = [
            'auth.confirm_password.payload_too_large',
            'auth.password_reset.payload_too_large',
            'auth.register.payload_too_large',
            'auth.2fa.payload_too_large',
            'auth.password_reset.email_subject',
            'auth.password_reset.email_heading',
            'auth.password_reset.email_body',
            'auth.password_reset.email_button',
            'auth.password_reset.email_footer',
            'auth.2fa.email_subject',
            'auth.2fa.email_heading',
            'auth.2fa.email_intro',
            'auth.2fa.email_expires',
            'auth.2fa.email_ignore',
        ];

        foreach ($keys as $k) {
            self::assertArrayHasKey($k, $en, "Missing $k in messages.en.php");
            self::assertArrayHasKey($k, $fr, "Missing $k in messages.fr.php");
            self::assertArrayHasKey($k, $es, "Missing $k in messages.es.php");
            self::assertArrayHasKey($k, $ar, "Missing $k in messages.ar.php");
        }
    }

    public function testRegisterPayloadLimitAndSecurityHeaders(): void
    {
        $client = static::createClient();

        // 1. Oversized payload (> 16KB)
        $hugeData = str_repeat('X', 17000);
        $client->request(
            'POST',
            '/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Alice', 'email' => 'alice@example.com', 'extra' => $hugeData])
        );

        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // 2. Invalid JSON
        $client->request(
            'POST',
            '/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"invalid_json'
        );
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testConfirmPasswordPayloadLimitAndSecurityHeaders(): void
    {
        $client = static::createClient();

        // Unauthenticated access redirects to login
        $client->request(
            'POST',
            '/confirm-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['password' => 'secret'])
        );
        self::assertSame(302, $client->getResponse()->getStatusCode());

        // Authenticated access with oversized payload
        $user = $this->createUser();
        $client->loginUser($user);

        $hugeData = str_repeat('Y', 17000);
        $client->request(
            'POST',
            '/confirm-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['password' => 'secret', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testForgotPasswordPayloadLimit(): void
    {
        $client = static::createClient();

        $hugeData = str_repeat('Z', 17000);
        $client->request(
            'POST',
            '/forgot-password',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INERTIA' => 'true',
            ],
            json_encode(['email' => 'user@example.com', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->contains('X-Content-Type-Options', 'nosniff'));
        self::assertTrue($client->getResponse()->headers->contains('X-Frame-Options', 'DENY'));
    }

    public function testResetPasswordPayloadLimit(): void
    {
        $client = static::createClient();

        $hugeData = str_repeat('W', 17000);
        $client->request(
            'POST',
            '/reset-password/sample-token',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INERTIA' => 'true',
            ],
            json_encode(['password' => 'newpassword123', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->contains('X-Content-Type-Options', 'nosniff'));
        self::assertTrue($client->getResponse()->headers->contains('X-Frame-Options', 'DENY'));
    }

    public function testTwoFactorChallengePayloadLimit(): void
    {
        $client = static::createClient();

        $hugeData = str_repeat('K', 17000);
        $client->request(
            'POST',
            '/two-factor-challenge',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INERTIA' => 'true',
            ],
            json_encode(['code' => '123456', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->contains('X-Content-Type-Options', 'nosniff'));
        self::assertTrue($client->getResponse()->headers->contains('X-Frame-Options', 'DENY'));
    }

    public function testTwoFactorMailerUsesTranslations(): void
    {
        $capturedEmail = null;
        $mockMailer = $this->createMock(MailerInterface::class);
        $mockMailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (Email $email) use (&$capturedEmail) {
                $capturedEmail = $email;
            });

        $translator = self::getContainer()->get(TranslatorInterface::class);
        $twoFactorMailer = new TwoFactorMailer($mockMailer, 'noreply@example.com', $translator);

        $twoFactorMailer->sendCode('test@example.com', '654321', 'JamboTest');

        self::assertNotNull($capturedEmail);
        self::assertStringContainsString('654321', (string) $capturedEmail->getHtmlBody());
        self::assertNotEmpty($capturedEmail->getSubject());
    }
}
