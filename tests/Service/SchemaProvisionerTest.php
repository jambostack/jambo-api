<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Exception\SchemaException;
use App\Service\SchemaProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SchemaProvisionerTest extends KernelTestCase
{
    private SchemaProvisioner $svc;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->svc = new SchemaProvisioner($this->em, new \App\Service\EndUserSchemaSeeder($this->em));
    }

    private function project(): Project
    {
        $p = new Project();
        $p->name = 'Prov ' . bin2hex(random_bytes(4));
        $this->em->persist($p);
        $this->em->flush();
        return $p;
    }

    public function testCreateCollectionNormalisesNameAndSlug(): void
    {
        $c = $this->svc->createCollection($this->project(), ['name' => 'blog posts']);
        self::assertSame('BlogPosts', $c->name);
        self::assertSame('blog_posts', $c->slug);
    }

    public function testDuplicateCollectionSlugThrows409(): void
    {
        $p = $this->project();
        $this->svc->createCollection($p, ['name' => 'Posts']);
        $this->expectException(SchemaException::class);
        $this->expectExceptionCode(409);
        $this->svc->createCollection($p, ['name' => 'Posts']);
    }

    public function testAddFieldRejectsUnknownType(): void
    {
        $c = $this->svc->createCollection($this->project(), ['name' => 'Posts']);
        $this->expectException(SchemaException::class);
        $this->expectExceptionCode(422);
        $this->svc->addField($c, ['name' => 'X', 'type' => 'wormhole']);
    }

    public function testAddFieldRejectsReservedSlug(): void
    {
        $c = $this->svc->createCollection($this->project(), ['name' => 'Posts']);
        $this->expectException(SchemaException::class);
        $this->expectExceptionCode(422);
        $this->svc->addField($c, ['name' => 'Status', 'slug' => 'status', 'type' => 'text']);
    }

    public function testAddFieldOk(): void
    {
        $c = $this->svc->createCollection($this->project(), ['name' => 'Posts']);
        $f = $this->svc->addField($c, ['name' => 'Titre', 'type' => 'text', 'is_required' => true]);
        self::assertSame('titre', $f->slug);
        self::assertSame('text', $f->type);
        self::assertTrue($f->isRequired);
    }
}
