<?php
namespace App\Service;

use App\Entity\Project;
use App\Workbench\Templates\BaseTemplate;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

class WorkbenchStreamService
{
    /** @param BaseTemplate[] $templates */
    public function __construct(
        private readonly JamboClientGenerator $clientGenerator,
        private readonly iterable $templates,
    ) {}

    /**
     * Builds the system prompt enriched with the project schema and framework context.
     * Public so it can be unit-tested without a real AI provider.
     */
    public function buildSystemPrompt(Project $project, string $framework, string $jamboApiUrl = ''): string
    {
        $template = $this->findTemplate($framework);
        $frameworkLabel = $template?->getLabel() ?? $framework;

        $collections = [];
        foreach ($project->collections as $col) {
            if (method_exists($col, 'isDeleted') && $col->isDeleted()) continue;
            $fields = [];
            foreach ($col->fields as $f) {
                if (method_exists($f, 'isDeleted') && $f->isDeleted()) continue;
                $fields[] = "  - {$f->slug} ({$f->type}" . ($f->isRequired ? ', required' : '') . ')';
            }
            $collections[] = "Collection: {$col->name} (slug: {$col->slug})\n" . implode("\n", $fields);
        }

        $schema = implode("\n\n", $collections) ?: 'No collections yet.';

        return <<<PROMPT
You are an expert {$frameworkLabel} developer. Your job is to generate production-quality {$frameworkLabel} application code based on the user's Jambo CMS schema.

## Project: {$project->name}
## Framework: {$frameworkLabel}
## Default locale: {$project->defaultLocale}

## Available Collections (Jambo Schema)
{$schema}

## Rules
1. Every file you generate MUST be wrapped in <jamboFile path="relative/path/to/file"> ... </jamboFile> tags.
2. Import data using the functions in lib/jambo.ts (already present in the project).
3. The JAMBO_API_URL is already set to '{$jamboApiUrl}' in the project's .env file. Use the env var in code, never hardcode it.
4. Write TypeScript. Use async/await. No class components.
5. Make the UI clean, modern, and responsive using Tailwind CSS classes.
6. When you create a page, also update the navigation in the layout file.
7. Output ONLY file tags — no explanation text outside the tags.
8. If the user asks to "add a page" create both the page file and update the layout.

## Output format (strict)
<jamboFile path="app/page.tsx">
...file content...
</jamboFile>
PROMPT;
    }

    /**
     * Yields SSE-formatted chunks for a StreamedResponse.
     * Each chunk: "data: {"content":"..."}\n\n"
     * Final chunk: "data: [DONE]\n\n"
     */
    public function stream(
        Project $project,
        string $userPrompt,
        string $framework,
        string $jamboApiUrl,
        PlatformInterface $platform,
        string $model,
    ): \Generator {
        $systemPrompt = $this->buildSystemPrompt($project, $framework, $jamboApiUrl);

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userPrompt),
        );

        $result = $platform->invoke($model, $messages, ['stream' => true]);

        foreach ($result->asTextStream() as $delta) {
            $text = $delta->getText();
            if ($text === '') continue;
            yield 'data: ' . json_encode(['content' => $text]) . "\n\n";
        }

        yield "data: [DONE]\n\n";
    }

    private function findTemplate(string $framework): ?BaseTemplate
    {
        foreach ($this->templates as $template) {
            if ($template->getId() === $framework) return $template;
        }
        return null;
    }
}
