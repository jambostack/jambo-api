<?php

namespace App\Command;

use App\Repository\AutomationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'automation:migrate-to-flow')]
class AutomationMigrateToFlowCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private readonly AutomationRepository $repo,
        private readonly EntityManagerInterface $em,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $automations = $this->repo->findAll();
        $migrated = 0;

        foreach ($automations as $automation) {
            if ($automation->flowGraph !== null) {
                continue; // déjà migré
            }

            // Les colonnes legacy n'existent plus en base — on crée un flow minimal
            $triggerId = 'n_' . bin2hex(random_bytes(4));
            $actionId  = 'n_' . bin2hex(random_bytes(4));

            $nodes = [
                [
                    'id' => $triggerId,
                    'type' => 'trigger.content.created',
                    'position' => ['x' => 100, 'y' => 200],
                    'data' => ['label' => 'Contenu créé', 'config' => []],
                ],
                [
                    'id' => $actionId,
                    'type' => 'action.send_notification',
                    'position' => ['x' => 400, 'y' => 200],
                    'data' => ['label' => 'Notification', 'config' => ['title' => 'Automatisation', 'body' => 'Exécutée']],
                ],
            ];

            $edges = [
                ['id' => 'e_' . bin2hex(random_bytes(4)), 'source' => $triggerId, 'target' => $actionId],
            ];

            $automation->flowGraph = ['nodes' => $nodes, 'edges' => $edges, 'variables' => []];
            $this->em->persist($automation);
            $migrated++;
        }

        $this->em->flush();
        $msg = $this->trans('cli.automation.migrated_count', ['%count%' => $migrated], null, null, "$migrated automatisation(s) migrée(s) avec un flow par défaut.");
        $output->writeln("<info>$msg</info>");

        return Command::SUCCESS;
    }
}
