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
        $input = trim(substr($input, 0, 64));

        // Partial UUID lookup (6+ chars)
        if (strlen($input) >= 6) {
            try {
                $escaped = addcslashes($input, '%_');
                $results = $repo->createQueryBuilder('p')
                    ->where('p.uuid LIKE :uuid')
                    ->setParameter('uuid', $escaped . '%')
                    ->setMaxResults(2)
                    ->getQuery()
                    ->getResult();

                if (is_array($results)) {
                    if (count($results) === 1) {
                        return $results[0];
                    }
                    if (count($results) > 1) {
                        throw new \RuntimeException("Plusieurs projets trouvés avec le préfixe '$input'. Soyez plus précis.");
                    }
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable) {
                // En cas d'exception query builder, poursuivre vers le fallback exact
            }
        }

        // Fallback: exact match
        return $repo->findOneBy(['uuid' => $input]);
    }
}
