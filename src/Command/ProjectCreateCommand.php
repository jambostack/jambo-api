<?php
namespace App\Command;

use App\Repository\UserRepository;
use App\Service\SchemaProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:project:create', description: 'Créer un nouveau projet')]
class ProjectCreateCommand extends Command
{
    public function __construct(
        private SchemaProvisioner $provisioner,
        private UserRepository $users,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Nom du projet')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Description', '')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale par défaut', 'en');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        try {
            $user = $this->users->findOneBy(['email' => 'admin@jambostack.site']);
            $project = $this->provisioner->createProject($user, [
                'name' => $i->getArgument('name'),
                'description' => $i->getOption('description'),
                'defaultLocale' => $i->getOption('locale'),
            ]);
            $io->success("Projet '{$project->name}' créé !");
            $io->writeln("UUID: {$project->uuid->toString()}");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
