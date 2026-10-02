<?php

namespace App\Command;

use App\Entity\Project;

/**
 * Trait permettant de trouver un projet par UUID partiel (6+ premiers caractères).
 * À utiliser dans les commandes Jambo.
 */
trait ProjectFinderTrait
{
    private function findProject(string $input): ?Project
    {
        $repo = $this->projects; // doit être injecté dans la commande

        // Partial UUID lookup (6+ chars)
        if (strlen($input) >= 6) {
            $results = $repo->createQueryBuilder('p')
                ->where('p.uuid LIKE :uuid')
                ->setParameter('uuid', $input . '%')
                ->setMaxResults(2)
                ->getQuery()
                ->getResult();

            if (count($results) === 1) {
                return $results[0];
            }
            if (count($results) > 1) {
                throw new \RuntimeException("Plusieurs projets trouvés avec le préfixe '$input'. Soyez plus précis.");
            }
        }

        // Fallback: exact match
        return $repo->findOneBy(['uuid' => $input]);
    }
}
