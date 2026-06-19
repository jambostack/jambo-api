<?php

namespace App\Command;

use App\Repository\AutomationRepository;
use App\Service\Automation\AutomationEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('automation:run-scheduled', 'Exécute les automatisations planifiées dont le cron matche')]
class AutomationRunScheduledCommand extends Command
{
    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationEngine $engine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $automations = $this->automationRepo->findActiveScheduled();
        $now = new \DateTimeImmutable();
        $executed = 0;

        foreach ($automations as $automation) {
            $schedule = $automation->triggerConfig['schedule'] ?? null;
            if ($schedule === null || $schedule === '') continue;

            try {
                if ($this->cronMatches($schedule, $now)) {
                    $payload = [
                        'trigger'      => 'schedule.cron',
                        'project_uuid' => $automation->project?->uuid?->toRfc4122(),
                        'timestamp'    => time(),
                        'schedule'     => $schedule,
                    ];

                    $this->engine->dispatchAsync($automation, $payload);
                    $executed++;
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>Automation #{$automation->id}: {$e->getMessage()}</error>");
            }
        }

        $output->writeln("<info>$executed automatisation(s) exécutée(s)</info>");
        return Command::SUCCESS;
    }

    /**
     * Parser cron minimal (5 champs : minute heure jour mois jour-semaine).
     * Supporte etoile, etoile/N, N, N,N,N.
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

        return $this->fieldMatches($min, $current['min'])
            && $this->fieldMatches($hour, $current['hour'])
            && $this->fieldMatches($day, $current['day'])
            && $this->fieldMatches($month, $current['month'])
            && $this->fieldMatches($dow, $current['dow']);
    }

    private function fieldMatches(string $pattern, int $value): bool
    {
        // * = toujours
        if ($pattern === '*') return true;

        // */N = tous les N
        if (str_starts_with($pattern, '*/')) {
            $step = (int) substr($pattern, 2);
            return $step > 0 && ($value % $step) === 0;
        }

        // N,N,N = liste
        if (str_contains($pattern, ',')) {
            $items = explode(',', $pattern);
            foreach ($items as $item) {
                if ($this->fieldMatches(trim($item), $value)) return true;
            }
            return false;
        }

        // N = valeur exacte
        return (int) $pattern === $value;
    }
}
