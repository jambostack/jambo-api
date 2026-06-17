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
                $cfv->numberValue = $value !== null ? (float) $value : null;
                break;
            case 'boolean':
            case 'checkbox':
                $cfv->booleanValue = (bool) $value;
                break;
            case 'date':
                $cfv->dateValue = $value !== null ? new \DateTimeImmutable($value) : null;
                break;
            case 'datetime':
                $cfv->datetimeValue = $value !== null ? new \DateTimeImmutable($value) : null;
                break;
            case 'time':
                $cfv->textValue = (string) $value;
                break;
            case 'json':
            case 'array':
            case 'repeater':
                $cfv->jsonValue = is_array($value) ? $value : json_decode((string) $value, true);
                break;
            case 'media':
            case 'relation':
            case 'enumeration':
            case 'tags':
                $cfv->jsonValue = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : [$value]);
                break;
            default:
                $cfv->textValue = (string) $value;
                break;
        }
        $cfv->fieldType = $fieldType;
    }
}
