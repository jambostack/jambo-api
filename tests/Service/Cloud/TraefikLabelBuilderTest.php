<?php
// tests/Service/Cloud/TraefikLabelBuilderTest.php
namespace App\Tests\Service\Cloud;

use App\Service\Cloud\TraefikLabelBuilder;
use PHPUnit\Framework\TestCase;

class TraefikLabelBuilderTest extends TestCase
{
    private TraefikLabelBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TraefikLabelBuilder('jambo.app', 'letsencrypt');
    }

    public function testBuildsSubdomainRouter(): void
    {
        $labels = $this->builder->build('monblog-a1b2c3', 3000, []);

        $this->assertSame('true', $labels['traefik.enable']);
        $this->assertSame(
            'Host(`monblog-a1b2c3.jambo.app`)',
            $labels['traefik.http.routers.jambo-monblog-a1b2c3.rule']
        );
        $this->assertSame(
            'letsencrypt',
            $labels['traefik.http.routers.jambo-monblog-a1b2c3.tls.certresolver']
        );
        $this->assertSame(
            '3000',
            $labels['traefik.http.services.jambo-monblog-a1b2c3.loadbalancer.server.port']
        );
    }

    public function testCustomDomainsAreOredIntoTheRule(): void
    {
        $labels = $this->builder->build('shop-ff00aa', 8080, ['shop.example.com', 'www.shop.example.com']);

        $this->assertSame(
            'Host(`shop-ff00aa.jambo.app`) || Host(`shop.example.com`) || Host(`www.shop.example.com`)',
            $labels['traefik.http.routers.jambo-shop-ff00aa.rule']
        );
        $this->assertSame('8080', $labels['traefik.http.services.jambo-shop-ff00aa.loadbalancer.server.port']);
    }

    public function testRouterNameIsSanitized(): void
    {
        $labels = $this->builder->build('My_App.01', 3000, []);
        // sanitized to lowercase [a-z0-9-]
        $this->assertArrayHasKey('traefik.http.routers.jambo-my-app-01.rule', $labels);
    }
}
