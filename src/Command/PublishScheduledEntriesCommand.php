<?php

namespace App\Command;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:publish-scheduled',
    description: 'Publie les entrées dont la date de planification est atteinte.',
)]
class PublishScheduledEntriesCommand extends Command
{
    use LockableTrait;
    use CliSecurityTrait;

    private ?ContentEntryRepository $repository = null;

    public function __construct(
        private EntityManagerInterface $em,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    /**
     * @internal pour les tests uniquement
     */
    public function setRepository(ContentEntryRepository $repository): void
    {
        $this->repository = $repository;
    }

    private function getRepository(): ContentEntryRepository
    {
        // En production, on récupère le repository depuis l'EntityManager
        // En test, il est injecté via setRepository()
        return $this->repository ?? $this->em->getRepository(ContentEntry::class);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>' . $this->trans('cli.command_already_running', [], null, null, 'Command is already running in another process.') . '</comment>');
            return Command::SUCCESS;
        }

        try {
            $entries = $this->getRepository()->findScheduledToPublish();

            if (empty($entries)) {
                $output->writeln($this->trans('cli.publish_scheduled.none', [], null, null, 'Aucune entrée à publier.'));
                return Command::SUCCESS;
            }

            $count = 0;
            foreach ($entries as $entry) {
                $entry->status = 'published'; // le setter définit publishedAt = now et scheduledAt = null
                $count++;
            }

            $this->em->flush();

            $output->writeln($this->trans('cli.publish_scheduled.success', ['%count%' => $count], null, null, sprintf('%d entrée(s) publiée(s).', $count)));
            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
