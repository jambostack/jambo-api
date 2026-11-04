<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\Project;
use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Service\SchemaProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class SchemaProvisionerLimitTest extends TestCase
{
    public function testCreateCollectionEnforcesMaxCollectionsLimit(): void
    {
        $project = new Project();
        $project->name = 'Test Project';
        $project->settings = [
            'max_collections' => 2,
        ];

        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(CollectionRepository::class);

        // Configuration du QueryBuilder pour le COUNT
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        // 2 collections existantes = limite atteinte
        $query->method('getSingleScalarResult')->willReturn(2);

        $repo->method('createQueryBuilder')->willReturn($qb);
        $em->method('getRepository')->with(Collection::class)->willReturn($repo);

        $em->method('wrapInTransaction')->willReturnCallback(function ($callback) {
            return $callback();
        });

        $seeder = $this->createMock(\App\Service\EndUserSchemaSeeder::class);
        $normalizer = new \App\Service\FieldRelationOptionsNormalizer($repo);

        $provisioner = new SchemaProvisioner($em, $seeder, $normalizer);

        $this->expectException(SchemaException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Collection limit reached for this project (maximum: 2).');

        $provisioner->createCollection($project, ['name' => 'Third Collection']);
    }
}
