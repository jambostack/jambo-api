<?php

namespace App\Command;

use App\Entity\Permission;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:setup',
    description: 'Initial setup: create admin user and seed system permissions.',
)]
class AppSetupCommand extends Command
{
    use CliSecurityTrait;

    private const PERMISSIONS = [
        ['name' => 'project.manage',    'label' => 'Gérer le projet',         'group' => 'project'],
        ['name' => 'project.create',    'label' => 'Créer un projet',         'group' => 'project'],
        ['name' => 'collection.create', 'label' => 'Créer une collection',    'group' => 'collection'],
        ['name' => 'collection.update', 'label' => 'Modifier une collection', 'group' => 'collection'],
        ['name' => 'collection.delete', 'label' => 'Supprimer une collection','group' => 'collection'],
        ['name' => 'content.create',    'label' => 'Créer du contenu',        'group' => 'content'],
        ['name' => 'content.update',    'label' => 'Modifier le contenu',     'group' => 'content'],
        ['name' => 'content.delete',    'label' => 'Supprimer le contenu',    'group' => 'content'],
        ['name' => 'content.trash',     'label' => 'Mettre en corbeille',     'group' => 'content'],
        ['name' => 'content.restore',   'label' => 'Restaurer le contenu',    'group' => 'content'],
        ['name' => 'assets.view',       'label' => 'Accéder aux assets',      'group' => 'assets'],
        ['name' => 'users.manage',      'label' => 'Gérer les utilisateurs',  'group' => 'users'],
        ['name' => 'roles.manage',      'label' => 'Gérer les rôles',         'group' => 'roles'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this
            ->addOption('email',         null, InputOption::VALUE_OPTIONAL, 'Admin email', 'admin@jambostack.site')
            ->addOption('password',      null, InputOption::VALUE_OPTIONAL, 'Admin password (prompted or generated if omitted)')
            ->addOption('name',          null, InputOption::VALUE_OPTIONAL, 'Admin name',  'Admin')
            ->addOption('show-password', null, InputOption::VALUE_NONE,     'Display password in output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Jambo API — Initial Setup');

        // ── Permissions ────────────────────────────────────────────────────────
        $permRepo = $this->em->getRepository(Permission::class);
        $created = 0;

        foreach (self::PERMISSIONS as $data) {
            if ($permRepo->findOneBy(['name' => $data['name']])) {
                continue;
            }
            $perm = new Permission();
            $perm->name  = $data['name'];
            $perm->label = $data['label'];
            $perm->group = $data['group'];
            $this->em->persist($perm);
            $created++;
        }

        $this->em->flush();
        $io->success(sprintf('%d permission(s) created (skipped existing).', $created));

        // ── Admin user ─────────────────────────────────────────────────────────
        $email = (string) $input->getOption('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error($this->trans('cli.invalid_email', ['%email%' => $email], null, null, "Invalid email address '$email'."));
            return Command::FAILURE;
        }

        $userRepo = $this->em->getRepository(User::class);

        if ($userRepo->findOneBy(['email' => $email])) {
            $io->warning($this->trans('cli.admin_exists', ['%email%' => $email], null, null, sprintf('Admin user "%s" already exists — skipped.', $email)));
        } else {
            $password = $input->getOption('password');
            $isGenerated = false;

            if ($password === null || $password === '') {
                if ($input->isInteractive()) {
                    $password = (string) $io->askHidden('Enter admin password');
                } else {
                    $password = $this->generateSecurePassword(16);
                    $isGenerated = true;
                }
            }

            if (!$this->validatePasswordComplexity($password) && !$isGenerated) {
                $io->error($this->trans('cli.invalid_password', [], null, null, 'Password must be at least 8 characters and include uppercase, lowercase, and digits.'));
                return Command::FAILURE;
            }

            $admin = new User();
            $admin->email    = $email;
            $admin->name     = (string) ($input->getOption('name') ?: 'Admin');
            $admin->roles    = ['ROLE_SUPER_ADMIN'];
            $admin->password = $this->hasher->hashPassword($admin, $password);

            $this->em->persist($admin);
            $this->em->flush();

            $io->success($this->trans('cli.admin_created', ['%email%' => $email], null, null, sprintf('Admin user created: %s', $email)));

            $passwordDisplay = ($isGenerated || $input->getOption('show-password'))
                ? $password
                : '[REDACTED (use --show-password to reveal)]';

            $io->definitionList(
                ['Email'    => $email],
                ['Password' => $passwordDisplay],
            );

            if ($isGenerated) {
                $io->caution($this->trans('cli.password_save_warning', [], null, null, 'Save this generated password immediately. It will not be shown again.'));
            } else {
                $io->caution($this->trans('cli.password_change_notice', [], null, null, 'Change this password immediately after your first login.'));
            }
        }

        return Command::SUCCESS;
    }
}
