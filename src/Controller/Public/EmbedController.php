<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EmbedController extends AbstractController
{
    #[Route('/forms/embed.js', name: 'public_embed_widget', methods: ['GET'])]
    public function widget(): Response
    {
        $js = $this->buildWidgetJs();

        return new Response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function buildWidgetJs(): string
    {
        return <<<'JS'
/**
 * Jambo Forms — Widget embarquable
 * Version: 1.0.0
 *
 * Usage:
 *   <div data-jambo-form="https://example.com/PROJECT_UUID/forms/CONTACT"></div>
 *   <script src="https://example.com/forms/embed.js" async></script>
 *
 * Le widget détecte automatiquement tous les éléments [data-jambo-form]
 * et injecte un iframe contenant le formulaire.
 */

(function () {
    'use strict';

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
    ].join('\n');

    // ── Injection des styles ─────────────────────────────────────────────────
    var styleEl = document.createElement('style');
    styleEl.textContent = STYLES;
    document.head.appendChild(styleEl);

    // ── PostMessage listener (reçoit la hauteur de l'iframe) ─────────────────
    window.addEventListener('message', function (event) {
        if (!event.data || event.data.type !== 'jambo-form-height') return;
        var iframes = document.querySelectorAll('iframe[data-jambo-id="' + event.data.formSlug + '"]');
        for (var i = 0; i < iframes.length; i++) {
            iframes[i].style.height = event.data.height + 'px';
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

        container.classList.add('jambo-form-container');

        // Affichage loading
        container.innerHTML = '<div class="jambo-loading">Chargement du formulaire…</div>';

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
                container.innerHTML = '<div class="jambo-error">⚠️ ' + err.message + '</div>';
            });
    }

    function renderForm(container, formUrl, formData) {
        var fields = formData.fields || [];
        var submitUrl = formUrl + '/submit';

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

        html += '<button type="submit" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 24px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;">Envoyer</button>';
        html += '</form>';
        html += '<div class="jambo-success" style="display:none;">✅ Formulaire envoyé avec succès !</div>';

        container.innerHTML = html;

        // Gestion de la soumission
        var formEl = container.querySelector('.jambo-form');
        var successEl = container.querySelector('.jambo-success');
        if (!formEl) return;

        formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = formEl.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Envoi en cours…';

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
                    btn.textContent = 'Envoyer';
                    alert('Erreur : ' + err.message);
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
