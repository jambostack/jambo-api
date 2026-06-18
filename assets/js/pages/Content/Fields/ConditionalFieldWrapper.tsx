import React from 'react';
import type { Field } from '@/types';
import { Badge } from '@/components/ui/badge';

interface Props {
    field: Field;
    formData: Record<string, any>;
    children: React.ReactNode;
}

/**
 * Evalue les conditions d'affichage du champ.
 * Masque le champ si les conditions ne sont pas remplies.
 * Affiche un badge "Conditionnel" si le champ a des conditions.
 */
export default function ConditionalFieldWrapper({ field, formData, children }: Props) {
    const conditions = field.options?.conditions;

    // Pas de conditions -> toujours visible
    if (!conditions || !Array.isArray(conditions) || conditions.length === 0) {
        return <>{children}</>;
    }

    // Evaluer toutes les conditions (AND)
    const allMet = conditions.every(cond => {
        const targetValue = formData[cond.field];

        switch (cond.operator) {
            case 'empty':
                return targetValue === null || targetValue === undefined || targetValue === '' || (Array.isArray(targetValue) && targetValue.length === 0);
            case 'notEmpty':
                return targetValue !== null && targetValue !== undefined && targetValue !== '' && !(Array.isArray(targetValue) && targetValue.length === 0);
            case 'eq':
                return targetValue == cond.value;
            case 'neq':
                return targetValue != cond.value;
            case 'in':
                return Array.isArray(cond.value) && cond.value.includes(targetValue);
            case 'contains':
                return typeof targetValue === 'string' && typeof cond.value === 'string' && targetValue.includes(cond.value);
            case 'startsWith':
                return typeof targetValue === 'string' && typeof cond.value === 'string' && targetValue.startsWith(cond.value);
            case 'gt':
                return Number(targetValue) > Number(cond.value);
            case 'gte':
                return Number(targetValue) >= Number(cond.value);
            case 'lt':
                return Number(targetValue) < Number(cond.value);
            case 'lte':
                return Number(targetValue) <= Number(cond.value);
            default:
                return false;
        }
    });

    if (!allMet) {
        return null;
    }

    return (
        <div className="relative">
            <div className="absolute -top-1 right-0 z-10">
                <Badge variant="outline" className="text-[10px] px-1.5 py-0 h-4 bg-amber-50 text-amber-700 border-amber-300">
                    Conditionnel
                </Badge>
            </div>
            {children}
        </div>
    );
}
