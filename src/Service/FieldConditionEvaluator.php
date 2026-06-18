<?php

namespace App\Service;

use App\Entity\Field;

class FieldConditionEvaluator
{
    /**
     * Détermine si un champ doit être visible selon les données du formulaire.
     * Un champ sans conditions est toujours visible.
     * Toutes les conditions sont combinées en ET (AND).
     */
    public function isVisible(Field $field, array $formData): bool
    {
        $conditions = $field->options['conditions'] ?? null;

        if (!is_array($conditions) || empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $targetSlug = $condition['field'] ?? null;
            if ($targetSlug === null) {
                continue;
            }

            $targetValue = $formData[$targetSlug] ?? null;

            if (!$this->evaluateCondition($condition, $targetValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Évalue une condition unique contre une valeur cible.
     */
    public function evaluateCondition(array $condition, mixed $targetValue): bool
    {
        $operator = $condition['operator'] ?? 'eq';
        $expectedValue = $condition['value'] ?? null;

        return match ($operator) {
            'empty'     => empty($targetValue) || $targetValue === '' || $targetValue === null || $targetValue === [],
            'notEmpty'  => !empty($targetValue) && $targetValue !== '' && $targetValue !== null && $targetValue !== [],
            'eq'        => $targetValue == $expectedValue,
            'neq'       => $targetValue != $expectedValue,
            'in'        => is_array($expectedValue) && in_array($targetValue, $expectedValue),
            'contains'  => is_string($targetValue) && is_string($expectedValue) && str_contains($targetValue, $expectedValue),
            'startsWith'=> is_string($targetValue) && is_string($expectedValue) && str_starts_with($targetValue, $expectedValue),
            'gt'        => is_numeric($targetValue) && is_numeric($expectedValue) && $targetValue > $expectedValue,
            'gte'       => is_numeric($targetValue) && is_numeric($expectedValue) && $targetValue >= $expectedValue,
            'lt'        => is_numeric($targetValue) && is_numeric($expectedValue) && $targetValue < $expectedValue,
            'lte'       => is_numeric($targetValue) && is_numeric($expectedValue) && $targetValue <= $expectedValue,
            default     => false,
        };
    }
}
