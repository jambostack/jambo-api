<?php

namespace App\Command;

use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsCommand(name: 'jambo', description: 'Jambo CMS — outils CLI complets')]
class JamboCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        private EntityManagerInterface $em,
        private ParameterBagInterface $params,
        private SluggerInterface $slugger,
    ) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse subcommand from argv manually since Symfony doesn't support subcommands natively
        global $argv;
        $sub = $argv[2] ?? 'help';
        $action = $argv[3] ?? 'list';

        $io->warning("Utilisez les commandes dédiées :\n" .
            "  php bin/console jambo:project  <action> [options]\n" .
            "  php bin/console jambo:collection <action> [options]\n" .
            "  php bin/console jambo:content   <action> [options]\n" .
            "  php bin/console jambo:token     <action> [options]\n" .
            "  php bin/console jambo:import    <fichier.csv> [options]"
        );
        return Command::SUCCESS;
    }
}
