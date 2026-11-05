<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service de sécurité pour la composition de pages et blocs visuels dans Studio et PageController.
 *
 * Responsabilités :
 * - Contrôle des quotas de blocs visuels (collections, champs, blocs répéteurs, total de blocs).
 * - Contrôle strict de la profondeur de composition JSON (protection anti-DoS récursif, stack overflow).
 * - Neutralisation des clés de pollution de prototype (__proto__, constructor, prototype).
 * - Assainissement XSS/DOM des attributs (suppression des gestionnaires on*, neutralisation javascript:/vbscript:).
 * - Détection et blocage de l'injection de scripts personnalisés non autorisés dans les paramètres de collection/page.
 */
class PageCompositionSecurityService
{
    public const MAX_COLLECTIONS_PER_SCHEMA = 50;
    public const MAX_FIELDS_PER_COLLECTION = 100;
    public const MAX_SUBFIELDS_PER_BLOCK = 50;
    public const MAX_TOTAL_BLOCKS = 500;
    public const MAX_BATCH_ENTRIES = 100;
    public const MAX_JSON_DEPTH = 10;
    public const MAX_ATTR_STRING_LENGTH = 2048;

    private const FORBIDDEN_KEYS = [
        '__proto__',
        'constructor',
        'prototype',
    ];

    private const DANGEROUS_URI_PROTOCOLS = [
        'javascript:',
        'vbscript:',
        'data:text/html',
        'data:text/javascript',
        'data:application/javascript',
    ];

    public function __construct(
        private readonly ?HtmlContentSanitizer $htmlSanitizer = null,
        private readonly ?SsrfProtectionService $ssrfProtection = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    /**
     * Valide les quotas de blocs visuels dans un schéma soumis.
     *
     * @param array<int, mixed> $collections
     * @throws \InvalidArgumentException Si un quota est dépassé
     */
    public function validateBlockQuotas(array $collections): void
    {
        $collectionCount = count($collections);
        if ($collectionCount > self::MAX_COLLECTIONS_PER_SCHEMA) {
            throw new \InvalidArgumentException(
                $this->trans('studio.quota.max_collections_exceeded', ['%max%' => (string) self::MAX_COLLECTIONS_PER_SCHEMA], sprintf('Maximum number of collections exceeded (max %d).', self::MAX_COLLECTIONS_PER_SCHEMA))
            );
        }

        $totalBlocks = $collectionCount;

        foreach ($collections as $col) {
            if (!is_array($col)) {
                continue;
            }

            $colName = (string) ($col['name'] ?? $col['slug'] ?? 'collection');
            $fields = $col['fields'] ?? [];
            if (!is_array($fields)) {
                continue;
            }

            $fieldCount = count($fields);
            if ($fieldCount > self::MAX_FIELDS_PER_COLLECTION) {
                throw new \InvalidArgumentException(
                    $this->trans('studio.quota.max_fields_exceeded', [
                        '%collection%' => $colName,
                        '%max%' => (string) self::MAX_FIELDS_PER_COLLECTION,
                    ], sprintf('Maximum number of fields for collection "%s" exceeded (max %d).', $colName, self::MAX_FIELDS_PER_COLLECTION))
                );
            }

            $totalBlocks += $fieldCount;

            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fieldName = (string) ($field['name'] ?? $field['slug'] ?? 'field');
                $options = $field['options'] ?? [];
                if (is_array($options)) {
                    $subfields = $options['fields'] ?? $options['subfields'] ?? null;
                    if (is_array($subfields)) {
                        $subfieldCount = count($subfields);
                        if ($subfieldCount > self::MAX_SUBFIELDS_PER_BLOCK) {
                            throw new \InvalidArgumentException(
                                $this->trans('studio.quota.max_subfields_exceeded', [
                                    '%field%' => $fieldName,
                                    '%max%' => (string) self::MAX_SUBFIELDS_PER_BLOCK,
                                ], sprintf('Maximum number of subfields for block "%s" exceeded (max %d).', $fieldName, self::MAX_SUBFIELDS_PER_BLOCK))
                            );
                        }
                        $totalBlocks += $subfieldCount;
                    }
                }
            }
        }

        if ($totalBlocks > self::MAX_TOTAL_BLOCKS) {
            throw new \InvalidArgumentException(
                $this->trans('studio.quota.max_total_blocks_exceeded', ['%max%' => (string) self::MAX_TOTAL_BLOCKS], sprintf('Maximum number of total visual blocks exceeded (max %d).', self::MAX_TOTAL_BLOCKS))
            );
        }
    }

    /**
     * Valide le quota d'entrées créées ou modifiées en lot (batch).
     *
     * @param array<mixed> $entries
     * @throws \InvalidArgumentException Si le quota est dépassé
     */
    public function validateBatchEntriesQuota(array $entries): void
    {
        if (count($entries) > self::MAX_BATCH_ENTRIES) {
            throw new \InvalidArgumentException(
                $this->trans('studio.quota.max_batch_entries_exceeded', ['%max%' => (string) self::MAX_BATCH_ENTRIES], sprintf('Batch entry creation quota exceeded (max %d entries).', self::MAX_BATCH_ENTRIES))
            );
        }
    }

    /**
     * Valide récursivement la profondeur maximale d'un payload JSON / composition de page.
     *
     * @throws \InvalidArgumentException Si la profondeur dépasse la limite ou si une clé interdite est détectée
     */
    public function assertJsonDepth(mixed $data, int $maxDepth = self::MAX_JSON_DEPTH, string $path = '', int $currentDepth = 1): void
    {
        if ($currentDepth > $maxDepth) {
            throw new \InvalidArgumentException(
                $this->trans('studio.composition.max_depth_exceeded', [
                    '%max%' => (string) $maxDepth,
                    '%path%' => $path ?: 'root',
                ], sprintf('Page JSON composition depth exceeds maximum limit of %d levels at "%s".', $maxDepth, $path ?: 'root'))
            );
        }

        if (!is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            $lowerKey = strtolower($keyStr);

            if (in_array($lowerKey, self::FORBIDDEN_KEYS, true)) {
                throw new \InvalidArgumentException(
                    $this->trans('studio.composition.forbidden_key', ['%key%' => $keyStr], sprintf('Forbidden property "%s" detected in composition.', $keyStr))
                );
            }

            if (is_array($value)) {
                $subPath = $path !== '' ? "{$path}.{$keyStr}" : $keyStr;
                $this->assertJsonDepth($value, $maxDepth, $subPath, $currentDepth + 1);
            }
        }
    }

    /**
     * Assainit un tableau d'attributs HTML/DOM.
     * Supprime les attributs d'événements on* et neutralise les protocoles dangereux.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function sanitizeDomAttributes(array $attributes): array
    {
        $sanitized = [];

        foreach ($attributes as $key => $value) {
            $keyStr = trim((string) $key);
            $lowerKey = strtolower($keyStr);

            // Neutraliser les clés de prototype pollution
            if (in_array($lowerKey, self::FORBIDDEN_KEYS, true)) {
                continue;
            }

            // Supprimer tous les gestionnaires d'événements DOM inline (onclick, onload, onerror, etc.)
            if (str_starts_with($lowerKey, 'on')) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$keyStr] = $this->sanitizeDomAttributes($value);
                continue;
            }

            if (!is_string($value)) {
                $sanitized[$keyStr] = $value;
                continue;
            }

            $cleanVal = str_replace(chr(0), '', $value);

            // Tronquer si longueur excessive
            if (mb_strlen($cleanVal) > self::MAX_ATTR_STRING_LENGTH) {
                $cleanVal = mb_substr($cleanVal, 0, self::MAX_ATTR_STRING_LENGTH);
            }

            // Neutralisation des protocoles d'exécution dans les URLs (href, src, action, etc.)
            $normalizedVal = strtolower(preg_replace('/\s+/', '', $cleanVal));
            foreach (self::DANGEROUS_URI_PROTOCOLS as $protocol) {
                if (str_starts_with($normalizedVal, $protocol)) {
                    $cleanVal = '#';
                    break;
                }
            }

            // Neutralisation de styles CSS inline malveillants
            if ($lowerKey === 'style') {
                $lowerStyle = strtolower($cleanVal);
                if (
                    str_contains($lowerStyle, 'javascript:') ||
                    str_contains($lowerStyle, 'expression(') ||
                    str_contains($lowerStyle, 'behavior:') ||
                    str_contains($lowerStyle, '-moz-binding')
                ) {
                    $cleanVal = '';
                }
            }

            $sanitized[$keyStr] = $cleanVal;
        }

        return $sanitized;
    }

    /**
     * Assainit les paramètres de configuration d'une collection ou d'une page (settings).
     * Vérifie la profondeur, bloque les injections de scripts non autorisés et filtre les attributs DOM.
     *
     * @param array<string, mixed>|null $settings
     * @return array<string, mixed>|null
     */
    public function sanitizeCollectionSettings(?array $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        $this->assertJsonDepth($settings);

        // Vérification et assainissement des scripts personnalisés
        $this->validateAndSanitizeCustomScripts($settings);

        return $this->sanitizeRecursive($settings);
    }

    /**
     * Vérifie et assainit les blocs de scripts personnalisés dans les paramètres.
     *
     * @param array<string, mixed> $settings
     */
    private function validateAndSanitizeCustomScripts(array &$settings): void
    {
        $scriptKeys = [
            'scripts', 'custom_scripts', 'customScripts',
            'head_scripts', 'headScripts', 'body_scripts', 'bodyScripts',
            'custom_js', 'customJs', 'custom_code', 'customCode',
        ];

        foreach ($scriptKeys as $key) {
            if (!isset($settings[$key])) {
                continue;
            }

            $scriptData = $settings[$key];

            if (is_string($scriptData)) {
                // Si chaîne contenant des balises script
                if (preg_match('#<script\b[^>]*>(.*?)</script>#is', $scriptData, $m)) {
                    // Autoriser uniquement JSON-LD structuré
                    $isJsonLd = (bool) preg_match('#type=[\'"]application/ld\+json[\'"]#i', $m[0]);
                    if (!$isJsonLd) {
                        throw new \InvalidArgumentException(
                            $this->trans('studio.security.script_injection_detected', [], 'Custom script injection detected and forbidden in block/page settings.')
                        );
                    }
                }

                // Neutraliser les gestionnaires d'événements inline ou pseudo-protocoles
                $lower = strtolower($scriptData);
                if (
                    str_contains($lower, 'javascript:') ||
                    str_contains($lower, 'onerror=') ||
                    str_contains($lower, 'onload=') ||
                    str_contains($lower, 'onclick=')
                ) {
                    throw new \InvalidArgumentException(
                        $this->trans('studio.security.script_injection_detected', [], 'Custom script injection detected and forbidden in block/page settings.')
                    );
                }
            } elseif (is_array($scriptData)) {
                // Structure de scripts (ex: [{ src: "https://...", type: "external" }])
                foreach ($scriptData as $idx => $scriptItem) {
                    if (is_array($scriptItem)) {
                        $src = $scriptItem['src'] ?? $scriptItem['url'] ?? null;
                        if (is_string($src) && $src !== '') {
                            $lowerSrc = strtolower(trim($src));
                            if (str_starts_with($lowerSrc, 'javascript:') || str_starts_with($lowerSrc, 'data:')) {
                                throw new \InvalidArgumentException(
                                    $this->trans('studio.security.script_injection_detected', [], 'Custom script injection detected and forbidden in block/page settings.')
                                );
                            }

                            // Valider HTTPS et anti-SSRF si URL absolue
                            if (str_starts_with($lowerSrc, 'http://') || str_starts_with($lowerSrc, 'https://')) {
                                if (!str_starts_with($lowerSrc, 'https://')) {
                                    throw new \InvalidArgumentException(
                                        $this->trans('studio.security.script_injection_detected', [], 'External scripts must use HTTPS.')
                                    );
                                }
                                $this->ssrfProtection?->assertSafeUrl($src, true);
                            }
                        }

                        $inline = $scriptItem['code'] ?? $scriptItem['content'] ?? null;
                        if (is_string($inline) && $inline !== '') {
                            $type = strtolower((string) ($scriptItem['type'] ?? ''));
                            if ($type !== 'application/ld+json' && $type !== 'json-ld') {
                                throw new \InvalidArgumentException(
                                    $this->trans('studio.security.script_injection_detected', [], 'Raw inline scripts are not permitted in page composition.')
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Assainit les options de configuration d'un champ ou bloc visuel.
     *
     * @param array<string, mixed>|null $options
     * @return array<string, mixed>|null
     */
    public function sanitizeFieldOptions(?array $options): ?array
    {
        if ($options === null) {
            return null;
        }

        $this->assertJsonDepth($options);

        return $this->sanitizeRecursive($options);
    }

    /**
     * Assainit récursivement un tableau (champs, options, settings).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeRecursive(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            $lowerKey = strtolower($keyStr);

            if (in_array($lowerKey, self::FORBIDDEN_KEYS, true)) {
                continue;
            }

            // Gestionnaires on*
            if (str_starts_with($lowerKey, 'on')) {
                continue;
            }

            if (is_array($value)) {
                // Sous-arbre d'attributs HTML spécifique
                if (in_array($lowerKey, ['attributes', 'dom_attributes', 'props', 'html_attrs'], true)) {
                    $sanitized[$keyStr] = $this->sanitizeDomAttributes($value);
                } else {
                    $sanitized[$keyStr] = $this->sanitizeRecursive($value);
                }
                continue;
            }

            if (!is_string($value)) {
                $sanitized[$keyStr] = $value;
                continue;
            }

            $sanitized[$keyStr] = $this->sanitizeStringValue($keyStr, $value);
        }

        return $sanitized;
    }

    /**
     * Assainit une valeur de chaîne individuelle selon le contexte de clé.
     */
    private function sanitizeStringValue(string $key, string $value): string
    {
        $cleaned = str_replace(chr(0), '', $value);
        $lowerKey = strtolower($key);

        // Tronquer si excessive
        if (mb_strlen($cleaned) > self::MAX_ATTR_STRING_LENGTH) {
            $cleaned = mb_substr($cleaned, 0, self::MAX_ATTR_STRING_LENGTH);
        }

        // Si la clé suggère une URL
        if (in_array($lowerKey, ['href', 'src', 'url', 'action', 'target_url', 'link'], true)) {
            $normalized = strtolower(preg_replace('/\s+/', '', $cleaned));
            foreach (self::DANGEROUS_URI_PROTOCOLS as $p) {
                if (str_starts_with($normalized, $p)) {
                    return '#';
                }
            }
        }

        // Si la clé suggère du texte brut (label, name, title, placeholder, helpText, description)
        if (in_array($lowerKey, ['name', 'label', 'placeholder', 'helptext', 'help_text'], true)) {
            return strip_tags($cleaned);
        }

        // Si la clé suggère du rich text / description
        if ($this->htmlSanitizer !== null && in_array($lowerKey, ['description', 'richtext', 'body', 'content_html'], true)) {
            return $this->htmlSanitizer->sanitize($cleaned);
        }

        // Par défaut : neutraliser les balises script résiduelles
        return preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $cleaned) ?? $cleaned;
    }

    /**
     * Assainit un texte brut (nom de champ, nom de collection, username, etc.).
     */
    public function sanitizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $cleaned = str_replace(chr(0), '', $text);
        // Retirer les balises script, style et leur contenu avant strip_tags
        $cleaned = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $cleaned) ?? $cleaned;
        $cleaned = strip_tags($cleaned);
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }
}
