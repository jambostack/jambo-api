<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new \App\DependencyInjection\FlowNodeHandlerPass());
        // Remove deprecated TurboStreamListenRenderer definitions (UX Turbo ≥ 3.1).
        // Must run after RegisterMercureHubsPass; use PassConfig::TYPE_BEFORE_REMOVING.
        $container->addCompilerPass(
            new \App\DependencyInjection\RemoveDeprecatedTurboRendererPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_REMOVING,
            -128,
        );
    }
}
