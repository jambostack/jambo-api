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
    public const MAX_TEMPLATE_LENGTH = 65536; // 64 KB

    public function __construct(
        private readonly NativeTwigSecurityPolicy $securityPolicy,
    ) {}

    /**
     * Exécute un template Twig utilisateur sous sandbox stricte.
     *
     * @throws \InvalidArgumentException si le template dépasse les limites de taille ou contient des octets nuls
     * @throws \Twig\Sandbox\SecurityError
     */
    public function render(string $templateContent, array $context = []): string
    {
        if (strlen($templateContent) > self::MAX_TEMPLATE_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Dynamic template exceeds maximum allowed length of %d bytes.', self::MAX_TEMPLATE_LENGTH));
        }

        if (str_contains($templateContent, "\0")) {
            throw new \InvalidArgumentException('Null byte detected in dynamic template content.');
        }

        $loader = new ArrayLoader(['user_template' => $templateContent]);
        $twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);

        $twig->addExtension(new SandboxExtension($this->securityPolicy, true));

        return $twig->render('user_template', $context);
    }
}
