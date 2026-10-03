<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Sandbox\SecurityPolicy;
use Twig\Sandbox\SecurityPolicyInterface;

class NativeTwigSecurityPolicy implements SecurityPolicyInterface
{
    private SecurityPolicy $policy;

    public function __construct(
        array $allowedTags = ['if', 'for', 'include'],
        array $allowedFilters = ['upper', 'lower', 'capitalize', 'trim', 'escape', 'default', 'raw', 'date', 'json_encode', 'slice'],
        array $allowedMethods = [],
        array $allowedProperties = [],
        array $allowedFunctions = ['range', 'cycle']
    ) {
        $this->policy = new SecurityPolicy(
            $allowedTags,
            $allowedFilters,
            $allowedMethods,
            $allowedProperties,
            $allowedFunctions
        );
    }

    public function checkSecurity($tags, $filters, $functions): void
    {
        $this->policy->checkSecurity($tags, $filters, $functions);
    }

    public function checkMethodAllowed($obj, $method): void
    {
        $this->policy->checkMethodAllowed($obj, $method);
    }

    public function checkPropertyAllowed($obj, $property): void
    {
        $this->policy->checkPropertyAllowed($obj, $property);
    }
}
