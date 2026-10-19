<?php

namespace App\Tests\Controller;

use App\Controller\StudioController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StudioAntiSsrfTest extends KernelTestCase
{
    private function invokeDownloadExternalImage(string $url): string
    {
        self::bootKernel();
        $controller = self::getContainer()->get(StudioController::class);
        $method = new \ReflectionMethod($controller, 'downloadExternalImage');
        $method->setAccessible(true);
        return $method->invoke($controller, $url);
    }

    public function testRejectsNonHttps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Protocole HTTPS obligatoire');
        $this->invokeDownloadExternalImage('http://127.0.0.1:8080/test.png');
    }

    public function testRejectsLoopbackIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Accès aux adresses privées ou réservées interdit');
        $this->invokeDownloadExternalImage('https://127.0.0.1/test.png');
    }

    public function testRejectsPrivateRangeIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Accès aux adresses privées ou réservées interdit');
        $this->invokeDownloadExternalImage('https://192.168.1.1/test.png');
    }

    public function testRejectsCloudMetadataIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Accès aux adresses privées ou réservées interdit');
        $this->invokeDownloadExternalImage('https://169.254.169.254/latest/meta-data');
    }
}
