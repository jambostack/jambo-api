<?php

namespace App\Service;

use App\Entity\Project;
use App\Repository\CollectionRepository;

/**
 * Normalise les options d'un champ relation vers le format canonique unique,
 * quelle que soit la provenance (FieldFormModal, SchemaBuilder, chat IA, legacy) :
 *
 *   - relation.type            : 1 (One to One) | 2 (One to Many)
 *   - relation.collection      : id int de la Collection cible (absent pour end_users)
 *   - relation.collection_slug : slug résolu (lecture seule, jamais stocké)
 *   - targetCollection         : 'end_users' uniquement (entité système)
 *
 * Formats legacy absorbés :
 *   - relationType au top-level (ancien SchemaBuilder)
 *   - targetCollection = slug d'une collection régulière (ancien SchemaBuilder)
 *   - relation.collection = slug string (données antérieures au correctif serializeField)
 */
final class FieldRelationOptionsNormalizer
{
    public function __construct(private CollectionRepository $collectionRepository) {}

    /**
     * @param bool $forStorage true = format de persistance (sans collection_slug dérivé)
     */
    public function normalize(?array $options, Project $project, bool $forStorage = false): ?array
    {
        if ($options === null) {
            return null;
        }

        $relation = is_array($options['relation'] ?? null) ? $options['relation'] : [];

        // relation.type : relation.type > relationType legacy (top-level) > 1
        $relation['type'] = (int) ($relation['type'] ?? $options['relationType'] ?? 1);
        unset($options['relationType']);

        // Cible : relation.collection (id ou slug legacy) ou targetCollection (slug)
        $target = $relation['collection'] ?? $options['targetCollection'] ?? null;

        if ($target === 'end_users') {
            // Entité système : pas de Collection, le slug vit dans targetCollection
            $options['targetCollection'] = 'end_users';
            unset($relation['collection']);
        } elseif (is_int($target) || (is_string($target) && ctype_digit($target))) {
            $coll = $this->collectionRepository->findOneBy(['id' => (int) $target, 'project' => $project, 'deletedAt' => null]);
            $relation['collection'] = (int) $target;
            if ($coll !== null) {
                $relation['collection_slug'] = $coll->slug;
            }
            unset($options['targetCollection']);
        } elseif (is_string($target) && $target !== '') {
            // Slug de collection régulière (formats legacy)
            $coll = $this->collectionRepository->findOneBy(['slug' => $target, 'project' => $project, 'deletedAt' => null]);
            if ($coll !== null) {
                $relation['collection'] = $coll->id;
                $relation['collection_slug'] = $coll->slug;
                unset($options['targetCollection']);
            } else {
                // Cible irrésoluble : ne pas détruire la donnée
                unset($relation['collection']);
                $options['targetCollection'] = $target;
                $relation['collection_slug'] = $target;
            }
        }

        if ($forStorage) {
            unset($relation['collection_slug']);
        }

        $options['relation'] = $relation;

        return $options;
    }
}
