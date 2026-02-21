<?php
/**
 * Tickets - Dashboard
 * Per-analyst customisable widget dashboard with Chart.js charts
 * Supports single-series (bar/pie/doughnut/line) and multi-series (stacked bar, multi-line)
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
    <title>Service Desk - Ticket Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <style>
        body {
            overflow: auto;
            height: auto;
        }

        .dashboard-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
        }

        .dashboard-toolbar h2 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .dashboard-toolbar-actions {
            display: flex;
            gap: 8px;
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

        .btn:hover {
            background: #f5f5f5;
            border-color: #ccc;
        }

        .btn-primary {
            background: #0078d4;
            color: #fff;
            border-color: #0078d4;
        }

        .btn-primary:hover {
            background: #106ebe;
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
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            transition: box-shadow 0.15s;
        }

        .widget-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .widget-card.dragging {
            opacity: 0.5;
        }

        .widget-card.drag-over {
            border-color: #0078d4;
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
            color: #333;
        }

        .widget-header p {
            margin: 4px 0 0 0;
            font-size: 12px;
            color: #888;
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
            color: #999;
            border-radius: 4px;
            display: flex;
            align-items: center;
        }

        .widget-action-btn:hover {
            color: #333;
            background: #f0f0f0;
        }

        .widget-action-btn svg {
            width: 16px;
            height: 16px;
        }

        .widget-filter {
            padding: 8px 16px;
        }

        .widget-filter select {
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            color: #555;
            background: #fff;
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

        /* Empty state */
        .dashboard-empty {
            text-align: center;
            padding: 80px 24px;
            color: #888;
        }

        .dashboard-empty svg {
            width: 64px;
            height: 64px;
            color: #ccc;
            margin-bottom: 16px;
        }

        .dashboard-empty h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: #555;
        }

        .dashboard-empty p {
            margin: 0 0 20px 0;
            font-size: 14px;
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

    <div class="dashboard-toolbar">
        <h2>Dashboard</h2>
        <div class="dashboard-toolbar-actions">
            <button class="btn btn-primary" onclick="window.location.href='library.php'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Add
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
        <h3>No widgets yet</h3>
        <p>Add widgets from the library to build your dashboard</p>
        <button class="btn btn-primary" onclick="window.location.href='library.php'">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add
        </button>
    </div>

    <script src="../../assets/js/chart.min.js"></script>
    <script>
        const API_BASE = '../../api/tickets/';
        let dashboardWidgets = [];
        let chartInstances = {};
        let dragSource = null;

        // Ticket statuses for filter dropdowns
        const STATUSES = ['Open', 'In Progress', 'On Hold', 'Closed'];

        // Chart.js color palette
        const COLORS = [
            '#0078d4', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6',
            '#1abc9c', '#e67e22', '#3498db', '#e91e63', '#00bcd4',
            '#8bc34a', '#ff9800', '#673ab7', '#009688', '#ff5722',
            '#607d8b', '#795548', '#cddc39', '#ffc107', '#03a9f4'
        ];

        // Fixed colours for well-known series values
        const SERIES_COLORS = {
            'Open': '#0078d4',
            'In Progress': '#f39c12',
            'On Hold': '#9b59b6',
            'Closed': '#2ecc71',
            'Awaiting Response': '#e67e22',
            'Critical': '#d13438',
            'High': '#e74c3c',
            'Normal': '#f39c12',
            'Low': '#2ecc71',
            'Created': '#0078d4',
            'Closed': '#2ecc71'
        };

        async function init() {
            try {
                const dashRes = await fetch(API_BASE + 'get_ticket_dashboard.php').then(r => r.json()).catch(() => ({ success: false }));

                if (dashRes.success) {
                    dashboardWidgets = dashRes.widgets || [];
                }
            } catch (err) {
                console.error('Dashboard init error:', err);
            }

            renderDashboard();
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

            dashboardWidgets.forEach((w) => {
                const card = document.createElement('div');
                card.className = 'widget-card';
                card.dataset.widgetId = w.widget_id;
                card.draggable = true;

                const filterable = parseInt(w.is_status_filterable) && w.series_property !== 'status';
                const filterHtml = filterable ? `
                    <div class="widget-filter">
                        <select onchange="onStatusFilterChange(${w.widget_id}, this.value)" data-widget-filter="${w.widget_id}">
                            <option value="">All statuses</option>
                            ${STATUSES.map(s => `<option value="${s}" ${w.status_filter === s ? 'selected' : ''}>${s}</option>`).join('')}
                        </select>
                    </div>
                ` : '';

                card.innerHTML = `
                    <div class="widget-header">
                        <div class="widget-header-left">
                            <h3>${escapeHtml(w.title)}</h3>
                            <p>${escapeHtml(w.description || '')}</p>
                        </div>
                        <div class="widget-actions">
                            <button class="widget-action-btn" onclick="removeWidget(${w.widget_id})" title="Remove">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>
                    </div>
                    ${filterHtml}
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

                loadWidgetData(w.widget_id, w.chart_type, w.series_property, w.aggregate_property, w.status_filter || '');
            });
        }

        async function loadWidgetData(widgetId, chartType, seriesProp, aggProp, status) {
            try {
                let url = API_BASE + `get_ticket_widget_data.php?widget_id=${widgetId}`;
                if (status) url += `&status=${encodeURIComponent(status)}`;

                const res = await fetch(url);
                const data = await res.json();

                if (!data.success) return;

                if (data.series) {
                    renderMultiSeriesChart(widgetId, chartType, aggProp, data.labels, data.series);
                } else {
                    renderChart(widgetId, chartType, data.labels, data.values);
                }
            } catch (err) {
                console.error('Failed to load widget data:', err);
            }
        }

        function renderChart(widgetId, chartType, labels, values) {
            const canvas = document.getElementById(`chart-${widgetId}`);
            if (!canvas) return;

            if (chartInstances[widgetId]) {
                chartInstances[widgetId].destroy();
            }

            const ctx = canvas.getContext('2d');
            const bgColors = labels.map((_, i) => COLORS[i % COLORS.length]);
            const isLine = chartType === 'line';
            const isPieType = chartType === 'doughnut' || chartType === 'pie';

            const config = {
                type: isPieType ? chartType : (isLine ? 'line' : 'bar'),
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: isPieType ? bgColors : (isLine ? 'rgba(0,120,212,0.1)' : bgColors),
                        borderColor: isPieType ? '#fff' : (isLine ? '#0078d4' : bgColors),
                        borderWidth: isPieType ? 2 : (isLine ? 2 : 0),
                        tension: isLine ? 0.3 : undefined,
                        fill: isLine ? true : undefined,
                        pointRadius: isLine ? 3 : undefined,
                        pointBackgroundColor: isLine ? '#0078d4' : undefined
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: isPieType,
                            position: 'right',
                            labels: { boxWidth: 12, padding: 8, font: { size: 11 } }
                        }
                    }
                }
            };

            if (!isPieType) {
                config.options.scales = {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { ticks: { maxRotation: 45, font: { size: 11 } } }
                };
            }

            chartInstances[widgetId] = new Chart(ctx, config);
        }

        function renderMultiSeriesChart(widgetId, chartType, aggProp, labels, series) {
            const canvas = document.getElementById(`chart-${widgetId}`);
            if (!canvas) return;

            if (chartInstances[widgetId]) {
                chartInstances[widgetId].destroy();
            }

            const ctx = canvas.getContext('2d');
            const isLine = chartType === 'line';
            const isCreatedVsClosed = aggProp && aggProp.startsWith('created_vs_closed');
            const stacked = !isCreatedVsClosed && !isLine;

            const datasets = series.map((s, i) => {
                const color = SERIES_COLORS[s.label] || COLORS[i % COLORS.length];

                if (isLine) {
                    return {
                        label: s.label,
                        data: s.values,
                        borderColor: color,
                        backgroundColor: hexToRgba(color, 0.1),
                        borderWidth: 2,
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3,
                        pointBackgroundColor: color
                    };
                } else {
                    return {
                        label: s.label,
                        data: s.values,
                        backgroundColor: color,
                        borderWidth: 0
                    };
                }
            });

            const config = {
                type: isLine ? 'line' : 'bar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: { boxWidth: 12, padding: 10, font: { size: 11 } }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            stacked: stacked,
                            ticks: { precision: 0 }
                        },
                        x: {
                            stacked: stacked,
                            ticks: { maxRotation: 45, font: { size: 11 } }
                        }
                    }
                }
            };

            chartInstances[widgetId] = new Chart(ctx, config);
        }

        function hexToRgba(hex, alpha) {
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);
            return `rgba(${r},${g},${b},${alpha})`;
        }

        async function onStatusFilterChange(widgetId, status) {
            const widget = dashboardWidgets.find(w => w.widget_id == widgetId);
            if (!widget) return;
            loadWidgetData(widgetId, widget.chart_type, widget.series_property, widget.aggregate_property, status);
        }

        async function removeWidget(widgetId) {
            try {
                const res = await fetch(API_BASE + 'remove_ticket_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ widget_id: widgetId })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'Failed to remove widget', 'error');
                    return;
                }

                if (chartInstances[widgetId]) {
                    chartInstances[widgetId].destroy();
                    delete chartInstances[widgetId];
                }

                dashboardWidgets = dashboardWidgets.filter(w => w.widget_id != widgetId);
                renderDashboard();
                showToast('Widget removed', 'success');
            } catch (err) {
                showToast('Failed to remove widget', 'error');
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
                await fetch(API_BASE + 'reorder_ticket_dashboard_widgets.php', {
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

        init();
    </script>
</body>
</html>
