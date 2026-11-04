<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Service de neutralisation d'injections de formules (CSV / Spreadsheet Formula Injection, CWE-1236)
 * et de formatage sécurisé pour exports tabulaires (CSV / Excel).
 *
 * Conforme aux recommandations OWASP CSV Injection :
 * - Toute cellule débutant par un caractère déclencheur de formule (=, +, -, @, \t, \r, %, |)
 *   est préfixée par une apostrophe simple (') pour forcer l'interprétation texte brut.
 * - Nettoyage des caractères de contrôle dangereux.
 * - Inclusion du Byte Order Mark UTF-8 (\xEF\xBB\xBF) pour garantir la restitution correcte
 *   des accents et caractères internationaux dans Microsoft Excel sous Windows et macOS.
 */
class CsvSanitizer
{
    /** Byte Order Mark pour UTF-8 */
    public const BOM_UTF8 = "\xEF\xBB\xBF";

    /**
     * Caractères déclencheurs de formule pouvant initier une exécution DDE,
     * une exfiltration de données via HYPERLINK, ou un recalcul malveillant.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r", '%', '|'];

    /**
     * Assainit une valeur unitaire pour une cellule CSV.
     */
    public function sanitizeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $this->sanitizeString($json !== false ? $json : '');
        }

        return $this->sanitizeString((string) $value);
    }

    /**
     * Assainit une ligne entière de valeurs CSV.
     *
     * @param array<int|string, mixed> $row
     * @return array<int, string>
     */
    public function sanitizeRow(array $row): array
    {
        $sanitized = [];
        foreach ($row as $col) {
            $sanitized[] = $this->sanitizeValue($col);
        }
        return $sanitized;
    }

    /**
     * Convertit une ligne de données en chaîne CSV valide avec assainissement.
     *
     * @param array<int|string, mixed> $row
     */
    public function toCsvLine(array $row, string $delimiter = ',', string $enclosure = '"', string $escape = "\\"): string
    {
        $sanitized = $this->sanitizeRow($row);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $sanitized, $delimiter, $enclosure, $escape);
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        return $line !== false ? $line : '';
    }

    /**
     * Neutralise les risques de formule et filtre les caractères de contrôle non désirés.
     */
    private function sanitizeString(string $input): string
    {
        // 1. Supprimer les caractères de contrôle nuls et dangereux (sauf LF et CRLF internes si souhaité)
        // Supprime les octets 0x00 à 0x08, 0x0B, 0x0C, 0x0E à 0x1F
        $filtered = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $input) ?? $input;

        // 2. Détecter si la chaîne commence (éventuellement précédée d'espaces) par un caractère déclencheur
        $trimmed = ltrim($filtered, " \t\r\n");
        if ($trimmed === '') {
            return $filtered;
        }

        $firstChar = $trimmed[0];
        if (in_array($firstChar, self::FORMULA_TRIGGERS, true)) {
            // OWASP : Préfixer par une apostrophe simple pour forcer l'affichage en texte brut
            return "'" . $filtered;
        }

        return $filtered;
    }
}
