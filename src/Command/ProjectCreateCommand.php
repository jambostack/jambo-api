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
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:project:create', description: 'Créer un nouveau projet')]
class ProjectCreateCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private SchemaProvisioner $provisioner,
        private UserRepository $users,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Nom du projet')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Description', '')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale par défaut', 'en')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Email du propriétaire (défaut : premier administrateur)', null);
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);

        $userEmail = $i->getOption('user');
        $user = null;
        if ($userEmail) {
            $user = $this->users->findOneBy(['email' => $userEmail]);
            if (!$user) {
                $io->error("Utilisateur avec l'email '$userEmail' introuvable.");
                return Command::FAILURE;
            }
        } else {
            $user = $this->users->findOneBy(['email' => 'admin@jambostack.site'])
                ?? $this->users->findOneBy([], ['id' => 'ASC']);
        }

        if (!$user) {
            $io->error('Aucun utilisateur trouvé en base de données. Exécutez "bin/console app:setup" d\'abord.');
            return Command::FAILURE;
        }

        try {
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
