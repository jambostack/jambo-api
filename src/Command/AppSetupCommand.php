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

#[AsCommand(
    name: 'app:setup',
    description: 'Initial setup: create admin user and seed system permissions.',
)]
class AppSetupCommand extends Command
{
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email',    null, InputOption::VALUE_OPTIONAL, 'Admin email',    'admin@jambostack.site')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Admin password', 'admin1234')
            ->addOption('name',     null, InputOption::VALUE_OPTIONAL, 'Admin name',     'Admin');
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
        $email = $input->getOption('email');
        $userRepo = $this->em->getRepository(User::class);

        if ($userRepo->findOneBy(['email' => $email])) {
            $io->warning(sprintf('Admin user "%s" already exists — skipped.', $email));
        } else {
            $admin = new User();
            $admin->email    = $email;
            $admin->name     = $input->getOption('name');
            $admin->roles    = ['ROLE_SUPER_ADMIN'];
            $admin->password = $this->hasher->hashPassword($admin, $input->getOption('password'));

            $this->em->persist($admin);
            $this->em->flush();

            $io->success(sprintf('Admin user created: %s', $email));
            $io->caution('Change the default password immediately!');
        }

        return Command::SUCCESS;
    }
}
