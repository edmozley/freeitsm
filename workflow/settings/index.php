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
require_once '../../includes/theme.php';
require_once '../../includes/ai_settings_panel.php';   // defines renderAiSettingsPanel()
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('workflow');

$current_page = 'settings';
$path_prefix  = '../../';

$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.title') . ' — ' . t('workflow.nav.settings')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/workflow.css?v=11">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        /* Module accent — drives the tab hover/active colour and any future
           modal form-field focus rings / toggle on-state (--accent fallback
           in inbox.css). Workflow's accent is amber var(--wf-accent, #f59e0b). */
        body { --accent: var(--wf-accent, #f59e0b); }
        .tab:hover { color: var(--wf-accent, #f59e0b); }
        .tab.active { color: var(--wf-accent, #f59e0b); border-bottom-color: var(--wf-accent, #f59e0b); }

        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }
        /* Amber-tinted SSL warning callout, scoped to the workflow settings
           page so it doesn't leak into other modules' SSL-verify rows. */
        .wfs-ssl-warning {
            margin-top: 8px;
            padding: 10px 14px;
            background: var(--danger-bg, #fef2f2);
            border-left: 3px solid var(--danger-text, #dc2626);
            color: var(--danger-text, #7f1d1d);
            border-radius: 4px;
            font-size: 12.5px;
            line-height: 1.55;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="ai"><?php echo htmlspecialchars(t('workflow.settings_tabs.ai')); ?></button>
            <button class="tab" data-tab="formats"><?php echo htmlspecialchars(t('workflow.settings_tabs.formats')); ?></button>
        </div>

        <!-- AI tab -->
        <div class="tab-content active" id="ai-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('workflow.ai_settings.title')); ?></h2>
            </div>
            <p style="color:var(--text-muted, #666); font-size:13px; margin: 0 0 20px 0; max-width: 720px;">
                <?php echo htmlspecialchars(t('workflow.ai_settings.intro')); ?>
            </p>

            <div style="max-width: 640px;">
                <?php renderAiSettingsPanel('workflow_ai'); ?>
            </div>
        </div>

        <!-- Message formats tab -->
        <div class="tab-content" id="formats-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('workflow.formats.title')); ?></h2>
                <button class="add-btn" onclick="WFF.openNew()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="color:var(--text-muted, #666); font-size:13px; margin: 0 0 8px 0;">
                <?php echo t('workflow.formats.intro'); ?>
            </p>
            <p style="color:var(--text-muted, #666); font-size:13px; margin: 0 0 20px 0;">
                <?php echo t('workflow.formats.reserved_note'); ?>
            </p>

            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('workflow.formats.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.formats.col_key')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.formats.col_template')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.formats.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.list.col_row_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="fmtRows">
                    <tr><td colspan="5" style="text-align:center;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add / edit a message format -->
    <div class="modal" id="fmtModal">
        <div class="modal-content" style="max-width: 720px; width: 92%;">
            <div class="modal-header" id="fmtModalTitle"><?php echo htmlspecialchars(t('workflow.formats.add_title')); ?></div>
            <div class="modal-body">
                <input type="hidden" id="fmtId" value="0">
                <div class="form-group">
                    <label for="fmtLabel"><?php echo htmlspecialchars(t('workflow.formats.f_label')); ?></label>
                    <input type="text" id="fmtLabel" class="form-input" maxlength="100" placeholder="Google Chat">
                </div>
                <div class="form-group">
                    <label for="fmtKey"><?php echo htmlspecialchars(t('workflow.formats.f_key')); ?></label>
                    <input type="text" id="fmtKey" class="form-input" maxlength="40" placeholder="google-chat">
                    <small style="display:block;color:var(--text-muted,#666);margin-top:4px;font-size:11.5px;"><?php echo htmlspecialchars(t('workflow.formats.f_key_hint')); ?></small>
                </div>
                <div class="form-group">
                    <label for="fmtTemplate"><?php echo htmlspecialchars(t('workflow.formats.f_template')); ?></label>
                    <textarea id="fmtTemplate" class="form-textarea" rows="6" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace;font-size:12.5px;">{"text": "{{message}}"}</textarea>
                    <small style="display:block;color:var(--text-muted,#666);margin-top:4px;font-size:11.5px;line-height:1.5;"><?php echo t('workflow.formats.f_template_hint'); ?></small>
                </div>
                <div class="form-group">
                    <label for="fmtUrlPattern"><?php echo htmlspecialchars(t('workflow.formats.f_url_pattern')); ?></label>
                    <input type="text" id="fmtUrlPattern" class="form-input" maxlength="255" placeholder="chat\.googleapis\.com" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace;">
                    <small style="display:block;color:var(--text-muted,#666);margin-top:4px;font-size:11.5px;line-height:1.5;"><?php echo htmlspecialchars(t('workflow.formats.f_url_pattern_hint')); ?></small>
                </div>
                <div class="form-group">
                    <label for="fmtHint"><?php echo htmlspecialchars(t('workflow.formats.f_hint')); ?></label>
                    <input type="text" id="fmtHint" class="form-input" maxlength="255" placeholder="Google Chat: *bold*, _italic_.">
                    <small style="display:block;color:var(--text-muted,#666);margin-top:4px;font-size:11.5px;line-height:1.5;"><?php echo htmlspecialchars(t('workflow.formats.f_hint_hint')); ?></small>
                </div>
                <div id="fmtError" style="display:none;" class="wf-diagnosis"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="WFF.close()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" onclick="WFF.save()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/ai-settings.js"></script>
    <script>
    (() => {
        const API = '../../api/workflow/';

        // ---- Tabs (the strip existed with a single tab; nothing drove it) ----
        document.querySelectorAll('.tab[data-tab]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab + '-tab').classList.add('active');
                if (btn.dataset.tab === 'formats') load();
            });
        });

        function esc(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }
        function toast(msg, kind) {
            if (window.showToast) window.showToast(msg, kind || 'info');
            else alert(msg);
        }

        let loaded = false;

        async function load() {
            const tb = document.getElementById('fmtRows');
            try {
                const r = await fetch(API + 'formats.php', { credentials: 'same-origin' });
                const d = await r.json();
                if (!d.success) {
                    tb.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger-text,#c33);">' + esc(d.error) + '</td></tr>';
                    return;
                }
                loaded = true;
                tb.innerHTML = d.formats.map(f => `
                    <tr>
                        <td><strong>${esc(f.label)}</strong>${f.markdown_hint ? '<br><span style="color:var(--text-dim,#777);font-size:11.5px;">' + esc(f.markdown_hint) + '</span>' : ''}</td>
                        <td><code style="font-size:12px;">${esc(f.key)}</code></td>
                        <td><code style="font-size:11.5px;word-break:break-all;">${esc((f.body_template || '').slice(0, 70))}${(f.body_template || '').length > 70 ? '…' : ''}</code></td>
                        <td>${f.is_builtin
                            ? '<span class="status-badge">' + esc(window.t('workflow.formats.builtin')) + '</span>'
                            : (f.is_active ? '<span class="status-badge status-active">' + esc(window.t('workflow.list.active')) + '</span>'
                                           : '<span class="status-badge status-inactive">' + esc(window.t('workflow.list.inactive')) + '</span>')}</td>
                        <td>
                            <button class="table-action-btn" onclick='WFF.copy(${JSON.stringify(f)})' title="${esc(window.t('workflow.formats.copy'))}">${esc(window.t('workflow.formats.copy'))}</button>
                            ${f.is_builtin ? '' : `
                                <button class="table-action-btn" onclick='WFF.edit(${JSON.stringify(f)})'>${esc(window.t('common.edit'))}</button>
                                <button class="table-action-btn delete" onclick="WFF.del(${f.id})">${esc(window.t('common.delete'))}</button>`}
                        </td>
                    </tr>
                `).join('');
            } catch (e) {
                tb.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger-text,#c33);">Load failed</td></tr>';
            }
        }

        function fill(f) {
            document.getElementById('fmtId').value          = f.id || 0;
            document.getElementById('fmtLabel').value       = f.label || '';
            document.getElementById('fmtKey').value         = f.key || '';
            document.getElementById('fmtTemplate').value    = f.body_template || '{"text": "{{message}}"}';
            document.getElementById('fmtUrlPattern').value  = f.url_pattern || '';
            document.getElementById('fmtHint').value        = f.markdown_hint || '';
            document.getElementById('fmtError').style.display = 'none';
            document.getElementById('fmtModal').classList.add('active');
        }

        const WFF = {
            openNew: () => {
                document.getElementById('fmtModalTitle').textContent = window.t('workflow.formats.add_title');
                fill({});
            },
            edit: (f) => {
                document.getElementById('fmtModalTitle').textContent = window.t('workflow.formats.edit_title');
                fill(f);
            },
            // A built-in can't be edited in place (that would break every workflow
            // using it), so Copy gives you an editable clone to start from.
            copy: (f) => {
                document.getElementById('fmtModalTitle').textContent = window.t('workflow.formats.add_title');
                fill({ ...f, id: 0, key: f.key + '-custom', label: f.label + ' (custom)' });
            },
            close: () => document.getElementById('fmtModal').classList.remove('active'),
            save: async () => {
                const err = document.getElementById('fmtError');
                const body = {
                    id:            parseInt(document.getElementById('fmtId').value, 10) || 0,
                    key:           document.getElementById('fmtKey').value.trim(),
                    label:         document.getElementById('fmtLabel').value.trim(),
                    body_template: document.getElementById('fmtTemplate').value.trim(),
                    url_pattern:   document.getElementById('fmtUrlPattern').value.trim(),
                    markdown_hint: document.getElementById('fmtHint').value.trim(),
                    is_active:     1,
                };
                try {
                    const r = await fetch(API + 'save_format.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body),
                    });
                    const d = await r.json();
                    if (!d.success) {
                        err.textContent = d.error || 'Could not save.';
                        err.style.display = 'block';
                        return;
                    }
                    WFF.close();
                    toast(window.t('workflow.formats.saved'), 'success');
                    load();
                } catch (e) {
                    err.textContent = 'Could not save.';
                    err.style.display = 'block';
                }
            },
            del: async (id) => {
                if (window.showConfirm && !(await window.showConfirm({
                    title: 'Confirm', message: window.t('workflow.formats.delete_confirm'),
                    okLabel: 'OK', okClass: 'primary'
                }))) return;
                try {
                    const r = await fetch(API + 'delete_format.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id }),
                    });
                    const d = await r.json();
                    if (!d.success) { toast(d.error || 'Delete failed', 'error'); return; }
                    toast(window.t('workflow.formats.deleted'), 'success');
                    load();
                } catch (e) { toast('Delete failed', 'error'); }
            },
        };
        window.WFF = WFF;
    })();
    </script>
</body>
</html>
