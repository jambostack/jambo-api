<?php

namespace App\Repository;

use App\Entity\MediaFolder;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MediaFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaFolder::class);
    }

    /**
     * Retourne tous les dossiers actifs d'un projet, sous forme de liste plate.
     *
     * @return MediaFolder[]
     */
    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.project = :project')
            ->andWhere('f.deletedAt IS NULL')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.name', 'ASC')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();
    }

    /**
     * Construit l'arbre complet des dossiers pour un projet.
     *
     * Retourne une liste plate avec les enfants hydratés,
     * ou un tableau nesté selon le format demandé.
     *
     * @return MediaFolder[] (racines, avec children hydratés)
     */
    public function findTree(Project $project): array
    {
        $all = $this->findByProject($project);

        $byId = [];
        foreach ($all as $folder) {
            $byId[$folder->id] = $folder;
        }

        $roots = [];
        foreach ($all as $folder) {
            if ($folder->parent !== null && isset($byId[$folder->parent->id])) {
                // L'enfant est déjà dans la collection du parent via Doctrine
                // On ne fait rien ici — l'arbre est construit par les relations
            } else {
                $roots[] = $folder;
            }
        }

        return $roots;
    }

    /**
     * Compte les médias dans un dossier (hors soft-delete).
     */
    public function countMediaInFolder(MediaFolder $folder): int
    {
        return (int) $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from('App\Entity\Media', 'm')
            ->where('m.folder = :folder')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('folder', $folder)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
