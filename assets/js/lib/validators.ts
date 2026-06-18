import type { Field } from '@/types';

export interface ValidationError {
    fieldSlug: string;
    message: string;
}

/**
 * Valide une valeur de champ selon ses validationRules et son type.
 * Mêmes règles que le serveur (EavFieldHelperService::validateFieldValue).
 * Retourne null si valide, ou une ValidationError si invalide.
 */
export function validateFieldValue(
    value: unknown,
    field: Field
): ValidationError | null {
    // Required check
    if (field.required && (value === null || value === '' || value === undefined || (Array.isArray(value) && value.length === 0))) {
        return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" est requis.` };
    }

    // Skip if empty and not required
    if (value === null || value === '' || value === undefined) return null;
    if (Array.isArray(value) && value.length === 0) return null;

    // Type-based validation (mirrors PHP EavFieldHelperService::validateValue)
    if (field.type === 'email' && typeof value === 'string') {
        const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRe.test(value)) {
            return { fieldSlug: field.slug, message: 'Format email invalide' };
        }
    }
    if (field.type === 'url' && typeof value === 'string') {
        try { new URL(value); } catch { return { fieldSlug: field.slug, message: 'Format URL invalide' }; }
    }
    if ((field.type === 'number' || field.type === 'decimal' || field.type === 'rating') && value !== null && value !== '') {
        if (isNaN(Number(value))) {
            return { fieldSlug: field.slug, message: 'Valeur numérique attendue' };
        }
    }

    // validationRules checks
    const rules = field.validationRules;
    if (!rules) return null;

    // regex
    if (rules.regex && typeof value === 'string') {
        try {
            const re = new RegExp(rules.regex);
            if (!re.test(value)) {
                return { fieldSlug: field.slug, message: rules.regexMessage || `Le champ "${field.label || field.name}" ne respecte pas le format attendu.` };
            }
        } catch {
            // invalid regex — skip silently, server will catch mismatch
        }
    }

    // minLength / maxLength
    if (typeof value === 'string') {
        if (rules.minLength !== undefined && value.length < rules.minLength) {
            return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" doit contenir au moins ${rules.minLength} caractères.` };
        }
        if (rules.maxLength !== undefined && value.length > rules.maxLength) {
            return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" ne doit pas dépasser ${rules.maxLength} caractères.` };
        }
    }

    // min / max (numeric)
    if (typeof value === 'number' || (typeof value === 'string' && !isNaN(Number(value)))) {
        const num = Number(value);
        if (rules.min !== undefined && num < rules.min) {
            return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" doit être supérieur ou égal à ${rules.min}.` };
        }
        if (rules.max !== undefined && num > rules.max) {
            return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" doit être inférieur ou égal à ${rules.max}.` };
        }
    }

    return null;
}
