<?php
namespace App\Twig;

use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicyInterface;
use Twig\Template;

/**
 * Politique de sécurité stricte pour les templates Twig générés par IA.
 * Liste blanche uniquement — tout ce qui n'est pas explicitement autorisé est bloqué.
 */
class NativeTwigSecurityPolicy implements SecurityPolicyInterface
{
    public function getAllowedTags(): array
    {
        return ['if', 'for', 'set', 'block', 'extends', 'include', 'embed', 'verbatim'];
    }

    public function getAllowedFilters(): array
    {
        return [
            'escape', 'e', 'raw',
            'upper', 'lower', 'title', 'capitalize',
            'date', 'date_modify',
            'striptags', 'trim', 'nl2br',
            'slice', 'first', 'last',
            'keys', 'length', 'sort', 'reverse',
            'merge', 'join', 'split',
            'json_encode',
            'default', 'abs', 'round',
            'url_encode',
        ];
    }

    public function getAllowedFunctions(): array
    {
        return [
            'jambo_collection', 'jambo_entry', 'jambo_setting',
            'jambo_url', 'jambo_asset', 'jambo_locale',
            'url', 'path', 'asset', 'absolute_url',
            'block', 'parent',
        ];
    }

    public function getAllowedMethods(): array
    {
        // Zéro accès aux méthodes d'objets PHP — les données sont des tableaux purs.
        return [];
    }

    public function getAllowedProperties(): array
    {
        // Zéro accès aux propriétés d'objets PHP.
        return [];
    }

    public function checkSecurity($tags, $filters, $functions): void
    {
        foreach ($tags as $tag) {
            if (!\in_array($tag, $this->getAllowedTags(), true)) {
                throw new SecurityNotAllowedTagError(\sprintf('Tag "%s" is not allowed.', $tag), $tag);
            }
        }

        foreach ($filters as $filter) {
            if (!\in_array($filter, $this->getAllowedFilters(), true)) {
                throw new SecurityNotAllowedFilterError(\sprintf('Filter "%s" is not allowed.', $filter), $filter);
            }
        }

        foreach ($functions as $function) {
            if (!\in_array($function, $this->getAllowedFunctions(), true)) {
                throw new SecurityNotAllowedFunctionError(\sprintf('Function "%s" is not allowed.', $function), $function);
            }
        }
    }

    public function checkMethodAllowed($obj, $method): void
    {
        if ($obj instanceof Template || $obj instanceof Markup) {
            return;
        }

        $allowed = false;
        $method = strtolower($method);
        foreach ($this->getAllowedMethods() as $class => $methods) {
            if ($obj instanceof $class && \in_array($method, $methods, true)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            $class = $obj::class;
            throw new SecurityNotAllowedMethodError(\sprintf('Calling "%s" method on a "%s" object is not allowed.', $method, $class), $class, $method);
        }
    }

    public function checkPropertyAllowed($obj, $property): void
    {
        $allowed = false;
        foreach ($this->getAllowedProperties() as $class => $properties) {
            if ($obj instanceof $class && \in_array($property, \is_array($properties) ? $properties : [$properties], true)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            $class = $obj::class;
            throw new SecurityNotAllowedPropertyError(\sprintf('Calling "%s" property on a "%s" object is not allowed.', $property, $class), $class, $property);
        }
    }
}
