<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\UX\Turbo\Bridge\Mercure\MercureStreamSourceRenderer;

/**
 * Compiler pass to migrate deprecated TurboStreamListenRenderer → MercureStreamSourceRenderer.
 *
 * symfony/ux-turbo ≥ 3.1 registers `turbo.mercure.<hub>.renderer` services using the
 * deprecated TurboStreamListenRenderer class (deprecated in 3.1, removed in 4.0).
 * The deprecation fires at class-load time (file-level trigger_deprecation call).
 *
 * Strategy: replace the deprecated class in existing definitions with a no-op shim
 * that delegates to MercureStreamSourceRenderer, so the tagged_locator in TurboRuntime
 * still resolves while suppressing the actual deprecated class instantiation.
 *
 * Since `renderTurboStreamListen()` is not used in this application (we only use
 * `turbo_stream_from` / MercureStreamSourceRenderer), we re-register the
 * turbo.mercure.*.renderer services as aliases pointing to the *.stream_source_renderer
 * and tag them with 'turbo.renderer.stream_listen' so the locator resolves.
 *
 * This pass runs TYPE_BEFORE_REMOVING (after RegisterMercureHubsPass) at priority -128.
 */
class RemoveDeprecatedTurboRendererPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->getDefinitions()) as $id) {
            if (
                str_starts_with($id, 'turbo.mercure.')
                && str_ends_with($id, '.renderer')
                && !str_ends_with($id, '.stream_source_renderer')
            ) {
                // Extract hub name: turbo.mercure.<name>.renderer
                $hubName = substr($id, strlen('turbo.mercure.'), -strlen('.renderer'));
                $sourceRendererId = "turbo.mercure.{$hubName}.stream_source_renderer";

                // Only migrate when the stream_source_renderer counterpart exists
                if (!$container->hasDefinition($sourceRendererId)) {
                    continue;
                }

                // Remove the deprecated definition and re-register as a forwarding service
                // using MercureStreamSourceRenderer (non-deprecated) with the stream_listen tag.
                $container->removeDefinition($id);

                $container->register($id, MercureStreamSourceRenderer::class)
                    ->addArgument(new Reference('turbo.id_accessor'))
                    ->addArgument(new Reference('twig'))
                    ->addArgument($hubName)
                    ->addTag('turbo.renderer.stream_listen', ['transport' => $hubName])
                    ->setPublic(false);
            }
        }
    }
}
