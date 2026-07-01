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
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();

$current_page = 'intune';
$path_prefix = '../../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('reporting.intune.heading')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=13">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <style>
        /* Module accent (rust-orange) — primary buttons, KPI warn, focus rings. */
        body { --accent: var(--rep-accent, #ca5010); --accent-hover: var(--rep-accent-hover, #a5410a); }

        .dashboard-page {
            height: calc(100vh - 48px);
            overflow-y: auto;
        }

        .dashboard-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; background: var(--surface, #fff); border-bottom: 1px solid var(--border, #e0e0e0);
        }
        .dashboard-toolbar h2 { margin: 0; font-size: 18px; color: var(--text, #333); }
        .dashboard-toolbar .last-sync {
            font-size: 12px; color: var(--text-dim, #888); margin-left: 16px;
        }
        .dashboard-toolbar-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; border: 1px solid var(--border, #ddd); border-radius: 6px;
            background: var(--surface, #fff); color: var(--text, #333); font-size: 13px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s; text-decoration: none;
        }
        .btn:hover { background: var(--surface-hover, #f5f5f5); border-color: var(--border, #ccc); }
        .btn-primary { background: var(--rep-accent, #ca5010); color: var(--rep-on-accent, #fff); border-color: var(--rep-accent, #ca5010); }
        .btn-primary:hover { background: var(--rep-accent-hover, #b34810); }
        .btn svg { width: 16px; height: 16px; }

        /* KPI strip */
        .kpi-strip {
            display: grid; grid-template-columns: repeat(5, 1fr);
            gap: 16px; padding: 24px 24px 0 24px;
        }
        .kpi-card {
            background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px;
            padding: 18px 20px;
        }
        .kpi-label {
            font-size: 11px; color: var(--text-dim, #888); text-transform: uppercase;
            letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;
        }
        .kpi-value {
            font-size: 28px; color: var(--text, #333); font-weight: 600;
            line-height: 1.1;
        }
        .kpi-sub {
            font-size: 11px; color: var(--text-dim, #888); margin-top: 4px;
        }
        .kpi-card.warn .kpi-value { color: var(--rep-accent, #ca5010); }
        .kpi-card.bad  .kpi-value { color: var(--danger-accent, #d13438); }
        .kpi-card.good .kpi-value { color: var(--success-accent, #107c10); }

        /* Widget grid */
        .widget-grid {
            display: grid; grid-template-columns: repeat(2, 1fr);
            gap: 20px; padding: 24px;
        }
        .widget-card {
            background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px;
            display: flex; flex-direction: column;
            transition: box-shadow 0.15s;
        }
        .widget-card:hover { box-shadow: 0 2px 8px var(--shadow, rgba(0,0,0,0.08)); }
        .widget-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            padding: 16px 16px 0 16px;
        }
        .widget-header h3 {
            margin: 0; font-size: 14px; font-weight: 600; color: var(--text, #333);
        }
        .widget-header p {
            margin: 4px 0 0 0; font-size: 12px; color: var(--text-dim, #888);
        }
        .widget-chart {
            padding: 12px 16px 16px 16px;
            flex: 1; min-height: 260px;
            display: flex; align-items: center; justify-content: center;
        }
        .widget-chart canvas { max-height: 290px; }

        .loading-state { text-align: center; padding: 60px 20px; color: var(--text-dim, #888); font-size: 13px; }
        .empty-banner {
            background: var(--warning-bg, #fff7e6); border: 1px solid var(--warning-border, #ffd591); border-radius: 8px;
            padding: 16px 20px; margin: 24px; color: var(--warning-text, #874d00); font-size: 13px;
        }
        .empty-banner strong { color: var(--warning-text, #874d00); }

        /* Drill-down modal */
        .drill-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: none; align-items: center; justify-content: center; z-index: 2500;
        }
        .drill-overlay.active { display: flex; }
        .drill-modal {
            background: var(--surface, #fff); border-radius: 8px; box-shadow: 0 10px 40px var(--shadow, rgba(0,0,0,0.2));
            width: 1000px; max-width: calc(100vw - 40px);
            max-height: calc(100vh - 40px);
            display: flex; flex-direction: column;
        }
        .drill-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border-soft, #eee);
            display: flex; justify-content: space-between; align-items: center;
        }
        .drill-header h3 {
            margin: 0; font-size: 15px; color: var(--text, #333);
        }
        .drill-header .drill-count {
            color: var(--text-dim, #888); font-size: 13px; margin-left: 8px; font-weight: normal;
        }
        .drill-close {
            background: none; border: none; font-size: 22px; line-height: 1;
            color: var(--text-faint, #999); cursor: pointer; padding: 0;
        }
        .drill-close:hover { color: var(--text, #333); }
        .drill-body {
            flex: 1; overflow: auto; padding: 0;
        }
        .drill-body table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .drill-body th, .drill-body td {
            padding: 10px 14px; text-align: left;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
        }
        .drill-body th {
            background: var(--surface-2, #fafafa); font-weight: 600; color: var(--text-muted, #555);
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
            position: sticky; top: 0; z-index: 1;
        }
        .drill-body tr:hover td { background: var(--surface-hover, #fafbfc); }
        .drill-body td.dim { color: var(--text-faint, #999); }
        .drill-body .compliance-pill {
            display: inline-block; padding: 2px 10px; border-radius: 10px;
            font-size: 11px; font-weight: 500;
        }
        .compliance-pill.compliant       { background: var(--success-bg, #d4edda); color: var(--success-text, #155724); }
        .compliance-pill.noncompliant    { background: var(--danger-bg, #f8d7da); color: var(--danger-text, #721c24); }
        .compliance-pill.in-grace-period { background: var(--warning-bg, #fff3cd); color: var(--warning-text, #856404); }
        .compliance-pill.unknown         { background: var(--surface-hover, #eee); color: var(--text-muted, #555); }
        .drill-loading {
            text-align: center; padding: 60px 20px; color: var(--text-dim, #888); font-size: 13px;
        }

        .drill-footer {
            padding: 12px 20px; border-top: 1px solid var(--border-soft, #eee);
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
        }
        .drill-footer .pager {
            display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-muted, #555);
        }
        .drill-footer .pager-btn {
            padding: 4px 12px; border: 1px solid var(--border, #ddd); border-radius: 4px;
            background: var(--surface, #fff); color: var(--text, #333); font-size: 12px; cursor: pointer;
        }
        .drill-footer .pager-btn:hover:not(:disabled) { background: var(--surface-hover, #f5f5f5); }
        .drill-footer .pager-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .drill-footer .drill-actions {
            display: flex; gap: 8px;
        }
        .drill-footer .btn-export {
            background: var(--rep-accent, #ca5010); color: white; border-color: var(--rep-accent, #ca5010);
        }
        .drill-footer .btn-export:hover { background: var(--rep-accent-hover, #b34810); }

        .kpi-card.clickable { cursor: pointer; transition: box-shadow 0.15s, transform 0.15s ease-out; }
        .kpi-card.clickable:hover { box-shadow: 0 8px 18px var(--shadow, rgba(0,0,0,0.14)); transform: translateY(-3px); }

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

    <div class="dashboard-page">

    <div class="dashboard-toolbar">
        <div style="display:flex; align-items:baseline;">
            <h2><?php echo htmlspecialchars(t('reporting.intune.heading')); ?></h2>
            <span class="last-sync" id="lastSyncInfo"><?php echo htmlspecialchars(t('reporting.intune.loading_meta')); ?></span>
        </div>
        <div class="dashboard-toolbar-actions">
            <button class="btn" onclick="loadDashboard()" title="<?php echo htmlspecialchars(t('reporting.intune.refresh_title')); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                <?php echo htmlspecialchars(t('reporting.intune.refresh')); ?>
            </button>
        </div>
    </div>

    <div id="kpiStrip" class="kpi-strip"></div>

    <div id="dashboardBody">
        <div class="loading-state" id="loadingState"><?php echo htmlspecialchars(t('reporting.intune.loading_data')); ?></div>
    </div>

    </div><!-- /.dashboard-page -->

    <!-- Drill-down modal -->
    <div class="drill-overlay" id="drillOverlay" onclick="if(event.target===this)closeDrillModal()">
        <div class="drill-modal">
            <div class="drill-header">
                <h3 id="drillTitle">&hellip;<span class="drill-count" id="drillCount"></span></h3>
            </div>
            <div class="drill-body" id="drillBody">
                <div class="drill-loading"><?php echo htmlspecialchars(t('reporting.intune.drill_loading')); ?></div>
            </div>
            <div class="drill-footer">
                <div class="pager">
                    <button type="button" class="pager-btn" id="drillPrev" onclick="drillGoto(drillState.page - 1)"><?php echo htmlspecialchars(t('reporting.intune.drill_prev')); ?></button>
                    <span id="drillPageInfo"><?php echo htmlspecialchars(t('reporting.intune.drill_page_info', ['current' => 1, 'total' => 1])); ?></span>
                    <button type="button" class="pager-btn" id="drillNext" onclick="drillGoto(drillState.page + 1)"><?php echo htmlspecialchars(t('reporting.intune.drill_next')); ?></button>
                </div>
                <div class="drill-actions">
                    <button type="button" class="btn btn-export" onclick="exportDrillCsv()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <?php echo htmlspecialchars(t('reporting.intune.drill_export')); ?>
                    </button>
                    <button type="button" class="btn" onclick="closeDrillModal()"><?php echo htmlspecialchars(t('reporting.intune.drill_close')); ?></button>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/chart.min.js"></script>
    <script>
        const API_BASE = '../../api/intune/';
        const chartInstances = {};
        let chartData = null;  // most recent API payload, used by click handlers

        // Dark-mode readability: Chart.js paints to a canvas and can't read our
        // CSS tokens, so set its global text (ticks/legend) + gridline colours
        // from the active palette mode. Series colours (STATE_COLORS/PALETTE)
        // are unchanged — they read on both. chartSurface is the doughnut
        // slice-border so slices separate against the card in either mode.
        const repDark = document.documentElement.getAttribute('data-theme-mode') === 'dark';
        const chartSurface = repDark ? '#1e2228' : '#ffffff';
        if (window.Chart) {
            Chart.defaults.color = repDark ? '#aab2bd' : '#666';
            Chart.defaults.borderColor = repDark ? 'rgba(255,255,255,0.10)' : 'rgba(0,0,0,0.08)';
        }
        const drillState = {
            filter: '',
            value: '',
            friendly: '',
            page: 1,
            pageSize: 25,
            totalPages: 1,
        };

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
            body.innerHTML = `<div class="loading-state">${escapeHtml(t('reporting.intune.loading_data'))}</div>`;
            document.getElementById('kpiStrip').innerHTML = '';

            try {
                const res = await fetch(API_BASE + 'dashboard_data.php');
                const data = await res.json();
                if (!data.success) {
                    body.innerHTML = `<div class="empty-banner">${escapeHtml(t('reporting.intune.error', { error: data.error }))}</div>`;
                    return;
                }

                chartData = data.charts;
                renderLastSync(data.last_sync_job);
                renderKpiStrip(data.kpi);

                if (data.kpi.total_devices === 0) {
                    body.innerHTML = `<div class="empty-banner">
                        <strong>${escapeHtml(t('reporting.intune.no_devices_title'))}</strong> ${escapeHtml(t('reporting.intune.no_devices_body'))}
                    </div>`;
                    return;
                }

                renderGrid();
                renderCharts(data.charts);
            } catch (err) {
                body.innerHTML = `<div class="empty-banner">${escapeHtml(t('reporting.intune.load_failed', { error: err.message }))}</div>`;
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
            span.innerHTML = `${escapeHtml(t('reporting.intune.last_sync', { when: when || '?' }))}${status === 'success' ? '' : ' &middot; <span style="color:var(--rep-accent,#ca5010);">' + escapeHtml(status) + '</span>'}`;
        }

        function renderKpiStrip(kpi) {
            const strip = document.getElementById('kpiStrip');

            // Tone the cards based on thresholds
            const compliantTone  = kpi.compliant_pct >= 90 ? 'good' : (kpi.compliant_pct >= 70 ? '' : 'bad');
            const encryptedTone  = kpi.encrypted_pct >= 90 ? 'good' : (kpi.encrypted_pct >= 70 ? '' : 'bad');
            const staleTone      = kpi.stale_count === 0 ? 'good' : (kpi.stale_count > 50 ? 'bad' : 'warn');

            const totalStr = kpi.total_devices.toLocaleString('en-GB');
            strip.innerHTML = `
                <div class="kpi-card">
                    <div class="kpi-label">${escapeHtml(t('reporting.intune.kpi_total'))}</div>
                    <div class="kpi-value">${totalStr}</div>
                    <div class="kpi-sub">${escapeHtml(t('reporting.intune.kpi_total_sub'))}</div>
                </div>
                <div class="kpi-card clickable ${compliantTone}" onclick="openDrillModal('kpi_compliant','',t('reporting.intune.kpi_compliant_drill'))">
                    <div class="kpi-label">${escapeHtml(t('reporting.intune.kpi_compliant'))}</div>
                    <div class="kpi-value">${kpi.compliant_pct}%</div>
                    <div class="kpi-sub">${escapeHtml(t('reporting.intune.kpi_compliant_sub', { count: kpi.compliant_count.toLocaleString('en-GB'), total: totalStr }))}</div>
                </div>
                <div class="kpi-card clickable ${encryptedTone}" onclick="openDrillModal('kpi_encrypted','',t('reporting.intune.kpi_encrypted_drill'))">
                    <div class="kpi-label">${escapeHtml(t('reporting.intune.kpi_encrypted'))}</div>
                    <div class="kpi-value">${kpi.encrypted_pct}%</div>
                    <div class="kpi-sub">${escapeHtml(t('reporting.intune.kpi_encrypted_sub', { count: kpi.encrypted_count.toLocaleString('en-GB'), total: totalStr }))}</div>
                </div>
                <div class="kpi-card clickable ${staleTone}" onclick="openDrillModal('kpi_stale','',t('reporting.intune.kpi_stale_drill'))">
                    <div class="kpi-label">${escapeHtml(t('reporting.intune.kpi_stale'))}</div>
                    <div class="kpi-value">${kpi.stale_count.toLocaleString('en-GB')}</div>
                    <div class="kpi-sub">${escapeHtml(t('reporting.intune.kpi_stale_sub'))}</div>
                </div>
                <div class="kpi-card clickable" onclick="openDrillModal('kpi_recent','',t('reporting.intune.kpi_enrolled_drill'))">
                    <div class="kpi-label">${escapeHtml(t('reporting.intune.kpi_enrolled'))}</div>
                    <div class="kpi-value">${kpi.enrolled_recently.toLocaleString('en-GB')}</div>
                    <div class="kpi-sub">${escapeHtml(t('reporting.intune.kpi_enrolled_sub'))}</div>
                </div>
            `;
        }

        function renderGrid() {
            const body = document.getElementById('dashboardBody');
            body.innerHTML = `
                <div class="widget-grid">
                    ${widgetCard('compliance',      t('reporting.intune.w_compliance_title'),   t('reporting.intune.w_compliance_desc'))}
                    ${widgetCard('osBreakdown',     t('reporting.intune.w_os_title'),           t('reporting.intune.w_os_desc'))}
                    ${widgetCard('ownerType',       t('reporting.intune.w_owner_title'),        t('reporting.intune.w_owner_desc'))}
                    ${widgetCard('manufacturers',   t('reporting.intune.w_manufacturers_title'),t('reporting.intune.w_manufacturers_desc'))}
                    ${widgetCard('osVersions',      t('reporting.intune.w_os_versions_title'),  t('reporting.intune.w_os_versions_desc'))}
                    ${widgetCard('lastSync',        t('reporting.intune.w_last_sync_title'),    t('reporting.intune.w_last_sync_desc'))}
                    ${widgetCard('enrolmentTrend',  t('reporting.intune.w_enrolment_title'),    t('reporting.intune.w_enrolment_desc'))}
                    ${widgetCard('encryptionByOs',  t('reporting.intune.w_encryption_title'),   t('reporting.intune.w_encryption_desc'))}
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
            doughnut('compliance',    charts.compliance,    'compliance');
            doughnut('osBreakdown',   charts.os_breakdown,  'os');
            doughnut('ownerType',     charts.owner_type,    'owner');
            barChart('manufacturers', charts.manufacturers, 'manufacturer');
            barChart('osVersions',    charts.os_versions,   'os_version');
            barChart('lastSync',      charts.last_sync,     'last_sync', true); // discrete buckets — flat labels
            lineChart('enrolmentTrend', charts.enrolment_trend, 'enrolment_day');
            stackedBar('encryptionByOs', charts.encryption_by_os);
        }

        function doughnut(id, items, filterKey) {
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
                    datasets: [{ data: values, backgroundColor: colors, borderColor: chartSurface, borderWidth: 2 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { boxWidth: 12, padding: 8, font: { size: 11 } }
                        }
                    },
                    onClick: (e, els) => handleSliceClick(filterKey, items, els),
                    onHover: (e, els) => { canvas.style.cursor = els && els.length ? 'pointer' : 'default'; }
                }
            });
        }

        function barChart(id, items, filterKey, shortLabels) {
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
                    },
                    onClick: (e, els) => handleSliceClick(filterKey, items, els),
                    onHover: (e, els) => { canvas.style.cursor = els && els.length ? 'pointer' : 'default'; }
                }
            });
        }

        function lineChart(id, items, filterKey) {
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
                        pointHoverRadius: 6,
                        pointHitRadius: 10
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: ctx => items[ctx[0].dataIndex].label,
                                label: ctx => t('reporting.intune.tooltip_enrolled', { count: ctx.parsed.y })
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { ticks: { autoSkip: false, font: { size: 10 } } }
                    },
                    onClick: (e, els) => {
                        if (!els || !els.length) return;
                        const point = items[els[0].index];
                        if (point && point.value > 0) {
                            openDrillModal(filterKey, point.raw, t('reporting.intune.drill_enrolled_on', { date: point.label }));
                        }
                    },
                    onHover: (e, els) => { canvas.style.cursor = els && els.length ? 'pointer' : 'default'; }
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
                    },
                    onClick: (e, els) => {
                        if (!els || !els.length) return;
                        const el = els[0];
                        const os = payload.labels[el.index];
                        const seriesLabel = payload.series[el.datasetIndex].label;
                        const encryptedFlag = seriesLabel === 'Encrypted' ? '1' : '0';
                        openDrillModal('encryption_os', encryptedFlag + '||' + os, seriesLabel + ' · ' + os);
                    },
                    onHover: (e, els) => { canvas.style.cursor = els && els.length ? 'pointer' : 'default'; }
                }
            });
        }

        function destroyExisting(id) {
            if (chartInstances[id]) {
                chartInstances[id].destroy();
                delete chartInstances[id];
            }
        }

        // ========== Drill-down modal ==========

        function handleSliceClick(filterKey, items, els) {
            if (!els || !els.length) return;
            const item = items[els[0].index];
            if (!item || !item.value) return;
            openDrillModal(filterKey, item.raw, item.label);
        }

        function openDrillModal(filter, value, friendlyLabel) {
            drillState.filter = filter;
            drillState.value = value;
            drillState.friendly = friendlyLabel || filter;
            drillState.page = 1;

            document.getElementById('drillTitle').innerHTML =
                escapeHtml(friendlyLabel || t('reporting.intune.drill_devices')) + '<span class="drill-count" id="drillCount"></span>';
            document.getElementById('drillBody').innerHTML = `<div class="drill-loading">${escapeHtml(t('reporting.intune.drill_loading'))}</div>`;
            document.getElementById('drillOverlay').classList.add('active');
            loadDrillPage();
        }

        function closeDrillModal() {
            document.getElementById('drillOverlay').classList.remove('active');
        }

        async function loadDrillPage() {
            const params = new URLSearchParams({
                filter: drillState.filter,
                value:  drillState.value,
                page:   drillState.page,
                page_size: drillState.pageSize,
            });
            try {
                const res = await fetch(API_BASE + 'dashboard_drilldown.php?' + params.toString());
                const data = await res.json();
                if (!data.success) {
                    document.getElementById('drillBody').innerHTML =
                        `<div class="drill-loading" style="color:var(--danger-accent,#d13438);">${escapeHtml(t('reporting.intune.drill_error', { error: data.error }))}</div>`;
                    return;
                }
                drillState.totalPages = data.total_pages;
                const countKey = data.total === 1 ? 'reporting.intune.drill_count' : 'reporting.intune.drill_count_plural';
                document.getElementById('drillCount').textContent = ' · ' + t(countKey, { count: data.total.toLocaleString('en-GB') });
                renderDrillRows(data.devices);
                updateDrillPager(data);
            } catch (err) {
                document.getElementById('drillBody').innerHTML =
                    `<div class="drill-loading" style="color:var(--danger-accent,#d13438);">${escapeHtml(t('reporting.intune.drill_load_failed', { error: err.message }))}</div>`;
            }
        }

        function renderDrillRows(devices) {
            if (!devices || devices.length === 0) {
                document.getElementById('drillBody').innerHTML =
                    `<div class="drill-loading">${escapeHtml(t('reporting.intune.drill_no_match'))}</div>`;
                return;
            }
            const rows = devices.map(d => {
                const compClass = (d.compliance_state || 'unknown').toLowerCase().replace(/\s+/g, '-');
                const compLabel = d.compliance_state ? d.compliance_state.charAt(0).toUpperCase() + d.compliance_state.slice(1) : t('reporting.intune.unknown');
                const lastSync = d.last_sync_datetime ? formatDate(d.last_sync_datetime) : `<span class="dim">${escapeHtml(t('reporting.intune.never'))}</span>`;
                const user = d.user_display_name || d.user_principal_name || '<span class="dim">—</span>';
                const enc = d.is_encrypted === true ? t('reporting.intune.yes') : (d.is_encrypted === false ? t('reporting.intune.no') : '<span class="dim">—</span>');
                return `<tr>
                    <td>${escapeHtml(d.device_name || '—')}</td>
                    <td>${user === '<span class="dim">—</span>' ? user : escapeHtml(user)}</td>
                    <td>${escapeHtml(d.operating_system || '')} ${escapeHtml(d.os_version || '')}</td>
                    <td><span class="compliance-pill ${compClass}">${escapeHtml(compLabel)}</span></td>
                    <td>${enc}</td>
                    <td>${lastSync}</td>
                </tr>`;
            }).join('');
            document.getElementById('drillBody').innerHTML = `
                <table>
                    <thead>
                        <tr>
                            <th>${escapeHtml(t('reporting.intune.drill_col_device'))}</th>
                            <th>${escapeHtml(t('reporting.intune.drill_col_user'))}</th>
                            <th>${escapeHtml(t('reporting.intune.drill_col_os'))}</th>
                            <th>${escapeHtml(t('reporting.intune.drill_col_compliance'))}</th>
                            <th>${escapeHtml(t('reporting.intune.drill_col_encrypted'))}</th>
                            <th>${escapeHtml(t('reporting.intune.drill_col_last_sync'))}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            `;
        }

        function updateDrillPager(data) {
            document.getElementById('drillPageInfo').textContent =
                t('reporting.intune.drill_page_info', { current: data.page, total: data.total_pages });
            document.getElementById('drillPrev').disabled = (data.page <= 1);
            document.getElementById('drillNext').disabled = (data.page >= data.total_pages);
        }

        function drillGoto(page) {
            if (page < 1 || page > drillState.totalPages) return;
            drillState.page = page;
            document.getElementById('drillBody').innerHTML = `<div class="drill-loading">${escapeHtml(t('reporting.intune.drill_loading'))}</div>`;
            loadDrillPage();
        }

        function exportDrillCsv() {
            const params = new URLSearchParams({
                filter: drillState.filter,
                value:  drillState.value,
                format: 'csv',
            });
            // Trigger a download via a hidden anchor — same-origin GET so the
            // browser handles the Content-Disposition header.
            window.location.href = API_BASE + 'dashboard_drilldown.php?' + params.toString();
        }

        // Close on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const overlay = document.getElementById('drillOverlay');
                if (overlay && overlay.classList.contains('active')) closeDrillModal();
            }
        });

        function showEmpty(canvas) {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const parent = canvas.parentElement;
            parent.innerHTML = `<div style="color:var(--text-faint,#aaa);font-size:13px;">${escapeHtml(t('reporting.intune.no_data'))}</div>`;
        }

        document.addEventListener('DOMContentLoaded', loadDashboard);
    </script>
</body>
</html>
