<?php

namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\Project;
use App\Service\FieldRelationOptionsNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class FieldRelationOptionsNormalizerTest extends KernelTestCase
{
    private FieldRelationOptionsNormalizer $normalizer;
    private Project $project;
    private Collection $articles;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->project = new Project();
        $this->project->name = 'Normalizer Test ' . bin2hex(random_bytes(4));
        $em->persist($this->project);

        $this->articles = new Collection();
        $this->articles->project = $this->project;
        $this->articles->name = 'Articles';
        $this->articles->slug = 'articles-' . bin2hex(random_bytes(4));
        $this->articles->order = 0;
        $em->persist($this->articles);

        $em->flush();

        $this->normalizer = new FieldRelationOptionsNormalizer(
            self::getContainer()->get(\App\Repository\CollectionRepository::class)
        );
    }

    public function testCanonicalIntIdGetsSlugResolved(): void
    {
        $result = $this->normalizer->normalize(
            ['relation' => ['collection' => $this->articles->id, 'type' => 2]],
            $this->project
        );

        $this->assertSame($this->articles->id, $result['relation']['collection']);
        $this->assertSame($this->articles->slug, $result['relation']['collection_slug']);
        $this->assertSame(2, $result['relation']['type']);
    }

    public function testLegacySchemaBuilderFormatIsConverted(): void
    {
        // Ancien format SchemaBuilder : { targetCollection: slug, relationType: 2 }
        $result = $this->normalizer->normalize(
            ['targetCollection' => $this->articles->slug, 'relationType' => 2, 'includeDraft' => true],
            $this->project
        );

        $this->assertSame($this->articles->id, $result['relation']['collection']);
        $this->assertSame(2, $result['relation']['type'], 'relationType top-level doit devenir relation.type');
        $this->assertSame($this->articles->slug, $result['relation']['collection_slug']);
        $this->assertArrayNotHasKey('targetCollection', $result, 'targetCollection est réservé à end_users');
        $this->assertArrayNotHasKey('relationType', $result);
        $this->assertTrue($result['includeDraft'], 'les autres clés doivent être préservées');
    }

    public function testEndUsersTargetIsPreserved(): void
    {
        $result = $this->normalizer->normalize(
            ['targetCollection' => 'end_users', 'relation' => ['type' => 2]],
            $this->project
        );

        $this->assertSame('end_users', $result['targetCollection']);
        $this->assertSame(2, $result['relation']['type']);
        $this->assertArrayNotHasKey('collection', $result['relation']);
    }

    public function testLegacySlugInRelationCollectionIsResolvedToId(): void
    {
        // Données antérieures au correctif serializeField : relation.collection = slug string
        $result = $this->normalizer->normalize(
            ['relation' => ['collection' => $this->articles->slug, 'type' => 2]],
            $this->project
        );

        $this->assertSame($this->articles->id, $result['relation']['collection']);
        $this->assertSame(2, $result['relation']['type']);
        $this->assertArrayNotHasKey('targetCollection', $result, 'un slug de collection régulière ne doit pas devenir targetCollection');
    }

    public function testLegacyEndUsersSlugInRelationCollection(): void
    {
        $result = $this->normalizer->normalize(
            ['relation' => ['collection' => 'end_users', 'type' => 1]],
            $this->project
        );

        $this->assertSame('end_users', $result['targetCollection']);
        $this->assertArrayNotHasKey('collection', $result['relation']);
    }

    public function testMissingTypeDefaultsToOne(): void
    {
        $result = $this->normalizer->normalize(
            ['relation' => ['collection' => $this->articles->id]],
            $this->project
        );

        $this->assertSame(1, $result['relation']['type']);
    }

    public function testUnresolvableSlugLeavesOptionsIntact(): void
    {
        $result = $this->normalizer->normalize(
            ['targetCollection' => 'does-not-exist', 'relationType' => 2],
            $this->project
        );

        // Pas de destruction de données : la cible inconnue reste lisible
        $this->assertSame('does-not-exist', $result['targetCollection']);
        $this->assertSame(2, $result['relation']['type']);
    }

    public function testCollectionOfAnotherProjectIsNotResolved(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $other = new Project();
        $other->name = 'Other ' . bin2hex(random_bytes(4));
        $em->persist($other);
        $em->flush();

        $result = $this->normalizer->normalize(
            ['targetCollection' => $this->articles->slug, 'relationType' => 1],
            $other
        );

        $this->assertArrayNotHasKey('collection', $result['relation'] ?? []);
    }

    public function testForStorageOmitsDerivedSlug(): void
    {
        $result = $this->normalizer->normalize(
            ['relation' => ['collection' => $this->articles->id, 'type' => 1, 'collection_slug' => 'stale-slug']],
            $this->project,
            forStorage: true
        );

        $this->assertArrayNotHasKey('collection_slug', $result['relation'], 'collection_slug est dérivé, jamais stocké');
        $this->assertSame($this->articles->id, $result['relation']['collection']);
    }

    public function testNullOptionsPassThrough(): void
    {
        $this->assertNull($this->normalizer->normalize(null, $this->project));
    }
}
