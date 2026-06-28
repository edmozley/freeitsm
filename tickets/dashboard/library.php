<?php
/**
 * Tickets - Widget Library
 * Full-page management screen for ticket dashboard widgets: search, create, edit, duplicate, delete
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();

$current_page = 'dashboard';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.dashboard.library.page_title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=4">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=35">
    <style>
        .dashboard-page {
            height: calc(100vh - 48px);
            overflow-y: auto;
        }

        .library-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: var(--surface, #fff);
            border-bottom: 1px solid var(--border, #e0e0e0);
            gap: 12px;
        }

        .library-toolbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .library-toolbar-left a {
            color: var(--text-muted, #555);
            text-decoration: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .library-toolbar-left a:hover {
            color: var(--accent, #0078d4);
        }

        .library-toolbar-left a svg {
            width: 16px;
            height: 16px;
        }

        .library-toolbar h2 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .library-toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-input {
            padding: 8px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 6px;
            font-size: 13px;
            width: 240px;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent, #0078d4);
            box-shadow: 0 0 0 2px rgba(0,120,212,0.1);
        }

        .btn {
            padding: 8px 16px;
            border: 1px solid var(--border, #ddd);
            border-radius: 6px;
            background: var(--surface, #fff);
            color: var(--text, #333);
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn:hover { background: var(--surface-2, #f5f5f5); border-color: var(--border, #ccc); }

        .btn-primary { background: var(--accent, #0078d4); color: var(--on-accent, #fff); border-color: var(--accent, #0078d4); }
        .btn-primary:hover { background: var(--accent-hover, #106ebe); }

        .btn-sm { padding: 5px 10px; font-size: 12px; }

        .btn-danger { color: var(--danger-accent, #d13438); border-color: var(--danger-accent, #d13438); }
        .btn-danger:hover { background: var(--danger-bg, #fdf3f3); }

        .btn:disabled { opacity: 0.4; cursor: not-allowed; }

        .btn svg { width: 14px; height: 14px; }

        /* Widget table */
        .library-container {
            padding: 24px;
        }

        .widget-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            overflow: hidden;
        }

        .widget-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted, #666);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: var(--surface-3, #f8f9fa);
            border-bottom: 1px solid var(--border, #e0e0e0);
        }

        .widget-table td {
            padding: 12px 16px;
            font-size: 13px;
            color: var(--text, #333);
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            vertical-align: middle;
        }

        .widget-table tr:last-child td {
            border-bottom: none;
        }

        .widget-table tr:hover td {
            background: var(--surface-3, #f8f9fa);
        }

        .widget-table .widget-title {
            font-weight: 600;
        }

        .widget-table .widget-desc {
            color: var(--text-dim, #888);
            font-size: 12px;
            margin-top: 2px;
        }

        .type-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
            background: var(--surface-hover, #f0f0f0);
            color: var(--text-muted, #555);
        }

        .series-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            background: var(--success-bg, #e8f5e9);
            color: var(--success-text, #2e7d32);
        }

        .actions-cell {
            white-space: nowrap;
        }

        .actions-cell .btn {
            margin-right: 4px;
        }

        /* Edit form panel */
        .edit-panel {
            display: none;
            background: var(--surface-3, #f8f9fa);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 20px;
        }

        .edit-panel.active {
            display: block;
        }

        .edit-panel h3 {
            margin: 0 0 16px 0;
            font-size: 15px;
            color: var(--text, #333);
        }

        .edit-panel-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border, #e0e0e0);
        }

        .no-results {
            text-align: center;
            padding: 40px;
            color: var(--text-dim, #888);
            font-size: 14px;
        }
        /* Form fields follow the palette (inbox.css only themes specific input classes). */
    .dashboard-page input, .dashboard-page select, .dashboard-page textarea { background: var(--surface, #fff); color: var(--text, #333); }
    </style>
</head>
<body>
    <?php require_once '../includes/header.php'; ?>

    <div class="dashboard-page">

    <div class="library-toolbar">
        <div class="library-toolbar-left">
            <a href="./">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                <?php echo htmlspecialchars(t('tickets.dashboard.library.back_dashboard')); ?>
            </a>
            <h2><?php echo htmlspecialchars(t('tickets.dashboard.library.heading')); ?></h2>
        </div>
        <div class="library-toolbar-right">
            <input type="text" class="search-input" id="searchInput" placeholder="<?php echo htmlspecialchars(t('tickets.dashboard.library.search_placeholder')); ?>" oninput="filterWidgets()">
            <button class="btn btn-primary" onclick="showNewForm()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <?php echo htmlspecialchars(t('tickets.dashboard.library.new_btn')); ?>
            </button>
        </div>
    </div>

    <div class="library-container">
        <!-- Edit/New panel -->
        <div class="edit-panel" id="editPanel">
            <h3 id="editPanelTitle"><?php echo htmlspecialchars(t('tickets.dashboard.library.new_widget')); ?></h3>
            <?php require_once 'includes/widget_edit_form.php'; ?>
            <div class="edit-panel-actions">
                <button class="btn btn-primary" onclick="handleSave()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button class="btn" onclick="closeEditPanel()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
            </div>
        </div>

        <!-- Widget table -->
        <table class="widget-table" id="widgetTable">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('tickets.dashboard.library.col_widget')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.dashboard.library.col_chart')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.dashboard.library.col_property')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.dashboard.library.col_series')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.dashboard.library.col_filterable')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.dashboard.library.col_actions')); ?></th>
                </tr>
            </thead>
            <tbody id="widgetTableBody"></tbody>
        </table>
        <div class="no-results" id="noResults" style="display:none;"><?php echo htmlspecialchars(t('tickets.dashboard.library.no_results')); ?></div>
    </div>

    </div><!-- /.dashboard-page -->

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <script src="../../assets/js/widget-editor.js"></script>
    <script>
        const API_BASE = '../../api/tickets/';
        let allWidgets = [];
        let dashboardWidgetIds = new Set();

        async function init() {
            const [libRes, dashRes] = await Promise.all([
                fetch(API_BASE + 'get_ticket_widget_library.php').then(r => r.json()).catch(() => ({ success: false })),
                fetch(API_BASE + 'get_ticket_dashboard.php').then(r => r.json()).catch(() => ({ success: false }))
            ]);

            if (libRes.success) allWidgets = libRes.widgets;
            if (dashRes.success) {
                dashboardWidgetIds = new Set((dashRes.widgets || []).map(w => parseInt(w.widget_id)));
            }

            await WidgetEditor.init(API_BASE);
            renderTable();
        }

        function renderTable() {
            const tbody = document.getElementById('widgetTableBody');
            const search = document.getElementById('searchInput').value.toLowerCase();
            const filtered = allWidgets.filter(w =>
                w.title.toLowerCase().includes(search) ||
                (w.description || '').toLowerCase().includes(search)
            );

            document.getElementById('noResults').style.display = filtered.length === 0 ? 'block' : 'none';
            document.getElementById('widgetTable').style.display = filtered.length === 0 ? 'none' : 'table';

            tbody.innerHTML = filtered.map(w => {
                const onDash = dashboardWidgetIds.has(parseInt(w.id));
                const propLabel = WidgetEditor.PROPERTY_LABELS[w.aggregate_property] || w.aggregate_property;
                const groupLabel = w.time_grouping ? WidgetEditor.TIME_GROUPING_LABELS[w.time_grouping] || w.time_grouping : '';
                const propDisplay = groupLabel ? propLabel + ' (' + groupLabel + ')' : propLabel;
                const seriesLabel = w.series_property ? WidgetEditor.SERIES_LABELS[w.series_property] || w.series_property : '';
                const rangeLabel = w.date_range ? WidgetEditor.DATE_RANGE_LABELS[w.date_range] || w.date_range : '';
                const deptCount = w.department_filter ? (typeof w.department_filter === 'string' ? JSON.parse(w.department_filter) : w.department_filter).length : 0;
                const filterInfo = [rangeLabel, deptCount > 0 ? window.t(deptCount > 1 ? 'tickets.dashboard.library.dept_many' : 'tickets.dashboard.library.dept_one', { count: deptCount }) : ''].filter(Boolean).join(', ');
                return `<tr>
                    <td>
                        <div class="widget-title">${escapeHtml(w.title)}</div>
                        <div class="widget-desc">${escapeHtml(w.description || '')}</div>
                    </td>
                    <td><span class="type-badge">${escapeHtml(w.chart_type)}</span></td>
                    <td>
                        ${escapeHtml(propDisplay)}
                        ${filterInfo ? `<div class="widget-desc">${escapeHtml(filterInfo)}</div>` : ''}
                    </td>
                    <td>${seriesLabel ? `<span class="series-badge">${escapeHtml(seriesLabel)}</span>` : '—'}</td>
                    <td>${parseInt(w.is_status_filterable) ? escapeHtml(window.t('tickets.dashboard.library.yes')) : escapeHtml(window.t('tickets.dashboard.library.no'))}</td>
                    <td class="actions-cell">
                        <button class="btn btn-sm btn-primary" onclick="addToDashboard(${w.id})" ${onDash ? 'disabled title="' + escapeHtml(window.t('tickets.dashboard.library.already_on_dashboard')) + '"' : ''}>
                            ${onDash ? escapeHtml(window.t('tickets.dashboard.library.added')) : escapeHtml(window.t('tickets.dashboard.library.add'))}
                        </button>
                        <button class="btn btn-sm" onclick="editWidget(${w.id})" title="${escapeHtml(window.t('tickets.dashboard.library.tooltip_edit'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="btn btn-sm" onclick="duplicateWidget(${w.id})" title="${escapeHtml(window.t('tickets.dashboard.library.tooltip_duplicate'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteWidget(${w.id}, '${escapeHtml(w.title)}')" title="${escapeHtml(window.t('tickets.dashboard.library.tooltip_delete'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function filterWidgets() {
            renderTable();
        }

        // Edit panel
        function showNewForm() {
            document.getElementById('editPanelTitle').textContent = window.t('tickets.dashboard.library.new_widget');
            WidgetEditor.resetForm();
            document.getElementById('editPanel').classList.add('active');
            document.getElementById('editTitle').focus();
        }

        function editWidget(id) {
            const w = allWidgets.find(w => parseInt(w.id) === id);
            if (!w) return;

            document.getElementById('editPanelTitle').textContent = window.t('tickets.dashboard.library.edit_widget');
            WidgetEditor.populateForm(w);

            document.getElementById('editPanel').classList.add('active');
            document.getElementById('editTitle').focus();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function closeEditPanel() {
            document.getElementById('editPanel').classList.remove('active');
        }

        async function handleSave() {
            const result = await WidgetEditor.saveWidget();
            if (!result.success) {
                if (result.error) showToast(result.error, 'error');
                return;
            }

            const id = document.getElementById('editId').value;
            if (id) {
                const idx = allWidgets.findIndex(w => parseInt(w.id) === parseInt(id));
                if (idx >= 0) allWidgets[idx] = result.widget;
            } else {
                allWidgets.push(result.widget);
            }

            closeEditPanel();
            renderTable();
            showToast(id ? window.t('tickets.dashboard.library.widget_updated') : window.t('tickets.dashboard.library.widget_created'), 'success');
        }

        async function duplicateWidget(id) {
            const w = allWidgets.find(w => parseInt(w.id) === id);
            if (!w) return;

            try {
                const res = await fetch(API_BASE + 'save_ticket_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: window.t('tickets.dashboard.library.copy_suffix', { title: w.title }),
                        description: w.description || '',
                        chart_type: w.chart_type,
                        aggregate_property: w.aggregate_property,
                        series_property: w.series_property || null,
                        is_status_filterable: parseInt(w.is_status_filterable),
                        date_range: w.date_range || null,
                        time_grouping: w.time_grouping || null,
                        department_filter: w.department_filter ? (typeof w.department_filter === 'string' ? JSON.parse(w.department_filter) : w.department_filter) : null
                    })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || window.t('tickets.dashboard.library.failed_duplicate'), 'error');
                    return;
                }

                allWidgets.push(data.widget);
                renderTable();
                showToast(window.t('tickets.dashboard.library.widget_duplicated'), 'success');
            } catch (err) {
                showToast(window.t('tickets.dashboard.library.failed_duplicate_widget'), 'error');
            }
        }

        async function deleteWidget(id, title) {
            if (!(await showConfirm({ title: window.t('tickets.dashboard.library.delete_confirm_title'), message: window.t('tickets.dashboard.library.delete_confirm_msg', { title: title }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;

            try {
                const res = await fetch(API_BASE + 'delete_ticket_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || window.t('tickets.dashboard.library.failed_delete'), 'error');
                    return;
                }

                allWidgets = allWidgets.filter(w => parseInt(w.id) !== id);
                dashboardWidgetIds.delete(id);
                renderTable();
                showToast(window.t('tickets.dashboard.library.widget_deleted'), 'success');
            } catch (err) {
                showToast(window.t('tickets.dashboard.library.failed_delete_widget'), 'error');
            }
        }

        async function addToDashboard(widgetId) {
            try {
                const res = await fetch(API_BASE + 'add_ticket_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ widget_id: widgetId })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || window.t('tickets.dashboard.library.failed_add'), 'error');
                    return;
                }

                dashboardWidgetIds.add(widgetId);
                renderTable();
                showToast(window.t('tickets.dashboard.library.added_to_dashboard'), 'success');
            } catch (err) {
                showToast(window.t('tickets.dashboard.library.failed_add_dashboard'), 'error');
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeEditPanel();
        });

        init();
    </script>
</body>
</html>
