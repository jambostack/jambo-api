<?php

namespace App\Service;

use function Symfony\Component\String\u;

/**
 * Conventions de nommage CANONIQUES de Jambo, partagées par TOUTES les surfaces
 * (agent IA du Studio, éditeur standard de collections/champs, schéma EndUsers).
 *
 * Règles (alignées sur StudioController::NAMING_CONVENTIONS) :
 *  - nom de collection : PascalCase  (« BlogPosts »)
 *  - nom de champ      : camelCase   (« publishedAt »)
 *  - slug (collection + champ) : snake_case dérivé du nom, conscient du camelCase
 *    (« publishedAt » → « published_at »), ASCII, ^[a-z][a-z0-9_]*$, jamais
 *    commençant par un chiffre.
 *
 * Le découpage en mots gère le camelCase, les espaces, les séparateurs et replie
 * les accents en ASCII — exactement comme l'agent IA, pour garantir des
 * identifiants identiques quelle que soit la voie de création.
 */
final class NamingConvention
{
    /** Slugs de champs système, gérés automatiquement — jamais créés manuellement. */
    public const RESERVED_FIELD_SLUGS = ['id', 'uuid', 'status', 'locale', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Découpe une chaîne en mots ASCII (gère camelCase, espaces, séparateurs, accents).
     *
     * @return string[]
     */
    public static function splitWords(string $value): array
    {
        // Replie les accents en ASCII, puis retire tout résidu non-ASCII.
        $value = u($value)->ascii()->toString();
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
        // Coupe aux frontières camelCase, puis aux non-alphanumériques.
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $value) ?? $value;
        $parts = preg_split('/[^A-Za-z0-9]+/', $value) ?: [];

        return array_values(array_filter($parts, static fn (string $p) => $p !== ''));
    }

    /** Nom de collection : PascalCase, commence par une lettre. */
    public static function toPascalCase(string $value): string
    {
        $words = self::splitWords($value);
        $out = implode('', array_map(static fn (string $w) => ucfirst(strtolower($w)), $words));
        if ($out !== '' && !ctype_alpha($out[0])) {
            $out = 'C' . $out; // doit commencer par une lettre
        }

        return $out;
    }

    /** Nom de champ : camelCase. */
    public static function toCamelCase(string $value): string
    {
        $pascal = self::toPascalCase($value);

        return $pascal === '' ? '' : lcfirst($pascal);
    }

    /** Slug : snake_case, ASCII, ne commence jamais par un chiffre. */
    public static function toSnakeCase(string $value): string
    {
        $words = array_map('strtolower', self::splitWords($value));
        $out = implode('_', $words);
        if ($out !== '' && ctype_digit($out[0])) {
            $out = 'f_' . $out; // ne doit pas commencer par un chiffre
        }

        return $out;
    }
}
