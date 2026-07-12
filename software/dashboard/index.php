<?php
/**
 * Software - Dashboard
 * Per-analyst customisable widget dashboard with Chart.js charts
 * Features: cog edit, click-to-drill-down, drag-and-drop reorder
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'dashboard';
$path_prefix = '../../';
$translationNamespaces = ['common', 'software'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('software.dashboard.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* Module accent (indigo) — primary button, focus rings, drag target. */
        body { --accent: var(--sw-accent, #5c6bc0); --accent-hover: var(--sw-accent-hover, #3f51b5); }

        .dashboard-page {
            height: calc(100vh - 48px);
            overflow-y: auto;
        }

        .dashboard-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: var(--surface, #fff);
            border-bottom: 1px solid var(--border, #e0e0e0);
        }

        .dashboard-toolbar h2 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .dashboard-toolbar-actions {
            display: flex;
            gap: 8px;
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

        .btn:hover {
            background: var(--surface-hover, #f5f5f5);
            border-color: var(--border, #ccc);
        }

        .btn-primary {
            background: var(--sw-accent, #5c6bc0);
            color: var(--sw-on-accent, #fff);
            border-color: var(--sw-accent, #5c6bc0);
        }

        .btn-primary:hover {
            background: var(--sw-accent-hover, #4a5ab5);
        }

        .btn svg {
            width: 16px;
            height: 16px;
        }

        /* Widget grid */
        .widget-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            padding: 24px;
        }

        .widget-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            transition: box-shadow 0.15s;
        }

        .widget-card:hover {
            box-shadow: 0 2px 8px var(--shadow, rgba(0,0,0,0.08));
        }

        .widget-card.dragging {
            opacity: 0.5;
        }

        .widget-card.drag-over {
            border-color: var(--sw-accent, #5c6bc0);
            border-style: dashed;
        }

        .widget-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 16px 16px 0 16px;
        }

        .widget-header-left {
            flex: 1;
            min-width: 0;
        }

        .widget-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--text, #333);
        }

        .widget-header p {
            margin: 4px 0 0 0;
            font-size: 12px;
            color: var(--text-dim, #888);
        }

        .widget-actions {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-left: 8px;
        }

        .widget-action-btn {
            background: none;
            border: none;
            padding: 4px;
            cursor: pointer;
            color: var(--text-faint, #999);
            border-radius: 4px;
            display: flex;
            align-items: center;
        }

        .widget-action-btn:hover {
            color: var(--text, #333);
            background: var(--surface-hover, #f0f0f0);
        }

        .widget-action-btn svg {
            width: 16px;
            height: 16px;
        }

        .widget-chart {
            padding: 12px 16px 16px 16px;
            flex: 1;
            min-height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .widget-chart canvas {
            max-height: 280px;
        }

        .widget-no-data {
            color: var(--text-faint, #aaa);
            font-size: 13px;
        }

        /* Empty state */
        .dashboard-empty {
            text-align: center;
            padding: 80px 24px;
            color: var(--text-dim, #888);
        }

        .dashboard-empty svg {
            width: 64px;
            height: 64px;
            color: var(--text-faint, #ccc);
            margin-bottom: 16px;
        }

        .dashboard-empty h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: var(--text-muted, #555);
        }

        .dashboard-empty p {
            margin: 0 0 20px 0;
            font-size: 14px;
        }

        /* Edit modal form */
        .edit-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .edit-form-grid .full-width {
            grid-column: 1 / -1;
        }

        .edit-form-grid label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted, #555);
            margin-bottom: 4px;
        }

        .edit-form-grid input,
        .edit-form-grid select,
        .edit-form-grid textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 13px;
            box-sizing: border-box;
        }

        .edit-form-grid textarea {
            resize: vertical;
            min-height: 60px;
        }

        .edit-form-grid .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding-top: 20px;
        }

        .edit-form-grid .checkbox-row input[type="checkbox"] {
            width: auto;
        }

        .edit-form-grid .checkbox-row label {
            margin-bottom: 0;
            font-weight: normal;
        }

        /* Drill-down modal */
        .drilldown-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 0 16px 0;
        }

        .drilldown-count {
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .drilldown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .drilldown-table th {
            text-align: left;
            padding: 8px 12px;
            background: var(--surface-2, #f5f5f5);
            border-bottom: 2px solid var(--border, #e0e0e0);
            font-weight: 600;
            color: var(--text-muted, #555);
        }

        .drilldown-table td {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-soft, #eee);
            color: var(--text, #333);
        }

        .drilldown-table tr:hover td {
            background: var(--surface-hover, #fafafa);
        }

        .drilldown-loading {
            text-align: center;
            padding: 40px;
            color: var(--text-faint, #999);
        }

        @media (max-width: 900px) {
            .widget-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header.php'; ?>

    <div class="dashboard-page">

    <div class="dashboard-toolbar">
        <h2><?php echo htmlspecialchars(t('software.dashboard.heading')); ?></h2>
        <div class="dashboard-toolbar-actions">
            <button class="btn btn-primary" onclick="window.location.href='library.php'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <?php echo htmlspecialchars(t('software.dashboard.add')); ?>
            </button>
        </div>
    </div>

    <div id="widgetGrid" class="widget-grid"></div>

    <div id="emptyState" class="dashboard-empty" style="display: none;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="3" y1="9" x2="21" y2="9"></line>
            <line x1="9" y1="21" x2="9" y2="9"></line>
        </svg>
        <h3><?php echo htmlspecialchars(t('software.dashboard.empty_heading')); ?></h3>
        <p><?php echo t('software.dashboard.empty_body', ['add' => '<strong>' . htmlspecialchars(t('software.dashboard.add')) . '</strong>']); ?></p>
    </div>

    </div><!-- /.dashboard-page -->

    <!-- Widget edit modal -->
    <div class="modal" id="widgetEditModal">
        <div class="modal-content" style="max-width:600px;">
            <div class="modal-header"><?php echo htmlspecialchars(t('software.dashboard.edit_title')); ?></div>
            <div class="modal-body">
                <div class="edit-form-grid">
                    <input type="hidden" id="editId">
                    <div>
                        <label><?php echo htmlspecialchars(t('software.dashboard.field_title')); ?></label>
                        <input type="text" id="editTitle" maxlength="100">
                    </div>
                    <div>
                        <label><?php echo htmlspecialchars(t('software.dashboard.field_chart_type')); ?></label>
                        <select id="editChartType">
                            <option value="bar"><?php echo htmlspecialchars(t('software.dashboard.chart_bar')); ?></option>
                            <option value="doughnut"><?php echo htmlspecialchars(t('software.dashboard.chart_doughnut')); ?></option>
                            <option value="pie"><?php echo htmlspecialchars(t('software.dashboard.chart_pie')); ?></option>
                        </select>
                    </div>
                    <div class="full-width">
                        <label><?php echo htmlspecialchars(t('software.dashboard.field_description')); ?></label>
                        <textarea id="editDescription" maxlength="255"></textarea>
                    </div>
                    <div>
                        <label><?php echo htmlspecialchars(t('software.dashboard.field_type')); ?></label>
                        <select id="editAggProperty" onchange="onEditAggChange()">
                            <option value="version_distribution"><?php echo htmlspecialchars(t('software.dashboard.agg_version')); ?></option>
                            <option value="top_installed"><?php echo htmlspecialchars(t('software.dashboard.agg_top')); ?></option>
                            <option value="publisher_distribution"><?php echo htmlspecialchars(t('software.dashboard.agg_publisher')); ?></option>
                        </select>
                    </div>
                    <div id="editAppGroup">
                        <label><?php echo htmlspecialchars(t('software.dashboard.field_application')); ?></label>
                        <select id="editAppId">
                            <option value=""><?php echo htmlspecialchars(t('software.dashboard.select')); ?></option>
                        </select>
                    </div>
                    <div id="editExcludeGroup" class="checkbox-row" style="display:none;">
                        <input type="checkbox" id="editExcludeComponents" checked>
                        <label for="editExcludeComponents"><?php echo htmlspecialchars(t('software.dashboard.exclude_components')); ?></label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="handleWidgetSave()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button class="btn" onclick="closeWidgetEditModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
            </div>
        </div>
    </div>

    <!-- Drill-down modal -->
    <div class="modal" id="drilldownModal">
        <div class="modal-content" style="max-width:800px;">
            <div class="modal-header" id="drilldownTitle"><?php echo htmlspecialchars(t('software.dashboard.drilldown_title')); ?></div>
            <div class="modal-body">
                <div class="drilldown-toolbar" id="drilldownToolbar" style="display:none;">
                    <span class="drilldown-count" id="drilldownCount"></span>
                    <button class="btn" onclick="exportDrilldownCSV()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <?php echo htmlspecialchars(t('software.dashboard.export')); ?>
                    </button>
                </div>
                <div id="drilldownBody">
                    <div class="drilldown-loading"><?php echo htmlspecialchars(t('software.dashboard.loading')); ?></div>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/chart.min.js"></script>
    <script>
        const API_BASE = '../../api/software/';

        // Dark-mode readability: Chart.js paints to a canvas and can't read our
        // CSS tokens, so set its global text (ticks/legend) + gridline colours
        // from the active palette mode. Series colours (COLORS) are unchanged —
        // they read on both. chartSurface is the pie/doughnut slice-border so
        // slices separate against the card in either mode.
        const swDark = document.documentElement.getAttribute('data-theme-mode') === 'dark';
        const chartSurface = swDark ? '#1e2228' : '#ffffff';
        if (window.Chart) {
            Chart.defaults.color = swDark ? '#aab2bd' : '#666';
            Chart.defaults.borderColor = swDark ? 'rgba(255,255,255,0.10)' : 'rgba(0,0,0,0.08)';
        }

        let dashboardWidgets = [];
        let chartInstances = {};
        let chartMetadata = {};
        let dragSource = null;
        let editingWidgetId = null;
        let allApps = [];
        let drilldownRows = [];
        let drilldownTitle = '';

        const COLORS = [
            '#5c6bc0', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6',
            '#1abc9c', '#e67e22', '#3498db', '#e91e63', '#00bcd4',
            '#8bc34a', '#ff9800', '#673ab7', '#009688', '#ff5722',
            '#607d8b', '#795548', '#cddc39', '#ffc107', '#03a9f4'
        ];

        const AGG_LABELS = {
            version_distribution: window.t('software.dashboard.agg_version'),
            top_installed: window.t('software.dashboard.agg_top'),
            publisher_distribution: window.t('software.dashboard.agg_publisher')
        };

        async function init() {
            try {
                const [appsRes, dashRes] = await Promise.all([
                    fetch(API_BASE + 'get_apps.php').then(r => r.json()).catch(() => ({ success: false })),
                    fetch(API_BASE + 'get_software_dashboard.php').then(r => r.json()).catch(() => ({ success: false }))
                ]);

                if (appsRes.success) {
                    allApps = (appsRes.apps || []).filter(a => !parseInt(a.system_component));
                }

                if (dashRes.success) {
                    dashboardWidgets = dashRes.widgets || [];
                }
            } catch (err) {
                console.error('Dashboard init error:', err);
            }

            populateAppDropdown();
            renderDashboard();
        }

        function populateAppDropdown() {
            const sel = document.getElementById('editAppId');
            sel.innerHTML = '<option value="">' + window.t('software.dashboard.select') + '</option>';
            allApps.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.display_name + (a.publisher ? ' (' + a.publisher + ')' : '');
                sel.appendChild(opt);
            });
        }

        function renderDashboard() {
            const grid = document.getElementById('widgetGrid');
            const empty = document.getElementById('emptyState');

            if (dashboardWidgets.length === 0) {
                grid.style.display = 'none';
                empty.style.display = 'block';
                return;
            }

            grid.style.display = 'grid';
            empty.style.display = 'none';
            grid.innerHTML = '';

            dashboardWidgets.forEach(w => {
                const card = document.createElement('div');
                card.className = 'widget-card';
                card.dataset.widgetId = w.widget_id;
                card.draggable = true;

                const desc = w.description || '';

                card.innerHTML = `
                    <div class="widget-header">
                        <div class="widget-header-left">
                            <h3>${escapeHtml(w.title)}</h3>
                            <p>${escapeHtml(desc)}</p>
                        </div>
                        <div class="widget-actions">
                            <button class="widget-action-btn" onclick="openWidgetEditModal(${w.widget_id})" title="${window.t('software.dashboard.edit_aria')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </button>
                            <button class="widget-action-btn" onclick="removeWidget(${w.widget_id})" title="${window.t('software.dashboard.remove_aria')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>
                    </div>
                    <div class="widget-chart">
                        <canvas id="chart-${w.widget_id}"></canvas>
                    </div>
                `;

                card.addEventListener('dragstart', onDragStart);
                card.addEventListener('dragover', onDragOver);
                card.addEventListener('dragleave', onDragLeave);
                card.addEventListener('drop', onDrop);
                card.addEventListener('dragend', onDragEnd);

                grid.appendChild(card);

                loadWidgetData(w.widget_id, w.chart_type, w.aggregate_property);
            });
        }

        async function loadWidgetData(widgetId, chartType, aggProp) {
            try {
                const res = await fetch(API_BASE + `get_software_widget_data.php?widget_id=${widgetId}`);
                const data = await res.json();

                if (!data.success || !data.labels || data.labels.length === 0) {
                    const chartDiv = document.querySelector(`#chart-${widgetId}`)?.parentElement;
                    if (chartDiv) chartDiv.innerHTML = '<span class="widget-no-data">' + window.t('software.dashboard.no_data') + '</span>';
                    return;
                }

                chartMetadata[widgetId] = {
                    app_id: data.app_id || null,
                    app_name: data.app_name || '',
                    app_ids: data.app_ids || [],
                    aggregate_property: aggProp
                };

                renderChart(widgetId, chartType, data.labels, data.values);
            } catch (err) {
                console.error('Failed to load widget data:', err);
            }
        }

        function renderChart(widgetId, chartType, labels, values) {
            const canvasId = `chart-${widgetId}`;
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            if (chartInstances[widgetId]) {
                chartInstances[widgetId].destroy();
            }

            const ctx = canvas.getContext('2d');
            const bgColors = labels.map((_, i) => COLORS[i % COLORS.length]);

            const config = {
                type: chartType === 'doughnut' || chartType === 'pie' ? chartType : 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: bgColors,
                        borderColor: chartType === 'bar' ? bgColors : chartSurface,
                        borderWidth: chartType === 'bar' ? 0 : 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: function(event, elements) {
                        if (elements.length === 0) return;
                        const index = elements[0].index;
                        const label = labels[index];
                        handleChartClick(widgetId, label, index);
                    },
                    onHover: function(event, elements) {
                        event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: {
                            display: chartType !== 'bar',
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                padding: 8,
                                font: { size: 11 }
                            }
                        }
                    }
                }
            };

            if (chartType === 'bar') {
                config.options.scales = {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            font: { size: 11 }
                        }
                    }
                };
            }

            chartInstances[widgetId] = new Chart(ctx, config);
        }

        // Click-to-drill-down
        async function handleChartClick(widgetId, label, index) {
            const meta = chartMetadata[widgetId];
            if (!meta) return;

            const aggProp = meta.aggregate_property;
            let url = API_BASE + 'get_software_drilldown.php?widget_id=' + widgetId;
            let title = '';

            if (aggProp === 'version_distribution') {
                url += '&app_id=' + meta.app_id + '&version=' + encodeURIComponent(label);
                title = meta.app_name + ' — ' + label;
            } else if (aggProp === 'top_installed') {
                url += '&app_id=' + (meta.app_ids[index] || '');
                title = label;
            } else if (aggProp === 'publisher_distribution') {
                url += '&publisher=' + encodeURIComponent(label);
                title = window.t('software.dashboard.publisher_prefix', { name: label });
            }

            openDrilldownModal(title);

            try {
                const res = await fetch(url).then(r => r.json());
                if (res.success) {
                    drilldownTitle = title;
                    renderDrilldownData(res);
                } else {
                    document.getElementById('drilldownBody').innerHTML = '<p style="color:var(--text-faint,#999);">' + window.t('software.dashboard.no_data_found') + '</p>';
                }
            } catch (err) {
                document.getElementById('drilldownBody').innerHTML = '<p style="color:var(--danger-accent,#c00);">' + window.t('software.dashboard.load_data_failed') + '</p>';
            }
        }

        function openDrilldownModal(title) {
            document.getElementById('drilldownTitle').textContent = title;
            document.getElementById('drilldownToolbar').style.display = 'none';
            document.getElementById('drilldownBody').innerHTML = '<div class="drilldown-loading">' + window.t('software.dashboard.loading') + '</div>';
            document.getElementById('drilldownModal').classList.add('active');
        }

        function closeDrilldownModal() {
            document.getElementById('drilldownModal').classList.remove('active');
        }

        function renderDrilldownData(res) {
            const body = document.getElementById('drilldownBody');
            const toolbar = document.getElementById('drilldownToolbar');
            drilldownRows = res.rows || [];

            if (drilldownRows.length === 0) {
                body.innerHTML = '<p style="color:var(--text-faint,#999);">' + window.t('software.dashboard.no_records') + '</p>';
                toolbar.style.display = 'none';
                return;
            }

            toolbar.style.display = 'flex';

            if (res.type === 'publisher') {
                document.getElementById('drilldownCount').textContent = window.t(drilldownRows.length !== 1 ? 'software.dashboard.count_applications' : 'software.dashboard.count_application', { count: drilldownRows.length });
                let html = '<table class="drilldown-table"><thead><tr><th>' + window.t('software.dashboard.col_application') + '</th><th>' + window.t('software.dashboard.col_install_count') + '</th></tr></thead><tbody>';
                drilldownRows.forEach(r => {
                    html += `<tr><td>${escapeHtml(r.app_name)}</td><td>${r.install_count}</td></tr>`;
                });
                html += '</tbody></table>';
                body.innerHTML = html;
            } else {
                document.getElementById('drilldownCount').textContent = window.t(drilldownRows.length !== 1 ? 'software.dashboard.count_machines' : 'software.dashboard.count_machine', { count: drilldownRows.length });
                let html = '<table class="drilldown-table"><thead><tr><th>' + window.t('software.dashboard.col_hostname') + '</th><th>' + window.t('software.dashboard.col_version') + '</th><th>' + window.t('software.dashboard.col_install_date') + '</th><th>' + window.t('software.dashboard.col_last_seen') + '</th></tr></thead><tbody>';
                drilldownRows.forEach(r => {
                    html += `<tr><td>${escapeHtml(r.hostname)}</td><td>${escapeHtml(r.display_version || '')}</td><td>${escapeHtml(r.install_date || '')}</td><td>${escapeHtml(r.last_seen || '')}</td></tr>`;
                });
                html += '</tbody></table>';
                body.innerHTML = html;
            }
        }

        function exportDrilldownCSV() {
            if (drilldownRows.length === 0) return;
            const csvCell = v => '"' + String(v || '').replace(/"/g, '""') + '"';
            let csv;

            if (drilldownRows[0].app_name !== undefined) {
                csv = csvCell(window.t('software.dashboard.col_application')) + ',' + csvCell(window.t('software.dashboard.col_install_count')) + '\n';
                drilldownRows.forEach(r => { csv += csvCell(r.app_name) + ',' + r.install_count + '\n'; });
            } else {
                csv = [
                    window.t('software.dashboard.col_hostname'),
                    window.t('software.dashboard.col_version'),
                    window.t('software.dashboard.col_install_date'),
                    window.t('software.dashboard.col_last_seen')
                ].map(csvCell).join(',') + '\n';
                drilldownRows.forEach(r => { csv += csvCell(r.hostname) + ',' + csvCell(r.display_version) + ',' + csvCell(r.install_date) + ',' + csvCell(r.last_seen) + '\n'; });
            }

            const blob = new Blob([csv], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = (drilldownTitle || 'export').replace(/[^a-zA-Z0-9 _-]/g, '') + '.csv';
            a.click();
        }

        // Widget edit modal
        function openWidgetEditModal(widgetId) {
            const widget = dashboardWidgets.find(w => w.widget_id == widgetId);
            if (!widget) return;
            editingWidgetId = widgetId;

            document.getElementById('editId').value = widget.widget_id;
            document.getElementById('editTitle').value = widget.title;
            document.getElementById('editDescription').value = widget.description || '';
            document.getElementById('editChartType').value = widget.chart_type;
            document.getElementById('editAggProperty').value = widget.aggregate_property;
            document.getElementById('editAppId').value = widget.app_id || '';
            document.getElementById('editExcludeComponents').checked = parseInt(widget.exclude_system_components) === 1;

            onEditAggChange();
            document.getElementById('widgetEditModal').classList.add('active');
        }

        function closeWidgetEditModal() {
            document.getElementById('widgetEditModal').classList.remove('active');
            editingWidgetId = null;
        }

        function onEditAggChange() {
            const val = document.getElementById('editAggProperty').value;
            document.getElementById('editAppGroup').style.display = val === 'version_distribution' ? '' : 'none';
            document.getElementById('editExcludeGroup').style.display = val !== 'version_distribution' ? '' : 'none';
        }

        async function handleWidgetSave() {
            const title = document.getElementById('editTitle').value.trim();
            if (!title) { showToast(window.t('software.dashboard.title_required'), 'error'); return; }

            const aggProp = document.getElementById('editAggProperty').value;
            const appId = document.getElementById('editAppId').value;

            if (aggProp === 'version_distribution' && !appId) {
                showToast(window.t('software.dashboard.app_required'), 'error');
                return;
            }

            const payload = {
                id: document.getElementById('editId').value,
                title: title,
                description: document.getElementById('editDescription').value.trim(),
                chart_type: document.getElementById('editChartType').value,
                aggregate_property: aggProp,
                app_id: aggProp === 'version_distribution' ? appId : null,
                exclude_system_components: document.getElementById('editExcludeComponents').checked ? 1 : 0
            };

            try {
                const res = await fetch(API_BASE + 'save_software_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || window.t('software.dashboard.save_failed'), 'error');
                    return;
                }

                const w = dashboardWidgets.find(w => w.widget_id == editingWidgetId);
                if (w) {
                    w.title = data.widget.title;
                    w.description = data.widget.description;
                    w.chart_type = data.widget.chart_type;
                    w.aggregate_property = data.widget.aggregate_property;
                    w.app_id = data.widget.app_id;
                    w.app_name = data.widget.app_name;
                    w.exclude_system_components = data.widget.exclude_system_components;
                }

                closeWidgetEditModal();
                renderDashboard();
                showToast(window.t('software.dashboard.widget_updated'), 'success');
            } catch (err) {
                showToast(window.t('software.dashboard.save_failed'), 'error');
            }
        }

        async function removeWidget(widgetId) {
            try {
                const res = await fetch(API_BASE + 'remove_software_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ widget_id: widgetId })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || window.t('software.dashboard.remove_failed'), 'error');
                    return;
                }

                if (chartInstances[widgetId]) {
                    chartInstances[widgetId].destroy();
                    delete chartInstances[widgetId];
                }

                dashboardWidgets = dashboardWidgets.filter(w => w.widget_id != widgetId);
                renderDashboard();
                showToast(window.t('software.dashboard.widget_removed'), 'success');
            } catch (err) {
                showToast(window.t('software.dashboard.remove_failed'), 'error');
            }
        }

        // Drag & Drop reordering
        function onDragStart(e) {
            dragSource = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        }

        function onDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const card = e.target.closest('.widget-card');
            if (card && card !== dragSource) {
                card.classList.add('drag-over');
            }
        }

        function onDragLeave(e) {
            const card = e.target.closest('.widget-card');
            if (card) card.classList.remove('drag-over');
        }

        function onDrop(e) {
            e.preventDefault();
            const target = e.target.closest('.widget-card');
            if (!target || target === dragSource) return;

            target.classList.remove('drag-over');

            const grid = document.getElementById('widgetGrid');
            const cards = [...grid.querySelectorAll('.widget-card')];
            const fromIdx = cards.indexOf(dragSource);
            const toIdx = cards.indexOf(target);

            if (fromIdx < toIdx) {
                grid.insertBefore(dragSource, target.nextSibling);
            } else {
                grid.insertBefore(dragSource, target);
            }

            const newOrder = [...grid.querySelectorAll('.widget-card')].map(c => parseInt(c.dataset.widgetId));
            saveOrder(newOrder);

            const reordered = newOrder.map(id => dashboardWidgets.find(w => parseInt(w.widget_id) === id)).filter(Boolean);
            dashboardWidgets = reordered;
        }

        function onDragEnd() {
            this.classList.remove('dragging');
            document.querySelectorAll('.widget-card').forEach(c => c.classList.remove('drag-over'));
        }

        async function saveOrder(order) {
            try {
                await fetch(API_BASE + 'reorder_software_dashboard_widgets.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order })
                });
            } catch (err) {
                console.error('Failed to save order:', err);
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeWidgetEditModal();
                closeDrilldownModal();
            }
        });

        document.getElementById('widgetEditModal').addEventListener('click', function(e) {
            if (e.target === this) closeWidgetEditModal();
        });

        document.getElementById('drilldownModal').addEventListener('click', function(e) {
            if (e.target === this) closeDrilldownModal();
        });

        init();
    </script>
</body>
</html>
