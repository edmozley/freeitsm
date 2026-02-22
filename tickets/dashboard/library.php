<?php
/**
 * Tickets - Widget Library
 * Full-page management screen for ticket dashboard widgets: search, create, edit, duplicate, delete
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
    <title>Service Desk - Ticket Widget Library</title>
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
            color: #0078d4;
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
            border-color: #0078d4;
            box-shadow: 0 0 0 2px rgba(0,120,212,0.1);
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

        .btn-primary { background: #0078d4; color: #fff; border-color: #0078d4; }
        .btn-primary:hover { background: #106ebe; }

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

        .series-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            background: #e8f5e9;
            color: #2e7d32;
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
            border-color: #0078d4;
            box-shadow: 0 0 0 2px rgba(0,120,212,0.1);
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
                    <input type="text" id="editTitle" maxlength="100" placeholder="e.g. Tickets by status">
                </div>
                <div class="form-group">
                    <label>Chart type</label>
                    <select id="editChartType">
                        <option value="bar">Bar</option>
                        <option value="doughnut">Doughnut</option>
                        <option value="pie">Pie</option>
                        <option value="line">Line</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Description</label>
                    <textarea id="editDescription" maxlength="255" placeholder="Brief description of what this widget shows"></textarea>
                </div>
                <div class="form-group">
                    <label>Aggregate property</label>
                    <select id="editProperty" onchange="onPropertyChange()">
                        <optgroup label="Categorical">
                            <option value="status">Status</option>
                            <option value="priority">Priority</option>
                            <option value="department">Department</option>
                            <option value="ticket_type">Ticket type</option>
                            <option value="analyst">Assigned analyst</option>
                            <option value="owner">Owner</option>
                            <option value="origin">Origin</option>
                            <option value="first_time_fix">First time fix</option>
                            <option value="training_provided">Training provided</option>
                        </optgroup>
                        <optgroup label="Time series">
                            <option value="created">Created</option>
                            <option value="closed">Closed</option>
                        </optgroup>
                        <optgroup label="Comparison">
                            <option value="created_vs_closed">Created vs closed</option>
                        </optgroup>
                    </select>
                </div>
                <div class="form-group" id="timeGroupingGroup" style="display:none;">
                    <label>Time grouping</label>
                    <select id="editTimeGrouping">
                        <option value="day">Day</option>
                        <option value="month">Month</option>
                        <option value="year">Year</option>
                    </select>
                </div>
                <div class="form-group" id="seriesGroup">
                    <label>Series breakdown</label>
                    <select id="editSeries">
                        <option value="">None (single series)</option>
                        <option value="status">By status</option>
                        <option value="priority">By priority</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date range</label>
                    <select id="editDateRange">
                        <option value="">All time</option>
                        <option value="7d">Last 7 days</option>
                        <option value="30d">Last 30 days</option>
                        <option value="this_month">This month</option>
                        <option value="3m">Last 3 months</option>
                        <option value="6m">Last 6 months</option>
                        <option value="12m">Last 12 months</option>
                        <option value="this_year">This year</option>
                    </select>
                </div>
                <div class="form-group full-width" id="deptFilterGroup">
                    <label>Department filter</label>
                    <div id="deptCheckboxes" style="display:flex;flex-wrap:wrap;gap:8px;padding:4px 0;"></div>
                    <div style="font-size:11px;color:#888;margin-top:4px;">Leave all unchecked to include all departments</div>
                </div>
                <div class="form-group checkbox-group" id="filterableGroup">
                    <input type="checkbox" id="editFilterable" checked>
                    <label for="editFilterable">Allow status filtering</label>
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
                    <th>Property</th>
                    <th>Series</th>
                    <th>Filterable</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="widgetTableBody"></tbody>
        </table>
        <div class="no-results" id="noResults" style="display:none;">No widgets match your search</div>
    </div>

    <script>
        const API_BASE = '../../api/tickets/';
        let allWidgets = [];
        let dashboardWidgetIds = new Set();

        const PROPERTY_LABELS = {
            status: 'Status', priority: 'Priority', department: 'Department',
            ticket_type: 'Ticket type', analyst: 'Assigned analyst', owner: 'Owner',
            origin: 'Origin', first_time_fix: 'First time fix', training_provided: 'Training provided',
            created: 'Created', closed: 'Closed', created_vs_closed: 'Created vs closed'
        };

        const SERIES_LABELS = { status: 'Status', priority: 'Priority' };

        const TIME_GROUPING_LABELS = { day: 'Daily', month: 'Monthly', year: 'Yearly' };
        const DATE_RANGE_LABELS = {
            '': 'All time', '7d': 'Last 7 days', '30d': 'Last 30 days',
            'this_month': 'This month', '3m': 'Last 3 months',
            '6m': 'Last 6 months', '12m': 'Last 12 months', 'this_year': 'This year'
        };

        const TIME_AGGREGATES = ['created', 'closed', 'created_vs_closed'];

        // Rules for which series options are valid per aggregate
        const SERIES_RULES = {
            status: [],
            priority: ['status'],
            department: ['status', 'priority'],
            ticket_type: ['status', 'priority'],
            analyst: ['status', 'priority'],
            owner: ['status', 'priority'],
            origin: ['status', 'priority'],
            first_time_fix: [],
            training_provided: [],
            created: ['status', 'priority'],
            closed: ['status', 'priority'],
            created_vs_closed: []
        };

        // Rules for which chart types are valid
        function getValidChartTypes(aggProp, seriesProp) {
            const isTime = TIME_AGGREGATES.includes(aggProp);

            if (seriesProp) return ['bar', 'line'];
            if (isTime) return ['bar', 'line'];
            return ['bar', 'doughnut', 'pie'];
        }

        let allDepartments = [];

        async function init() {
            const [libRes, dashRes, deptRes] = await Promise.all([
                fetch(API_BASE + 'get_ticket_widget_library.php').then(r => r.json()).catch(() => ({ success: false })),
                fetch(API_BASE + 'get_ticket_dashboard.php').then(r => r.json()).catch(() => ({ success: false })),
                fetch(API_BASE + 'get_departments.php').then(r => r.json()).catch(() => ({ success: false }))
            ]);

            if (libRes.success) allWidgets = libRes.widgets;
            if (dashRes.success) {
                dashboardWidgetIds = new Set((dashRes.widgets || []).map(w => parseInt(w.widget_id)));
            }
            if (deptRes.success) {
                allDepartments = (deptRes.departments || []).filter(d => d.is_active);
                buildDeptCheckboxes();
            }

            renderTable();
        }

        function buildDeptCheckboxes() {
            const container = document.getElementById('deptCheckboxes');
            container.innerHTML = allDepartments.map(d =>
                `<label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:normal;cursor:pointer;">
                    <input type="checkbox" value="${d.id}" class="dept-checkbox" style="width:14px;height:14px;">
                    ${escapeHtml(d.name)}
                </label>`
            ).join('');
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
                const propLabel = PROPERTY_LABELS[w.aggregate_property] || w.aggregate_property;
                const groupLabel = w.time_grouping ? TIME_GROUPING_LABELS[w.time_grouping] || w.time_grouping : '';
                const propDisplay = groupLabel ? propLabel + ' (' + groupLabel + ')' : propLabel;
                const seriesLabel = w.series_property ? SERIES_LABELS[w.series_property] || w.series_property : '';
                const rangeLabel = w.date_range ? DATE_RANGE_LABELS[w.date_range] || w.date_range : '';
                const deptCount = w.department_filter ? (typeof w.department_filter === 'string' ? JSON.parse(w.department_filter) : w.department_filter).length : 0;
                const filterInfo = [rangeLabel, deptCount > 0 ? deptCount + ' dept' + (deptCount > 1 ? 's' : '') : ''].filter(Boolean).join(', ');
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
                    <td>${parseInt(w.is_status_filterable) ? 'Yes' : 'No'}</td>
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
                        <button class="btn btn-sm btn-danger" onclick="deleteWidget(${w.id}, '${escapeHtml(w.title)}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function filterWidgets() {
            renderTable();
        }

        // Dynamic form rules
        function onPropertyChange() {
            const prop = document.getElementById('editProperty').value;
            const seriesSelect = document.getElementById('editSeries');
            const seriesGroup = document.getElementById('seriesGroup');
            const timeGroupingGroup = document.getElementById('timeGroupingGroup');
            const isTime = TIME_AGGREGATES.includes(prop);

            // Update series options
            const allowedSeries = SERIES_RULES[prop] || [];
            seriesSelect.innerHTML = '<option value="">None (single series)</option>';
            allowedSeries.forEach(s => {
                seriesSelect.innerHTML += `<option value="${s}">By ${SERIES_LABELS[s] || s}</option>`;
            });

            if (allowedSeries.length === 0) {
                seriesGroup.style.display = 'none';
                seriesSelect.value = '';
            } else {
                seriesGroup.style.display = '';
            }

            // Time grouping visibility
            if (isTime) {
                timeGroupingGroup.style.display = '';
                if (!document.getElementById('editTimeGrouping').value) {
                    document.getElementById('editTimeGrouping').value = 'month';
                }
            } else {
                timeGroupingGroup.style.display = 'none';
            }

            // Update chart type options
            updateChartTypeOptions();

            // Hide filterable when series is status
            updateFilterableVisibility();
        }

        function updateChartTypeOptions() {
            const prop = document.getElementById('editProperty').value;
            const series = document.getElementById('editSeries').value;
            const chartSelect = document.getElementById('editChartType');
            const current = chartSelect.value;

            const valid = getValidChartTypes(prop, series);
            const allTypes = [
                { value: 'bar', label: 'Bar' },
                { value: 'doughnut', label: 'Doughnut' },
                { value: 'pie', label: 'Pie' },
                { value: 'line', label: 'Line' }
            ];

            chartSelect.innerHTML = allTypes
                .filter(t => valid.includes(t.value))
                .map(t => `<option value="${t.value}">${t.label}</option>`)
                .join('');

            if (valid.includes(current)) {
                chartSelect.value = current;
            }
        }

        function updateFilterableVisibility() {
            const series = document.getElementById('editSeries').value;
            const filterableGroup = document.getElementById('filterableGroup');
            if (series === 'status') {
                filterableGroup.style.display = 'none';
                document.getElementById('editFilterable').checked = false;
            } else {
                filterableGroup.style.display = '';
            }
        }

        // Listen for series change to update chart types and filterable
        document.getElementById('editSeries').addEventListener('change', function() {
            updateChartTypeOptions();
            updateFilterableVisibility();
        });

        // Edit panel
        function showNewForm() {
            document.getElementById('editPanelTitle').textContent = 'New Widget';
            document.getElementById('editId').value = '';
            document.getElementById('editTitle').value = '';
            document.getElementById('editDescription').value = '';
            document.getElementById('editChartType').value = 'bar';
            document.getElementById('editProperty').value = 'status';
            document.getElementById('editSeries').value = '';
            document.getElementById('editFilterable').checked = true;
            document.getElementById('editDateRange').value = '';
            document.getElementById('editTimeGrouping').value = 'month';
            document.querySelectorAll('.dept-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('seriesGroup').style.display = 'none';
            document.getElementById('filterableGroup').style.display = '';
            document.getElementById('editPanel').classList.add('active');
            document.getElementById('editTitle').focus();
            onPropertyChange();
        }

        function editWidget(id) {
            const w = allWidgets.find(w => parseInt(w.id) === id);
            if (!w) return;

            document.getElementById('editPanelTitle').textContent = 'Edit Widget';
            document.getElementById('editId').value = w.id;
            document.getElementById('editTitle').value = w.title;
            document.getElementById('editDescription').value = w.description || '';
            document.getElementById('editProperty').value = w.aggregate_property;

            // Trigger property change to populate series/chart options and time grouping visibility
            onPropertyChange();

            document.getElementById('editSeries').value = w.series_property || '';
            updateChartTypeOptions();
            document.getElementById('editChartType').value = w.chart_type;
            document.getElementById('editFilterable').checked = parseInt(w.is_status_filterable) === 1;
            updateFilterableVisibility();

            // Populate new fields
            document.getElementById('editDateRange').value = w.date_range || '';
            document.getElementById('editTimeGrouping').value = w.time_grouping || 'month';

            // Department filter checkboxes
            const deptIds = w.department_filter ? (typeof w.department_filter === 'string' ? JSON.parse(w.department_filter) : w.department_filter) : [];
            document.querySelectorAll('.dept-checkbox').forEach(cb => {
                cb.checked = deptIds.includes(parseInt(cb.value));
            });

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
            const aggregate_property = document.getElementById('editProperty').value;
            const series_property = document.getElementById('editSeries').value || null;
            const is_status_filterable = document.getElementById('editFilterable').checked ? 1 : 0;
            const date_range = document.getElementById('editDateRange').value || null;
            const time_grouping = TIME_AGGREGATES.includes(aggregate_property)
                ? document.getElementById('editTimeGrouping').value || null
                : null;
            const department_filter = [...document.querySelectorAll('.dept-checkbox:checked')]
                .map(cb => parseInt(cb.value));

            if (!title) {
                showToast('Title is required', 'error');
                return;
            }

            if (TIME_AGGREGATES.includes(aggregate_property) && !time_grouping) {
                showToast('Time grouping is required for time-based aggregates', 'error');
                return;
            }

            try {
                const res = await fetch(API_BASE + 'save_ticket_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id, title, description, chart_type, aggregate_property, series_property,
                        is_status_filterable, date_range, time_grouping,
                        department_filter: department_filter.length > 0 ? department_filter : null
                    })
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
                const res = await fetch(API_BASE + 'save_ticket_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: w.title + ' (Copy)',
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
                const res = await fetch(API_BASE + 'delete_ticket_dashboard_widget.php', {
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
                const res = await fetch(API_BASE + 'add_ticket_dashboard_widget.php', {
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

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeEditPanel();
        });

        init();
    </script>
</body>
</html>
