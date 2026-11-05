<?php

namespace App\Service\Security;

/**
 * HtmlContentSanitizer — Durcissement XSS, assainissement HTML et JSON-LD pour champs WYSIWYG / Richtext.
 *
 * Règles :
 * - Élimine les balises à haut risque : script, iframe, object, embed, applet, meta, link, base, form, input, button, textarea, select, style.
 * - Préserve et sécurise les blocs JSON-LD (<script type="application/ld+json">...</script>) avec ré-encodage strict anti-breakout.
 * - Neutralise tous les gestionnaires d'événements inline (on* : onclick, onerror, onload, etc.).
 * - Neutralise les pseudo-protocoles dangereux (javascript:, vbscript:, data:text/html).
 * - Préserve les balises de mise en forme légitimes : p, a, b, strong, i, em, u, s, h1-h6, ul, ol, li, blockquote, code, pre, table, thead, tbody, tr, th, td, img, span, div, br, hr.
 * - Force rel="noopener noreferrer" sur les liens target="_blank".
 */
class HtmlContentSanitizer
{
    private const ALLOWED_TAGS = '<p><a><b><strong><i><em><u><s><h1><h2><h3><h4><h5><h6><ul><ol><li><blockquote><code><pre><table><thead><tbody><tfoot><tr><th><td><img><span><div><br><hr><figure><figcaption><del><ins><sub><sup>';

    private const SAFE_HREF_PROTOCOLS = ['http://', 'https://', 'mailto:', 'tel:', '/', '#', '?'];

    public function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // 1. Suppression des caractères nuls
        $cleaned = str_replace(chr(0), '', $html);

        // 2. Traitement sécurisé des blocs JSON-LD (<script type="application/ld+json">...</script>)
        $jsonLdPlaceholders = [];
        $cleaned = preg_replace_callback(
            '#<script\s+[^>]*type=[\'"]application/ld\+json[\'"][^>]*>(.*?)</script>#is',
            function (array $matches) use (&$jsonLdPlaceholders): string {
                $rawJson = trim($matches[1]);
                $decoded = json_decode($rawJson, true);
                if (!is_array($decoded) && !is_object($decoded)) {
                    // JSON invalide ou malveillant : neutraliser
                    return '';
                }
                // Ré-encodage strict anti-breakout (hex tags, hex apos, hex quot, hex amp)
                $safeJson = json_encode(
                    $decoded,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                );
                if ($safeJson === false) {
                    return '';
                }
                $placeholder = 'JAMBO_JSON_LD_TOKEN_' . count($jsonLdPlaceholders) . '_SECRET';
                $jsonLdPlaceholders[$placeholder] = '<script type="application/ld+json">' . $safeJson . '</script>';
                return $placeholder;
            },
            $cleaned
        );

        // 3. Suppression des autres balises <script> non JSON-LD résiduelles
        $cleaned = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $cleaned);

        // 4. Suppression des balises dangereuses conteneur
        $dangerousContainers = ['iframe', 'object', 'embed', 'applet', 'style', 'form', 'textarea', 'select'];
        foreach ($dangerousContainers as $tag) {
            $cleaned = preg_replace('#<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>#is', '', $cleaned);
        }

        // 5. Filtrage des balises autorisées via strip_tags
        $cleaned = strip_tags($cleaned, self::ALLOWED_TAGS);

        // 6. Suppression de tous les gestionnaires d'événements inline (on*)
        $cleaned = preg_replace_callback('#<([a-z0-9]+)\b([^>]*)>#i', function (array $tagMatches): string {
            $tagName = strtolower($tagMatches[1]);
            $attrs   = $tagMatches[2];

            // Retirer on*="..." ou on*=...
            $cleanAttrs = preg_replace('#\s*on[a-z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)#i', '', $attrs);

            // Neutraliser javascript:, vbscript:, data:text/html dans href
            $cleanAttrs = preg_replace_callback('#\bhref\s*=\s*(["\'])(.*?)\1#i', function (array $hrefMatches): string {
                $rawUrl = trim($hrefMatches[2]);
                $lowerUrl = strtolower(preg_replace('/\s+/', '', $rawUrl));
                if (str_starts_with($lowerUrl, 'javascript:') || str_starts_with($lowerUrl, 'vbscript:') || str_starts_with($lowerUrl, 'data:text/html')) {
                    return 'href="#"';
                }
                return 'href="' . htmlspecialchars($rawUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }, $cleanAttrs);

            // Neutraliser javascript:, vbscript: dans src
            $cleanAttrs = preg_replace_callback('#\bsrc\s*=\s*(["\'])(.*?)\1#i', function (array $srcMatches): string {
                $rawSrc = trim($srcMatches[2]);
                $lowerSrc = strtolower(preg_replace('/\s+/', '', $rawSrc));
                if (str_starts_with($lowerSrc, 'javascript:') || str_starts_with($lowerSrc, 'vbscript:')) {
                    return 'src=""';
                }
                // Si data URI, n'autoriser que les images sûres
                if (str_starts_with($lowerSrc, 'data:')) {
                    if (!preg_match('#^data:image/(?:png|jpe?g|gif|webp|svg\+xml);base64,#i', $rawSrc)) {
                        return 'src=""';
                    }
                }
                return 'src="' . htmlspecialchars($rawSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }, $cleanAttrs);

            // Sécuriser target="_blank"
            if (preg_match('#target\s*=\s*(["\'])_blank\1#i', $cleanAttrs)) {
                if (!preg_match('#rel\s*=\s*(["\'])[^"\']*noopener[^"\']*\1#i', $cleanAttrs)) {
                    $cleanAttrs .= ' rel="noopener noreferrer"';
                }
            }

            return '<' . $tagName . $cleanAttrs . '>';
        }, $cleaned);

        // 7. Restitution des blocs JSON-LD sécurisés
        if (!empty($jsonLdPlaceholders)) {
            $cleaned = str_replace(array_keys($jsonLdPlaceholders), array_values($jsonLdPlaceholders), $cleaned);
        }

        return $cleaned;
    }

    /**
     * Valide si une chaîne contient du JSON-LD syntaxiquement valide.
     */
    public function isValidJsonLd(string $content): bool
    {
        if (!preg_match_all('#<script\s+[^>]*type=[\'"]application/ld\+json[\'"][^>]*>(.*?)</script>#is', $content, $matches)) {
            return true; // Pas de JSON-LD
        }

        foreach ($matches[1] as $block) {
            $trimmed = trim($block);
            if ($trimmed === '') {
                return false;
            }
            try {
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }
}
