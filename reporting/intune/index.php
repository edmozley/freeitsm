<?php
/**
 * Reporting - Intune Dashboard
 *
 * Curated dashboard of Intune device aggregations: KPI strip plus an
 * eight-widget chart grid covering compliance, OS, owner type,
 * manufacturer, OS version, enrolment trend, last sync, and encryption.
 *
 * All data fetched from a single call to api/intune/dashboard_data.php.
 */
session_start();
require_once '../../config.php';

$current_page = 'intune';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Intune Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <style>
        body { overflow: auto; height: auto; }

        .dashboard-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; background: #fff; border-bottom: 1px solid #e0e0e0;
        }
        .dashboard-toolbar h2 { margin: 0; font-size: 18px; color: #333; }
        .dashboard-toolbar .last-sync {
            font-size: 12px; color: #888; margin-left: 16px;
        }
        .dashboard-toolbar-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; border: 1px solid #ddd; border-radius: 6px;
            background: #fff; color: #333; font-size: 13px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s; text-decoration: none;
        }
        .btn:hover { background: #f5f5f5; border-color: #ccc; }
        .btn-primary { background: #ca5010; color: #fff; border-color: #ca5010; }
        .btn-primary:hover { background: #b34810; }
        .btn svg { width: 16px; height: 16px; }

        /* KPI strip */
        .kpi-strip {
            display: grid; grid-template-columns: repeat(5, 1fr);
            gap: 16px; padding: 24px 24px 0 24px;
        }
        .kpi-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 18px 20px;
        }
        .kpi-label {
            font-size: 11px; color: #888; text-transform: uppercase;
            letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;
        }
        .kpi-value {
            font-size: 28px; color: #333; font-weight: 600;
            line-height: 1.1;
        }
        .kpi-sub {
            font-size: 11px; color: #888; margin-top: 4px;
        }
        .kpi-card.warn .kpi-value { color: #ca5010; }
        .kpi-card.bad  .kpi-value { color: #d13438; }
        .kpi-card.good .kpi-value { color: #107c10; }

        /* Widget grid */
        .widget-grid {
            display: grid; grid-template-columns: repeat(2, 1fr);
            gap: 20px; padding: 24px;
        }
        .widget-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            display: flex; flex-direction: column;
            transition: box-shadow 0.15s;
        }
        .widget-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .widget-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            padding: 16px 16px 0 16px;
        }
        .widget-header h3 {
            margin: 0; font-size: 14px; font-weight: 600; color: #333;
        }
        .widget-header p {
            margin: 4px 0 0 0; font-size: 12px; color: #888;
        }
        .widget-chart {
            padding: 12px 16px 16px 16px;
            flex: 1; min-height: 260px;
            display: flex; align-items: center; justify-content: center;
        }
        .widget-chart canvas { max-height: 290px; }

        .loading-state { text-align: center; padding: 60px 20px; color: #888; font-size: 13px; }
        .empty-banner {
            background: #fff7e6; border: 1px solid #ffd591; border-radius: 8px;
            padding: 16px 20px; margin: 24px; color: #874d00; font-size: 13px;
        }
        .empty-banner strong { color: #874d00; }

        @media (max-width: 1100px) {
            .kpi-strip { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 900px) {
            .widget-grid { grid-template-columns: 1fr; }
            .kpi-strip { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header.php'; ?>

    <div class="dashboard-toolbar">
        <div style="display:flex; align-items:baseline;">
            <h2>Intune Dashboard</h2>
            <span class="last-sync" id="lastSyncInfo">Loading&hellip;</span>
        </div>
        <div class="dashboard-toolbar-actions">
            <button class="btn" onclick="loadDashboard()" title="Refresh data">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                Refresh
            </button>
        </div>
    </div>

    <div id="kpiStrip" class="kpi-strip"></div>

    <div id="dashboardBody">
        <div class="loading-state" id="loadingState">Loading Intune data&hellip;</div>
    </div>

    <script src="../../assets/js/chart.min.js"></script>
    <script>
        const API_BASE = '../../api/intune/';
        const chartInstances = {};

        // Stable colours for known compliance/owner states
        const STATE_COLORS = {
            'Compliant':         '#107c10',
            'Noncompliant':      '#d13438',
            'In Grace Period':   '#ffaa44',
            'Config Manager':    '#0078d4',
            'Conflict':          '#e74c3c',
            'Error':             '#d13438',
            'Unknown':           '#999',
            'Company':           '#0078d4',
            'Personal':          '#9b59b6',
            'Encrypted':         '#107c10',
            'Not encrypted':     '#d13438',
        };

        // Generic palette for everything else
        const PALETTE = [
            '#0078d4', '#ca5010', '#107c10', '#9b59b6', '#1abc9c',
            '#e67e22', '#3498db', '#e91e63', '#00bcd4', '#8bc34a',
            '#ff9800', '#673ab7', '#009688', '#ff5722', '#607d8b'
        ];

        function colorFor(label, fallbackIdx) {
            if (STATE_COLORS[label]) return STATE_COLORS[label];
            return PALETTE[fallbackIdx % PALETTE.length];
        }

        function escapeHtml(text) {
            if (text == null) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }

        function formatDate(dt) {
            if (!dt) return null;
            const d = new Date(dt.replace(' ', 'T') + 'Z');
            return d.toLocaleString('en-GB', {
                day: '2-digit', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
        }

        async function loadDashboard() {
            const body = document.getElementById('dashboardBody');
            body.innerHTML = '<div class="loading-state">Loading Intune data&hellip;</div>';
            document.getElementById('kpiStrip').innerHTML = '';

            try {
                const res = await fetch(API_BASE + 'dashboard_data.php');
                const data = await res.json();
                if (!data.success) {
                    body.innerHTML = `<div class="empty-banner"><strong>Error:</strong> ${escapeHtml(data.error)}</div>`;
                    return;
                }

                renderLastSync(data.last_sync_job);
                renderKpiStrip(data.kpi);

                if (data.kpi.total_devices === 0) {
                    body.innerHTML = `<div class="empty-banner">
                        <strong>No Intune devices found.</strong> Run an Intune sync from the Assets module to import devices, then come back here.
                    </div>`;
                    return;
                }

                renderGrid();
                renderCharts(data.charts);
            } catch (err) {
                body.innerHTML = `<div class="empty-banner"><strong>Failed to load dashboard:</strong> ${escapeHtml(err.message)}</div>`;
            }
        }

        function renderLastSync(job) {
            const span = document.getElementById('lastSyncInfo');
            if (!job) {
                span.textContent = '';
                return;
            }
            const when = formatDate(job.finished_datetime || job.started_datetime);
            const status = job.status || '';
            span.innerHTML = `Last sync: ${escapeHtml(when || '?')}${status === 'success' ? '' : ' &middot; <span style="color:#ca5010;">' + escapeHtml(status) + '</span>'}`;
        }

        function renderKpiStrip(kpi) {
            const strip = document.getElementById('kpiStrip');

            // Tone the cards based on thresholds
            const compliantTone  = kpi.compliant_pct >= 90 ? 'good' : (kpi.compliant_pct >= 70 ? '' : 'bad');
            const encryptedTone  = kpi.encrypted_pct >= 90 ? 'good' : (kpi.encrypted_pct >= 70 ? '' : 'bad');
            const staleTone      = kpi.stale_count === 0 ? 'good' : (kpi.stale_count > 50 ? 'bad' : 'warn');

            strip.innerHTML = `
                <div class="kpi-card">
                    <div class="kpi-label">Total Devices</div>
                    <div class="kpi-value">${kpi.total_devices.toLocaleString('en-GB')}</div>
                    <div class="kpi-sub">All managed devices</div>
                </div>
                <div class="kpi-card ${compliantTone}">
                    <div class="kpi-label">Compliant</div>
                    <div class="kpi-value">${kpi.compliant_pct}%</div>
                    <div class="kpi-sub">${kpi.compliant_count.toLocaleString('en-GB')} of ${kpi.total_devices.toLocaleString('en-GB')}</div>
                </div>
                <div class="kpi-card ${encryptedTone}">
                    <div class="kpi-label">Encrypted</div>
                    <div class="kpi-value">${kpi.encrypted_pct}%</div>
                    <div class="kpi-sub">${kpi.encrypted_count.toLocaleString('en-GB')} of ${kpi.total_devices.toLocaleString('en-GB')}</div>
                </div>
                <div class="kpi-card ${staleTone}">
                    <div class="kpi-label">Stale (30+ days)</div>
                    <div class="kpi-value">${kpi.stale_count.toLocaleString('en-GB')}</div>
                    <div class="kpi-sub">No sync in last 30 days</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Enrolled (30 days)</div>
                    <div class="kpi-value">${kpi.enrolled_recently.toLocaleString('en-GB')}</div>
                    <div class="kpi-sub">New in last 30 days</div>
                </div>
            `;
        }

        function renderGrid() {
            const body = document.getElementById('dashboardBody');
            body.innerHTML = `
                <div class="widget-grid">
                    ${widgetCard('compliance',      'Compliance Breakdown', 'Devices by compliance state')}
                    ${widgetCard('osBreakdown',     'Operating System',     'Devices grouped by OS')}
                    ${widgetCard('ownerType',       'Owner Type',           'Corporate vs personal devices')}
                    ${widgetCard('manufacturers',   'Top Manufacturers',    'Devices by manufacturer (top 10)')}
                    ${widgetCard('osVersions',      'Top OS Versions',      'Most common OS + version combinations')}
                    ${widgetCard('lastSync',        'Last Sync Window',     'How recently devices checked in')}
                    ${widgetCard('enrolmentTrend',  'Enrolments (last 90 days)', 'New devices enrolled per day')}
                    ${widgetCard('encryptionByOs',  'Encryption by OS',     'Encrypted vs unencrypted, per OS')}
                </div>
            `;
        }

        function widgetCard(canvasId, title, desc) {
            return `
                <div class="widget-card">
                    <div class="widget-header">
                        <div>
                            <h3>${escapeHtml(title)}</h3>
                            <p>${escapeHtml(desc)}</p>
                        </div>
                    </div>
                    <div class="widget-chart"><canvas id="chart-${canvasId}"></canvas></div>
                </div>
            `;
        }

        function renderCharts(charts) {
            doughnut('compliance',    charts.compliance);
            doughnut('osBreakdown',   charts.os_breakdown);
            doughnut('ownerType',     charts.owner_type);
            barChart('manufacturers', charts.manufacturers);
            barChart('osVersions',    charts.os_versions);
            barChart('lastSync',      charts.last_sync, true);   // discrete buckets — rotate labels less
            lineChart('enrolmentTrend', charts.enrolment_trend);
            stackedBar('encryptionByOs', charts.encryption_by_os);
        }

        function doughnut(id, items) {
            const canvas = document.getElementById('chart-' + id);
            if (!canvas) return;
            destroyExisting(id);

            if (!items || items.length === 0) {
                showEmpty(canvas);
                return;
            }

            const labels = items.map(i => i.label);
            const values = items.map(i => i.value);
            const colors = labels.map((l, idx) => colorFor(l, idx));

            chartInstances[id] = new Chart(canvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{ data: values, backgroundColor: colors, borderColor: '#fff', borderWidth: 2 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { boxWidth: 12, padding: 8, font: { size: 11 } }
                        }
                    }
                }
            });
        }

        function barChart(id, items, shortLabels) {
            const canvas = document.getElementById('chart-' + id);
            if (!canvas) return;
            destroyExisting(id);

            if (!items || items.length === 0) {
                showEmpty(canvas);
                return;
            }

            const labels = items.map(i => i.label);
            const values = items.map(i => i.value);
            const colors = labels.map((l, idx) => colorFor(l, idx));

            chartInstances[id] = new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { ticks: { maxRotation: shortLabels ? 0 : 45, autoSkip: false, font: { size: 11 } } }
                    }
                }
            });
        }

        function lineChart(id, items) {
            const canvas = document.getElementById('chart-' + id);
            if (!canvas) return;
            destroyExisting(id);

            if (!items || items.length === 0) {
                showEmpty(canvas);
                return;
            }

            // For 90-day windows, label every 15th day to avoid clutter
            const labels = items.map((p, i) => i % 15 === 0 ? p.label : '');
            const values = items.map(p => p.value);

            chartInstances[id] = new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        borderColor: '#0078d4',
                        backgroundColor: 'rgba(0,120,212,0.12)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 0,
                        pointHoverRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: ctx => items[ctx[0].dataIndex].label,
                                label: ctx => ctx.parsed.y + ' enrolled'
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { ticks: { autoSkip: false, font: { size: 10 } } }
                    }
                }
            });
        }

        function stackedBar(id, payload) {
            const canvas = document.getElementById('chart-' + id);
            if (!canvas) return;
            destroyExisting(id);

            if (!payload || !payload.labels || payload.labels.length === 0) {
                showEmpty(canvas);
                return;
            }

            const datasets = payload.series.map(s => ({
                label: s.label,
                data: s.values,
                backgroundColor: STATE_COLORS[s.label] || (s.label === 'Encrypted' ? '#107c10' : '#d13438'),
                borderWidth: 0
            }));

            chartInstances[id] = new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 12, padding: 10, font: { size: 11 } } }
                    },
                    scales: {
                        x: { stacked: true, ticks: { maxRotation: 45, font: { size: 11 } } },
                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        }

        function destroyExisting(id) {
            if (chartInstances[id]) {
                chartInstances[id].destroy();
                delete chartInstances[id];
            }
        }

        function showEmpty(canvas) {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const parent = canvas.parentElement;
            parent.innerHTML = '<div style="color:#aaa;font-size:13px;">No data</div>';
        }

        document.addEventListener('DOMContentLoaded', loadDashboard);
    </script>
</body>
</html>
