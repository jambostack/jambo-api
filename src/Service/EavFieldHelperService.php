<?php

namespace App\Service;

use App\Entity\Collection;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Service\Security\HtmlContentSanitizer;
use Symfony\Contracts\Translation\TranslatorInterface;

class EavFieldHelperService
{
    public function __construct(
        private ?TranslatorInterface $translator = null,
        private ?HtmlContentSanitizer $htmlSanitizer = null,
    ) {
        $this->htmlSanitizer ??= new HtmlContentSanitizer();
    }

    private function trans(string $id, array $parameters = [], string $default = ''): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters);
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default !== '' ? vsprintf($default, $parameters) : $id;
    }
    /**
     * Applique une valeur typée à un ContentFieldValue selon le type de champ EAV.
     */
    public function setFieldValue(ContentFieldValue $cfv, string $type, mixed $value): void
    {
        match ($type) {
            'number', 'decimal', 'rating' => $cfv->numberValue = ($value !== null && is_scalar($value)) ? (string) $value : null,
            'boolean', 'checkbox' => $cfv->booleanValue = $value !== null ? (is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN)) : null,
            'date' => $cfv->dateValue = $this->parseDateTime($value),
            'datetime' => $cfv->datetimeValue = $this->parseDateTime($value),
            'richtext', 'wysiwyg' => $cfv->textValue = ($value !== null && is_scalar($value))
                ? $this->htmlSanitizer->sanitize((string) $value)
                : (is_array($value) ? $this->htmlSanitizer->sanitize(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : null),
            'json', 'array', 'repeater', 'enumeration', 'media', 'relation', 'tags'
                => $cfv->jsonValue = $this->parseJsonValue($value),
            default => $cfv->textValue = ($value !== null && is_scalar($value)) ? (string) $value : (is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null),
        };
    }

    private function parseDateTime(mixed $value): ?\DateTime
    {
        if (empty($value) || !is_string($value)) {
            return null;
        }
        try {
            return new \DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseJsonValue(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : [$decoded];
            } catch (\Throwable) {
                return null;
            }
        }
        return is_scalar($value) ? [(string) $value] : null;
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
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? [] : [$this->trans('field.validation.invalid_email', [], 'Format email invalide')],
            'url' => filter_var($value, FILTER_VALIDATE_URL) ? [] : [$this->trans('field.validation.invalid_url', [], 'Format URL invalide')],
            'number', 'decimal' => is_numeric($value) ? [] : [$this->trans('field.validation.numeric_expected', [], 'Valeur numérique attendue')],
            'rating' => is_numeric($value) ? [] : [$this->trans('field.validation.rating_expected', [], 'Note numérique attendue')],
            'tags' => is_array($value) ? [] : [$this->trans('field.validation.array_expected', [], 'Liste de valeurs attendue (tableau)')],
            'boolean', 'checkbox' => is_bool($value) || in_array($value, [0, 1, '0', '1', true, false], true) ? [] : [$this->trans('field.validation.boolean_expected', [], 'Valeur booléenne attendue')],
            'date' => strtotime((string) $value) ? [] : [$this->trans('field.validation.invalid_date', [], 'Format de date invalide')],
            'datetime' => strtotime((string) $value) ? [] : [$this->trans('field.validation.invalid_datetime', [], 'Format de datetime invalide')],
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
            $errors[] = $this->trans('field.validation.required', ['%field%' => $field->name], 'Le champ "%s" est requis.');
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
                    $msg = $rules['regexMessage'] ?? $this->trans('field.validation.regex_mismatch', ['%field%' => $field->name], 'Le champ "%s" ne respecte pas le format attendu.');
                    $errors[] = $msg;
                }
            }
        }

        // minLength / maxLength (champs texte)
        if (is_string($value)) {
            $len = mb_strlen($value);
            if (isset($rules['minLength']) && $len < (int)$rules['minLength']) {
                $errors[] = $this->trans('field.validation.min_length', ['%field%' => $field->name, '%min%' => $rules['minLength']], 'Le champ "%s" doit contenir au moins %d caractères.');
            }
            if (isset($rules['maxLength']) && $len > (int)$rules['maxLength']) {
                $errors[] = $this->trans('field.validation.max_length', ['%field%' => $field->name, '%max%' => $rules['maxLength']], 'Le champ "%s" ne doit pas dépasser %d caractères.');
            }
        }

        // min / max (champs numériques)
        if (is_numeric($value)) {
            $num = (float)$value;
            if (isset($rules['min']) && $num < (float)$rules['min']) {
                $errors[] = $this->trans('field.validation.min_value', ['%field%' => $field->name, '%min%' => $rules['min']], 'Le champ "%s" doit être supérieur ou égal à %s.');
            }
            if (isset($rules['max']) && $num > (float)$rules['max']) {
                $errors[] = $this->trans('field.validation.max_value', ['%field%' => $field->name, '%max%' => $rules['max']], 'Le champ "%s" doit être inférieur ou égal à %s.');
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
