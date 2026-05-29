<?php
namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\JamboClientGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class JamboClientGeneratorTest extends TestCase
{
    private JamboClientGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new JamboClientGenerator();
    }

    public function testGeneratesInterfaceForCollection(): void
    {
        $project = $this->makeProject();
        $output = $this->generator->generate($project, 'https://api.example.com', 'test-uuid');

        $this->assertStringContainsString('export interface Article', $output);
        $this->assertStringContainsString('title?: string', $output);
        $this->assertStringContainsString('views?: number', $output);
    }

    public function testGeneratesGetFunction(): void
    {
        $project = $this->makeProject();
        $output = $this->generator->generate($project, 'https://api.example.com', 'test-uuid');

        $this->assertStringContainsString('export async function getArticles()', $output);
        $this->assertStringContainsString('/collections/articles', $output);
    }

    public function testApiUrlIsInjected(): void
    {
        $project = $this->makeProject();
        $output = $this->generator->generate($project, 'https://my-cms.example.com', 'proj-123');

        $this->assertStringContainsString('https://my-cms.example.com', $output);
        $this->assertStringContainsString('proj-123', $output);
    }

    private function makeProject(): Project
    {
        $project = new Project();
        $project->name = 'Test';
        $project->defaultLocale = 'en';

        $col = new Collection();
        $col->name = 'Articles';
        $col->slug = 'articles';
        $col->project = $project;
        $col->fields = new ArrayCollection();

        $f1 = new Field(); $f1->name = 'Title'; $f1->slug = 'title'; $f1->type = 'text'; $f1->isRequired = false;
        $f2 = new Field(); $f2->name = 'Views'; $f2->slug = 'views'; $f2->type = 'number'; $f2->isRequired = false;
        $col->fields->add($f1);
        $col->fields->add($f2);

        $project->collections = new ArrayCollection([$col]);

        return $project;
    }
}
