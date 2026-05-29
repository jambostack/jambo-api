<?php
// tests/Service/Cloud/CustomDomainServiceTest.php
namespace App\Tests\Service\Cloud;

use App\Entity\CustomDomain;
use App\Entity\HostedApp;
use App\Service\Cloud\CustomDomainService;
use App\Service\Cloud\DnsResolverInterface;
use PHPUnit\Framework\TestCase;

class CustomDomainServiceTest extends TestCase
{
    public function testAddDomainGeneratesToken(): void
    {
        $service = new CustomDomainService($this->createMock(DnsResolverInterface::class));
        $cd = $service->addDomain(new HostedApp(), 'shop.example.com');

        $this->assertSame('shop.example.com', $cd->domain);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $cd->verificationToken);
        $this->assertFalse($cd->verified);
    }

    public function testVerifySucceedsWhenTxtRecordMatches(): void
    {
        $cd = $this->makeDomain('shop.example.com', 'abc123');

        $dns = $this->createMock(DnsResolverInterface::class);
        $dns->method('txtRecords')
            ->with('_jambo-challenge.shop.example.com')
            ->willReturn(['some-other-record', 'jambo-verify=abc123']);

        $service = new CustomDomainService($dns);
        $this->assertTrue($service->verify($cd));
        $this->assertTrue($cd->verified);
        $this->assertNotNull($cd->verifiedAt);
        $this->assertSame(CustomDomain::SSL_ACTIVE, $cd->sslStatus);
    }

    public function testVerifyFailsWhenTxtRecordMissing(): void
    {
        $cd = $this->makeDomain('shop.example.com', 'abc123');

        $dns = $this->createMock(DnsResolverInterface::class);
        $dns->method('txtRecords')->willReturn(['jambo-verify=WRONG']);

        $service = new CustomDomainService($dns);
        $this->assertFalse($service->verify($cd));
        $this->assertFalse($cd->verified);
    }

    private function makeDomain(string $domain, string $token): CustomDomain
    {
        $cd = new CustomDomain();
        $cd->hostedApp         = new HostedApp();
        $cd->domain            = $domain;
        $cd->verificationToken = $token;
        return $cd;
    }
}
