<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Field;

/**
 * Service de validation rigoureuse des entrées de contenu.
 * Valide les champs scalaires EAV et les champs complexes/répéteurs avec JSON Schema.
 */
class ContentEntryValidator
{
    public function __construct(
        private readonly EavFieldHelperService $fieldHelper,
    ) {}

    /**
     * Valide l'ensemble des données soumises pour une entrée de collection.
     *
     * @param array<string, mixed> $payload
     * @return array<string, list<string>> Map champ => liste des erreurs
     */
    public function validateEntry(Collection $collection, array $payload, ?ContentEntry $existingEntry = null): array
    {
        $errors = [];

        foreach ($collection->fields as $field) {
            if ($field->isDeleted()) {
                continue;
            }

            $slug = $field->slug;
            $hasKey = array_key_exists($slug, $payload);
            $value = $hasKey ? $payload[$slug] : null;

            // 1. Validation de présence si obligatoire
            if ($field->isRequired && (!$hasKey || $value === null || $value === '' || $value === [])) {
                $errors[$slug][] = sprintf('Le champ "%s" est obligatoire.', $field->name);
                continue;
            }

            if (!$hasKey || $value === null || $value === '') {
                continue;
            }

            // 2. Validation scalaire et règles de base via EavFieldHelperService
            $basicErrors = $this->fieldHelper->validateFieldValue($field, $value, $existingEntry);
            if (!empty($basicErrors)) {
                $errors[$slug] = array_merge($errors[$slug] ?? [], $basicErrors);
            }

            // 3. Validation spécifique pour les types complexes (repeater, json, array)
            if (in_array($field->type, ['repeater', 'json', 'array'], true)) {
                $complexErrors = $this->validateComplexField($field, $value);
                if (!empty($complexErrors)) {
                    $errors[$slug] = array_merge($errors[$slug] ?? [], $complexErrors);
                }
            }
        }

        return $errors;
    }

    /**
     * Valide un champ répéteur ou JSON structurel.
     *
     * @return list<string>
     */
    public function validateComplexField(Field $field, mixed $value): array
    {
        $errors = [];

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [sprintf('Le champ "%s" contient un JSON invalide.', $field->name)];
            }
            $value = $decoded;
        }

        if ($field->type === 'repeater' || $field->type === 'array') {
            if (!is_array($value)) {
                return [sprintf('Le champ "%s" doit être une liste (tableau indexé).', $field->name)];
            }

            $options = $field->options ?? [];
            $rules = $field->validationRules ?? [];

            $minItems = $rules['min_items'] ?? $rules['minItems'] ?? $options['min_items'] ?? $options['minItems'] ?? null;
            if ($minItems !== null && count($value) < (int) $minItems) {
                $errors[] = sprintf('Le champ "%s" doit comporter au moins %d élément(s).', $field->name, (int) $minItems);
            }

            $maxItems = $rules['max_items'] ?? $rules['maxItems'] ?? $options['max_items'] ?? $options['maxItems'] ?? null;
            if ($maxItems !== null && count($value) > (int) $maxItems) {
                $errors[] = sprintf('Le champ "%s" ne peut pas dépasser %d élément(s).', $field->name, (int) $maxItems);
            }

            // Validation des sous-champs si définis dans options
            $subfields = $options['fields'] ?? $options['subfields'] ?? null;
            if (is_array($subfields) && !empty($subfields)) {
                foreach ($value as $index => $item) {
                    if (!is_array($item)) {
                        $errors[] = sprintf('L\'élément #%d du répéteur "%s" doit être un objet.', $index + 1, $field->name);
                        continue;
                    }
                    foreach ($subfields as $sub) {
                        $subName = $sub['slug'] ?? $sub['name'] ?? null;
                        if (!$subName) continue;
                        $isSubRequired = !empty($sub['required']) || !empty($sub['isRequired']);
                        if ($isSubRequired && (!array_key_exists($subName, $item) || $item[$subName] === null || $item[$subName] === '')) {
                            $errors[] = sprintf('L\'élément #%d du répéteur "%s" : le sous-champ "%s" est requis.', $index + 1, $field->name, $subName);
                        }
                    }
                }
            }
        }

        // Validation JSON Schema si un schéma est configuré
        $schema = ($field->validationRules['schema'] ?? null) ?: ($field->options['schema'] ?? null);
        if (is_array($schema) && !empty($schema)) {
            $schemaErrors = $this->validateJsonSchema($schema, $value, $field->name);
            $errors = array_merge($errors, $schemaErrors);
        }

        return $errors;
    }

    /**
     * Valideur JSON Schema intégré (type, properties, required, items).
     *
     * @return list<string>
     */
    public function validateJsonSchema(array $schema, mixed $data, string $path = ''): array
    {
        $errors = [];

        // Validation de type
        if (isset($schema['type'])) {
            $type = $schema['type'];
            $validType = match ($type) {
                'string'  => is_string($data),
                'number', 'integer' => is_numeric($data),
                'boolean' => is_bool($data),
                'array'   => is_array($data) && array_is_list($data),
                'object'  => is_array($data) && (!array_is_list($data) || empty($data)),
                'null'    => $data === null,
                default   => true,
            };

            if (!$validType) {
                $errors[] = sprintf('Le champ "%s" doit être de type %s.', $path, $type);
                return $errors;
            }
        }

        // Validation des propriétés requises d'un objet
        if (isset($schema['required']) && is_array($schema['required']) && is_array($data)) {
            foreach ($schema['required'] as $reqField) {
                if (!array_key_exists($reqField, $data) || $data[$reqField] === null || $data[$reqField] === '') {
                    $errors[] = sprintf('La propriété requise "%s.%s" est manquante.', $path, $reqField);
                }
            }
        }

        // Validation récursive des propriétés d'un objet
        if (isset($schema['properties']) && is_array($schema['properties']) && is_array($data)) {
            foreach ($schema['properties'] as $propKey => $propSchema) {
                if (array_key_exists($propKey, $data) && is_array($propSchema)) {
                    $subPath = $path ? "$path.$propKey" : $propKey;
                    $subErrors = $this->validateJsonSchema($propSchema, $data[$propKey], $subPath);
                    $errors = array_merge($errors, $subErrors);
                }
            }
        }

        // Validation des éléments d'un tableau
        if (isset($schema['items']) && is_array($schema['items']) && is_array($data)) {
            foreach ($data as $i => $item) {
                $subPath = sprintf('%s[%d]', $path, $i);
                $subErrors = $this->validateJsonSchema($schema['items'], $item, $subPath);
                $errors = array_merge($errors, $subErrors);
            }
        }

        return $errors;
    }
}
