<?php

namespace App\EventSubscriber;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\GraphQL\SchemaGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
class GraphQLCacheInvalidationSubscriber
{
    /** @var array<string, Project> */
    private array $invalidatedProjects = [];

    public function __construct(
        private SchemaGenerator $schemaGenerator,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        // Invalidation sur modification de collection
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $project = $this->getProject($entity);
            if ($project) {
                $this->invalidatedProjects[$project->uuid->toRfc4122()] = $project;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $project = $this->getProject($entity);
            if ($project) {
                $this->invalidatedProjects[$project->uuid->toRfc4122()] = $project;
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $project = $this->getProject($entity);
            if ($project) {
                $this->invalidatedProjects[$project->uuid->toRfc4122()] = $project;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->invalidatedProjects as $project) {
            $this->schemaGenerator->invalidateCache($project);
        }
        $this->invalidatedProjects = [];
    }

    private function getProject(object $entity): ?Project
    {
        if ($entity instanceof Project) {
            return $entity;
        }
        if ($entity instanceof Collection) {
            return $entity->project;
        }
        if ($entity instanceof Field) {
            return $entity->collection?->project;
        }

        return null;
    }
}
