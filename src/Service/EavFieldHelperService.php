<?php

namespace App\Service;

use App\Entity\Collection;
use App\Entity\ContentFieldValue;
use App\Entity\Field;

class EavFieldHelperService
{
    /**
     * Applique une valeur typée à un ContentFieldValue selon le type de champ EAV.
     */
    public function setFieldValue(ContentFieldValue $cfv, string $type, mixed $value): void
    {
        match ($type) {
            'number', 'decimal', 'rating' => $cfv->numberValue = $value !== null ? (string) $value : null,
            'boolean', 'checkbox' => $cfv->booleanValue = $value,
            'date' => $cfv->dateValue = $value ? new \DateTime($value) : null,
            'datetime' => $cfv->datetimeValue = $value ? new \DateTime($value) : null,
            'json', 'array', 'repeater', 'enumeration', 'media', 'relation', 'tags'
                => $cfv->jsonValue = is_string($value) ? json_decode($value, true) : $value,
            default => $cfv->textValue = $value !== null ? (string) $value : null,
        };
    }

    /**
     * Trouve un champ par slug dans une collection (hors soft-deleted).
     */
    public function findField(Collection $collection, string $slug): ?Field
    {
        return $collection->fields->findFirst(
            fn(int $key, Field $f) => $f->slug === $slug && !$f->isDeleted()
        );
    }

    /**
     * Valide une valeur selon le type de champ. Retourne un tableau d'erreurs (vide = valide).
     */
    public function validateValue(string $type, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return match ($type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? [] : ['Format email invalide'],
            'url' => filter_var($value, FILTER_VALIDATE_URL) ? [] : ['Format URL invalide'],
            'number', 'decimal' => is_numeric($value) ? [] : ['Valeur numérique attendue'],
            'rating' => is_numeric($value) ? [] : ['Note numérique attendue'],
            'tags' => is_array($value) ? [] : ['Liste de valeurs attendue (tableau)'],
            'boolean', 'checkbox' => is_bool($value) || in_array($value, [0, 1, '0', '1', true, false], true) ? [] : ['Valeur booléenne attendue'],
            'date' => strtotime((string) $value) ? [] : ['Format de date invalide'],
            'datetime' => strtotime((string) $value) ? [] : ['Format de datetime invalide'],
            'text', 'longtext', 'richtext', 'slug', 'color', 'time', 'password',
            'markdown', 'code', 'icon', 'uuid',
            'json', 'array', 'repeater', 'enumeration', 'media', 'relation' => [],
            default => [],
        };
    }

    /**
     * Valide une valeur selon les règles de validation du champ ET le type.
     * Retourne un tableau de messages d'erreur (vide = valide).
     */
    public function validateFieldValue(Field $field, mixed $value, ?\App\Entity\ContentEntry $existingEntry = null): array
    {
        $errors = [];

        // 1. Required check (already enforced elsewhere, but double-check)
        if ($field->isRequired && ($value === null || $value === '' || $value === [])) {
            $errors[] = sprintf('Le champ "%s" est requis.', $field->name);
            return $errors;
        }

        // Si la valeur est vide et non requise, pas de validation supplémentaire
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        // 2. Validation par type (existante)
        $typeErrors = $this->validateValue($field->type, $value);
        $errors = array_merge($errors, $typeErrors);

        // 3. Validation par validationRules
        $rules = $field->validationRules;
        if (!is_array($rules) || empty($rules)) {
            return $errors;
        }

        // regex
        if (!empty($rules['regex'])) {
            if (is_string($value)) {
                $regex = $rules['regex'];
                // Ajouter des delimiteurs si absents (preg_match les exige)
                if (@preg_match($regex, '') === false) {
                    $regex = '/' . str_replace('/', '\/', $regex) . '/';
                }
                if (!preg_match($regex, $value)) {
                    $msg = $rules['regexMessage'] ?? sprintf('Le champ "%s" ne respecte pas le format attendu.', $field->name);
                    $errors[] = $msg;
                }
            }
        }

        // minLength / maxLength (champs texte)
        if (is_string($value)) {
            $len = mb_strlen($value);
            if (isset($rules['minLength']) && $len < (int)$rules['minLength']) {
                $errors[] = sprintf('Le champ "%s" doit contenir au moins %d caractères.', $field->name, $rules['minLength']);
            }
            if (isset($rules['maxLength']) && $len > (int)$rules['maxLength']) {
                $errors[] = sprintf('Le champ "%s" ne doit pas dépasser %d caractères.', $field->name, $rules['maxLength']);
            }
        }

        // min / max (champs numériques)
        if (is_numeric($value)) {
            $num = (float)$value;
            if (isset($rules['min']) && $num < (float)$rules['min']) {
                $errors[] = sprintf('Le champ "%s" doit être supérieur ou égal à %s.', $field->name, $rules['min']);
            }
            if (isset($rules['max']) && $num > (float)$rules['max']) {
                $errors[] = sprintf('Le champ "%s" doit être inférieur ou égal à %s.', $field->name, $rules['max']);
            }
        }

        // unique (dans la collection, hors entrée courante)
        if (!empty($rules['unique']) && $existingEntry !== null && $field->collection !== null) {
            // On ne fait pas la vérification d'unicité ici — elle nécessite
            // un accès au repository. Elle sera faite dans le contrôleur.
        }

        // custom message (surcharge le dernier message d'erreur si défini)
        if (!empty($rules['custom']) && !empty($errors)) {
            $errors[count($errors) - 1] = $rules['custom'];
        }

        return $errors;
    }
}
