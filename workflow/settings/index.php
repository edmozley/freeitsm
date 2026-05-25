<?php
/**
 * Workflows — Settings page.
 *
 * Single tab for now (AI integration); the tabs scaffold is in place so
 * further per-module settings (defaults, retention policy, etc.) can be
 * dropped in without re-architecting the page.
 *
 * Visual chrome mirrors tickets/settings exactly (shared inbox.css
 * primitives + tab strip + form-group layout) so the page feels like the
 * rest of the product. AI key is encrypted at rest via the existing
 * `workflow_ai_api_key` entry in ENCRYPTED_SETTING_KEYS and returned to
 * the client as a "****<last4>" mask only.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'settings';
$path_prefix  = '../../';

$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.title') . ' — ' . t('workflow.nav.settings')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/workflow.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <script src="../../assets/js/toast.js"></script>
    <style>
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }
        /* Amber-tinted SSL warning callout, scoped to the workflow settings
           page so it doesn't leak into other modules' SSL-verify rows. */
        .wfs-ssl-warning {
            margin-top: 8px;
            padding: 10px 14px;
            background: #fef2f2;
            border-left: 3px solid #dc2626;
            color: #7f1d1d;
            border-radius: 4px;
            font-size: 12.5px;
            line-height: 1.55;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <!-- Tabs strip (one tab for now; ready to grow) -->
        <div class="tabs">
            <button class="tab active" data-tab="ai" onclick="WFS.switchTab('ai')"><?php echo htmlspecialchars(t('workflow.settings_tabs.ai')); ?></button>
        </div>

        <!-- AI tab -->
        <div class="tab-content active" id="ai-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('workflow.ai_settings.title')); ?></h2>
            </div>
            <p style="color:#666; font-size:13px; margin: 0 0 20px 0; max-width: 720px;">
                <?php echo htmlspecialchars(t('workflow.ai_settings.intro')); ?>
            </p>

            <div style="max-width: 640px;">
                <form id="wfsAiForm" autocomplete="off" onsubmit="event.preventDefault(); WFS.save();">
                    <div class="form-group">
                        <label for="wfsProvider"><?php echo htmlspecialchars(t('workflow.ai_settings.provider_label')); ?></label>
                        <select id="wfsProvider" onchange="WFS.onProviderChange()">
                            <option value="anthropic">Anthropic (Claude)</option>
                            <option value="openai">OpenAI (GPT)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="wfsModel"><?php echo htmlspecialchars(t('workflow.ai_settings.model_label')); ?></label>
                        <input type="text" id="wfsModel" list="wfsModelOptions" placeholder="e.g. claude-sonnet-4-6" autocomplete="off">
                        <datalist id="wfsModelOptions"></datalist>
                        <small style="display:block; color:#666; margin-top:4px;">
                            <?php echo htmlspecialchars(t('workflow.ai_settings.model_hint')); ?>
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="wfsApiKey"><?php echo htmlspecialchars(t('workflow.ai_settings.key_label')); ?></label>
                        <input type="text" id="wfsApiKey" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('workflow.ai_settings.key_placeholder_empty')); ?>">
                        <small style="display:block; color:#666; margin-top:4px;">
                            <?php echo htmlspecialchars(t('workflow.ai_settings.key_hint')); ?>
                            Anthropic: <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener" style="color:#f59e0b;">console.anthropic.com</a>.
                            OpenAI: <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:#f59e0b;">platform.openai.com</a>.
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="toggle-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; color: #333;">
                            <span class="toggle-switch">
                                <input type="checkbox" id="wfsVerifySsl" checked onchange="WFS.onVerifySslChange()">
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('workflow.ai_settings.verify_ssl_label')); ?>
                        </label>
                        <small style="display: block; color:#666; margin-top:4px;">
                            <?php echo htmlspecialchars(t('workflow.ai_settings.verify_ssl_hint')); ?>
                        </small>
                        <div id="wfsSslWarning" class="wfs-ssl-warning" style="display:none;">
                            <strong><?php echo htmlspecialchars(t('workflow.ai_settings.ssl_warning_title')); ?>:</strong>
                            <?php echo htmlspecialchars(t('workflow.ai_settings.ssl_warning_body')); ?>
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; margin-top: 22px;">
                        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                        <button type="button" class="btn" id="wfsTestBtn" onclick="WFS.testKey()" style="background:white; border:1px solid #ddd; color:#333;"><?php echo htmlspecialchars(t('workflow.ai_settings.test_btn')); ?></button>
                        <span id="wfsTestStatus" style="font-size:13px; margin-left:8px;"></span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Model suggestions per provider — mirrors the API_HELPERS catalogue.
        const WFS_MODEL_OPTIONS = {
            anthropic: [
                { id: 'claude-opus-4-7',           label: 'Opus 4.7 — most capable' },
                { id: 'claude-sonnet-4-6',         label: 'Sonnet 4.6 — recommended (best balance)' },
                { id: 'claude-haiku-4-5-20251001', label: 'Haiku 4.5 — fastest and cheapest' },
            ],
            openai: [
                { id: 'gpt-4.1',     label: 'GPT-4.1 — most capable' },
                { id: 'gpt-4o',      label: 'GPT-4o — recommended default' },
                { id: 'gpt-4o-mini', label: 'GPT-4o mini — fastest and cheapest' },
            ],
        };
        const WFS_DEFAULT_MODEL = {
            anthropic: 'claude-sonnet-4-6',
            openai:    'gpt-4o',
        };
    </script>
    <script>
    const WFS = (() => {
        const API = '../../api/workflow/';

        function escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        function setStatus(msg, kind) {
            const el = document.getElementById('wfsTestStatus');
            el.textContent = msg;
            el.style.color = kind === 'success' ? '#065f46'
                           : kind === 'error'   ? '#d13438'
                           : kind === 'busy'    ? '#b45309'
                           :                      '#555';
        }

        function switchTab(name) {
            document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === name + '-tab'));
        }

        function refreshModelOptions() {
            const provider = document.getElementById('wfsProvider').value;
            const list = document.getElementById('wfsModelOptions');
            const opts = WFS_MODEL_OPTIONS[provider] || [];
            list.innerHTML = opts.map(m => `<option value="${escHtml(m.id)}">${escHtml(m.label)}</option>`).join('');
        }

        function onProviderChange() {
            refreshModelOptions();
            // Reset to the provider's recommended default if the current model
            // doesn't make sense for the new provider.
            const provider = document.getElementById('wfsProvider').value;
            const modelEl  = document.getElementById('wfsModel');
            const known    = (WFS_MODEL_OPTIONS[provider] || []).map(m => m.id);
            if (!modelEl.value || !known.includes(modelEl.value)) {
                modelEl.value = WFS_DEFAULT_MODEL[provider];
            }
        }

        function onVerifySslChange() {
            const checked = document.getElementById('wfsVerifySsl').checked;
            document.getElementById('wfsSslWarning').style.display = checked ? 'none' : '';
        }

        async function load() {
            try {
                const r = await fetch(API + 'get_ai_settings.php', { credentials: 'same-origin' });
                const d = await r.json();
                if (!d.success) throw new Error(d.error || 'Load failed');
                const s = d.settings || {};
                document.getElementById('wfsProvider').value = s.workflow_ai_provider || 'anthropic';
                refreshModelOptions();
                document.getElementById('wfsModel').value =
                    s.workflow_ai_model || WFS_DEFAULT_MODEL[document.getElementById('wfsProvider').value];
                document.getElementById('wfsApiKey').value = s.workflow_ai_api_key || '';
                document.getElementById('wfsApiKey').placeholder = d.has_key
                    ? window.t('workflow.ai_settings.key_placeholder_stored')
                    : window.t('workflow.ai_settings.key_placeholder_empty');
                document.getElementById('wfsVerifySsl').checked = (s.workflow_ai_verify_ssl !== '0');
                onVerifySslChange();
            } catch (e) {
                setStatus('Could not load settings: ' + e.message, 'error');
            }
        }

        async function save() {
            const payload = {
                provider:   document.getElementById('wfsProvider').value,
                model:      document.getElementById('wfsModel').value.trim(),
                api_key:    document.getElementById('wfsApiKey').value,
                verify_ssl: document.getElementById('wfsVerifySsl').checked ? '1' : '0',
            };
            try {
                const r = await fetch(API + 'save_ai_settings.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const d = await r.json();
                if (!d.success) throw new Error(d.error || 'Save failed');
                window.showToast(window.t('workflow.toast.saved_settings'), 'success');
                setStatus('', '');
                await load();
            } catch (e) {
                window.showToast(e.message, 'error');
            }
        }

        async function testKey() {
            const btn = document.getElementById('wfsTestBtn');
            const payload = {
                provider:   document.getElementById('wfsProvider').value,
                model:      document.getElementById('wfsModel').value.trim(),
                api_key:    document.getElementById('wfsApiKey').value,
                verify_ssl: document.getElementById('wfsVerifySsl').checked ? '1' : '0',
            };
            if (!payload.model) { setStatus('Pick a model first', 'error'); return; }
            btn.disabled = true;
            setStatus(window.t('workflow.ai_settings.testing'), 'busy');
            try {
                const r = await fetch(API + 'test_ai_key.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const d = await r.json();
                if (!d.success) throw new Error(d.error || 'Failed');
                const tokens = (d.tokens_in != null && d.tokens_out != null)
                    ? ` — ${d.tokens_in} in / ${d.tokens_out} out tokens` : '';
                setStatus(`OK — ${d.provider} · ${d.model} · ${d.latency_ms}ms${tokens}`, 'success');
            } catch (e) {
                setStatus('Failed: ' + e.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        document.addEventListener('DOMContentLoaded', load);

        return { switchTab, onProviderChange, onVerifySslChange, save, testKey };
    })();
    </script>
</body>
</html>
