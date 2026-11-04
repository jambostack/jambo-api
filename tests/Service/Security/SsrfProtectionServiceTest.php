<?php

namespace App\Tests\Service\Security;

use App\Service\Security\SsrfProtectionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SsrfProtectionServiceTest extends TestCase
{
    private SsrfProtectionService $service;

    protected function setUp(): void
    {
        $this->service = new SsrfProtectionService();
    }

    public function testRejectsEmptyAndMalformedUrls(): void
    {
        $this->assertFalse($this->service->isSafeUrl(''));
        $this->assertFalse($this->service->isSafeUrl('not-a-valid-url'));
        $this->assertFalse($this->service->isSafeUrl('://broken'));
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $schemes = ['ftp', 'gopher', 'file', 'php', 'dict', 'ldap', 'ssh', 'telnet'];
        foreach ($schemes as $scheme) {
            $this->assertFalse(
                $this->service->isSafeUrl($scheme . '://example.com/test'),
                "Scheme $scheme should be rejected"
            );
        }
    }

    public function testEnforcesHttpsWhenRequired(): void
    {
        $this->assertTrue($this->service->isSafeUrl('https://example.com/test', true));
        $this->assertFalse($this->service->isSafeUrl('http://example.com/test', true));
    }

    public function testRejectsCredentialsInUrl(): void
    {
        $this->assertFalse($this->service->isSafeUrl('http://admin:secret@example.com/webhook'));
        $this->assertFalse($this->service->isSafeUrl('https://user@example.com/test'));
    }

    public function testRejectsDangerousPorts(): void
    {
        $dangerousPorts = [21, 22, 23, 25, 53, 110, 143, 3306, 5432, 6379, 11211, 27017];
        foreach ($dangerousPorts as $port) {
            $this->assertFalse(
                $this->service->isSafeUrl("http://example.com:{$port}/hook"),
                "Port $port should be rejected"
            );
        }
    }

    public function testAllowsSafeWebPorts(): void
    {
        $safePorts = [80, 443, 8000, 8080, 8443];
        foreach ($safePorts as $port) {
            $scheme = in_array($port, [443, 8443], true) ? 'https' : 'http';
            $this->assertTrue(
                $this->service->isSafeUrl("{$scheme}://example.com:{$port}/hook"),
                "Safe port $port should be allowed"
            );
        }
    }

    public function testRejectsEvasiveIpNotations(): void
    {
        $evasiveUrls = [
            'http://2130706433/test',      // Decimal integer for 127.0.0.1
            'http://0177.0.0.1/test',      // Dotted octal for 127.0.0.1
            'http://0x7f000001/test',      // Hex notation for 127.0.0.1
            'http://127.1/test',           // Shorthand for 127.0.0.1
            'http://10.1/test',            // Shorthand for 10.0.0.1
            'http://0x7f.1/test',          // Mixed hex/shorthand
        ];

        foreach ($evasiveUrls as $url) {
            $this->assertFalse($this->service->isSafeUrl($url), "Evasive URL $url should be blocked");
        }
    }

    public function testRejectsLoopbackAndLocalhost(): void
    {
        $loopbacks = [
            'http://127.0.0.1/',
            'http://127.0.0.2:8080/hook',
            'http://0.0.0.0/',
            'http://[::1]/',
            'http://localhost/',
            'http://sub.localhost/',
            'http://ip6-localhost/',
            'http://ip6-loopback/',
            'http://host.docker.internal/',
            'http://gateway.docker.internal/',
            'http://service.local/',
            'http://internal.service.lan/',
        ];

        foreach ($loopbacks as $url) {
            $this->assertFalse($this->service->isSafeUrl($url), "Loopback URL $url should be blocked");
        }
    }

    public function testRejectsPrivateRfc1918AndCgnat(): void
    {
        $privates = [
            'http://10.0.0.1/',
            'http://10.255.255.254/',
            'http://172.16.0.1/',
            'http://172.31.255.254/',
            'http://192.168.0.1/',
            'http://192.168.1.254/',
            'http://100.64.0.1/', // Carrier-grade NAT RFC 6598
            'http://100.127.255.254/',
        ];

        foreach ($privates as $url) {
            $this->assertFalse($this->service->isSafeUrl($url), "Private range URL $url should be blocked");
        }
    }

    public function testRejectsCloudMetadataAndLinkLocal(): void
    {
        $metadata = [
            'http://169.254.169.254/latest/meta-data/', // AWS/GCP/Azure IMDSv1/v2
            'http://169.254.169.253/',
            'http://169.254.1.1/',
            'http://[fd00:ec2::254]/',                  // AWS IPv6 IMDS
            'http://metadata.google.internal/',         // GCP
            'http://instance-data/',                    // AWS legacy
        ];

        foreach ($metadata as $url) {
            $this->assertFalse($this->service->isSafeUrl($url), "Cloud metadata URL $url should be blocked");
        }
    }

    public function testRejectsIpv6PrivateAndMapped(): void
    {
        $ipv6Blocked = [
            'http://[::1]/',
            'http://[fc00::1]/',
            'http://[fd12:3456:789a::1]/',
            'http://[fe80::1]/',
            'http://[ff02::1]/',
            'http://[::ffff:127.0.0.1]/',
            'http://[::ffff:169.254.169.254]/',
            'http://[::ffff:10.0.0.1]/',
        ];

        foreach ($ipv6Blocked as $url) {
            $this->assertFalse($this->service->isSafeUrl($url), "IPv6 blocked URL $url should be blocked");
        }
    }

    public function testAllowsSafePublicUrls(): void
    {
        $safeUrls = [
            'https://example.com/webhook',
            'https://example.org:8443/api/receive',
            'http://93.184.216.34/webhook', // Canonical public IPv4 (example.com)
        ];

        foreach ($safeUrls as $url) {
            $this->assertTrue($this->service->isSafeUrl($url), "Safe public URL $url should be allowed");
        }
    }

    public function testBuildSafeHttpClientOptionsPinsDnsAndEnforcesTimeouts(): void
    {
        $url = 'https://example.com/webhook';
        $options = $this->service->buildSafeHttpClientOptions($url, [
            'headers' => ['X-Custom' => '123'],
        ], idleTimeout: 3.5, maxDuration: 8.0);

        $this->assertSame(0, $options['max_redirects']);
        $this->assertSame(3.5, $options['timeout']);
        $this->assertSame(8.0, $options['max_duration']);
        $this->assertArrayHasKey('resolve', $options);
        $this->assertArrayHasKey('example.com', $options['resolve']);
        $this->assertNotEmpty($options['resolve']['example.com']);
        $this->assertSame('123', $options['headers']['X-Custom']);
    }

    public function testReadSafeResponseBodyCapsStreamLength(): void
    {
        $largeData = str_repeat('A', 10000);
        $client = new MockHttpClient([new MockResponse($largeData)]);
        $response = $client->request('GET', 'https://example.com');

        $read = $this->service->readSafeResponseBody($response, 100);
        $this->assertSame(100, strlen($read));
        $this->assertSame(str_repeat('A', 100), $read);
    }
}
