<?php

namespace App\Tests\Controller\Settings;

use App\Controller\Settings\PasswordController;
use App\Controller\Settings\ProfileController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class SettingsSecurityTest extends WebTestCase
{
    public function testAnonymousPasswordEndpointDeniedWithout500(): void
    {
        $client = static::createClient();
        $client->request('PUT', '/api/settings/password', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'current_password' => 'wrong',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]));

        self::assertContains($client->getResponse()->getStatusCode(), [401, 302, 403]);
        self::assertNotSame(500, $client->getResponse()->getStatusCode());
    }

    public function testAnonymousProfileEndpointDeniedWithout500(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/settings/profile');

        self::assertContains($client->getResponse()->getStatusCode(), [401, 302, 403]);
        self::assertNotSame(500, $client->getResponse()->getStatusCode());
    }

    public function testNullUserReturns401JsonInControllers(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $profileController = $container->get(ProfileController::class);
        $res = $profileController->show();
        self::assertSame(401, $res->getStatusCode());

        $passwordController = $container->get(PasswordController::class);
        $resPass = $passwordController->update(new Request([], [], [], [], [], [], json_encode(['current_password' => 'x'])));
        self::assertSame(401, $resPass->getStatusCode());
    }
}
