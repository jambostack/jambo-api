<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Dto\SeoAuditReport;
use App\Entity\ContentEntry;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Exporte un rapport d'audit SEO au format PDF.
 */
class SeoPdfExporter
{
    public function __construct(
        private readonly SeoAnalyzer $analyzer,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null, ?string $locale = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages', $locale);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

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
        $locale = $entry->locale ?? $entry->project?->defaultLocale ?? 'en';

        $html = $this->buildHtml($entry, $score, $report, $locale);

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
     * Construit le HTML du rapport PDF avec support multilingue et RTL.
     */
    private function buildHtml(ContentEntry $entry, \App\Dto\SeoScore $score, \App\Dto\SeoAuditReport $report, string $locale): string
    {
        $untitled = $this->trans('realtime.untitled', [], 'Untitled', $locale);
        $title = htmlspecialchars((string) ($entry->metaTitle ?? $untitled), ENT_QUOTES, 'UTF-8');
        $scoreValue = $score->score;
        $scoreColor = $scoreValue >= 80 ? '#16a34a' : ($scoreValue >= 50 ? '#ca8a04' : '#dc2626');
        $scoreLabel = $scoreValue >= 80
            ? $this->trans('seo.score.good', [], 'Good', $locale)
            : ($scoreValue >= 50
                ? $this->trans('seo.score.average', [], 'Average', $locale)
                : $this->trans('seo.score.poor', [], 'Poor', $locale));

        $now = new \DateTimeImmutable();
        $dateFormatted = $this->trans('seo.report.generated_on', ['%date%' => $now->format('Y-m-d H:i')], 'Generated on ' . $now->format('Y-m-d H:i'), $locale);

        $isRtl = in_array(strtolower($locale), ['ar', 'fa', 'he', 'ur'], true);
        $dir = $isRtl ? 'rtl' : 'ltr';
        $align = $isRtl ? 'right' : 'left';

        $thCriteria = htmlspecialchars($this->trans('seo.report.criteria', [], 'Criteria', $locale), ENT_QUOTES, 'UTF-8');
        $thScore = htmlspecialchars($this->trans('seo.report.score', [], 'Score', $locale), ENT_QUOTES, 'UTF-8');
        $thAdvice = htmlspecialchars($this->trans('seo.report.advice', [], 'Recommendations', $locale), ENT_QUOTES, 'UTF-8');
        $hWarnings = htmlspecialchars($this->trans('seo.report.warnings', [], 'Warnings', $locale), ENT_QUOTES, 'UTF-8');
        $hSuggestions = htmlspecialchars($this->trans('seo.report.suggestions', [], 'Key Suggestions', $locale), ENT_QUOTES, 'UTF-8');
        $reportTitle = htmlspecialchars($this->trans('seo.report.title', [], 'SEO Audit Report', $locale), ENT_QUOTES, 'UTF-8');

        // Critères
        $criteriaRows = '';
        foreach ($score->criteria as $criterion) {
            $icon = $criterion['passed'] ? '✅' : '❌';
            $label = htmlspecialchars((string) $criterion['label'], ENT_QUOTES, 'UTF-8');
            $advice = $criterion['advice'] ? htmlspecialchars((string) $criterion['advice'], ENT_QUOTES, 'UTF-8') : '—';
            $criteriaRows .= <<<HTML
            <tr>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">{$icon}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:{$align};">{$label}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">{$criterion['score']}/{$criterion['maxScore']}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#6b7280;text-align:{$align};">{$advice}</td>
            </tr>
            HTML;
        }

        $warningsHtml = '';
        if ($report->warnings !== []) {
            $warningsHtml .= "<h2 style=\"font-size:16px;border-bottom:2px solid #ef4444;padding-bottom:4px;margin-top:28px;text-align:{$align};\">{$hWarnings}</h2>\n<ul style=\"padding-{$align}:20px;text-align:{$align};\">\n";
            foreach ($report->warnings as $warning) {
                $w = htmlspecialchars((string) $warning, ENT_QUOTES, 'UTF-8');
                $warningsHtml .= "<li style=\"margin-bottom:4px;color:#b91c1c;\">⚠️ {$w}</li>\n";
            }
            $warningsHtml .= "</ul>\n";
        }

        $suggestionsHtml = '';
        if ($score->suggestions !== []) {
            $suggestionsHtml .= "<h2 style=\"font-size:16px;border-bottom:2px solid #3b82f6;padding-bottom:4px;margin-top:28px;text-align:{$align};\">{$hSuggestions}</h2>\n<ul style=\"padding-{$align}:20px;text-align:{$align};\">\n";
            foreach ($score->suggestions as $suggestion) {
                $s = htmlspecialchars((string) $suggestion, ENT_QUOTES, 'UTF-8');
                $suggestionsHtml .= "<li style=\"margin-bottom:4px;\">💡 {$s}</li>\n";
            }
            $suggestionsHtml .= "</ul>\n";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="{$locale}" dir="{$dir}">
<head>
<meta charset="UTF-8">
<title>{$reportTitle} — {$title}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 13px; color: #1f2937; margin: 0; padding: 40px; direction: {$dir}; text-align: {$align}; }
    h1 { font-size: 22px; margin-bottom: 4px; }
    h2 { font-size: 16px; border-bottom: 2px solid #3b82f6; padding-bottom: 4px; margin-top: 28px; }
    .subtitle { color: #6b7280; font-size: 12px; margin-bottom: 20px; }
    .score-circle { display: inline-block; width: 80px; height: 80px; border-radius: 50%; background: {$scoreColor}; color: #fff; text-align: center; line-height: 80px; font-size: 28px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th { text-align: {$align}; padding: 8px; background: #f3f4f6; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
    ul { padding-{$align}: 20px; }
    .footer { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
    <h1>📊 {$reportTitle}</h1>
    <p class="subtitle">{$title} &bull; {$dateFormatted}</p>

    <div style="margin:16px 0;">
        <span class="score-circle">{$scoreValue}</span>
        <span style="margin-left:16px;margin-right:16px;font-size:16px;font-weight:600;color:{$scoreColor};">{$scoreLabel}</span>
    </div>

    <h2>{$thCriteria}</h2>
    <table>
        <thead>
            <tr>
                <th style="width:30px;"></th>
                <th style="text-align:{$align};">{$thCriteria}</th>
                <th style="width:80px;text-align:center;">{$thScore}</th>
                <th style="width:250px;text-align:{$align};">{$thAdvice}</th>
            </tr>
        </thead>
        <tbody>
            {$criteriaRows}
        </tbody>
    </table>

    {$suggestionsHtml}
    {$warningsHtml}

    <div class="footer">
        {$dateFormatted}
    </div>
</body>
</html>
HTML;
    }
}
