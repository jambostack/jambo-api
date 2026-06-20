<?php

namespace App\Tests\GraphQL;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\GraphQL\SchemaGenerator;
use App\Repository\ContentEntryRepository;
use App\Service\EavDataFormatterService;
use App\Service\EavFieldHelperService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SchemaGeneratorTest extends TestCase
{
    private function makeProject(?string $namespace = null): Project
    {
        $project = new Project();
        $project->uuid = Uuid::v5(
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            $namespace ?? 'test-proj'
        );
        $project->name = 'Test Project';
        return $project;
    }

    private function makeCollection(Project $project, string $slug = 'articles'): Collection
    {
        $collection = new Collection();
        $collection->uuid = Uuid::v5(
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            $slug
        );
        $collection->slug = $slug;
        $collection->name = ucfirst($slug);
        $collection->project = $project;
        $collection->fields = new ArrayCollection();
        return $collection;
    }

    private function makeField(Collection $collection, string $slug, string $type = 'text', string $name = ''): Field
    {
        $field = new Field();
        $field->slug = $slug;
        $field->name = $name ?: ucfirst($slug);
        $field->type = $type;
        $field->order = 0;
        $field->collection = $collection;
        return $field;
    }

    public function testBuildSchemaReturnsSchemaWithQuery(): void
    {
        $collectionRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $formatter = new EavDataFormatterService();
        $fieldHelper = $this->createMock(EavFieldHelperService::class);

        $generator = new SchemaGenerator($em, $entryRepo, $formatter, $fieldHelper);

        $project = $this->makeProject('schema-query');
        $schema = $generator->buildSchema($project);

        $this->assertInstanceOf(\GraphQL\Type\Schema::class, $schema);
        $this->assertNotNull($schema->getQueryType());
    }

    public function testBuildSchemaCachesResult(): void
    {
        $collectionRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $formatter = new EavDataFormatterService();
        $fieldHelper = $this->createMock(EavFieldHelperService::class);

        $generator = new SchemaGenerator($em, $entryRepo, $formatter, $fieldHelper);
        $project = $this->makeProject('cache-test');

        // Première appel : déclenche findBy()
        $schema1 = $generator->buildSchema($project);

        // Deuxième appel : doit utiliser le cache
        $schema2 = $generator->buildSchema($project);

        $this->assertInstanceOf(\GraphQL\Type\Schema::class, $schema1);
        $this->assertInstanceOf(\GraphQL\Type\Schema::class, $schema2);
        // Vérifier que le cache renvoie la même instance
        $this->assertSame($schema1, $schema2);
    }

    public function testInvalidateCache(): void
    {
        $collectionRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $collectionRepo->expects($this->exactly(2))
            ->method('findBy')
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $formatter = new EavDataFormatterService();
        $fieldHelper = $this->createMock(EavFieldHelperService::class);

        $generator = new SchemaGenerator($em, $entryRepo, $formatter, $fieldHelper);
        $project = $this->makeProject('invalidate-test');

        // Premier appel : population du cache
        $generator->buildSchema($project);

        // Invalidation
        $generator->invalidateCache($project);

        // Deuxième appel : doit rappeler findBy()
        $generator->buildSchema($project);
    }

    public function testBuildSchemaIncludesSubscriptionType(): void
    {
        $project = $this->makeProject('subscription-test');
        $collection = $this->makeCollection($project, 'articles');
        $titleField = $this->makeField($collection, 'title', 'text', 'Title');
        $collection->fields->add($titleField);

        $collectionRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->willReturn([$collection]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $formatter = new EavDataFormatterService();
        $fieldHelper = $this->createMock(EavFieldHelperService::class);

        $generator = new SchemaGenerator($em, $entryRepo, $formatter, $fieldHelper, '');

        $schema = $generator->buildSchema($project);

        $this->assertNotNull($schema->getSubscriptionType(), 'Schema must include a Subscription type.');
        $subscriptionFields = $schema->getSubscriptionType()->getFields();
        // Avec une collection 'articles', le champ 'articles' doit exister dans Subscription
        $this->assertArrayHasKey('articles', $subscriptionFields, 'Subscription should have a field named after the collection.');
        $this->assertArrayHasKey('projectEvents', $subscriptionFields, 'Subscription should have a projectEvents field.');
    }
}
