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
    /** @var array<string, Environment> Cache des environnements Twig par UUID de workbench */
    private array $environments = [];

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
        $twig = $this->getEnvironment($workbench);

        // Construire le contexte accessible au template
        $context = [
            'project' => $workbench->project,
        ];

        return $twig->render($templateName, $context);
    }

    /**
     * Recupere ou cree l'environnement Twig pour un workbench.
     * L'environnement est cree une fois par UUID et reutilise pour les appels
     * suivants, ce qui permet la mise en cache des templates compiles.
     */
    private function getEnvironment(WorkbenchProject $workbench): Environment
    {
        $key = $workbench->uuid->toRfc4122();
        if (isset($this->environments[$key])) {
            return $this->environments[$key];
        }

        $loader = new ArrayLoader($workbench->files);
        $twig = new Environment($loader, [
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);

        // Activer le sandbox (mode strict : tout bloque sauf la liste blanche)
        $twig->addExtension(new SandboxExtension(new NativeTwigSecurityPolicy(), true));

        // Ajouter l'extension custom Jambo
        $twig->addExtension($this->jamboExtension);

        return $this->environments[$key] = $twig;
    }

    /**
     * Résout un path HTTP en nom de template Twig.
     *
     * Mapping :
     *   /              → templates/index.html.twig
     *   /blog          → templates/blog.html.twig (si existe)
     *   /blog/article  → templates/blog.html.twig    (pour toute collection)
     *   /*             → templates/index.html.twig    (fallback SPA)
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

        // Ex: /blog/mon-article → templates/blog.html.twig
        $parts = explode('/', $clean);
        if (isset($parts[1])) {
            $candidate = 'templates/' . $parts[0] . '.html.twig';
            if (isset($workbench->files[$candidate])) {
                return $candidate;
            }
        }

        // Fallback SPA : index.html.twig
        return 'templates/index.html.twig';
    }
}
