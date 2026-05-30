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
        $templateName = $this->resolveTemplate($workbench, $path);

        // Charger le template depuis les fichiers du WorkbenchProject
        $templateSource = $this->findTemplate($workbench, $templateName);

        // Créer un environnement Twig sandboxé
        $loader = new ArrayLoader([$templateName => $templateSource]);
        $twig = new Environment($loader, [
            'strict_variables' => false,
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
     */
    private function resolveTemplate(WorkbenchProject $workbench, string $path): string
    {
        $clean = trim($path, '/');

        if ($clean === '' || $clean === 'index.html') {
            return 'templates/index.html.twig';
        }

        // Chercher un template qui correspond au path
        // Ex: /blog → templates/blog.html.twig
        $candidate = 'templates/' . $clean . '.html.twig';
        if (isset($workbench->files[$candidate])) {
            return $candidate;
        }

        // Ex: /blog/mon-article → templates/post.html.twig (détecter la collection parente)
        $parts = explode('/', $clean);
        if (count($parts) === 2) {
            $parentSlug = $parts[0];
            // Vérifier si un template post.html.twig existe
            if (isset($workbench->files['templates/post.html.twig'])) {
                return 'templates/post.html.twig';
            }
        }

        // Fallback SPA Twig : index.html.twig
        return 'templates/index.html.twig';
    }

    /**
     * Trouve le contenu d'un template dans les fichiers du WorkbenchProject.
     */
    private function findTemplate(WorkbenchProject $workbench, string $templateName): string
    {
        if (isset($workbench->files[$templateName])) {
            return $workbench->files[$templateName];
        }

        // Fallback : chercher sans le préfixe templates/
        $shortName = str_replace('templates/', '', $templateName);
        foreach ($workbench->files as $path => $content) {
            if (str_ends_with($path, $shortName)) {
                return $content;
            }
        }

        throw new \RuntimeException(sprintf('Template "%s" not found in WorkbenchProject files.', $templateName));
    }
}
