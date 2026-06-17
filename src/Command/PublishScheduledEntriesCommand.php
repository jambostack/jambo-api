<?php

namespace App\Command;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:publish-scheduled',
    description: 'Publie les entrées dont la date de planification est atteinte.',
)]
class PublishScheduledEntriesCommand extends Command
{
    private ?ContentEntryRepository $repository = null;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
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
        $entries = $this->getRepository()->findScheduledToPublish();

        if (empty($entries)) {
            $output->writeln('Aucune entrée à publier.');
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($entries as $entry) {
            $entry->status = 'published'; // le setter définit publishedAt = now et scheduledAt = null
            $count++;
        }

        $this->em->flush();

        $output->writeln(sprintf('%d entrée(s) publiée(s).', $count));
        return Command::SUCCESS;
    }
}
