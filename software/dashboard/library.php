<?php
/**
 * Software - Widget Library
 * Full-page management screen for software dashboard widgets: search, create, edit, duplicate, delete
 */
session_start();
require_once '../../config.php';

$current_page = 'dashboard';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Software Widget Library</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <style>
        body {
            overflow: auto;
            height: auto;
        }

        .library-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            gap: 12px;
        }

        .library-toolbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .library-toolbar-left a {
            color: #555;
            text-decoration: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .library-toolbar-left a:hover {
            color: #5c6bc0;
        }

        .library-toolbar-left a svg {
            width: 16px;
            height: 16px;
        }

        .library-toolbar h2 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .library-toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            width: 240px;
        }

        .search-input:focus {
            outline: none;
            border-color: #5c6bc0;
            box-shadow: 0 0 0 2px rgba(92,107,192,0.1);
        }

        .btn {
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: #fff;
            color: #333;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn:hover { background: #f5f5f5; border-color: #ccc; }

        .btn-primary { background: #5c6bc0; color: #fff; border-color: #5c6bc0; }
        .btn-primary:hover { background: #4a5ab5; }

        .btn-sm { padding: 5px 10px; font-size: 12px; }

        .btn-danger { color: #d13438; border-color: #d13438; }
        .btn-danger:hover { background: #fdf3f3; }

        .btn:disabled { opacity: 0.4; cursor: not-allowed; }

        .btn svg { width: 14px; height: 14px; }

        /* Widget table */
        .library-container {
            padding: 24px;
        }

        .widget-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }

        .widget-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }

        .widget-table td {
            padding: 12px 16px;
            font-size: 13px;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .widget-table tr:last-child td {
            border-bottom: none;
        }

        .widget-table tr:hover td {
            background: #f8f9fa;
        }

        .widget-table .widget-title {
            font-weight: 600;
        }

        .widget-table .widget-desc {
            color: #888;
            font-size: 12px;
            margin-top: 2px;
        }

        .type-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
            background: #f0f0f0;
            color: #555;
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
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
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
            color: #333;
        }

        .edit-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .edit-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .edit-form .form-group.full-width {
            grid-column: 1 / -1;
        }

        .edit-form label {
            font-size: 12px;
            font-weight: 600;
            color: #555;
        }

        .edit-form input,
        .edit-form select,
        .edit-form textarea {
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
        }

        .edit-form input:focus,
        .edit-form select:focus,
        .edit-form textarea:focus {
            outline: none;
            border-color: #5c6bc0;
            box-shadow: 0 0 0 2px rgba(92,107,192,0.1);
        }

        .edit-form textarea {
            resize: vertical;
            min-height: 60px;
        }

        .edit-form .checkbox-group {
            flex-direction: row;
            align-items: center;
            gap: 8px;
            padding-top: 20px;
        }

        .edit-form .checkbox-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        .edit-panel-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e0e0e0;
        }

        .no-results {
            text-align: center;
            padding: 40px;
            color: #888;
            font-size: 14px;
        }

        @media (max-width: 900px) {
            .edit-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header.php'; ?>

    <div class="library-toolbar">
        <div class="library-toolbar-left">
            <a href="./">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                Dashboard
            </a>
            <h2>Widget Library</h2>
        </div>
        <div class="library-toolbar-right">
            <input type="text" class="search-input" id="searchInput" placeholder="Search widgets..." oninput="filterWidgets()">
            <button class="btn btn-primary" onclick="showNewForm()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                New
            </button>
        </div>
    </div>

    <div class="library-container">
        <!-- Edit/New panel -->
        <div class="edit-panel" id="editPanel">
            <h3 id="editPanelTitle">New Widget</h3>
            <input type="hidden" id="editId">
            <div class="edit-form">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" id="editTitle" maxlength="100" placeholder="e.g. Chrome Versions">
                </div>
                <div class="form-group">
                    <label>Chart Type</label>
                    <select id="editChartType">
                        <option value="bar">Bar</option>
                        <option value="doughnut">Doughnut</option>
                        <option value="pie">Pie</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Description</label>
                    <textarea id="editDescription" maxlength="255" placeholder="Brief description of what this widget shows"></textarea>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select id="editAggProperty" onchange="onAggChange()">
                        <option value="version_distribution">Version Distribution</option>
                        <option value="top_installed">Top Installed</option>
                        <option value="publisher_distribution">Publisher Distribution</option>
                    </select>
                </div>
                <div class="form-group" id="appIdGroup">
                    <label>Application</label>
                    <select id="editAppId">
                        <option value="">-- Select --</option>
                    </select>
                </div>
                <div class="form-group checkbox-group" id="excludeGroup" style="display:none;">
                    <input type="checkbox" id="editExcludeComponents" checked>
                    <label for="editExcludeComponents">Exclude system components</label>
                </div>
            </div>
            <div class="edit-panel-actions">
                <button class="btn btn-primary" onclick="saveWidget()">Save</button>
                <button class="btn" onclick="closeEditPanel()">Cancel</button>
            </div>
        </div>

        <!-- Widget table -->
        <table class="widget-table" id="widgetTable">
            <thead>
                <tr>
                    <th>Widget</th>
                    <th>Chart</th>
                    <th>Type</th>
                    <th>Application</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="widgetTableBody"></tbody>
        </table>
        <div class="no-results" id="noResults" style="display:none;">No widgets match your search</div>
    </div>

    <script>
        const API_BASE = '../../api/software/';
        let allWidgets = [];
        let allApps = [];
        let dashboardWidgetIds = new Set();
        let descriptionManuallyEdited = false;

        const AGG_LABELS = {
            version_distribution: 'Version Distribution',
            top_installed: 'Top Installed',
            publisher_distribution: 'Publisher Distribution'
        };

        async function init() {
            const [libRes, dashRes, appsRes] = await Promise.all([
                fetch(API_BASE + 'get_software_widget_library.php').then(r => r.json()).catch(() => ({ success: false })),
                fetch(API_BASE + 'get_software_dashboard.php').then(r => r.json()).catch(() => ({ success: false })),
                fetch(API_BASE + 'get_apps.php').then(r => r.json()).catch(() => ({ success: false }))
            ]);

            if (libRes.success) allWidgets = libRes.widgets;
            if (dashRes.success) {
                dashboardWidgetIds = new Set((dashRes.widgets || []).map(w => parseInt(w.widget_id)));
            }
            if (appsRes.success) {
                allApps = (appsRes.apps || []).filter(a => !parseInt(a.system_component));
            }

            populateAppDropdown();
            renderTable();
        }

        function populateAppDropdown() {
            const sel = document.getElementById('editAppId');
            sel.innerHTML = '<option value="">-- Select --</option>';
            allApps.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.display_name + (a.publisher ? ' (' + a.publisher + ')' : '');
                sel.appendChild(opt);
            });
        }

        function renderTable() {
            const tbody = document.getElementById('widgetTableBody');
            const search = document.getElementById('searchInput').value.toLowerCase();
            const filtered = allWidgets.filter(w =>
                w.title.toLowerCase().includes(search) ||
                (w.description || '').toLowerCase().includes(search) ||
                (w.app_name || '').toLowerCase().includes(search)
            );

            document.getElementById('noResults').style.display = filtered.length === 0 ? 'block' : 'none';
            document.getElementById('widgetTable').style.display = filtered.length === 0 ? 'none' : 'table';

            tbody.innerHTML = filtered.map(w => {
                const onDash = dashboardWidgetIds.has(parseInt(w.id));
                const aggLabel = AGG_LABELS[w.aggregate_property] || w.aggregate_property;
                const appName = w.app_name || '--';
                return `<tr>
                    <td>
                        <div class="widget-title">${escapeHtml(w.title)}</div>
                        <div class="widget-desc">${escapeHtml(w.description || '')}</div>
                    </td>
                    <td><span class="type-badge">${escapeHtml(w.chart_type)}</span></td>
                    <td>${escapeHtml(aggLabel)}</td>
                    <td>${escapeHtml(appName)}</td>
                    <td class="actions-cell">
                        <button class="btn btn-sm btn-primary" onclick="addToDashboard(${w.id})" ${onDash ? 'disabled title="Already on dashboard"' : ''}>
                            ${onDash ? 'Added' : 'Add'}
                        </button>
                        <button class="btn btn-sm" onclick="editWidget(${w.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="btn btn-sm" onclick="duplicateWidget(${w.id})" title="Duplicate">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteWidget(${w.id}, '${escapeHtml(w.title).replace(/'/g, "\\'")}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function filterWidgets() {
            renderTable();
        }

        function onAggChange() {
            const val = document.getElementById('editAggProperty').value;
            document.getElementById('appIdGroup').style.display = val === 'version_distribution' ? '' : 'none';
            document.getElementById('excludeGroup').style.display = val !== 'version_distribution' ? '' : 'none';
            autoFillDescription();
        }

        function autoFillDescription() {
            if (descriptionManuallyEdited) return;
            const agg = document.getElementById('editAggProperty').value;
            const appSel = document.getElementById('editAppId');
            const appName = appSel.selectedOptions[0]?.textContent || '';
            let desc = '';

            if (agg === 'version_distribution' && appName && appName !== '-- Select --') {
                desc = 'Version distribution for ' + appName.split(' (')[0];
            } else if (agg === 'top_installed') {
                desc = 'Most installed applications across all machines';
            } else if (agg === 'publisher_distribution') {
                desc = 'Software distribution by publisher';
            }

            document.getElementById('editDescription').value = desc;
        }

        // Edit panel
        function showNewForm() {
            document.getElementById('editPanelTitle').textContent = 'New Widget';
            document.getElementById('editId').value = '';
            document.getElementById('editTitle').value = '';
            document.getElementById('editDescription').value = '';
            document.getElementById('editChartType').value = 'bar';
            document.getElementById('editAggProperty').value = 'version_distribution';
            document.getElementById('editAppId').value = '';
            document.getElementById('editExcludeComponents').checked = true;
            descriptionManuallyEdited = false;
            onAggChange();
            document.getElementById('editPanel').classList.add('active');
            document.getElementById('editTitle').focus();
        }

        function editWidget(id) {
            const w = allWidgets.find(w => parseInt(w.id) === id);
            if (!w) return;

            document.getElementById('editPanelTitle').textContent = 'Edit Widget';
            document.getElementById('editId').value = w.id;
            document.getElementById('editTitle').value = w.title;
            document.getElementById('editDescription').value = w.description || '';
            document.getElementById('editChartType').value = w.chart_type;
            document.getElementById('editAggProperty').value = w.aggregate_property;
            document.getElementById('editAppId').value = w.app_id || '';
            document.getElementById('editExcludeComponents').checked = parseInt(w.exclude_system_components) === 1;
            descriptionManuallyEdited = true; // Don't overwrite existing descriptions
            onAggChange();
            document.getElementById('editPanel').classList.add('active');
            document.getElementById('editTitle').focus();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function closeEditPanel() {
            document.getElementById('editPanel').classList.remove('active');
        }

        async function saveWidget() {
            const id = document.getElementById('editId').value || null;
            const title = document.getElementById('editTitle').value.trim();
            const description = document.getElementById('editDescription').value.trim();
            const chart_type = document.getElementById('editChartType').value;
            const aggregate_property = document.getElementById('editAggProperty').value;
            const app_id = aggregate_property === 'version_distribution' ? (document.getElementById('editAppId').value || null) : null;
            const exclude_system_components = document.getElementById('editExcludeComponents').checked ? 1 : 0;

            if (!title) {
                showToast('Title is required', 'error');
                return;
            }

            if (aggregate_property === 'version_distribution' && !app_id) {
                showToast('Application is required for version distribution', 'error');
                return;
            }

            try {
                const res = await fetch(API_BASE + 'save_software_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, title, description, chart_type, aggregate_property, app_id, exclude_system_components })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'Failed to save', 'error');
                    return;
                }

                if (id) {
                    const idx = allWidgets.findIndex(w => parseInt(w.id) === parseInt(id));
                    if (idx >= 0) allWidgets[idx] = data.widget;
                } else {
                    allWidgets.push(data.widget);
                }

                closeEditPanel();
                renderTable();
                showToast(id ? 'Widget updated' : 'Widget created', 'success');
            } catch (err) {
                showToast('Failed to save widget', 'error');
            }
        }

        async function duplicateWidget(id) {
            const w = allWidgets.find(w => parseInt(w.id) === id);
            if (!w) return;

            try {
                const res = await fetch(API_BASE + 'save_software_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: w.title + ' (Copy)',
                        description: w.description || '',
                        chart_type: w.chart_type,
                        aggregate_property: w.aggregate_property,
                        app_id: w.app_id || null,
                        exclude_system_components: parseInt(w.exclude_system_components)
                    })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'Failed to duplicate', 'error');
                    return;
                }

                allWidgets.push(data.widget);
                renderTable();
                showToast('Widget duplicated', 'success');
            } catch (err) {
                showToast('Failed to duplicate widget', 'error');
            }
        }

        async function deleteWidget(id, title) {
            if (!confirm('Delete "' + title + '"? This will also remove it from all analyst dashboards.')) return;

            try {
                const res = await fetch(API_BASE + 'delete_software_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'Failed to delete', 'error');
                    return;
                }

                allWidgets = allWidgets.filter(w => parseInt(w.id) !== id);
                dashboardWidgetIds.delete(id);
                renderTable();
                showToast('Widget deleted', 'success');
            } catch (err) {
                showToast('Failed to delete widget', 'error');
            }
        }

        async function addToDashboard(widgetId) {
            try {
                const res = await fetch(API_BASE + 'add_software_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ widget_id: widgetId })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'Failed to add', 'error');
                    return;
                }

                dashboardWidgetIds.add(widgetId);
                renderTable();
                showToast('Added to dashboard', 'success');
            } catch (err) {
                showToast('Failed to add to dashboard', 'error');
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Track manual description edits
        document.getElementById('editDescription').addEventListener('input', function() {
            descriptionManuallyEdited = true;
        });

        document.getElementById('editAppId').addEventListener('change', autoFillDescription);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeEditPanel();
        });

        init();
    </script>
</body>
</html>
