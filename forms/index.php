<?php
/**
 * Forms Module - Forms list (dashboard)
 *
 * Full-width table view. Editing happens on /forms/edit/ (#437) which
 * is also where AI Assist + versioning + the field builder all live.
 * This page is purely the list / dashboard now — click a row to edit,
 * or use the row icons for Fill / Submissions / Delete.
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'forms';
$path_prefix = '../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.list.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=2"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=1"></script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=16">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/forms.css?v=<?= time() ?>">
    <style>
        /* Module accent (teal). */
        body { --accent: var(--forms-accent, #00897b); --accent-hover: var(--forms-accent-hover, #00695c); }

        /* Full-width forms dashboard — matches the canonical layout
           used by other modules' settings + reporting pages. */
        .forms-list-container {
            height: calc(100vh - 48px);
            display: flex;
            flex-direction: column;
            padding: 16px 30px 0;
            background: var(--app-bg, #f5f5f5);
        }
        .forms-list-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
            flex-shrink: 0;
        }
        .forms-list-toolbar h1 {
            margin: 0;
            font-size: 22px;
            color: var(--text, #333);
        }
        .forms-list-toolbar .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .forms-list-search {
            padding: 8px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 13px;
            min-width: 260px;
            background: var(--surface, #fff);
            color: var(--text, #333);
        }
        .forms-list-search:focus { outline: none; border-color: var(--forms-accent, #00897b); }
        .new-form-btn {
            background: var(--forms-accent, #00897b);
            color: white;
            padding: 9px 16px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .new-form-btn:hover { background: var(--forms-accent-hover, #00695c); color: white; }

        /* The table card itself — scrolls internally so the toolbar
           stays pinned at the top of the page. */
        .forms-table-card {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            background: var(--surface, #fff);
            border-radius: 8px;
            box-shadow: 0 2px 4px var(--shadow, rgba(0,0,0,0.06));
            margin-bottom: 24px;
        }
        .forms-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .forms-table thead th {
            position: sticky;
            top: 0;
            background: var(--surface-2, #fafafa);
            text-align: left;
            font-weight: 600;
            color: var(--text-muted, #666);
            padding: 12px 14px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }
        .forms-table thead th:hover { background: var(--surface-hover, #f0f0f0); }
        .forms-table thead th .sort-arrow {
            display: inline-block;
            margin-left: 4px;
            opacity: 0.4;
            font-size: 10px;
        }
        .forms-table thead th.sort-asc .sort-arrow,
        .forms-table thead th.sort-desc .sort-arrow { opacity: 1; }
        .forms-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            vertical-align: middle;
        }
        .forms-table tbody tr {
            cursor: pointer;
            transition: background 0.1s;
        }
        .forms-table tbody tr:hover { background: #f5fbfa; }
        /* Keep the pale light-mode hover as-is; give dark a glow-safe teal tint. */
        [data-theme-mode="dark"] .forms-table tbody tr:hover { background: var(--forms-accent-soft); }

        .forms-table td.col-title strong {
            display: block;
            color: var(--text, #111);
            font-size: 14px;
        }
        .forms-table td.col-title small {
            display: block;
            color: var(--text-dim, #888);
            font-size: 12px;
            margin-top: 2px;
            max-width: 480px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ft-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .ft-pill.active    { background: var(--forms-accent-soft, #e0f2f1); color: var(--forms-accent-hover, #00695c); }
        .ft-pill.inactive  { background: var(--surface-2, #fafafa); color: var(--text-faint, #999); }
        .ft-pill.version   { background: var(--forms-accent, #00897b); color: white; font-size: 11px; }

        /* Icon-only row actions — matches the canonical settings table
           pattern from #401 / #403 / etc. */
        .forms-table td.col-actions {
            white-space: nowrap;
            width: 1%;
            text-align: right;
        }
        .ft-action-btn {
            background: none;
            border: none;
            padding: 4px;
            margin-left: 2px;
            color: var(--text-muted, #666);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            vertical-align: middle;
            cursor: pointer;
            text-decoration: none;
            border-radius: 4px;
        }
        .ft-action-btn:hover { background: var(--surface-hover, #f0f0f0); color: var(--forms-accent, #00897b); }
        .ft-action-btn.danger:hover { color: var(--danger-text, #c62828); }
        .ft-action-btn svg { width: 16px; height: 16px; }

        .forms-empty {
            padding: 60px 30px;
            text-align: center;
            color: var(--text-dim, #888);
        }
        .forms-empty svg { color: var(--border, #ccc); margin-bottom: 14px; }
        .forms-empty h3 {
            color: var(--text-muted, #555);
            font-weight: 600;
            margin: 0 0 6px;
            font-size: 16px;
        }
        .forms-empty p {
            margin: 0 0 16px;
            font-size: 13px;
        }

        /* Delete confirmation overlay */
        .confirm-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.4);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .confirm-overlay.open { display: flex; }
        .confirm-box {
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 24px 26px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
        }
        .confirm-box h3 { margin: 0 0 8px; font-size: 17px; color: var(--text, #333); }
        .confirm-box p { margin: 0 0 18px; color: var(--text-muted, #666); font-size: 14px; line-height: 1.5; }
        .confirm-actions { display: flex; justify-content: flex-end; gap: 10px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="forms-list-container">
        <div class="forms-list-toolbar">
            <h1><?php echo htmlspecialchars(t('forms.list.title')); ?></h1>
            <div class="toolbar-actions">
                <input type="text" id="formSearch" class="forms-list-search" placeholder="<?php echo htmlspecialchars(t('forms.list.search_placeholder')); ?>" oninput="filterForms()">
                <a href="<?php echo BASE_URL; ?>forms/edit/" class="new-form-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <?php echo htmlspecialchars(t('forms.list.new_form')); ?>
                </a>
            </div>
        </div>

        <div class="forms-table-card">
            <table class="forms-table" id="formsTable">
                <thead>
                    <tr>
                        <th data-sort="title"><?php echo htmlspecialchars(t('forms.list.col_title')); ?> <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-sort="version" style="width: 80px;"><?php echo htmlspecialchars(t('forms.list.col_version')); ?> <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-sort="status" style="width: 100px;"><?php echo htmlspecialchars(t('forms.list.col_status')); ?> <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-sort="fields" style="width: 80px; text-align: right;"><?php echo htmlspecialchars(t('forms.list.col_fields')); ?> <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-sort="submissions" style="width: 110px; text-align: right;"><?php echo htmlspecialchars(t('forms.list.col_submissions')); ?> <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-sort="modified" style="width: 200px;"><?php echo htmlspecialchars(t('forms.list.col_modified')); ?> <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th><?php echo htmlspecialchars(t('forms.list.col_modified_by')); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="formsTableBody">
                    <tr><td colspan="8" class="forms-empty"><?php echo htmlspecialchars(t('forms.list.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>


    <script>
        const API_BASE = '<?php echo BASE_URL; ?>api/forms/';
        const EDIT_BASE = '<?php echo BASE_URL; ?>forms/edit/';

        let allForms = [];
        let filteredForms = [];
        let sortKey = 'modified';
        let sortDir = 'desc';   // start with newest-modified at the top

        // SVG icons used in the action column
        const ICON_FILL    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const ICON_SUBS    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        const ICON_DELETE  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

        document.addEventListener('DOMContentLoaded', function() {
            loadForms();

            // Wire column sorting
            document.querySelectorAll('.forms-table thead th[data-sort]').forEach(th => {
                th.addEventListener('click', () => {
                    const key = th.dataset.sort;
                    if (sortKey === key) {
                        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortKey = key;
                        sortDir = 'asc';
                    }
                    render();
                });
            });
        });

        async function loadForms() {
            try {
                const res = await fetch(API_BASE + 'get_forms.php');
                const data = await res.json();
                if (data.success) {
                    allForms = data.forms || [];
                    filteredForms = allForms.slice();
                    render();
                } else {
                    document.getElementById('formsTableBody').innerHTML =
                        '<tr><td colspan="8" class="forms-empty">' + esc(window.t('forms.list.error_loading')) + '</td></tr>';
                }
            } catch (e) {
                document.getElementById('formsTableBody').innerHTML =
                    '<tr><td colspan="8" class="forms-empty">' + esc(window.t('forms.list.error_loading')) + '</td></tr>';
            }
        }

        function filterForms() {
            const q = document.getElementById('formSearch').value.trim().toLowerCase();
            if (!q) {
                filteredForms = allForms.slice();
            } else {
                filteredForms = allForms.filter(f =>
                    (f.title || '').toLowerCase().includes(q) ||
                    (f.description || '').toLowerCase().includes(q)
                );
            }
            render();
        }

        function render() {
            // Update sort indicator on the right column header
            document.querySelectorAll('.forms-table thead th').forEach(th => {
                th.classList.remove('sort-asc', 'sort-desc');
                if (th.dataset.sort === sortKey) {
                    th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
                    const arrow = th.querySelector('.sort-arrow');
                    if (arrow) arrow.textContent = sortDir === 'asc' ? '▲' : '▼';
                } else {
                    const arrow = th.querySelector('.sort-arrow');
                    if (arrow) arrow.textContent = '▲▼';
                }
            });

            // Sort
            const sorted = filteredForms.slice().sort((a, b) => {
                const va = sortValue(a, sortKey);
                const vb = sortValue(b, sortKey);
                if (va === vb) return 0;
                const cmp = va > vb ? 1 : -1;
                return sortDir === 'asc' ? cmp : -cmp;
            });

            const tbody = document.getElementById('formsTableBody');
            if (sorted.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8"><div class="forms-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                    </svg>
                    <h3>${allForms.length === 0 ? esc(window.t('forms.list.empty_none_title')) : esc(window.t('forms.list.empty_match_title'))}</h3>
                    <p>${allForms.length === 0
                        ? window.t('forms.list.empty_none_body')
                        : window.t('forms.list.empty_match_body')}</p>
                </div></td></tr>`;
                return;
            }

            tbody.innerHTML = sorted.map(f => {
                const desc = f.description ? `<small>${esc(f.description)}</small>` : '';
                const statusPill = f.is_active == 1
                    ? '<span class="ft-pill active">' + esc(window.t('forms.list.status_active')) + '</span>'
                    : '<span class="ft-pill inactive">' + esc(window.t('forms.list.status_inactive')) + '</span>';
                return `<tr onclick="openEdit(${f.id})">
                    <td class="col-title">
                        <strong>${esc(f.title)}</strong>
                        ${desc}
                    </td>
                    <td><span class="ft-pill version">v${f.version_number || 1}</span></td>
                    <td>${statusPill}</td>
                    <td style="text-align: right;">${f.field_count}</td>
                    <td style="text-align: right;">${f.submission_count}</td>
                    <td title="${esc(fullLocalDate(f.modified_date))}">${esc(relativeDate(f.modified_date))}</td>
                    <td>${esc(f.modified_by_name || f.created_by_name || window.t('forms.list.unknown_user'))}</td>
                    <td class="col-actions" onclick="event.stopPropagation()">
                        <a class="ft-action-btn" href="<?php echo BASE_URL; ?>forms/fill.php?id=${f.id}" title="${escAttr(window.t('forms.list.fill_title'))}">${ICON_FILL}</a>
                        <a class="ft-action-btn" href="<?php echo BASE_URL; ?>forms/submissions.php?id=${f.id}" title="${escAttr(window.t('forms.list.subs_title'))}">${ICON_SUBS}</a>
                        <button class="ft-action-btn danger" onclick="confirmDelete(${f.id})" title="${escAttr(window.t('forms.list.delete_title'))}">${ICON_DELETE}</button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Pick the value used for sorting. Strings normalised to
        // lowercase; counts and dates come back numeric/comparable.
        function sortValue(f, key) {
            switch (key) {
                case 'title':       return (f.title || '').toLowerCase();
                case 'version':     return Number(f.version_number) || 0;
                case 'status':      return f.is_active == 1 ? 1 : 0;
                case 'fields':      return Number(f.field_count) || 0;
                case 'submissions': return Number(f.submission_count) || 0;
                case 'modified':    return f.modified_date || '';
                default:            return '';
            }
        }

        function openEdit(id) {
            window.location.href = EDIT_BASE + '?id=' + id;
        }

        // Friendly "5 minutes ago" / "2 days ago" — falls back to the
        // date once it's older than a week so dates stay readable.
        // modified_date is a server-stamped UTC timestamp (kind 1): parse
        // it as UTC and render the fallback date in the analyst's zone.
        function relativeDate(iso) {
            if (!iso) return '';
            const d = parseUTCDate(iso);
            if (!d || isNaN(d.getTime())) return iso;
            const now = new Date();
            const secs = Math.floor((now - d) / 1000);
            if (secs < 60)        return window.t('forms.list.relative_just_now');
            if (secs < 3600)      return window.t('forms.list.relative_min_ago', { n: Math.floor(secs / 60) });
            if (secs < 86400)     return window.t('forms.list.relative_hr_ago', { n: Math.floor(secs / 3600) });
            if (secs < 604800)    return window.t('forms.list.relative_days_ago', { n: Math.floor(secs / 86400) });
            return d.toLocaleDateString(undefined, tzOpts());
        }

        // Full timestamp for the row's hover title — the UTC value (kind 1)
        // shown in the analyst's zone.
        function fullLocalDate(iso) {
            if (!iso) return '';
            const d = parseUTCDate(iso);
            if (!d || isNaN(d.getTime())) return iso;
            return d.toLocaleString(undefined, tzOpts());
        }

        function esc(text) {
            if (text == null) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }
        function escAttr(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                .replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // ===== Delete =====
        async function confirmDelete(id) {
            const ok = await showConfirm({
                title: window.t('forms.delete.title'),
                message: window.t('forms.delete.message'),
                okLabel: window.t('forms.delete.ok'),
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const res = await fetch(API_BASE + 'delete_form.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) {
                    await loadForms();
                    showToast(window.t('forms.toast.form_deleted'), 'success');
                } else {
                    showToast(data.error || window.t('forms.toast.delete_failed'), 'error');
                }
            } catch (e) {
                showToast(window.t('forms.toast.delete_failed'), 'error');
            }
        }
    </script>
</body>
</html>
