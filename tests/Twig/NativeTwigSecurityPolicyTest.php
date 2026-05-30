<?php
namespace App\Tests\Twig;

use App\Twig\NativeTwigSecurityPolicy;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;

class NativeTwigSecurityPolicyTest extends TestCase
{
    private function createSandboxedTwig(string $template): Environment
    {
        $loader = new ArrayLoader(['test.html.twig' => $template]);
        $twig   = new Environment($loader);
        $twig->addExtension(new SandboxExtension(new NativeTwigSecurityPolicy(), true));
        return $twig;
    }

    public function testAllowedTagPasses(): void
    {
        $twig = $this->createSandboxedTwig('{% if true %}OK{% endif %}');
        $this->assertSame('OK', $twig->render('test.html.twig'));
    }

    public function testAllowedForLoopPasses(): void
    {
        $twig = $this->createSandboxedTwig('{% for i in [1,2] %}{{ i }}{% endfor %}');
        $this->assertSame('12', $twig->render('test.html.twig'));
    }

    public function testAllowedFilterPasses(): void
    {
        $twig = $this->createSandboxedTwig('{{ "hello"|upper }}');
        $this->assertSame('HELLO', $twig->render('test.html.twig'));
    }

    public function testDoingTagIsBlocked(): void
    {
        $twig = $this->createSandboxedTwig('{% do 1 + 1 %}');
        $this->expectException(\Twig\Sandbox\SecurityNotAllowedTagError::class);
        $twig->render('test.html.twig');
    }

    public function testDumpFunctionIsBlocked(): void
    {
        $loader = new ArrayLoader(['test.html.twig' => '{{ dump() }}']);
        $twig   = new Environment($loader);
        $twig->addExtension(new DebugExtension());
        $twig->addExtension(new SandboxExtension(new NativeTwigSecurityPolicy(), true));
        $this->expectException(\Twig\Sandbox\SecurityNotAllowedFunctionError::class);
        $twig->render('test.html.twig');
    }

    public function testIncludeAllowed(): void
    {
        $loader = new ArrayLoader([
            'main.html.twig'   => '{% include "partial.html.twig" %}',
            'partial.html.twig'=> 'Partial',
        ]);
        $twig = new Environment($loader);
        $twig->addExtension(new SandboxExtension(new NativeTwigSecurityPolicy(), true));
        $this->assertSame('Partial', $twig->render('main.html.twig'));
    }

    public function testMethodCallOnObjectIsBlocked(): void
    {
        $twig = $this->createSandboxedTwig('{{ obj.getName() }}');
        $this->expectException(\Twig\Sandbox\SecurityNotAllowedMethodError::class);
        $twig->render('test.html.twig', ['obj' => new class {
            public function getName(): string { return 'test'; }
        }]);
    }
}
