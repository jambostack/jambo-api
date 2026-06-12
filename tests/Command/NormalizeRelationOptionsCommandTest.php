<?php

namespace App\Tests\Command;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class NormalizeRelationOptionsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Project $project;
    private Collection $articles;
    private Field $legacyField;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->project = new Project();
        $this->project->name = 'Migrate Test ' . bin2hex(random_bytes(4));
        $this->em->persist($this->project);

        $this->articles = new Collection();
        $this->articles->project = $this->project;
        $this->articles->name = 'Articles';
        $this->articles->slug = 'articles-' . bin2hex(random_bytes(4));
        $this->articles->order = 0;
        $this->em->persist($this->articles);

        $pages = new Collection();
        $pages->project = $this->project;
        $pages->name = 'Pages';
        $pages->slug = 'pages-' . bin2hex(random_bytes(4));
        $pages->order = 1;
        $this->em->persist($pages);

        // Champ relation au format legacy SchemaBuilder
        $this->legacyField = new Field();
        $this->legacyField->collection = $pages;
        $this->legacyField->name = 'Article lié';
        $this->legacyField->slug = 'article_lie';
        $this->legacyField->type = 'relation';
        $this->legacyField->options = ['targetCollection' => $this->articles->slug, 'relationType' => 2];
        $this->legacyField->order = 0;
        $this->em->persist($this->legacyField);

        $this->em->flush();
    }

    private function runCommand(array $input = []): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:normalize-relation-options');
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    public function testLegacyOptionsAreNormalizedInDatabase(): void
    {
        $tester = $this->runCommand();
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $field = $this->em->find(Field::class, $this->legacyField->id);
        $opts = $field->options;

        $this->assertSame($this->articles->id, $opts['relation']['collection'] ?? null);
        $this->assertSame(2, $opts['relation']['type'] ?? null);
        $this->assertArrayNotHasKey('targetCollection', $opts);
        $this->assertArrayNotHasKey('relationType', $opts);
        $this->assertArrayNotHasKey('collection_slug', $opts['relation']);
    }

    public function testDryRunLeavesDatabaseUntouched(): void
    {
        $tester = $this->runCommand(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $field = $this->em->find(Field::class, $this->legacyField->id);

        // NB : MySQL réordonne les clés JSON, on compare donc clé par clé.
        $this->assertSame($this->articles->slug, $field->options['targetCollection'] ?? null, 'le mode --dry-run ne doit rien écrire');
        $this->assertSame(2, $field->options['relationType'] ?? null);
        $this->assertArrayNotHasKey('relation', $field->options);
    }
}
