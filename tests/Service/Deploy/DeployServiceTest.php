<?php
// tests/Service/Deploy/DeployServiceTest.php
namespace App\Tests\Service\Deploy;

use App\Entity\DeployToken;
use App\Entity\User;
use App\Entity\WorkbenchProject;
use App\Entity\Project;
use App\Service\Deploy\DeployResult;
use App\Service\Deploy\DeployProviderInterface;
use App\Service\Deploy\DeployService;
use PHPUnit\Framework\TestCase;

class DeployServiceTest extends TestCase
{
    public function testEncryptDecryptRoundtrip(): void
    {
        $service = new DeployService('test-secret-32-chars-padded-here!', []);
        $plain   = 'my_oauth_token_xyz123';

        $encrypted = $service->encryptToken($plain);
        $this->assertNotEquals($plain, $encrypted);

        $decrypted = $service->decryptToken($encrypted);
        $this->assertEquals($plain, $decrypted);
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $service = new DeployService('test-secret-32-chars-padded-here!', []);
        $plain   = 'same_token';

        $enc1 = $service->encryptToken($plain);
        $enc2 = $service->encryptToken($plain);
        $this->assertNotEquals($enc1, $enc2, 'IV must be random each time');
    }

    public function testFindProviderReturnsCorrectOne(): void
    {
        $mockProvider = $this->createMock(DeployProviderInterface::class);
        $mockProvider->method('getId')->willReturn('netlify');

        $service = new DeployService('test-secret-32-chars-padded-here!', [$mockProvider]);

        $found = $service->findProvider('netlify');
        $this->assertSame($mockProvider, $found);
    }

    public function testFindProviderReturnsNullForUnknown(): void
    {
        $service = new DeployService('test-secret-32-chars-padded-here!', []);
        $this->assertNull($service->findProvider('unknown'));
    }
}
