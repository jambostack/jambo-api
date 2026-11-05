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
        try {
            return $this->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return null;
        }
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
     * Cherche dans TOUS les nœuds, pas seulement nodes[0].
     *
     * @return Automation[]
     */
    public function findActiveByTriggerType(Project $project, string $triggerType): array
    {
        return $this->findActiveByCondition($project, $triggerType);
    }

    /**
     * Trouve toutes les automatisations actives de type cron (planifié),
     * optionnellement filtrées par projet pour garantir l'isolation multi-tenant.
     *
     * @return Automation[]
     */
    public function findActiveScheduled(?Project $project = null): array
    {
        return $this->findActiveByCondition($project, 'trigger.schedule.cron');
    }

    /**
     * Trouve les automatisations webhook entrant actives par projet.
     *
     * @return Automation[]
     */
    public function findActiveWebhookByProject(Project $project): array
    {
        return $this->findActiveByCondition($project, 'trigger.webhook.inbound');
    }

    // ─── Private ─────────────────────────────────────────────────────────

    /**
     * Méthode générique : trouve les automatisations actives et filtre selon le type de trigger.
     *
     * @param Project|null $whereProject null = pas de filtre projet
     * @param string $triggerType Le type de trigger à chercher
     * @return Automation[]
     */
    private function findActiveByCondition(?Project $whereProject, string $triggerType): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.isActive = true');

        if ($whereProject !== null) {
            $qb->andWhere('a.project = :project')
               ->setParameter('project', $whereProject);
        }

        /** @var Automation[] $allActive */
        $allActive = $qb->getQuery()->getResult();

        return array_values(array_filter($allActive, fn (Automation $a) => $this->hasTriggerType($a->flowGraph, $triggerType)));
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
