<?php

namespace App\Tests\Controller;

use App\Controller\StudioController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class StudioAntiSsrfTest extends KernelTestCase
{
    private function invokeDownloadExternalImage(string $url): string
    {
        self::bootKernel();
        $controller = self::getContainer()->get(StudioController::class);
        $method = new \ReflectionMethod($controller, 'downloadExternalImage');
        return $method->invoke($controller, $url);
    }

    private function getExpectedMessage(string $id): string
    {
        self::bootKernel();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        return $translator->trans($id);
    }

    public function testRejectsNonHttps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($this->getExpectedMessage('ssrf.https_required'));
        $this->invokeDownloadExternalImage('http://127.0.0.1:8080/test.png');
    }

    public function testRejectsLoopbackIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($this->getExpectedMessage('ssrf.private_address_forbidden'));
        $this->invokeDownloadExternalImage('https://127.0.0.1/test.png');
    }

    public function testRejectsPrivateRangeIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($this->getExpectedMessage('ssrf.private_address_forbidden'));
        $this->invokeDownloadExternalImage('https://192.168.1.1/test.png');
    }

    public function testRejectsCloudMetadataIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($this->getExpectedMessage('ssrf.private_address_forbidden'));
        $this->invokeDownloadExternalImage('https://169.254.169.254/latest/meta-data');
    }
}
