<?php
namespace App\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(name: 'jambo:info', description: 'Afficher les informations système Jambo')]
class InfoCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private ParameterBagInterface $params,
    ) { parent::__construct(); }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $nbProjects = $this->em->createQuery('SELECT COUNT(p) FROM App\Entity\Project p')->getSingleScalarResult();
        $nbCollections = $this->em->createQuery('SELECT COUNT(c) FROM App\Entity\Collection c WHERE c.deletedAt IS NULL')->getSingleScalarResult();
        $nbEntries = $this->em->createQuery('SELECT COUNT(e) FROM App\Entity\ContentEntry e WHERE e.deletedAt IS NULL')->getSingleScalarResult();
        $nbEndUsers = $this->em->createQuery('SELECT COUNT(u) FROM App\Entity\EndUser u')->getSingleScalarResult();
        $nbAdminUsers = $this->em->createQuery('SELECT COUNT(u) FROM App\Entity\User u')->getSingleScalarResult();
        $rows = [
            ['PHP', PHP_VERSION],
            ['Environnement', $this->params->get('kernel.environment')],
            ['Projets', $nbProjects],
            ['Collections', $nbCollections],
            ['Entrées de contenu', $nbEntries],
            ['Utilisateurs admin', $nbAdminUsers],
            ['EndUsers (frontend)', $nbEndUsers],
        ];
        $io->table(['Métrique', 'Valeur'], $rows);
        return Command::SUCCESS;
    }
}
