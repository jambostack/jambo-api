<?php
namespace App\Command;

use App\Entity\EndUser;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:enduser:create', description: 'Créer un utilisateur frontend (EndUser)')]
class EndUserCreateCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse email')
            ->addArgument('password', InputArgument::OPTIONAL, 'Mot de passe (demandé ou généré si omis)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom', '')
            ->addOption('show-password', null, InputOption::VALUE_NONE, 'Afficher le mot de passe dans la sortie');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $projectUuid = (string) $i->getArgument('project-uuid');
        $project = $this->projects->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, 'Projet introuvable'));
            return Command::FAILURE;
        }

        $email = trim((string) $i->getArgument('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            $io->error($this->trans('cli.invalid_email', ['%email%' => $email], null, null, "Adresse email invalide '$email'."));
            return Command::FAILURE;
        }

        $existing = $this->em->getRepository(EndUser::class)->findOneBy(['project' => $project, 'email' => $email]);
        if ($existing) {
            $io->error($this->trans('cli.enduser_already_exists', ['%email%' => $email], null, null, "Un EndUser avec l'adresse '$email' existe déjà dans ce projet."));
            return Command::FAILURE;
        }

        $password = $i->getArgument('password');
        $isGenerated = false;

        if ($password === null || $password === '') {
            if ($i->isInteractive()) {
                $password = (string) $io->askHidden('Mot de passe EndUser');
            } else {
                $password = $this->generateSecurePassword(16);
                $isGenerated = true;
            }
        }

        if (!$this->validatePasswordComplexity((string) $password) && !$isGenerated) {
            $io->error($this->trans('cli.invalid_password', [], null, null, 'Le mot de passe doit comporter au moins 8 caractères avec majuscules, minuscules et chiffres.'));
            return Command::FAILURE;
        }

        $eu = new EndUser($project, $email);
        $eu->name = (string) $i->getOption('name');
        $eu->isActive = true;
        $eu->password = $this->hasher->hashPassword($eu, (string) $password);

        $this->em->persist($eu);
        $this->em->flush();

        $io->success($this->trans('cli.enduser_created', ['%email%' => $eu->email], null, null, "EndUser '{$eu->email}' créé !"));

        $displayPassword = ($isGenerated || $i->getOption('show-password'))
            ? $password
            : '[REDACTED (use --show-password to reveal)]';

        $io->definitionList(
            ['Project'  => "{$project->name} ({$projectUuid})"],
            ['Email'    => $eu->email],
            ['Password' => $displayPassword],
        );

        if ($isGenerated) {
            $io->caution($this->trans('cli.password_save_warning', [], null, null, 'Save this generated password immediately. It will not be shown again.'));
        }

        return Command::SUCCESS;
    }
}
