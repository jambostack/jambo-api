<?php

namespace App\Service\Form;

use App\Entity\Form;
use Symfony\Component\Validator\Constraints as Assert;

class FormBuilder
{
    private const ALLOWED_TYPES = [
        'text', 'email', 'tel', 'number', 'textarea', 'select', 'checkbox',
        'radio', 'file', 'date', 'hidden', 'heading', 'paragraph',
    ];

    /** @var array<string, list<class-string>> mapping field types to constraint classes to instantiate */
    private const TYPE_CONSTRAINTS_MAP = [
        'email'    => Assert\Email::class,
        'tel'      => Assert\Regex::class,
        'number'   => Assert\Type::class,
        'date'     => Assert\Date::class,
    ];

    /** @var array<string, array<string, mixed>> additional constructor arguments per type */
    private const TYPE_CONSTRAINT_ARGS = [
        'tel'    => ['pattern' => '/^[+\-\s\d()]{6,20}$/'],
        'number' => ['type' => 'numeric'],
    ];

    /**
     * Validate a form field definition array.
     *
     * @param array $fields The fields array from the Form entity
     * @return array{valid: bool, errors: array}
     */
    public function validateDefinition(array $fields): array
    {
        $errors = [];

        foreach ($fields as $index => $field) {
            $fieldErrors = [];

            if (!isset($field['type']) || !is_string($field['type'])) {
                $fieldErrors[] = 'Missing or invalid required key: type';
            } elseif (!in_array($field['type'], self::ALLOWED_TYPES, true)) {
                $fieldErrors[] = sprintf(
                    'Invalid field type "%s". Allowed types: %s',
                    $field['type'],
                    implode(', ', self::ALLOWED_TYPES)
                );
            }

            if (!isset($field['label']) || !is_string($field['label']) || trim($field['label']) === '') {
                $fieldErrors[] = 'Missing or empty required key: label';
            }

            if (!empty($fieldErrors)) {
                $key = $field['id'] ?? $field['name'] ?? $index;
                $errors[$key] = $fieldErrors;
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Build a Symfony validation constraints array from the form's fields definition.
     *
     * @param Form $form
     * @return array<string, list<Assert\Constraint>> Mapping of field name => list of constraints
     */
    public function buildFormSchema(Form $form): array
    {
        $schema = [];

        foreach ($form->fields as $field) {
            $type = $field['type'] ?? 'text';
            $name = $field['name'] ?? $field['id'] ?? null;

            if (!$name) {
                continue;
            }

            $constraints = [];
            $typeConstraintClass = self::TYPE_CONSTRAINTS_MAP[$type] ?? null;

            if (null !== $typeConstraintClass) {
                $args = self::TYPE_CONSTRAINT_ARGS[$type] ?? [];
                $constraints[] = new $typeConstraintClass(...$args);
            }

            // Required validation
            if (!empty($field['required'])) {
                $constraints[] = new Assert\NotBlank();
            }

            // Length constraints
            $minLen = !empty($field['minLength']) ? (int) $field['minLength'] : null;
            $maxLen = !empty($field['maxLength']) ? (int) $field['maxLength'] : null;
            if (null !== $minLen || null !== $maxLen) {
                $constraints[] = new Assert\Length(min: $minLen, max: $maxLen);
            }

            // Select/radio options validation
            if (in_array($type, ['select', 'radio'], true) && !empty($field['options'])) {
                $allowedChoices = array_column($field['options'], 'value');
                if (!empty($allowedChoices)) {
                    $constraints[] = new Assert\Choice(choices: $allowedChoices);
                }
            }

            if (!empty($constraints)) {
                $schema[$name] = $constraints;
            }
        }

        return $schema;
    }

    /**
     * Resolve conditional field visibility based on submitted values.
     *
     * @param array $fields The fields definition array
     * @param array $values The submitted values (field name => value)
     * @return array List of visible field IDs/names
     */
    public function resolveConditions(array $fields, array $values): array
    {
        $visible = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? $field['id'] ?? null;
            if (!$name) {
                continue;
            }

            // If the field has no conditions, it is always visible
            if (empty($field['conditions'])) {
                $visible[] = $name;
                continue;
            }

            if ($this->evaluateConditions($field['conditions'], $values)) {
                $visible[] = $name;
            }
        }

        return $visible;
    }

    /**
     * Evaluate a set of conditions (AND/OR compound or single condition).
     *
     * @param array $conditions
     * @param array $values
     * @return bool
     */
    private function evaluateConditions(array $conditions, array $values): bool
    {
        // Compound: AND
        if (isset($conditions['operator']) && strtolower($conditions['operator']) === 'and' && isset($conditions['conditions'])) {
            foreach ($conditions['conditions'] as $condition) {
                if (!$this->evaluateSingleCondition($condition, $values)) {
                    return false;
                }
            }
            return true;
        }

        // Compound: OR
        if (isset($conditions['operator']) && strtolower($conditions['operator']) === 'or' && isset($conditions['conditions'])) {
            foreach ($conditions['conditions'] as $condition) {
                if ($this->evaluateSingleCondition($condition, $values)) {
                    return true;
                }
            }
            return false;
        }

        // Single condition
        return $this->evaluateSingleCondition($conditions, $values);
    }

    /**
     * Evaluate a single condition.
     *
     * Supports: equals, not_equals, contains, greater_than, is_empty, is_not_empty
     *
     * @param array $condition
     * @param array $values
     * @return bool
     */
    private function evaluateSingleCondition(array $condition, array $values): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;
        $submittedValue = $values[$field] ?? null;

        return match ($operator) {
            'equals' => $submittedValue === $value,
            'not_equals' => $submittedValue !== $value,
            'contains' => is_string($submittedValue) && is_string($value) && str_contains($submittedValue, $value),
            'greater_than' => is_numeric($submittedValue) && is_numeric($value) && (float) $submittedValue > (float) $value,
            'is_empty' => empty($submittedValue),
            'is_not_empty' => !empty($submittedValue),
            default => true,
        };
    }
}
