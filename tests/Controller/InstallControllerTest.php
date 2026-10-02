<?php

namespace App\Tests\Controller;

use App\Controller\InstallController;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class InstallControllerTest extends TestCase
{
    public function testIndexReturnsHtmlDiagnostics(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $controller = new InstallController($em, $userRepo, $hasher, sys_get_temp_dir());

        $response = $controller->index();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Diagnostic', $response->getContent());
    }

    public function testCheckReturnsJsonDiagnostics(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $controller = new InstallController($em, $userRepo, $hasher, sys_get_temp_dir());

        $response = $controller->check();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertInstanceOf(JsonResponse::class, $response);

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('ready', $data);
        $this->assertArrayHasKey('checks', $data);
        $this->assertNotEmpty($data['checks']);
    }
}
