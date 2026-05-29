<?php
namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\Project;
use App\Service\JamboClientGenerator;
use App\Service\WorkbenchStreamService;
use App\Workbench\Templates\NextjsTemplate;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class WorkbenchStreamServiceTest extends TestCase
{
    public function testBuildSystemPromptContainsSchema(): void
    {
        $service = $this->makeService();
        $project = $this->makeProject();

        $prompt = $service->buildSystemPrompt($project, 'nextjs');

        $this->assertStringContainsString('articles', $prompt);
        $this->assertStringContainsString('<jamboFile', $prompt);
        $this->assertStringContainsString('Next.js', $prompt);
    }

    public function testBuildSystemPromptContainsApiUrl(): void
    {
        $service = $this->makeService();
        $project = $this->makeProject();

        $prompt = $service->buildSystemPrompt($project, 'nextjs');

        $this->assertStringContainsString('JAMBO_API_URL', $prompt);
    }

    private function makeService(): WorkbenchStreamService
    {
        return new WorkbenchStreamService(
            new JamboClientGenerator(),
            [new NextjsTemplate()],
        );
    }

    private function makeProject(): Project
    {
        $project = new Project();
        $project->name = 'Test Blog';
        $project->defaultLocale = 'fr';
        $project->collections = new ArrayCollection();
        $col = new Collection();
        $col->name = 'Articles'; $col->slug = 'articles';
        $col->project = $project;
        $col->fields = new ArrayCollection();
        $project->collections->add($col);
        return $project;
    }
}
