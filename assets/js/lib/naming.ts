/**
 * Conventions de nommage CANONIQUES de Jambo — miroir TypeScript de
 * App\Service\NamingConvention (PHP) et des règles de l'agent IA du Studio.
 *
 *  - nom de collection : PascalCase  (« BlogPosts »)
 *  - nom de champ      : camelCase   (« publishedAt »)
 *  - slug (collection + champ) : snake_case dérivé du nom, conscient du
 *    camelCase (« publishedAt » → « published_at »), ASCII, jamais commençant
 *    par un chiffre.
 *
 * Garder STRICTEMENT aligné avec la version PHP pour une cohérence front/back.
 */

/** Découpe en mots ASCII (gère camelCase, espaces, séparateurs, accents). */
function splitWords(value: string): string[] {
    const ascii = value
        .normalize('NFKD')               // décompose les accents
        .replace(/[^\x20-\x7E]/g, '');   // retire diacritiques et résidus non-ASCII
    const spaced = ascii.replace(/([a-z0-9])([A-Z])/g, '$1 $2'); // frontières camelCase
    return spaced.split(/[^A-Za-z0-9]+/).filter(Boolean);
}

/** Nom de collection : PascalCase, commence par une lettre. */
export function toPascalCase(value: string): string {
    let out = splitWords(value)
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
        .join('');
    if (out !== '' && !/[A-Za-z]/.test(out[0])) {
        out = 'C' + out;
    }
    return out;
}

/** Nom de champ : camelCase. */
export function toCamelCase(value: string): string {
    const pascal = toPascalCase(value);
    return pascal === '' ? '' : pascal.charAt(0).toLowerCase() + pascal.slice(1);
}

/** Slug : snake_case, ASCII, ne commence jamais par un chiffre. */
export function toSnakeCase(value: string): string {
    let out = splitWords(value)
        .map((w) => w.toLowerCase())
        .join('_');
    if (out !== '' && /[0-9]/.test(out[0])) {
        out = 'f_' + out;
    }
    return out;
}
