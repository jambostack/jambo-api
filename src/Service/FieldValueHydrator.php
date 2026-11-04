<?php

namespace App\Service;

use App\Entity\ContentFieldValue;

class FieldValueHydrator
{
    public function hydrate(ContentFieldValue $cfv, mixed $value, string $fieldType): void
    {
        switch ($fieldType) {
            case 'number':
            case 'decimal':
            case 'rating':
                $cfv->numberValue = ($value !== null && is_scalar($value)) ? (string) $value : null;
                break;
            case 'boolean':
            case 'checkbox':
                $cfv->booleanValue = $value !== null ? (is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN)) : null;
                break;
            case 'date':
                $cfv->dateValue = $this->parseDateTime($value);
                break;
            case 'datetime':
                $cfv->datetimeValue = $this->parseDateTime($value);
                break;
            case 'time':
                $cfv->textValue = ($value !== null && is_scalar($value)) ? (string) $value : null;
                break;
            case 'json':
            case 'array':
            case 'repeater':
            case 'media':
            case 'relation':
            case 'enumeration':
            case 'tags':
                $cfv->jsonValue = $this->parseJsonValue($value);
                break;
            default:
                $cfv->textValue = ($value !== null && is_scalar($value)) ? (string) $value : (is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
                break;
        }
        $cfv->fieldType = $fieldType;
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
}
