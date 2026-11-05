<?php

namespace App\Command;

use App\Repository\AutomationRepository;
use App\Repository\ProjectRepository;
use App\Service\Automation\AutomationEngine;
use App\Service\Automation\AutomationQuotaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('automation:run-scheduled', 'Exécute les automatisations planifiées dont le cron matche')]
class AutomationRunScheduledCommand extends Command
{
    use LockableTrait;
    use CliSecurityTrait;
    use ProjectFinderTrait;

    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationEngine $engine,
        private readonly ?ProjectRepository $projects = null,
        ?TranslatorInterface $translator = null,
        private readonly ?AutomationQuotaService $quotaService = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'Filter by project UUID or slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>' . $this->trans('cli.command_already_running', [], null, null, 'Command is already running in another process.') . '</comment>');
            return Command::SUCCESS;
        }

        try {
            $projectFilter = null;
            $projectInput = $input->getOption('project');
            if ($projectInput !== null && $this->projects !== null) {
                $projectFilter = $this->findProject((string) $projectInput);
                if ($projectFilter === null) {
                    $output->writeln('<error>' . $this->trans('cli.project_not_found', ['%ident%' => $projectInput], null, null, "Projet '$projectInput' introuvable.") . '</error>');
                    return Command::FAILURE;
                }
            }

            $automations = $this->automationRepo->findActiveScheduled($projectFilter);
            $now = new \DateTimeImmutable();
            $executed = 0;

            foreach ($automations as $automation) {
                $graph = $automation->flowGraph;
                if (!$graph || empty($graph['nodes'])) continue;

                // Cherche le trigger dans tous les nœuds (pas seulement nodes[0])
                $triggerInfo = AutomationRepository::findTriggerNode($graph);
                $triggerNode = $triggerInfo['node'];

                if (!$triggerNode) continue;

                $schedule = $triggerNode['data']['config']['schedule'] ?? null;
                if ($schedule === null || $schedule === '') continue;

                if ($automation->project !== null && $this->quotaService !== null && $this->quotaService->isQuotaExceeded($automation->project)) {
                    $output->writeln('<comment>' . $this->trans('cli.automation.quota_exceeded_skipped', ['%name%' => $automation->name ?? (string) $automation->id], null, null, "Quota dépassé pour l'automatisation %name%, ignorée.") . '</comment>');
                    continue;
                }

                try {
                    if ($this->cronMatches($schedule, $now)) {
                        $payload = [
                            'trigger'      => 'schedule.cron',
                            'project_uuid' => $automation->project?->uuid?->toRfc4122(),
                            'timestamp'    => time(),
                            'schedule'     => $schedule,
                        ];

                        $this->engine->execute($automation, $payload);
                        $executed++;
                    }
                } catch (\Throwable $e) {
                    $output->writeln("<error>Automation #{$automation->id}: {$e->getMessage()}</error>");
                }
            }

            $msg = $this->trans('cli.automation.executed_count', ['%count%' => $executed], null, null, "$executed automatisation(s) exécutée(s)");
            $output->writeln("<info>$msg</info>");
            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }

    /**
     * Parser cron (5 champs : minute heure jour mois jour-semaine).
     * Supporte : *, step/N, N, N,N,N, N-N, noms de jours (mon,tue,...,sun).
     */
    private function cronMatches(string $expression, \DateTimeImmutable $now): bool
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) return false;

        [$min, $hour, $day, $month, $dow] = $parts;

        $current = [
            'min'   => (int) $now->format('i'),
            'hour'  => (int) $now->format('H'),
            'day'   => (int) $now->format('d'),
            'month' => (int) $now->format('m'),
            'dow'   => (int) $now->format('w'),
        ];

        return $this->fieldMatches($min, $current['min'], 0, 59)
            && $this->fieldMatches($hour, $current['hour'], 0, 23)
            && $this->fieldMatches($day, $current['day'], 1, 31)
            && $this->fieldMatches($month, $current['month'], 1, 12)
            && $this->fieldMatches($dow, $current['dow'], 0, 7);
    }

    private function fieldMatches(string $pattern, int $value, int $min, int $max): bool
    {
        // * = toujours
        if ($pattern === '*') return true;

        // Noms de jours (0=dim, 1=lun, ..., 6=sam, 7=dim)
        $dayNames = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
        $lower = strtolower($pattern);
        if (isset($dayNames[$lower])) {
            return $value === $dayNames[$lower];
        }

        // */N = tous les N
        if (str_starts_with($pattern, '*/')) {
            $step = (int) substr($pattern, 2);
            return $step > 0 && ($value % $step) === 0;
        }

        // N-N = plage
        if (str_contains($pattern, '-') && !str_contains($pattern, ',')) {
            $parts = explode('-', $pattern);
            if (count($parts) === 2) {
                $lo = (int) trim($parts[0]);
                $hi = (int) trim($parts[1]);
                return $value >= $lo && $value <= $hi;
            }
        }

        // N,N,N = liste
        if (str_contains($pattern, ',')) {
            $items = explode(',', $pattern);
            foreach ($items as $item) {
                if ($this->fieldMatches(trim($item), $value, $min, $max)) return true;
            }
            return false;
        }

        // N = valeur exacte (valide la plage)
        $num = (int) $pattern;
        if ($num < $min || $num > $max) return false;
        return $num === $value;
    }
}
