<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use App\DependencyInjection\RemoveDeprecatedTurboRendererPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\UX\Turbo\Bridge\Mercure\MercureStreamSourceRenderer;

class RemoveDeprecatedTurboRendererPassTest extends TestCase
{
    public function testProcessMigratesDeprecatedRenderer(): void
    {
        $container = new ContainerBuilder();

        // Register dummy dependencies
        $container->setDefinition('turbo.id_accessor', new Definition(\stdClass::class));
        $container->setDefinition('twig', new Definition(\stdClass::class));

        // Register stream source renderer
        $container->setDefinition('turbo.mercure.default.stream_source_renderer', new Definition(\stdClass::class));

        // Register deprecated renderer
        $container->setDefinition('turbo.mercure.default.renderer', new Definition(\stdClass::class));

        $pass = new RemoveDeprecatedTurboRendererPass();
        $pass->process($container);

        $this->assertTrue($container->hasDefinition('turbo.mercure.default.renderer'));
        $def = $container->getDefinition('turbo.mercure.default.renderer');
        $this->assertSame(MercureStreamSourceRenderer::class, $def->getClass());
        $this->assertTrue($def->hasTag('turbo.renderer.stream_listen'));
        $this->assertSame([['transport' => 'default']], $def->getTag('turbo.renderer.stream_listen'));
        $this->assertFalse($def->isPublic());
    }

    public function testProcessIgnoresWhenNoStreamSourceRendererCounterpart(): void
    {
        $container = new ContainerBuilder();
        $dummyDef = new Definition(\stdClass::class);
        $container->setDefinition('turbo.mercure.custom.renderer', $dummyDef);

        $pass = new RemoveDeprecatedTurboRendererPass();
        $pass->process($container);

        // Not migrated if counterpart does not exist
        $this->assertSame(\stdClass::class, $container->getDefinition('turbo.mercure.custom.renderer')->getClass());
    }

    public function testProcessIgnoresUnrelatedServices(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.my_service', new Definition(\stdClass::class));

        $pass = new RemoveDeprecatedTurboRendererPass();
        $pass->process($container);

        $this->assertSame(\stdClass::class, $container->getDefinition('app.my_service')->getClass());
    }
}
