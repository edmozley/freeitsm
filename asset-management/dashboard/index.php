<?php
/**
 * Asset Management - Dashboard
 * Per-analyst customisable widget dashboard with Chart.js charts
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
    <title>Service Desk - Asset Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7"></script>
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

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: #fff;
            border-radius: 10px;
            width: 560px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 16px;
            color: #333;
        }

        .modal-close {
            background: none;
            border: none;
            padding: 4px;
            cursor: pointer;
            color: #999;
            border-radius: 4px;
        }

        .modal-close:hover {
            color: #333;
            background: #f0f0f0;
        }

        .modal-close svg {
            width: 20px;
            height: 20px;
        }

        .modal-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px 20px;
        }

        .widget-library-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .widget-library-item:last-child {
            border-bottom: none;
        }

        .widget-library-info {
            flex: 1;
            min-width: 0;
        }

        .widget-library-info h4 {
            margin: 0;
            font-size: 14px;
            color: #333;
        }

        .widget-library-info p {
            margin: 2px 0 0 0;
            font-size: 12px;
            color: #888;
        }

        .widget-library-info .widget-type-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            background: #f0f0f0;
            border-radius: 10px;
            font-size: 11px;
            color: #666;
        }

        .widget-library-item .btn {
            flex-shrink: 0;
            margin-left: 12px;
            padding: 6px 12px;
            font-size: 12px;
        }

        .widget-library-item .btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
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
            <button class="btn btn-primary" onclick="openWidgetLibrary()">
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
        <button class="btn btn-primary" onclick="openWidgetLibrary()">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add
        </button>
    </div>

    <!-- Widget Library Modal -->
    <div id="libraryModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3>Widget Library</h3>
                <button class="modal-close" onclick="closeWidgetLibrary()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body" id="libraryList"></div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/assets/';
        let dashboardWidgets = [];
        let widgetLibrary = [];
        let statusTypes = [];
        let chartInstances = {};
        let dragSource = null;

        // Chart.js color palette
        const COLORS = [
            '#0078d4', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6',
            '#1abc9c', '#e67e22', '#3498db', '#e91e63', '#00bcd4',
            '#8bc34a', '#ff9800', '#673ab7', '#009688', '#ff5722',
            '#607d8b', '#795548', '#cddc39', '#ffc107', '#03a9f4'
        ];

        async function init() {
            try {
                // Load status types and dashboard in parallel
                const [statusRes, dashRes] = await Promise.all([
                    fetch(API_BASE + 'get_asset_status_types.php').then(r => r.json()).catch(() => ({ success: false })),
                    fetch(API_BASE + 'get_dashboard.php').then(r => r.json()).catch(() => ({ success: false }))
                ]);

                if (statusRes.success) {
                    statusTypes = statusRes.asset_status_types || [];
                }

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

            dashboardWidgets.forEach((w, idx) => {
                const card = document.createElement('div');
                card.className = 'widget-card';
                card.dataset.widgetId = w.widget_id;
                card.draggable = true;

                const filterableInt = parseInt(w.is_status_filterable);
                const filterHtml = filterableInt ? `
                    <div class="widget-filter">
                        <select onchange="onStatusFilterChange(${w.widget_id}, this.value)" data-widget-filter="${w.widget_id}">
                            <option value="">All statuses</option>
                            ${statusTypes.map(s => `<option value="${s.id}" ${w.status_filter_id == s.id ? 'selected' : ''}>${s.name}</option>`).join('')}
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

                // Drag events
                card.addEventListener('dragstart', onDragStart);
                card.addEventListener('dragover', onDragOver);
                card.addEventListener('dragleave', onDragLeave);
                card.addEventListener('drop', onDrop);
                card.addEventListener('dragend', onDragEnd);

                grid.appendChild(card);

                // Load chart data
                const statusId = w.status_filter_id || '';
                loadWidgetData(w.widget_id, w.chart_type, statusId);
            });
        }

        async function loadWidgetData(widgetId, chartType, statusId) {
            try {
                let url = API_BASE + `get_widget_data.php?widget_id=${widgetId}`;
                if (statusId) url += `&status_id=${statusId}`;

                const res = await fetch(url);
                const data = await res.json();

                if (!data.success) return;

                renderChart(widgetId, chartType, data.labels, data.values);
            } catch (err) {
                console.error('Failed to load widget data:', err);
            }
        }

        function renderChart(widgetId, chartType, labels, values) {
            const canvasId = `chart-${widgetId}`;
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            // Destroy existing chart
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
                        borderColor: chartType === 'bar' ? bgColors : '#fff',
                        borderWidth: chartType === 'bar' ? 0 : 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
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
                config.options.indexAxis = 'x';
            }

            chartInstances[widgetId] = new Chart(ctx, config);
        }

        async function onStatusFilterChange(widgetId, statusId) {
            const widget = dashboardWidgets.find(w => w.widget_id == widgetId);
            if (!widget) return;

            loadWidgetData(widgetId, widget.chart_type, statusId);
        }

        // Widget Library Modal
        async function openWidgetLibrary() {
            const modal = document.getElementById('libraryModal');
            const list = document.getElementById('libraryList');

            try {
                // Load library if not cached
                if (widgetLibrary.length === 0) {
                    const res = await fetch(API_BASE + 'get_widget_library.php');
                    const data = await res.json();
                    if (data.success) {
                        widgetLibrary = data.widgets;
                    }
                }
            } catch (err) {
                console.error('Failed to load widget library:', err);
            }

            const addedIds = new Set(dashboardWidgets.map(w => parseInt(w.widget_id)));

            list.innerHTML = widgetLibrary.map(w => {
                const isAdded = addedIds.has(parseInt(w.id));
                return `
                    <div class="widget-library-item">
                        <div class="widget-library-info">
                            <h4>${escapeHtml(w.title)}</h4>
                            <p>${escapeHtml(w.description || '')}</p>
                            <span class="widget-type-badge">${w.chart_type}</span>
                        </div>
                        <button class="btn" onclick="addWidget(${w.id})" ${isAdded ? 'disabled' : ''}>
                            ${isAdded ? 'Added' : 'Add'}
                        </button>
                    </div>
                `;
            }).join('');

            modal.classList.add('active');
        }

        function closeWidgetLibrary() {
            document.getElementById('libraryModal').classList.remove('active');
        }

        async function addWidget(widgetId) {
            try {
                const res = await fetch(API_BASE + 'add_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ widget_id: widgetId })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'Failed to add widget', 'error');
                    return;
                }

                // Re-fetch dashboard to get full widget data
                const dashRes = await fetch(API_BASE + 'get_dashboard.php');
                const dashData = await dashRes.json();
                if (dashData.success) {
                    dashboardWidgets = dashData.widgets;
                }

                renderDashboard();
                closeWidgetLibrary();
                showToast('Widget added', 'success');
            } catch (err) {
                showToast('Failed to add widget', 'error');
            }
        }

        async function removeWidget(widgetId) {
            try {
                const res = await fetch(API_BASE + 'remove_dashboard_widget.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ widget_id: widgetId })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'Failed to remove widget', 'error');
                    return;
                }

                // Clean up chart instance
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

            // Reorder in DOM
            if (fromIdx < toIdx) {
                grid.insertBefore(dragSource, target.nextSibling);
            } else {
                grid.insertBefore(dragSource, target);
            }

            // Save new order
            const newOrder = [...grid.querySelectorAll('.widget-card')].map(c => parseInt(c.dataset.widgetId));
            saveOrder(newOrder);

            // Update local array
            const reordered = newOrder.map(id => dashboardWidgets.find(w => parseInt(w.widget_id) === id)).filter(Boolean);
            dashboardWidgets = reordered;
        }

        function onDragEnd() {
            this.classList.remove('dragging');
            document.querySelectorAll('.widget-card').forEach(c => c.classList.remove('drag-over'));
        }

        async function saveOrder(order) {
            try {
                await fetch(API_BASE + 'reorder_dashboard_widgets.php', {
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

        // Close modal on overlay click
        document.getElementById('libraryModal').addEventListener('click', function(e) {
            if (e.target === this) closeWidgetLibrary();
        });

        // Close modal on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeWidgetLibrary();
        });

        // Init
        init();
    </script>
</body>
</html>
