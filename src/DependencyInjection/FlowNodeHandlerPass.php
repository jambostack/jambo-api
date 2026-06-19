<?php

namespace App\DependencyInjection;

use App\Service\Flow\NodeRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class FlowNodeHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(NodeRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(NodeRegistry::class);
        $tagged = $container->findTaggedServiceIds('app.flow.node_handler');

        foreach ($tagged as $id => $tags) {
            $registry->addMethodCall('addHandler', [new Reference($id)]);
        }
    }
}
