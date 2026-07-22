/**
 * Drives the reusable AI settings panel (includes/ai_settings_panel.php).
 *
 * Auto-initialises every [data-ai-panel] on the page: hydrates from
 * api/system/ai/get_settings.php, swaps the model suggestions when the
 * provider changes (curated list for Anthropic/OpenAI, live OpenRouter
 * catalogue with pricing for OpenRouter), and saves/tests via the shared
 * endpoints. No per-module code — the namespace + endpoints come from
 * data-attributes on the panel element.
 *
 * The model picker is a self-contained dropdown (NOT a native <datalist>):
 * a long OpenRouter list rendered via <datalist> made the browser scroll the
 * overflow:hidden page body when focused, pushing the header off-screen. This
 * custom menu is absolutely positioned inside the panel with its own capped
 * scroll, so it can never move the page.
 */
(function () {
    'use strict';

    function tr(key, fallback) {
        try {
            if (window.t) {
                var v = window.t('common.ai.' + key);
                if (v && v !== 'common.ai.' + key) return v;
            }
        } catch (e) { /* ignore */ }
        return fallback;
    }

    function toast(msg, type) {
        if (typeof showToast === 'function') showToast(msg, type || 'info');
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function fmtPrice(perToken) {
        if (perToken === null || perToken === undefined || perToken === '') return null;
        var perM = parseFloat(perToken) * 1000000;
        if (isNaN(perM)) return null;
        return '$' + (perM >= 1 ? perM.toFixed(2) : perM.toPrecision(2));
    }

    function initPanel(panel) {
        var ns      = panel.getAttribute('data-ns');
        var apiBase = panel.getAttribute('data-api-base');
        var curated = {};
        try { curated = JSON.parse(panel.getAttribute('data-models') || '{}'); } catch (e) { curated = {}; }

        var providerEl = panel.querySelector('[data-ai-provider]');
        var modelEl    = panel.querySelector('[data-ai-model]');
        var menuEl     = panel.querySelector('[data-ai-model-menu]');
        var modelHint  = panel.querySelector('[data-ai-model-hint]');
        var keyEl      = panel.querySelector('[data-ai-key]');
        var keyHint    = panel.querySelector('[data-ai-key-hint]');
        var noteEl     = panel.querySelector('[data-ai-openrouter-note]');
        var saveBtn    = panel.querySelector('[data-ai-save]');
        var testBtn    = panel.querySelector('[data-ai-test]');
        var resultEl   = panel.querySelector('[data-ai-result]');

        var defaults = { anthropic: 'claude-haiku-4-5-20251001', openai: 'gpt-4o', openrouter: '' };
        var orModelsLoaded = false;
        var MODELS = [];   // [{ id, price? }]  current suggestion set
        var menuOpen = false;

        function setResult(text, cls) {
            resultEl.textContent = text || '';
            resultEl.className = 'ai-result' + (cls ? ' ' + cls : '');
        }

        // ---- custom model dropdown ----
        function renderMenu() {
            var q = (modelEl.value || '').trim().toLowerCase();
            var matches = MODELS.filter(function (m) {
                return !q || m.id.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 50); // cap so the menu stays short
            if (!matches.length) {
                menuEl.innerHTML = '<div class="ai-model-empty">' +
                    esc(tr('no_models', 'No matching models — you can type any model id')) + '</div>';
                return;
            }
            menuEl.innerHTML = matches.map(function (m) {
                var px = m.price ? '<span class="ai-model-px">' + esc(m.price) + '</span>' : '';
                return '<div class="ai-model-opt" data-id="' + esc(m.id) + '">' +
                       '<span class="ai-model-id">' + esc(m.id) + '</span>' + px + '</div>';
            }).join('');
        }
        function showMenu() { renderMenu(); menuEl.hidden = false; menuOpen = true; }
        function hideMenu() { menuEl.hidden = true; menuOpen = false; }

        modelEl.addEventListener('focus', showMenu);
        modelEl.addEventListener('input', function () { menuOpen ? renderMenu() : showMenu(); });
        modelEl.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
        modelEl.addEventListener('blur', function () { setTimeout(hideMenu, 150); });
        // mousedown (not click) so we win the race against the input's blur.
        menuEl.addEventListener('mousedown', function (e) {
            var opt = e.target.closest ? e.target.closest('.ai-model-opt') : null;
            if (!opt) return;
            e.preventDefault();
            modelEl.value = opt.getAttribute('data-id');
            hideMenu();
        });

        function setCuratedModels(provider) {
            MODELS = (curated[provider] || []).map(function (id) { return { id: id }; });
            modelHint.textContent = '';
        }

        function loadOpenRouterModels() {
            modelHint.textContent = tr('loading_models', 'Loading model list…');
            fetch(apiBase + 'openrouter_models.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) { modelHint.textContent = d.error || 'Could not load models'; return; }
                    orModelsLoaded = true;
                    MODELS = d.models.map(function (m) {
                        var inp = fmtPrice(m.prompt_price), out = fmtPrice(m.completion_price);
                        return { id: m.id, price: (inp && out) ? (inp + ' / ' + out) : '' };
                    });
                    modelHint.textContent = tr('openrouter_pricing', 'Prices shown per 1M tokens (in / out).')
                        + (d.stale ? ' (' + tr('models_stale', 'cached') + ')' : '');
                    if (menuOpen) renderMenu();
                })
                .catch(function () { modelHint.textContent = 'Could not load models'; });
        }

        function applyProviderUi(provider, opts) {
            opts = opts || {};
            noteEl.classList.toggle('show', provider === 'openrouter');
            if (provider === 'openrouter') {
                if (!orModelsLoaded) loadOpenRouterModels(); else if (menuOpen) renderMenu();
            } else {
                setCuratedModels(provider);
            }
            // On a provider switch, reset the model to that provider's default so
            // an OpenRouter id never gets sent to Anthropic (and vice versa). On
            // the initial hydrate we keep the saved model.
            if (!opts.keepModel) modelEl.value = defaults[provider] || '';
        }

        // ---- hydrate from saved settings ----
        fetch(apiBase + 'get_settings.php?ns=' + encodeURIComponent(ns), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { setResult(d.error || '', 'err'); return; }
                providerEl.value = d.provider || 'anthropic';
                modelEl.value = d.model || '';
                if (d.has_key) {
                    keyEl.placeholder = d.masked_key || '••••';
                    keyHint.textContent = tr('api_key_set', 'A key is saved. Leave blank to keep it.');
                }
                applyProviderUi(providerEl.value, { keepModel: true });
            })
            .catch(function () { applyProviderUi(providerEl.value || 'anthropic', { keepModel: true }); });

        providerEl.addEventListener('change', function () {
            applyProviderUi(providerEl.value, { keepModel: false });
        });

        // ---- save ----
        saveBtn.addEventListener('click', function () {
            saveBtn.disabled = true;
            setResult('', '');
            var payload = {
                ns: ns,
                provider: providerEl.value,
                model: modelEl.value.trim(),
                api_key: keyEl.value // blank → server keeps existing key
            };
            fetch(apiBase + 'save_settings.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        keyEl.value = '';
                        if (payload.api_key) {
                            keyEl.placeholder = '••••';
                            keyHint.textContent = tr('api_key_set', 'A key is saved. Leave blank to keep it.');
                        }
                        toast(tr('saved', 'Saved'), 'success');
                    } else {
                        toast(d.error || tr('save_failed', 'Failed to save'), 'error');
                    }
                })
                .catch(function () { toast(tr('save_failed', 'Failed to save'), 'error'); })
                .finally(function () { saveBtn.disabled = false; });
        });

        // ---- test ----
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            setResult(tr('testing', 'Testing…'), 'busy');
            fetch(apiBase + 'test_connection.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ns: ns })
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        setResult(tr('test_ok', 'Connection OK') + ' — ' + d.provider + ' · ' + d.model + ' · ' + d.duration_ms + 'ms', 'ok');
                    } else {
                        setResult((tr('test_failed', 'Test failed') + ': ') + (d.error || ''), 'err');
                    }
                })
                .catch(function () { setResult(tr('test_failed', 'Test failed'), 'err'); })
                .finally(function () { testBtn.disabled = false; });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-ai-panel]').forEach(initPanel);
    });
})();
