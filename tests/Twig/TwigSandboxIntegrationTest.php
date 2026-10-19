<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\DynamicTemplateRenderer;
use App\Twig\NativeTwigSecurityPolicy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicyInterface;

class TwigSandboxIntegrationTest extends KernelTestCase
{
    public function testSandboxServicesRegisteredInContainer(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->assertTrue($container->has(NativeTwigSecurityPolicy::class));
        $policy = $container->get(NativeTwigSecurityPolicy::class);
        $this->assertInstanceOf(SecurityPolicyInterface::class, $policy);

        $this->assertTrue($container->has(SecurityPolicyInterface::class));
        $this->assertSame($policy, $container->get(SecurityPolicyInterface::class));

        $this->assertTrue($container->has(SandboxExtension::class));
        $extension = $container->get(SandboxExtension::class);
        $this->assertInstanceOf(SandboxExtension::class, $extension);

        // Verify SandboxExtension is active in main Twig environment
        $twig = $container->get(Environment::class);
        $this->assertTrue($twig->hasExtension(SandboxExtension::class));
    }

    public function testDynamicTemplateRendererPassesSafeTemplates(): void
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(DynamicTemplateRenderer::class);

        $html = $renderer->render('Hello {{ name|upper }}!', ['name' => 'jambo']);
        $this->assertSame('Hello JAMBO!', $html);

        $forLoop = $renderer->render('{% for item in items %}[{{ item }}]{% endfor %}', ['items' => ['a', 'b']]);
        $this->assertSame('[a][b]', $forLoop);
    }

    public function testDynamicTemplateRendererBlocksDangerousTags(): void
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(DynamicTemplateRenderer::class);

        $this->expectException(SecurityNotAllowedTagError::class);
        $renderer->render('{% do 1 + 1 %}');
    }

    public function testDynamicTemplateRendererBlocksMethodCalls(): void
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(DynamicTemplateRenderer::class);

        $obj = new class {
            public function malicious(): string { return 'owned'; }
        };

        $this->expectException(SecurityNotAllowedMethodError::class);
        $renderer->render('{{ obj.malicious() }}', ['obj' => $obj]);
    }
}
