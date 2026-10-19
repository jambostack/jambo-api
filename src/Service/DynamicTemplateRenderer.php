<?php

declare(strict_types=1);

namespace App\Service;

use App\Twig\NativeTwigSecurityPolicy;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;

/**
 * Service de rendu hermétique pour les templates dynamiques utilisateurs.
 * Applique la politique stricte NativeTwigSecurityPolicy (AST whitelist).
 */
class DynamicTemplateRenderer
{
    public function __construct(
        private readonly NativeTwigSecurityPolicy $securityPolicy,
    ) {}

    /**
     * Exécute un template Twig utilisateur sous sandbox stricte.
     *
     * @throws \Twig\Sandbox\SecurityError
     */
    public function render(string $templateContent, array $context = []): string
    {
        $loader = new ArrayLoader(['user_template' => $templateContent]);
        $twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);

        $twig->addExtension(new SandboxExtension($this->securityPolicy, true));

        return $twig->render('user_template', $context);
    }
}
