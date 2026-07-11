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
    <link rel="stylesheet" href="../assets/css/theme.css?v=19">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=5">
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
                <a class="add-btn" href="editor.php"><?php echo htmlspecialchars(t('workflow.list.add_btn')); ?></a>
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

        document.addEventListener('DOMContentLoaded', load);
        window.WF = { del };
    })();
    </script>
</body>
</html>
