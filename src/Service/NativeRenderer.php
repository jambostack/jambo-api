<?php

namespace App\Service;

use App\Entity\WorkbenchProject;
use App\Twig\JamboNativeExtension;
use App\Twig\NativeTwigSecurityPolicy;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;

class NativeRenderer
{
    public function __construct(
        private readonly JamboNativeExtension $jamboExtension,
    ) {}

    /**
     * Rend un template Twig natif pour un WorkbenchProject.
     *
     * @return string HTML rendu
     * @throws \RuntimeException si le template n'existe pas ou si le rendu échoue
     */
    public function render(WorkbenchProject $workbench, string $path): string
    {
        if (empty($workbench->files)) {
            throw new \RuntimeException('WorkbenchProject has no template files.');
        }

        $templateName = $this->resolveTemplate($workbench, $path);

        // Charger TOUS les fichiers du projet dans l'ArrayLoader pour que
        // {% extends 'templates/_layout.html.twig' %} et {% include %} fonctionnent.
        $loader = new ArrayLoader($workbench->files);
        $twig = new Environment($loader, [
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);

        // Activer le sandbox (mode strict : tout bloqué sauf la liste blanche)
        $twig->addExtension(new SandboxExtension(new NativeTwigSecurityPolicy(), true));

        // Ajouter l'extension custom Jambo
        $twig->addExtension($this->jamboExtension);

        // Construire le contexte accessible au template
        $context = [
            'project' => $workbench->project,
        ];

        return $twig->render($templateName, $context);
    }

    /**
     * Résout un path HTTP en nom de template Twig.
     *
     * Mapping :
     *   /              → templates/index.html.twig
     *   /blog          → templates/blog.html.twig (si existe)
     *   /blog/article  → templates/post.html.twig  (si existe, pour toute collection)
     *   /*             → templates/index.html.twig (fallback SPA)
     */
    private function resolveTemplate(WorkbenchProject $workbench, string $path): string
    {
        $clean = trim($path, '/');

        if ($clean === '' || $clean === 'index.html') {
            return 'templates/index.html.twig';
        }

        // Ex: /blog → templates/blog.html.twig
        $candidate = 'templates/' . $clean . '.html.twig';
        if (isset($workbench->files[$candidate])) {
            return $candidate;
        }

        // Ex: /blog/mon-article → templates/post.html.twig
        $parts = explode('/', $clean);
        if (count($parts) === 2 && isset($workbench->files['templates/post.html.twig'])) {
            return 'templates/post.html.twig';
        }

        // Fallback SPA : index.html.twig
        return 'templates/index.html.twig';
    }
}
