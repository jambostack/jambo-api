<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Form;
use App\Repository\FormRepository;
use App\Repository\ProjectRepository;
use App\Service\Form\FormBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmbedController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const SLUG_REGEX = '/^[a-zA-Z0-9_\-]{1,128}$/';

    public function __construct(
        private readonly ProjectRepository $projectRepo,
        private readonly FormRepository $formRepo,
        private readonly FormBuilder $formBuilder,
        #[Autowire(service: 'limiter.form_view_limiter')]
        private readonly ?RateLimiterFactory $formViewLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    #[Route('/forms/embed.js', name: 'public_embed_widget', methods: ['GET'])]
    public function widget(Request $request): Response
    {
        $labels = [
            'loading' => $this->trans('form.embed.loading', [], 'Loading form…'),
            'submit' => $this->trans('form.embed.submit', [], 'Submit'),
            'submitting' => $this->trans('form.embed.submitting', [], 'Submitting…'),
            'success' => $this->trans('form.embed.success', [], '✅ Form submitted successfully!'),
            'errorPrefix' => $this->trans('form.embed.error_prefix', [], 'Error: '),
        ];

        $js = $this->buildWidgetJs($labels);

        return new Response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    #[Route('/embed/forms/{projectUuid}/{slug}', name: 'public_embed_form_iframe', methods: ['GET'])]
    #[Route('/forms/embed/{projectUuid}/{slug}', name: 'public_embed_form_iframe_alt', methods: ['GET'])]
    public function iframe(string $projectUuid, string $slug, Request $request): Response
    {
        if ($this->formViewLimiter !== null) {
            $limiter = $this->formViewLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return new Response($this->renderErrorHtml($this->trans('form.rate_limit_exceeded', [], 'Too many requests. Try again later.')), 429, [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'X-Content-Type-Options' => 'nosniff',
                    'Retry-After' => '60',
                ]);
            }
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid) || !preg_match(self::SLUG_REGEX, $slug)) {
            return new Response($this->renderErrorHtml($this->trans('form.not_found', [], 'Form not found.')), 404, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        try {
            $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            $project = null;
        }

        if (!$project) {
            return new Response($this->renderErrorHtml($this->trans('form.project_not_found', [], 'Project not found.')), 404, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $form = $this->formRepo->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$form) {
            return new Response($this->renderErrorHtml($this->trans('form.not_found', [], 'Form not found.')), 404, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $submitUrl = '/api/projects/' . rawurlencode($projectUuid) . '/forms/' . rawurlencode($slug) . '/submit';
        $html = $this->renderFormIframeHtml($form, $submitUrl);

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; frame-ancestors *; form-action 'self';",
        ]);
    }

    private function renderErrorHtml(string $errorMessage): string
    {
        $safeMsg = htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 24px; color: #1f2937; background: transparent; }
        .jambo-error-card { border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; padding: 16px; border-radius: 8px; text-align: center; font-size: 14px; }
    </style>
</head>
<body>
    <div class="jambo-error-card">⚠️ {$safeMsg}</div>
</body>
</html>
HTML;
    }

    private function renderFormIframeHtml(Form $form, string $submitUrl): string
    {
        $safeTitle = htmlspecialchars($form->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSlug = htmlspecialchars($form->slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubmitUrl = htmlspecialchars($submitUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $submitBtnLabel = htmlspecialchars($this->trans('form.embed.submit', [], 'Submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $submittingLabel = htmlspecialchars($this->trans('form.embed.submitting', [], 'Submitting…'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $successLabel = htmlspecialchars($this->trans('form.embed.success', [], '✅ Form submitted successfully!'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $errorPrefix = htmlspecialchars($this->trans('form.embed.error_prefix', [], 'Error: '), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $selectPlaceholder = htmlspecialchars($this->trans('form.embed.select_placeholder', [], '-- Select --'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $fieldsHtml = '';
        $fields = is_array($form->fields) ? $form->fields : [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldName = htmlspecialchars((string) ($field['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $fieldLabel = htmlspecialchars((string) ($field['label'] ?? $field['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $fieldPlaceholder = htmlspecialchars((string) ($field['placeholder'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $isRequired = !empty($field['required']);
            $reqAttr = $isRequired ? ' required' : '';
            $reqStar = $isRequired ? ' <span style="color:#dc2626;">*</span>' : '';
            $fieldType = (string) ($field['type'] ?? 'text');

            $fieldsHtml .= '<div class="form-group">';
            $fieldsHtml .= '<label for="' . $fieldName . '">' . $fieldLabel . $reqStar . '</label>';

            if ($fieldType === 'textarea') {
                $fieldsHtml .= '<textarea id="' . $fieldName . '" name="' . $fieldName . '" placeholder="' . $fieldPlaceholder . '"' . $reqAttr . '></textarea>';
            } elseif ($fieldType === 'select') {
                $fieldsHtml .= '<select id="' . $fieldName . '" name="' . $fieldName . '"' . $reqAttr . '>';
                $fieldsHtml .= '<option value="">' . $selectPlaceholder . '</option>';
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                foreach ($options as $opt) {
                    if (is_array($opt)) {
                        $val = htmlspecialchars((string) ($opt['value'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $lbl = htmlspecialchars((string) ($opt['label'] ?? $opt['value'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $fieldsHtml .= '<option value="' . $val . '">' . $lbl . '</option>';
                    }
                }
                $fieldsHtml .= '</select>';
            } elseif ($fieldType === 'checkbox') {
                $fieldsHtml .= '<input type="checkbox" id="' . $fieldName . '" name="' . $fieldName . '" value="1"' . $reqAttr . ' style="width:18px;height:18px;" />';
            } else {
                $inputMode = match ($fieldType) {
                    'email' => 'email',
                    'tel' => 'tel',
                    'number' => 'number',
                    'date' => 'date',
                    default => 'text',
                };
                $fieldsHtml .= '<input type="' . $inputMode . '" id="' . $fieldName . '" name="' . $fieldName . '" placeholder="' . $fieldPlaceholder . '"' . $reqAttr . ' />';
            }

            $fieldsHtml .= '</div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: transparent;
            color: #1f2937;
        }
        .jambo-container { max-width: 640px; margin: 0 auto; }
        .form-group { margin-bottom: 14px; }
        label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; }
        input[type="text"], input[type="email"], input[type="tel"], input[type="number"], input[type="date"], select, textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            color: #111827;
            transition: border-color .15s ease-in-out, box-shadow .15s ease-in-out;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }
        textarea { min-height: 90px; resize: vertical; }
        .submit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 24px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 600;
            font-size: 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color .15s ease;
        }
        .submit-btn:hover { background: #1d4ed8; }
        .submit-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .alert-error {
            padding: 12px;
            border-radius: 6px;
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            margin-bottom: 14px;
            display: none;
            font-size: 14px;
        }
        .alert-success {
            padding: 20px;
            border-radius: 8px;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            text-align: center;
            font-size: 15px;
            font-weight: 500;
            display: none;
        }
        .hp-field { position: absolute; left: -9999px; }
    </style>
</head>
<body>
    <div class="jambo-container">
        <div id="errorBanner" class="alert-error"></div>
        <div id="successBanner" class="alert-success">{$successLabel}</div>

        <form id="jamboForm" method="POST" action="{$safeSubmitUrl}">
            <div class="hp-field" aria-hidden="true">
                <input type="text" name="_website" tabindex="-1" autocomplete="off" />
            </div>

            {$fieldsHtml}

            <button type="submit" id="submitBtn" class="submit-btn">{$submitBtnLabel}</button>
        </form>
    </div>

    <script>
        (function() {
            var formSlug = '{$safeSlug}';
            var submitBtn = document.getElementById('submitBtn');
            var formEl = document.getElementById('jamboForm');
            var errorBanner = document.getElementById('errorBanner');
            var successBanner = document.getElementById('successBanner');

            function postHeight() {
                var h = document.body.scrollHeight;
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'jambo-form-height', formSlug: formSlug, height: h }, '*');
                }
            }

            window.addEventListener('load', postHeight);
            window.addEventListener('resize', postHeight);

            formEl.addEventListener('submit', function(e) {
                e.preventDefault();
                submitBtn.disabled = true;
                submitBtn.textContent = '{$submittingLabel}';
                errorBanner.style.display = 'none';

                var formData = {};
                var elements = formEl.elements;
                for (var i = 0; i < elements.length; i++) {
                    var el = elements[i];
                    if (!el.name) continue;
                    if (el.type === 'checkbox') {
                        formData[el.name] = el.checked;
                    } else {
                        formData[el.name] = el.value;
                    }
                }

                fetch(formEl.action, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(formData)
                })
                .then(function(res) {
                    return res.json().then(function(data) {
                        return { ok: res.ok, status: res.status, data: data };
                    });
                })
                .then(function(result) {
                    if (!result.ok) {
                        var msg = result.data.error || ('HTTP Error ' + result.status);
                        throw new Error(msg);
                    }
                    formEl.style.display = 'none';
                    successBanner.style.display = 'block';
                    postHeight();
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({
                            type: 'jambo-form-success',
                            formSlug: formSlug,
                            submissionId: result.data.submission_id
                        }, '*');
                    }
                })
                .catch(function(err) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = '{$submitBtnLabel}';
                    errorBanner.textContent = '{$errorPrefix}' + err.message;
                    errorBanner.style.display = 'block';
                    postHeight();
                });
            });
        })();
    </script>
</body>
</html>
HTML;
    }

    private function buildWidgetJs(array $labels): string
    {
        $i18nJson = json_encode($labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        return <<<JS
/**
 * Jambo Forms — Widget embarquable
 * Version: 1.1.0
 *
 * Usage:
 *   <div data-jambo-form="https://example.com/PROJECT_UUID/forms/CONTACT"></div>
 *   <script src="https://example.com/forms/embed.js" async></script>
 *
 * Le widget détecte automatiquement tous les éléments [data-jambo-form]
 * et injecte le formulaire ou l'iframe interactif.
 */

(function () {
    'use strict';

    var I18N = {$i18nJson};

    // ── Configuration ────────────────────────────────────────────────────────
    var JAMBO_BASE_URL = document.currentScript
        ? new URL(document.currentScript.src).origin
        : window.location.origin;

    // ── Styles injectés ──────────────────────────────────────────────────────
    var STYLES = [
        '.jambo-form-container {',
        '  max-width: 640px;',
        '  margin: 0 auto;',
        '  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;',
        '}',
        '.jambo-form-container iframe {',
        '  width: 100%;',
        '  border: 1px solid #e5e7eb;',
        '  border-radius: 8px;',
        '  min-height: 200px;',
        '}',
        '.jambo-form-container .jambo-loading {',
        '  text-align: center;',
        '  padding: 40px 20px;',
        '  color: #6b7280;',
        '  font-size: 14px;',
        '}',
        '.jambo-form-container .jambo-error {',
        '  text-align: center;',
        '  padding: 40px 20px;',
        '  color: #dc2626;',
        '  font-size: 14px;',
        '}',
        '.jambo-form-container .jambo-success {',
        '  text-align: center;',
        '  padding: 40px 20px;',
        '  color: #16a34a;',
        '  font-size: 14px;',
        '}',
    ].join('\\n');

    // ── Injection des styles ─────────────────────────────────────────────────
    var styleEl = document.createElement('style');
    styleEl.textContent = STYLES;
    document.head.appendChild(styleEl);

    // ── PostMessage listener (reçoit la hauteur de l\'iframe) ─────────────────
    window.addEventListener('message', function (event) {
        if (!event.data || event.data.type !== 'jambo-form-height') return;
        var slug = String(event.data.formSlug || '');
        if (!/^[a-zA-Z0-9_\-]+$/.test(slug)) return;
        var height = parseInt(event.data.height, 10);
        if (isNaN(height) || height < 0 || height > 10000) return;
        var iframes = document.querySelectorAll('iframe[data-jambo-id="' + slug + '"]');
        for (var i = 0; i < iframes.length; i++) {
            iframes[i].style.height = height + 'px';
        }
    });

    // ── Détection des conteneurs ─────────────────────────────────────────────
    function init() {
        var containers = document.querySelectorAll('[data-jambo-form]');
        for (var i = 0; i < containers.length; i++) {
            mountForm(containers[i]);
        }
    }

    function mountForm(container) {
        var formUrl = container.getAttribute('data-jambo-form');
        if (!formUrl) return;
        formUrl = formUrl.trim();
        if (!/^(https?:\\/\\/|\\/)/i.test(formUrl) || /^(javascript:|data:|vbscript:|\\/\\/)/i.test(formUrl)) return;

        var mode = container.getAttribute('data-jambo-mode');
        if (mode === 'iframe') {
            mountIframeMode(container, formUrl);
            return;
        }

        container.classList.add('jambo-form-container');

        // Affichage loading
        container.innerHTML = '<div class="jambo-loading">' + escapeHtml(I18N.loading) + '</div>';

        // Fetch des données du formulaire
        fetch(formUrl, { headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (!res.ok) throw new Error('Form not found (' + res.status + ')');
                return res.json();
            })
            .then(function (formData) {
                if (formData.error) throw new Error(formData.error);
                renderForm(container, formUrl, formData);
            })
            .catch(function (err) {
                container.innerHTML = '<div class="jambo-error">⚠️ ' + escapeHtml(err.message) + '</div>';
            });
    }

    function mountIframeMode(container, formUrl) {
        var cleanUrl = formUrl.replace(/\/+$/, '');
        var iframeSrc = cleanUrl + '/embed';
        var iframe = document.createElement('iframe');
        iframe.src = iframeSrc;
        iframe.style.width = '100%';
        iframe.style.border = 'none';
        iframe.style.overflow = 'hidden';
        iframe.setAttribute('loading', 'lazy');
        container.innerHTML = '';
        container.appendChild(iframe);
    }

    function renderForm(container, formUrl, formData) {
        var fields = formData.fields || [];
        var cleanFormUrl = formUrl.split('?')[0].replace(/\/+$/, '');
        var submitUrl = cleanFormUrl + '/submit';

        var html = '<form class="jambo-form" data-submit-url="' + escapeHtml(submitUrl) + '">';

        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            html += '<div style="margin-bottom:16px;">';
            html += '<label style="display:block;margin-bottom:4px;font-weight:500;font-size:14px;">' + escapeHtml(f.label || '') + (f.required ? ' <span style="color:#dc2626;">*</span>' : '') + '</label>';

            switch (f.type) {
                case 'textarea':
                    html += '<textarea name="' + escapeHtml(f.name || '') + '" placeholder="' + escapeHtml(f.placeholder || '') + '" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;min-height:100px;"' + (f.required ? ' required' : '') + '></textarea>';
                    break;
                case 'select':
                    html += '<select name="' + escapeHtml(f.name || '') + '" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"' + (f.required ? ' required' : '') + '>';
                    html += '<option value="">--</option>';
                    var opts = f.options || [];
                    for (var j = 0; j < opts.length; j++) {
                        html += '<option value="' + escapeHtml(opts[j].value || '') + '">' + escapeHtml(opts[j].label || '') + '</option>';
                    }
                    html += '</select>';
                    break;
                case 'checkbox':
                    html += '<input type="checkbox" name="' + escapeHtml(f.name || '') + '" style="width:18px;height:18px;"' + (f.required ? ' required' : '') + '/>';
                    break;
                default:
                    var inputType = f.type === 'email' ? 'email' : f.type === 'tel' ? 'tel' : f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text';
                    html += '<input type="' + inputType + '" name="' + escapeHtml(f.name || '') + '" placeholder="' + escapeHtml(f.placeholder || '') + '" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"' + (f.required ? ' required' : '') + '/>';
                    break;
            }

            html += '</div>';
        }

        // Honeypot caché
        html += '<div style="position:absolute;left:-9999px;" aria-hidden="true">';
        html += '<input type="text" name="_website" tabindex="-1" autocomplete="off" />';
        html += '</div>';

        html += '<button type="submit" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 24px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;">' + escapeHtml(I18N.submit) + '</button>';
        html += '</form>';
        html += '<div class="jambo-success" style="display:none;">' + escapeHtml(I18N.success) + '</div>';

        container.innerHTML = html;

        // Gestion de la soumission
        var formEl = container.querySelector('.jambo-form');
        var successEl = container.querySelector('.jambo-success');
        if (!formEl) return;

        formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = formEl.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = I18N.submitting;

            var formDataObj = {};
            var inputs = formEl.querySelectorAll('input, select, textarea');
            for (var k = 0; k < inputs.length; k++) {
                var input = inputs[k];
                if (!input.name) continue;
                if (input.type === 'checkbox') {
                    formDataObj[input.name] = input.checked;
                } else {
                    formDataObj[input.name] = input.value;
                }
            }

            fetch(submitUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(formDataObj),
            })
                .then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result.error) throw new Error(result.error);
                    formEl.style.display = 'none';
                    successEl.style.display = 'block';
                })
                .catch(function (err) {
                    btn.disabled = false;
                    btn.textContent = I18N.submit;
                    alert(I18N.errorPrefix + err.message);
                });
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ── Démarrage ────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
    }
}
