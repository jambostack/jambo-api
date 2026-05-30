<?php
// src/Workbench/Templates/NativeTemplate.php
namespace App\Workbench\Templates;

class NativeTemplate extends BaseTemplate
{
    public function getId(): string
    {
        return 'native';
    }

    public function getLabel(): string
    {
        return 'Jambo Native (Twig SSR)';
    }

    public function getStarterFiles(string $jamboApiUrl, string $projectUuid, array $collections): array
    {
        $collectionsList = '';
        foreach ($collections as $c) {
            $collectionsList .= " * - {$c['slug']}: {$c['name']}\n";
        }

        return [
            'templates/_layout.html.twig' => <<<'TWIG'
<!DOCTYPE html>
<html lang="{{ jambo_locale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}My Site{% endblock %}</title>
    {% block head %}{% endblock %}
</head>
<body>
    <header>
        <nav>
            <a href="/">Home</a>
        </nav>
    </header>
    <main>
        {% block content %}{% endblock %}
    </main>
    <footer>
        <p>&copy; {{ 'now'|date('Y') }} — Powered by Jambo</p>
    </footer>
</body>
</html>
TWIG,
            'templates/index.html.twig' => <<<'TWIG'
{% extends 'templates/_layout.html.twig' %}

{% block title %}Welcome{% endblock %}

{% block content %}
    <h1>Welcome to your Jambo Native site!</h1>
    <p>This page is server-rendered via Twig with direct EAV access.</p>

    <h2>Your Collections</h2>
    <ul>
TWIG . $collectionsList . <<<'TWIG'
    </ul>
{% endblock %}
TWIG,
        ];
    }

    public function getDevCommand(): string
    {
        return 'echo "Jambo Native — no dev server needed"';
    }

    public function getInstallCommand(): string
    {
        return 'echo "Jambo Native — no install needed"';
    }

    public function getBuildCommand(): string
    {
        return 'echo "Jambo Native — no build needed"';
    }

    public function getDockerfile(): string
    {
        return '';
    }
}
