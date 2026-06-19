<?php

namespace App\Command;

use App\Entity\Automation;
use App\Repository\AutomationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'automation:migrate-to-flow')]
class AutomationMigrateToFlowCommand extends Command
{
    public function __construct(
        private readonly AutomationRepository $repo,
        private readonly EntityManagerInterface $em,
    ) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $automations = $this->repo->findAll();
        $migrated = 0;

        foreach ($automations as $automation) {
            if ($automation->flowGraph !== null) {
                continue; // déjà migré
            }

            // Récupère les anciennes propriétés via SQL directe
            // (les colonnes existent encore en base avant migration)
            $conn = $this->em->getConnection();
            $row = $conn->fetchAssociative(
                'SELECT trigger_type, trigger_config, conditions, action_type, action_config FROM automation WHERE id = ?',
                [$automation->id]
            );

            if (!$row) continue;

            $triggerType = $row['trigger_type'] ?? 'content.created';
            $triggerConfig = json_decode($row['trigger_config'] ?? 'null', true);
            $conditions = json_decode($row['conditions'] ?? '[]', true) ?: [];
            $actionType = $row['action_type'] ?? 'send_email';
            $actionConfig = json_decode($row['action_config'] ?? 'null', true);

            $nodes = [];
            $edges = [];
            $prevId = null;

            // Node 1 : Trigger
            $triggerId = 'n_' . bin2hex(random_bytes(4));
            $nodes[] = [
                'id' => $triggerId,
                'type' => 'trigger.' . $triggerType,
                'position' => ['x' => 100, 'y' => 200],
                'data' => ['label' => $this->triggerLabel($triggerType), 'config' => $triggerConfig ?? []],
            ];
            $prevId = $triggerId;

            // Nodes conditions (si présentes)
            foreach ($conditions as $i => $cond) {
                $condId = 'n_' . bin2hex(random_bytes(4));
                $nodes[] = [
                    'id' => $condId,
                    'type' => 'logic.condition',
                    'position' => ['x' => 100 + (($i + 1) * 300), 'y' => 200],
                    'data' => ['label' => ($cond['field'] ?? '') . ' ' . ($cond['operator'] ?? 'eq') . ' ' . ($cond['value'] ?? ''), 'config' => $cond],
                ];
                $edges[] = ['id' => 'e_' . bin2hex(random_bytes(4)), 'source' => $prevId, 'target' => $condId];
                $prevId = $condId;
            }

            // Node Action
            $actionId = 'n_' . bin2hex(random_bytes(4));
            $nodes[] = [
                'id' => $actionId,
                'type' => 'action.' . $actionType,
                'position' => ['x' => 100 + ((count($conditions) + 1) * 300), 'y' => 200],
                'data' => ['label' => $this->actionLabel($actionType), 'config' => $actionConfig ?? []],
            ];
            $edges[] = ['id' => 'e_' . bin2hex(random_bytes(4)), 'source' => $prevId, 'target' => $actionId];

            $automation->flowGraph = ['nodes' => $nodes, 'edges' => $edges, 'variables' => []];
            $this->em->persist($automation);
            $migrated++;
        }

        $this->em->flush();
        $output->writeln("$migrated automatisation(s) migrée(s).");

        return Command::SUCCESS;
    }

    private function triggerLabel(string $type): string
    {
        return match ($type) {
            'content.created' => 'Contenu créé',
            'content.updated' => 'Contenu modifié',
            'content.deleted' => 'Contenu supprimé',
            'content.status_changed' => 'Statut changé',
            'schedule.cron' => 'Planifié',
            default => $type,
        };
    }

    private function actionLabel(string $type): string
    {
        return match ($type) {
            'send_email' => 'Envoyer email',
            'call_webhook' => 'Appeler webhook',
            'create_entry' => 'Créer entrée',
            'update_entry' => 'Modifier entrée',
            'delete_entry' => 'Supprimer entrée',
            'send_notification' => 'Notification',
            default => $type,
        };
    }
}
