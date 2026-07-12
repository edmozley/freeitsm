<?php
/**
 * Workflows — landing / list page.
 *
 * Mirrors the tickets-settings chrome (shared inbox.css primitives: container,
 * tab-content card, section-header, add-btn, plain table, table-action-btn,
 * status-badge) so the two pages feel like one product.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('workflow');

$current_page = 'workflow';
$path_prefix = '../';

$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.list.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=11">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="tab-content active">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('workflow.list.page_title')); ?></h2>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <button class="add-btn" style="background: var(--surface, #fff); color: var(--text, #333); border: 1px solid var(--border, #d0d0d0);" onclick="WF.openTemplates()"><?php echo htmlspecialchars(t('workflow.templates.btn')); ?></button>
                    <a class="add-btn" href="editor.php"><?php echo htmlspecialchars(t('workflow.list.add_btn')); ?></a>
                </div>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('workflow.list.intro')); ?></p>

            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('workflow.list.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.list.col_trigger')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.list.col_actions')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.list.col_last_run')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.list.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.list.col_row_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="wfRows">
                    <tr><td colspan="6" style="text-align: center;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Starter templates gallery — clone a ready-made workflow. -->
    <div class="modal" id="wfTplModal">
        <div class="modal-content" style="max-width: 900px; width: 92%;">
            <div class="modal-header"><?php echo htmlspecialchars(t('workflow.templates.title')); ?></div>
            <div class="modal-body">
                <p style="margin: 0 0 16px; color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('workflow.templates.intro')); ?></p>

                <!-- Filters. The gallery is past the point where scanning it is
                     pleasant, so let people narrow by module or just type. -->
                <div class="wf-tpl-filters">
                    <div>
                        <label for="wfTplCat"><?php echo htmlspecialchars(t('workflow.templates.filter_cat')); ?></label>
                        <select id="wfTplCat" class="form-input" onchange="WF.filterTemplates()">
                            <option value=""><?php echo htmlspecialchars(t('workflow.templates.all_cats')); ?></option>
                        </select>
                    </div>
                    <div style="flex: 1 1 220px;">
                        <label for="wfTplSearch"><?php echo htmlspecialchars(t('workflow.templates.f_search')); ?></label>
                        <input type="text" id="wfTplSearch" class="form-input" oninput="WF.filterTemplates()" placeholder="<?php echo htmlspecialchars(t('workflow.templates.search_ph')); ?>">
                    </div>
                    <div class="wf-tpl-count" id="wfTplCount"></div>
                </div>

                <div id="wfTplList" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px;">
                    <div style="color: var(--text-faint, #999);"><?php echo htmlspecialchars(t('common.loading')); ?></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="WF.closeTemplates()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
            </div>
        </div>
    </div>

    <script>
    (() => {
        const API = '../api/workflow/';

        function esc(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        function fmtDate(s) {
            if (!s) return '<span style="color:var(--text-faint, #999);">' + esc(window.t('workflow.list.never_run')) + '</span>';
            // last_run_datetime is a server-stamped UTC instant → show in the analyst's zone.
            try { return esc(window.parseUTCDate(s).toLocaleString(undefined, window.tzOpts({}))); }
            catch (e) { return esc(s); }
        }

        function statusPill(active) {
            return active
                ? '<span class="status-badge status-active">' + esc(window.t('workflow.list.active')) + '</span>'
                : '<span class="status-badge status-inactive">' + esc(window.t('workflow.list.inactive')) + '</span>';
        }

        const ICON_EDIT   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

        async function load() {
            const tb = document.getElementById('wfRows');
            try {
                const r = await fetch(API + 'list.php', { credentials: 'same-origin' });
                const d = await r.json();
                if (!d.success) {
                    tb.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--danger-text, #c33);">' + esc(d.error || 'Load failed') + '</td></tr>';
                    return;
                }
                if (!d.workflows || !d.workflows.length) {
                    tb.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-faint, #999);">' + esc(window.t('workflow.list.no_workflows')) + '</td></tr>';
                    return;
                }
                tb.innerHTML = d.workflows.map(w => `
                    <tr>
                        <td><strong><a href="editor.php?id=${w.id}" style="color: var(--text, #333); text-decoration: none;">${esc(w.name)}</a></strong>${w.description ? '<br><span style="color:var(--text-dim, #777); font-size: 12px;">' + esc(w.description) + '</span>' : ''}</td>
                        <td><code style="font-size:12px;">${esc(w.trigger_event)}</code></td>
                        <td style="color:var(--text-muted, #666);">${w.run_count} run${w.run_count == 1 ? '' : 's'}</td>
                        <td>${fmtDate(w.last_run_datetime)}</td>
                        <td>${statusPill(+w.is_active)}</td>
                        <td>
                            <a class="table-action-btn" href="editor.php?id=${w.id}" title="${esc(window.t('common.edit'))}">${ICON_EDIT}</a>
                            <button class="table-action-btn delete" onclick="WF.del(${w.id})" title="${esc(window.t('common.delete'))}">${ICON_DELETE}</button>
                        </td>
                    </tr>
                `).join('');
            } catch (e) {
                tb.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--danger-text, #c33);">' + esc(e.message || 'Network error') + '</td></tr>';
            }
        }

        async function del(id) {
            if (!(await showConfirm({ title: 'Confirm', message: window.t('workflow.toast.delete_confirm'), okLabel: 'OK', okClass: 'primary' }))) return;
            try {
                const r = await fetch(API + 'delete.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const d = await r.json();
                if (d.success) { window.showToast(window.t('workflow.toast.deleted'), 'success'); load(); }
                else window.showToast(d.error || 'Delete failed', 'error');
            } catch (e) { window.showToast('Delete failed', 'error'); }
        }

        // ---- Starter templates ------------------------------------------

        let templatesLoaded = false;

        // inbox.css keeps .modal permanently display:flex and reveals it with
        // the .active class (opacity + visibility) — setting display here would
        // do nothing.
        function openTemplates() {
            document.getElementById('wfTplModal').classList.add('active');
            if (!templatesLoaded) loadTemplates();
        }

        function closeTemplates() {
            document.getElementById('wfTplModal').classList.remove('active');
        }

        // The whole catalogue, held once. Filtering is client-side: it's a few
        // dozen recipes, so a round-trip per keystroke would buy nothing.
        let allTemplates = [];

        async function loadTemplates() {
            const host = document.getElementById('wfTplList');
            try {
                const r = await fetch(API + 'templates.php', { credentials: 'same-origin' });
                const d = await r.json();
                if (!d.success) {
                    host.innerHTML = '<div style="color: var(--danger-text, #c33);">' + esc(d.error || 'Load failed') + '</div>';
                    return;
                }
                templatesLoaded = true;
                allTemplates = d.templates || [];

                // Categories come from the registry, not a hardcoded list — a new
                // recipe in a new category appears in the dropdown on its own.
                const sel  = document.getElementById('wfTplCat');
                const cats = [...new Set(allTemplates.map(t => t.category))].sort((a, b) => a.localeCompare(b));
                sel.insertAdjacentHTML('beforeend', cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join(''));

                renderTemplates(allTemplates);
            } catch (e) {
                host.innerHTML = '<div style="color: var(--danger-text, #c33);">' + esc(e.message || 'Network error') + '</div>';
            }
        }

        // Search covers everything a person might reach for: the name, the
        // description, the category, the trigger and the action labels — so
        // "expiry", "slack" and "warranty" all find something.
        function templateHaystack(t) {
            return [t.name, t.description, t.category, t.trigger_label, t.trigger_event, (t.steps || []).join(' ')]
                .join(' ').toLowerCase();
        }

        function filterTemplates() {
            const cat = document.getElementById('wfTplCat').value;
            const q   = document.getElementById('wfTplSearch').value.trim().toLowerCase();
            renderTemplates(allTemplates.filter(t =>
                (!cat || t.category === cat) && (!q || templateHaystack(t).includes(q))
            ));
        }

        function renderTemplates(list) {
            const host  = document.getElementById('wfTplList');
            const count = document.getElementById('wfTplCount');
            count.textContent = window.t('workflow.templates.showing', { n: list.length, total: allTemplates.length });

            if (!list.length) {
                host.innerHTML = '<div style="grid-column: 1 / -1; color: var(--text-faint, #999);">'
                    + esc(window.t('workflow.templates.no_match')) + '</div>';
                return;
            }
            host.innerHTML = list.map(t => `
                <div class="wf-tpl-card">
                    <div class="wf-tpl-cat">${esc(t.category)}</div>
                    <div class="wf-tpl-name">${esc(t.name)}</div>
                    <div class="wf-tpl-desc">${esc(t.description)}</div>
                    <div class="wf-tpl-meta">
                        <div><strong>${esc(window.t('workflow.templates.when'))}</strong> ${esc(t.trigger_label)}</div>
                        <div><strong>${esc(window.t('workflow.templates.then'))}</strong> ${t.steps.map(esc).join(', ')}</div>
                    </div>
                    <button class="btn btn-primary" style="width: 100%; margin-top: 10px;" onclick="WF.useTemplate('${esc(t.key)}', this)">${esc(window.t('workflow.templates.use'))}</button>
                </div>
            `).join('');
        }

        async function useTemplate(key, btn) {
            btn.disabled = true;
            try {
                const r = await fetch(API + 'create_from_template.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key })
                });
                const d = await r.json();
                if (!d.success) {
                    window.showToast(d.error || 'Could not create from template', 'error');
                    btn.disabled = false;
                    return;
                }
                // Hand the "you still need to fill these in" list to the editor.
                // It's a one-hop message, not state worth a database column.
                if (d.unresolved && d.unresolved.length) {
                    try { sessionStorage.setItem('wfUnresolved:' + d.id, JSON.stringify(d.unresolved)); } catch (e) {}
                }
                window.location.href = 'editor.php?id=' + d.id + '&from_template=1';
            } catch (e) {
                window.showToast('Could not create from template', 'error');
                btn.disabled = false;
            }
        }

        document.addEventListener('DOMContentLoaded', load);
        window.WF = { del, openTemplates, closeTemplates, useTemplate, filterTemplates };
    })();
    </script>
</body>
</html>
