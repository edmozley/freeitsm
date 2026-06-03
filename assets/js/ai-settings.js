/**
 * Drives the reusable AI settings panel (includes/ai_settings_panel.php).
 *
 * Auto-initialises every [data-ai-panel] on the page: hydrates from
 * api/system/ai/get_settings.php, swaps the model suggestions when the
 * provider changes (curated list for Anthropic/OpenAI, live OpenRouter
 * catalogue with pricing for OpenRouter), and saves/tests via the shared
 * endpoints. No per-module code — the namespace + endpoints come from
 * data-attributes on the panel element.
 */
(function () {
    'use strict';

    // Tiny translation helper — falls back to a sensible English string if the
    // common.ai.* keys aren't on the page for some reason.
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

    function fmtPrice(perToken) {
        if (perToken === null || perToken === undefined || perToken === '') return null;
        var perM = parseFloat(perToken) * 1000000;
        if (isNaN(perM)) return null;
        // Trim to a tidy figure
        return '$' + (perM >= 1 ? perM.toFixed(2) : perM.toPrecision(2)) + '/M';
    }

    function initPanel(panel) {
        var ns       = panel.getAttribute('data-ns');
        var apiBase  = panel.getAttribute('data-api-base');
        var curated  = {};
        try { curated = JSON.parse(panel.getAttribute('data-models') || '{}'); } catch (e) { curated = {}; }

        var providerEl = panel.querySelector('[data-ai-provider]');
        var modelEl    = panel.querySelector('[data-ai-model]');
        var modelList  = panel.querySelector('[data-ai-model-list]');
        var modelHint  = panel.querySelector('[data-ai-model-hint]');
        var keyEl      = panel.querySelector('[data-ai-key]');
        var keyHint    = panel.querySelector('[data-ai-key-hint]');
        var sslEl      = panel.querySelector('[data-ai-verify-ssl]');
        var noteEl     = panel.querySelector('[data-ai-openrouter-note]');
        var saveBtn    = panel.querySelector('[data-ai-save]');
        var testBtn    = panel.querySelector('[data-ai-test]');
        var resultEl   = panel.querySelector('[data-ai-result]');

        var defaults = { anthropic: 'claude-haiku-4-5-20251001', openai: 'gpt-4o', openrouter: '' };
        var orModelsLoaded = false;

        function setResult(text, cls) {
            resultEl.textContent = text || '';
            resultEl.className = 'ai-result' + (cls ? ' ' + cls : '');
        }

        function setDatalist(options) {
            // options: [{value, label}]
            modelList.innerHTML = '';
            options.forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o.value;
                if (o.label) opt.label = o.label;
                modelList.appendChild(opt);
            });
        }

        function populateCurated(provider) {
            var list = (curated[provider] || []).map(function (id) { return { value: id }; });
            setDatalist(list);
            modelHint.textContent = '';
        }

        function loadOpenRouterModels() {
            modelHint.textContent = tr('loading_models', 'Loading model list…');
            fetch(apiBase + 'openrouter_models.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) { modelHint.textContent = d.error || 'Could not load models'; return; }
                    orModelsLoaded = true;
                    var opts = d.models.map(function (m) {
                        var price = fmtPrice(m.prompt_price);
                        var comp  = fmtPrice(m.completion_price);
                        var bits = [m.id];
                        if (price && comp) bits.push(price + ' in / ' + comp + ' out');
                        return { value: m.id, label: bits.join('  —  ') };
                    });
                    setDatalist(opts);
                    modelHint.textContent = tr('openrouter_pricing', 'Prices shown per 1M tokens (in / out).')
                        + (d.stale ? ' (' + tr('models_stale', 'cached') + ')' : '');
                })
                .catch(function () { modelHint.textContent = 'Could not load models'; });
        }

        function applyProviderUi(provider, opts) {
            opts = opts || {};
            noteEl.classList.toggle('show', provider === 'openrouter');
            if (provider === 'openrouter') {
                if (!orModelsLoaded) loadOpenRouterModels();
            } else {
                populateCurated(provider);
            }
            // When the user switches provider, reset the model to that provider's
            // default so an OpenRouter id never gets sent to Anthropic (and vice
            // versa). On the initial hydrate we keep the saved model.
            if (!opts.keepModel) {
                modelEl.value = defaults[provider] || '';
            }
        }

        // ---- hydrate from saved settings ----
        fetch(apiBase + 'get_settings.php?ns=' + encodeURIComponent(ns), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { setResult(d.error || '', 'err'); return; }
                providerEl.value = d.provider || 'anthropic';
                modelEl.value = d.model || '';
                sslEl.checked = !!d.verify_ssl;
                if (d.has_key) {
                    keyEl.placeholder = d.masked_key || '••••';
                    keyHint.textContent = tr('api_key_set', 'A key is saved. Leave blank to keep it.');
                }
                applyProviderUi(providerEl.value, { keepModel: true });
            })
            .catch(function () { /* leave defaults */ });

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
                verify_ssl: sslEl.checked,
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
                .catch(function (e) { setResult(tr('test_failed', 'Test failed'), 'err'); })
                .finally(function () { testBtn.disabled = false; });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-ai-panel]').forEach(initPanel);
    });
})();
