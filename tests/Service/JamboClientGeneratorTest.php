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

    public function testPreventsCommentBreakingAndCodeInjectionInProjectName(): void
    {
        $project = new Project();
        $project->name = "Evil Project \n console.log('hacked'); //";
        $project->collections = new ArrayCollection();

        $output = $this->generator->generate($project, 'https://api.example.com', 'safe-uuid');

        $this->assertStringNotContainsString("\n console.log", $output);
        $this->assertStringContainsString("// Auto-generated Jambo API Client for project: Evil Project  console.log('hacked'); //", $output);
    }

    public function testSafelyEncodesApiUrlAndProjectUuid(): void
    {
        $project = new Project();
        $project->name = 'Secure Project';
        $project->collections = new ArrayCollection();

        $maliciousUrl = "https://api.example.com'; alert(1); const foo = '";
        $maliciousUuid = "uuid-123'; evil(); '";

        $output = $this->generator->generate($project, $maliciousUrl, $maliciousUuid);

        // JSON encoding safely encloses in double quotes so single quotes cannot break syntax
        $this->assertStringContainsString('const API_URL = "https://api.example.com\'; alert(1); const foo = \'";', $output);
        $this->assertStringContainsString('const PROJECT_UUID = "uuid-123\'; evil(); \'";', $output);
    }

    public function testHandlesHyphenatedSlugsAndQuotedProperties(): void
    {
        $project = new Project();
        $project->name = 'Shop';

        $col = new Collection();
        $col->name = 'Blog Posts';
        $col->slug = 'blog-posts';
        $col->project = $project;
        $col->fields = new ArrayCollection();

        $f = new Field();
        $f->name = 'First Name';
        $f->slug = 'first-name';
        $f->type = 'text';
        $f->isRequired = true;
        $col->fields->add($f);

        $project->collections = new ArrayCollection([$col]);

        $output = $this->generator->generate($project, 'https://api.example.com', 'uuid');

        $this->assertStringContainsString('export interface BlogPost', $output);
        $this->assertStringContainsString('"first-name": string;', $output);
        $this->assertStringContainsString('export async function getBlogPosts(): Promise<BlogPost[]>', $output);
        $this->assertStringContainsString('/collections/blog-posts', $output);
    }

    public function testHandlesReservedKeywordsInCollectionName(): void
    {
        $project = new Project();
        $project->name = 'Keywords';

        $col = new Collection();
        $col->name = 'Interface';
        $col->slug = 'interface';
        $col->project = $project;
        $col->fields = new ArrayCollection();

        $project->collections = new ArrayCollection([$col]);

        $output = $this->generator->generate($project, 'https://api.example.com', 'uuid');

        $this->assertStringContainsString('export interface IInterface', $output);
    }

    public function testHandlesNumericCollectionNames(): void
    {
        $project = new Project();
        $project->name = 'Numeric';

        $col = new Collection();
        $col->name = '123Items';
        $col->slug = '123-items';
        $col->project = $project;
        $col->fields = new ArrayCollection();

        $project->collections = new ArrayCollection([$col]);

        $output = $this->generator->generate($project, 'https://api.example.com', 'uuid');

        $this->assertStringContainsString('export interface Item123item', $output);
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
