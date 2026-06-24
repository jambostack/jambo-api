<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Dto\SeoAuditReport;
use App\Entity\ContentEntry;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Exporte un rapport d'audit SEO au format PDF.
 */
class SeoPdfExporter
{
    public function __construct(
        private readonly SeoAnalyzer $analyzer,
    ) {}

    /**
     * Génère un PDF d'audit SEO pour une entrée.
     *
     * @param ContentEntry $entry
     * @param string|null $keyword Mot-clé cible optionnel
     * @return string Contenu binaire du PDF
     */
    public function exportAuditPdf(ContentEntry $entry, ?string $keyword = null): string
    {
        $report = $this->analyzer->audit($entry);
        $score = $this->analyzer->analyze($entry, $keyword);

        $html = $this->buildHtml($entry, $score, $report);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Construit le HTML du rapport PDF.
     */
    private function buildHtml(ContentEntry $entry, \App\Dto\SeoScore $score, \App\Dto\SeoAuditReport $report): string
    {
        $title = htmlspecialchars($entry->metaTitle ?? 'Sans titre', ENT_QUOTES, 'UTF-8');
        $scoreValue = $score->score;
        $scoreColor = $scoreValue >= 80 ? '#16a34a' : ($scoreValue >= 50 ? '#ca8a04' : '#dc2626');
        $scoreLabel = $scoreValue >= 80 ? 'Bon' : ($scoreValue >= 50 ? 'Moyen' : 'Faible');
        $date = (new \DateTimeImmutable())->format('d/m/Y à H:i');

        // Critères
        $criteriaRows = '';
        foreach ($score->criteria as $key => $criterion) {
            $icon = $criterion['passed'] ? '✅' : '❌';
            $label = htmlspecialchars($criterion['label'], ENT_QUOTES, 'UTF-8');
            $advice = $criterion['advice'] ? htmlspecialchars($criterion['advice'], ENT_QUOTES, 'UTF-8') : '—';
            $criteriaRows .= <<<HTML
            <tr>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">{$icon}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">{$label}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">{$criterion['score']}/{$criterion['maxScore']}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#6b7280;">{$advice}</td>
            </tr>
            HTML;
        }

        $warningsHtml = '';
        if ($report->warnings !== []) {
            foreach ($report->warnings as $warning) {
                $w = htmlspecialchars($warning, ENT_QUOTES, 'UTF-8');
                $warningsHtml .= "<li style=\"margin-bottom:4px;\">⚠️ {$w}</li>\n";
            }
        }

        $brokenLinksCount = count($report->brokenLinks);
        $suggestionsHtml = '';
        foreach ($score->suggestions as $suggestion) {
            $s = htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8');
            $suggestionsHtml .= "<li style=\"margin-bottom:4px;\">💡 {$s}</li>\n";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Audit SEO — {$title}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 13px; color: #1f2937; margin: 0; padding: 40px; }
    h1 { font-size: 22px; margin-bottom: 4px; }
    h2 { font-size: 16px; border-bottom: 2px solid #3b82f6; padding-bottom: 4px; margin-top: 28px; }
    .subtitle { color: #6b7280; font-size: 12px; margin-bottom: 20px; }
    .score-circle { display: inline-block; width: 80px; height: 80px; border-radius: 50%; background: {$scoreColor}; color: #fff; text-align: center; line-height: 80px; font-size: 28px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th { text-align: left; padding: 8px; background: #f3f4f6; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
    ul { padding-left: 20px; }
    .footer { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
    <h1>📊 Audit SEO</h1>
    <p class="subtitle">{$title} — Généré le {$date}</p>

    <div style="margin:16px 0;">
        <span class="score-circle">{$scoreValue}</span>
        <span style="margin-left:16px;font-size:16px;font-weight:600;color:{$scoreColor};">{$scoreLabel}</span>
    </div>

    <h2>Critères d'évaluation</h2>
    <table>
        <thead>
            <tr>
                <th style="width:30px;"></th>
                <th>Critère</th>
                <th style="width:80px;text-align:center;">Score</th>
                <th style="width:250px;">Conseil</th>
            </tr>
        </thead>
        <tbody>
            {$criteriaRows}
        </tbody>
    </table>

    <h2>Recommandations</h2>
    <ul>
        {$suggestionsHtml}
    </ul>

    <h2>Avertissements</h2>
    <ul>
        {$warningsHtml}
    </ul>

    <div style="margin-top:16px;font-size:12px;">
        <strong>Liens cassés détectés :</strong> {$brokenLinksCount}
    </div>

    <div class="footer">
        Rapport généré par JamboAPI SEO Analyzer — {$date}
    </div>
</body>
</html>
HTML;
    }
}
