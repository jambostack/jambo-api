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
            'number', 'decimal' => $cfv->numberValue = $value !== null ? (string) $value : null,
            'boolean', 'checkbox' => $cfv->booleanValue = $value,
            'date' => $cfv->dateValue = $value ? new \DateTime($value) : null,
            'datetime' => $cfv->datetimeValue = $value ? new \DateTime($value) : null,
            'json', 'array', 'repeater', 'enumeration', 'media', 'relation'
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
            'number', 'decimal' => is_numeric($value) ? [] : ['Valeur numérique attendue'],
            'boolean', 'checkbox' => is_bool($value) || in_array($value, [0, 1, '0', '1', true, false], true) ? [] : ['Valeur booléenne attendue'],
            'date' => strtotime((string) $value) ? [] : ['Format de date invalide'],
            'datetime' => strtotime((string) $value) ? [] : ['Format de datetime invalide'],
            'text', 'longtext', 'richtext', 'slug', 'color', 'time', 'password',
            'json', 'array', 'repeater', 'enumeration', 'media', 'relation' => [],
            default => [],
        };
    }
}
