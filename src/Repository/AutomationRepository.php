<?php

namespace App\Repository;

use App\Entity\Automation;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AutomationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Automation::class);
    }

    /** @return Automation[] */
    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.project = :project')
            ->setParameter('project', $project)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Trouve une automatisation par son UUID. */
    public function findOneByUuid(string $uuid): ?Automation
    {
        return $this->createQueryBuilder('a')
            ->where('a.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve le premier nœud de type trigger.* dans le flowGraph.
     * Ne suppose pas que le trigger est en position [0] — parcourt tous les nœuds.
     *
     * @return array{node: array|null, type: string} Le nœud trigger et son type
     */
    public static function findTriggerNode(array $flowGraph): array
    {
        $nodes = $flowGraph['nodes'] ?? [];
        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            if (str_starts_with($type, 'trigger.')) {
                return ['node' => $node, 'type' => $type];
            }
        }
        return ['node' => null, 'type' => ''];
    }

    /**
     * Trouve les automatisations actives dont le graphe contient un trigger
     * du type spécifié (ex: trigger.content.created).
     *
     * Utilise JSON_EXTRACT (MySQL) si disponible, sinon filtre en PHP.
     * Cherche dans TOUS les nœuds, pas seulement nodes[0].
     *
     * @return Automation[]
     */
    public function findActiveByTriggerType(Project $project, string $triggerType): array
    {
        return $this->findActiveByCondition(
            whereProject: $project,
            jsonCondition: "JSON_CONTAINS(a.flowGraph, JSON_OBJECT('type', :triggerType), '$.nodes')",
            triggerType: $triggerType,
        );
    }

    /**
     * Trouve toutes les automatisations actives de type cron (planifié).
     *
     * @return Automation[]
     */
    public function findActiveScheduled(): array
    {
        return $this->findActiveByCondition(
            whereProject: null,
            jsonCondition: "JSON_CONTAINS(a.flowGraph, JSON_OBJECT('type', :triggerType), '$.nodes')",
            triggerType: 'trigger.schedule.cron',
        );
    }

    /**
     * Trouve les automatisations webhook entrant actives par projet.
     *
     * @return Automation[]
     */
    public function findActiveWebhookByProject(Project $project): array
    {
        return $this->findActiveByCondition(
            whereProject: $project,
            jsonCondition: "JSON_CONTAINS(a.flowGraph, JSON_OBJECT('type', :triggerType), '$.nodes')",
            triggerType: 'trigger.webhook.inbound',
        );
    }

    // ─── Private ─────────────────────────────────────────────────────────

    /**
     * Méthode générique : trouve les automatisations actives avec une condition JSON.
     *
     * @param Project|null $whereProject null = pas de filtre projet
     * @param string $jsonCondition Condition JSON (utilise :triggerType comme paramètre)
     * @param string $triggerType Le type de trigger à chercher
     * @return Automation[]
     */
    private function findActiveByCondition(?Project $whereProject, string $jsonCondition, string $triggerType): array
    {
        // Tentative avec fonctions JSON (MySQL / MariaDB)
        try {
            $qb = $this->createQueryBuilder('a')
                ->where('a.isActive = true');

            if ($whereProject !== null) {
                $qb->andWhere('a.project = :project')
                   ->setParameter('project', $whereProject);
            }

            return $qb->andWhere($jsonCondition)
                ->setParameter('triggerType', $triggerType)
                ->getQuery()
                ->getResult();
        } catch (\Exception) {
            // Fallback PHP si le driver ne supporte pas JSON_CONTAINS
        }

        // Fallback : récupère toutes les actives et filtre en PHP
        $qb = $this->createQueryBuilder('a')
            ->where('a.isActive = true');

        if ($whereProject !== null) {
            $qb->andWhere('a.project = :project')
               ->setParameter('project', $whereProject);
        }

        $allActive = $qb->getQuery()->getResult();

        return array_filter($allActive, fn (Automation $a) => $this->hasTriggerType($a->flowGraph, $triggerType));
    }

    /** Vérifie si le flowGraph contient un nœud trigger du type donné. */
    private function hasTriggerType(?array $flowGraph, string $triggerType): bool
    {
        if (!$flowGraph) return false;

        $nodes = $flowGraph['nodes'] ?? [];
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === $triggerType) {
                return true;
            }
        }
        return false;
    }
}
