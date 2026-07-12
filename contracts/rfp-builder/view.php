<?php
/**
 * RFP Builder — single RFP overview.
 * Shows phase tiles for the planned workflow; later phases fill these in.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('contracts');

$current_page = 'rfp-builder';
$path_prefix = '../../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.rfp.list.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { --accent: var(--con-accent, #f59e0b); }
        .rfp-view-wrap { padding: 30px 40px; background: var(--app-bg, #f5f5f5); height: calc(100vh - 48px); overflow-y: auto; box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: var(--text-dim, #888); margin-bottom: 8px; }
        .breadcrumb a { color: var(--text-muted, #666); text-decoration: none; }
        .breadcrumb a:hover { color: var(--con-accent, #f59e0b); }
        .breadcrumb span { margin: 0 6px; color: var(--text-faint, #ccc); }

        .rfp-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .rfp-header h1 {
            margin: 0; font-size: 24px; font-weight: 700; color: var(--text, #222);
            display: flex; align-items: center; gap: 12px;
        }
        .rfp-header .rfp-actions { display: flex; gap: 8px; }

        .status-badge {
            display: inline-block; padding: 4px 8px; border-radius: 3px;
            font-size: 13px; font-weight: 500; text-transform: capitalize;
        }
        .status-badge.draft        { background: #e5e7eb; color: #374151; }
        .status-badge.collecting   { background: #dbeafe; color: #1e40af; }
        .status-badge.consolidating { background: #fed7aa; color: #9a3412; }
        .status-badge.generating   { background: #ede9fe; color: #5b21b6; }
        .status-badge.scoring      { background: #ccfbf1; color: #115e59; }
        .status-badge.closed       { background: #d1fae5; color: #065f46; }
        .status-badge.abandoned    { background: #fee2e2; color: #991b1b; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s;
        }
        .btn-primary { background: var(--con-accent, #f59e0b); color: white; }
        .btn-primary:hover { background: var(--con-accent-hover, #d97706); }
        .btn-secondary { background: var(--surface, white); color: var(--text, #333); border-color: var(--border, #ddd); }
        .btn-secondary:hover { background: var(--surface-hover, #f5f5f5); }
        .btn-danger { background: var(--surface, white); color: #ef4444; border-color: #fca5a5; }
        .btn-danger:hover { background: #fef2f2; }

        .phase-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }

        .phase-tile {
            background: var(--surface, white); border-radius: 10px; padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex; flex-direction: column; gap: 10px;
            border-left: 4px solid var(--border, #e5e7eb);
        }
        .phase-tile.ready { border-left-color: var(--con-accent, #f59e0b); }
        .phase-tile.done  { border-left-color: #10b981; }

        .phase-tile-header {
            display: flex; align-items: center; gap: 10px;
        }
        .phase-tile-num {
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--surface-hover, #f3f4f6); color: var(--text-muted, #555);
            font-size: 13px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .phase-tile.ready .phase-tile-num { background: var(--con-accent-soft, #fef3c7); color: var(--con-accent-hover, #b45309); }
        .phase-tile.done  .phase-tile-num { background: #d1fae5; color: #065f46; }
        .phase-tile-title {
            font-size: 15px; font-weight: 600; color: var(--text, #222);
        }
        .phase-tile-desc { font-size: 13px; color: var(--text-muted, #666); line-height: 1.5; flex-grow: 1; }
        .phase-tile-stats {
            display: flex; gap: 14px; font-size: 13px; color: var(--text-muted, #555);
            padding-top: 8px; border-top: 1px solid var(--border-soft, #f0f0f0);
        }
        .phase-tile-stats strong { color: var(--text, #222); font-size: 16px; }
        .phase-tile-cta { margin-top: auto; }
        .phase-tile-cta .btn { width: 100%; justify-content: center; }
        .phase-tile-cta .placeholder {
            font-size: 12px; color: var(--text-dim, #999); font-style: italic; text-align: center;
            padding: 8px 0;
        }

        .meta-card {
            background: var(--surface, white); border-radius: 10px; padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .meta-card h2 {
            margin: 0 0 14px 0; font-size: 14px; font-weight: 600;
            color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .meta-card h2 .h2-extra {
            font-size: 12px; font-weight: 500; color: var(--text-faint, #aaa); text-transform: none; letter-spacing: 0;
        }
        .meta-row {
            display: flex; gap: 16px; padding: 8px 0;
            border-bottom: 1px solid var(--border-soft, #f5f5f5); font-size: 14px;
        }
        .meta-row:last-child { border-bottom: none; }
        .meta-row .meta-label { color: var(--text-dim, #888); min-width: 160px; }
        .meta-row .meta-value { color: var(--text, #333); flex: 1; }

        .ai-stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px; margin-bottom: 14px;
        }
        .ai-stat {
            background: var(--surface-2, #f9fafb); border-radius: 8px; padding: 12px 14px;
            border: 1px solid var(--border-soft, #f0f0f0);
        }
        .ai-stat .ai-stat-num {
            font-size: 20px; font-weight: 700; color: var(--text, #222); line-height: 1.1;
        }
        .ai-stat .ai-stat-label {
            font-size: 11px; color: var(--text-dim, #888); text-transform: uppercase;
            letter-spacing: 0.5px; margin-top: 4px;
        }
        .ai-stat.error .ai-stat-num   { color: #b91c1c; }
        .ai-stat.cached .ai-stat-num  { color: #047857; }

        .ai-log-table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .ai-log-table th {
            text-align: left; padding: 8px 10px; font-weight: 600;
            color: var(--text-dim, #888); border-bottom: 1px solid var(--border-soft, #eee);
            text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px;
        }
        .ai-log-table td {
            padding: 9px 10px; border-bottom: 1px solid var(--border-soft, #f5f5f5);
            color: var(--text, #333); vertical-align: top;
        }
        .ai-log-table tr:last-child td { border-bottom: none; }
        .ai-log-table .num { text-align: right; font-variant-numeric: tabular-nums; }
        .ai-log-table .target { color: var(--text-muted, #555); }
        .ai-log-table .target em { color: var(--text-dim, #999); font-style: normal; }
        .ai-log-table .err {
            color: #b91c1c; font-size: 12px; margin-top: 3px;
            white-space: normal; overflow-wrap: anywhere;
        }
        .status-pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .status-pill.success { background: #d1fae5; color: #065f46; }
        .status-pill.error   { background: #fee2e2; color: #991b1b; }
        .action-pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            background: var(--surface-3, #e5e7eb); color: var(--text-muted, #374151);
            font-size: 11px; font-weight: 500;
        }
        .ai-empty {
            text-align: center; padding: 28px 16px; color: var(--text-dim, #999); font-size: 13px;
        }

        .loading, .error-state { text-align: center; padding: 60px; color: var(--text-dim, #999); }
        .error-state { color: #d13438; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="rfp-view-wrap" id="viewWrap">
        <div class="loading" id="loadingEl"><?php echo htmlspecialchars(t('common.loading')); ?></div>
        <div id="contentEl" style="display:none;">
            <div class="breadcrumb">
                <a href="../"><?php echo htmlspecialchars(t('contracts.title')); ?></a><span>›</span>
                <a href="./"><?php echo htmlspecialchars(t('contracts.nav.rfp_builder')); ?></a><span>›</span>
                <span id="bcName">-</span>
            </div>

            <div class="rfp-header">
                <h1>
                    <span id="rfpName">-</span>
                    <span class="status-badge" id="rfpStatus">draft</span>
                </h1>
                <div class="rfp-actions">
                    <a href="./" class="btn btn-secondary">&larr; <?php echo htmlspecialchars(t('contracts.detail.back')); ?></a>
                    <a href="help.php" class="btn btn-secondary"><?php echo htmlspecialchars(t('contracts.nav.help')); ?></a>
                    <button class="btn btn-secondary" onclick="editRfp()"><?php echo htmlspecialchars(t('common.edit')); ?></button>
                    <button class="btn btn-danger" onclick="deleteRfp()"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                </div>
            </div>

            <div class="phase-grid" id="phaseGrid">
                <!-- Tiles rendered by JS -->
            </div>

            <div class="meta-card" id="aiActivityCard" style="display:none;">
                <h2>
                    <?php echo htmlspecialchars(t('contracts.rfp.view.ai_activity')); ?>
                    <span class="h2-extra" id="aiActivityLastRun"></span>
                </h2>
                <div class="ai-stats" id="aiStats"></div>
                <div id="aiLog"></div>
            </div>

            <div class="meta-card">
                <h2><?php echo htmlspecialchars(t('contracts.rfp.view.details')); ?></h2>
                <div class="meta-row"><div class="meta-label"><?php echo htmlspecialchars(t('contracts.rfp.view.created_by')); ?></div><div class="meta-value" id="metaCreatedBy">-</div></div>
                <div class="meta-row"><div class="meta-label"><?php echo htmlspecialchars(t('contracts.detail.created')); ?></div><div class="meta-value" id="metaCreatedAt">-</div></div>
                <div class="meta-row"><div class="meta-label"><?php echo htmlspecialchars(t('contracts.rfp.view.last_updated')); ?></div><div class="meta-value" id="metaUpdatedAt">-</div></div>
                <div class="meta-row"><div class="meta-label"><?php echo htmlspecialchars(t('contracts.rfp.view.linked_contract')); ?></div><div class="meta-value" id="metaContract">-</div></div>
                <div class="meta-row"><div class="meta-label"><?php echo htmlspecialchars(t('contracts.rfp.view.chosen_supplier')); ?></div><div class="meta-value" id="metaChosenSupplier">-</div></div>
                <div class="meta-row"><div class="meta-label"><?php echo htmlspecialchars(t('contracts.rfp.list.style_guide_override')); ?></div><div class="meta-value" id="metaStyleGuide">-</div></div>
            </div>
        </div>
        <div class="error-state" id="errorEl" style="display:none;"></div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let currentRfp = null;

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId) {
                showError(window.t('contracts.rfp.view.no_id') + ' <a href="./">' + window.t('contracts.rfp.view.back_to_list') + '</a>.');
                return;
            }
            loadRfp();
        });

        async function loadRfp() {
            try {
                const res = await fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId));
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                currentRfp = data.rfp;
                render(currentRfp);
                loadAiActivity();
            } catch (err) {
                showError(escapeHtml(err.message));
            }
        }

        async function loadAiActivity() {
            try {
                const res = await fetch(API_BASE + 'get_processing_log.php?rfp_id=' + encodeURIComponent(rfpId) + '&limit=25');
                const data = await res.json();
                if (!data.success) return; // silent — secondary panel
                renderAiActivity(data.totals, data.entries);
            } catch (_) {
                // ignore — panel is non-critical
            }
        }

        function renderAiActivity(totals, entries) {
            if (!totals || totals.run_count === 0) return; // hide entirely until first run
            document.getElementById('aiActivityCard').style.display = 'block';

            document.getElementById('aiActivityLastRun').innerHTML =
                (totals.last_run ? escapeHtml(window.t('contracts.rfp.view.last_run', { when: formatDateTime(totals.last_run) })) + ' · ' : '') +
                '<a href="audit.php?id=' + encodeURIComponent(rfpId) + '" style="color:var(--text-dim,#888);">' + escapeHtml(window.t('contracts.rfp.view.view_audit')) + '</a>';

            const cachePct = totals.tokens_in_recent > 0
                ? Math.round((totals.cache_read_recent / totals.tokens_in_recent) * 100)
                : 0;

            const stats = [
                { num: totals.run_count,        label: window.t('contracts.rfp.view.stat_runs') },
                { num: totals.success_count,    label: window.t('contracts.rfp.view.stat_successful') },
                { num: totals.error_count,      label: window.t('contracts.rfp.view.stat_errors'), cls: totals.error_count > 0 ? 'error' : '' },
                { num: formatTokens(totals.total_tokens_in),  label: window.t('contracts.rfp.view.stat_input_tokens') },
                { num: formatTokens(totals.total_tokens_out), label: window.t('contracts.rfp.view.stat_output_tokens') },
                {
                    num: formatTokens(totals.cache_read_recent),
                    label: window.t('contracts.rfp.view.stat_cached_input') + (cachePct > 0 ? ' (' + cachePct + '%)' : ''),
                    cls: 'cached'
                }
            ];
            document.getElementById('aiStats').innerHTML = stats.map(s => `
                <div class="ai-stat ${s.cls || ''}">
                    <div class="ai-stat-num">${s.num}</div>
                    <div class="ai-stat-label">${escapeHtml(s.label)}</div>
                </div>
            `).join('');

            const logEl = document.getElementById('aiLog');
            if (!entries.length) {
                logEl.innerHTML = '<div class="ai-empty">' + escapeHtml(window.t('contracts.rfp.view.no_ai_activity')) + '</div>';
                return;
            }
            logEl.innerHTML = `
                <table class="ai-log-table">
                    <thead>
                        <tr>
                            <th>${escapeHtml(window.t('contracts.rfp.view.col_when'))}</th>
                            <th>${escapeHtml(window.t('contracts.rfp.view.col_action'))}</th>
                            <th>${escapeHtml(window.t('contracts.rfp.view.col_target'))}</th>
                            <th>${escapeHtml(window.t('contracts.detail.status'))}</th>
                            <th class="num">${escapeHtml(window.t('contracts.rfp.view.col_in'))}</th>
                            <th class="num">${escapeHtml(window.t('contracts.rfp.view.col_out'))}</th>
                            <th class="num">${escapeHtml(window.t('contracts.rfp.view.col_cached'))}</th>
                            <th class="num">${escapeHtml(window.t('contracts.rfp.view.col_time'))}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${entries.map(e => renderLogRow(e)).join('')}
                    </tbody>
                </table>
            `;
        }

        function renderLogRow(e) {
            const target = e.document_filename
                ? escapeHtml(e.document_filename)
                : (e.section_title ? escapeHtml(e.section_title) : '<em>—</em>');
            const errLine = e.error
                ? `<div class="err">${escapeHtml(truncate(e.error, 200))}</div>`
                : '';
            const dur = e.duration_ms !== null && e.duration_ms !== undefined
                ? (e.duration_ms >= 1000 ? (e.duration_ms / 1000).toFixed(1) + 's' : e.duration_ms + 'ms')
                : '—';
            return `
                <tr>
                    <td>${escapeHtml(formatDateTime(e.created_datetime))}</td>
                    <td><span class="action-pill">${escapeHtml(e.action)}</span></td>
                    <td class="target">${target}${errLine}</td>
                    <td><span class="status-pill ${escapeHtml(e.status)}">${escapeHtml(e.status)}</span></td>
                    <td class="num">${e.tokens_in  !== null ? formatTokens(e.tokens_in)  : '—'}</td>
                    <td class="num">${e.tokens_out !== null ? formatTokens(e.tokens_out) : '—'}</td>
                    <td class="num">${e.cache_read !== null && e.cache_read !== undefined ? formatTokens(e.cache_read) : '—'}</td>
                    <td class="num">${dur}</td>
                </tr>
            `;
        }

        function formatTokens(n) {
            if (n === null || n === undefined) return '—';
            n = Number(n);
            if (n >= 1000) return (n / 1000).toFixed(n >= 10000 ? 0 : 1) + 'k';
            return n.toString();
        }

        function truncate(s, n) {
            if (!s) return '';
            return s.length > n ? s.slice(0, n) + '…' : s;
        }

        function rfpStatusLabel(status) {
            const key = 'contracts.rfp.status.' + status;
            const label = window.t(key);
            return label === key ? status : label;
        }

        function render(r) {
            document.getElementById('loadingEl').style.display = 'none';
            document.getElementById('contentEl').style.display = 'block';

            document.getElementById('bcName').textContent = r.name;
            document.getElementById('rfpName').textContent = r.name;
            const statusEl = document.getElementById('rfpStatus');
            statusEl.textContent = rfpStatusLabel(r.status);
            statusEl.className = 'status-badge ' + r.status;

            document.getElementById('metaCreatedBy').textContent = r.created_by_name || '-';
            document.getElementById('metaCreatedAt').textContent = formatDateTime(r.created_datetime);
            document.getElementById('metaUpdatedAt').textContent = formatDateTime(r.updated_datetime);
            document.getElementById('metaContract').textContent = r.contract_title || window.t('contracts.rfp.view.not_linked');
            document.getElementById('metaChosenSupplier').textContent = r.chosen_supplier_name || window.t('contracts.rfp.view.not_chosen');
            document.getElementById('metaStyleGuide').textContent = r.style_guide ? window.t('contracts.rfp.view.custom_override') : window.t('contracts.rfp.view.using_default');

            renderPhases(r);
        }

        function renderPhases(r) {
            const lockedReady = r.consolidated_count > 0 && r.locked_count === r.consolidated_count;
            const lockCta = r.consolidated_count === 0
                ? window.t('contracts.rfp.view.cta_run_consolidation')
                : (r.locked_count !== r.consolidated_count ? window.t('contracts.rfp.view.cta_lock_first') : '');
            const phases = [
                {
                    num: 1, title: window.t('contracts.rfp.view.phase1_title'), phase: 1,
                    desc: window.t('contracts.rfp.view.phase1_desc'),
                    stats: [{label: window.t('contracts.rfp.view.stat_documents'), value: r.document_count}],
                    ready: true,
                    cta: '',
                    href: 'documents.php?id=' + r.id
                },
                {
                    num: 2, title: window.t('contracts.rfp.view.phase2_title'), phase: 2,
                    desc: window.t('contracts.rfp.view.phase2_desc'),
                    stats: [{label: window.t('contracts.rfp.view.stat_extracted'), value: r.extracted_count}],
                    ready: r.extracted_count > 0,
                    cta: r.extracted_count > 0 ? '' : window.t('contracts.rfp.view.cta_run_extraction_docs'),
                    href: r.extracted_count > 0 ? 'extracted.php?id=' + r.id : null
                },
                {
                    num: 3, title: window.t('contracts.rfp.view.phase3_title'), phase: 3,
                    desc: window.t('contracts.rfp.view.phase3_desc'),
                    stats: [
                        {label: window.t('contracts.rfp.view.stat_consolidated'), value: r.consolidated_count},
                        {label: window.t('contracts.rfp.view.stat_locked'), value: r.locked_count},
                        {label: window.t('contracts.rfp.view.stat_open_conflicts'), value: r.open_conflicts}
                    ],
                    ready: r.extracted_count > 0,
                    cta: r.extracted_count > 0 ? '' : window.t('contracts.rfp.view.cta_run_extraction'),
                    href: r.extracted_count > 0 ? 'consolidate.php?id=' + r.id : null
                },
                {
                    num: 4, title: window.t('contracts.rfp.view.phase4_title'), phase: 4,
                    desc: window.t('contracts.rfp.view.phase4_desc'),
                    stats: [
                        {label: window.t('contracts.rfp.view.stat_categories'), value: r.category_count},
                        {label: window.t('contracts.rfp.view.stat_sections'), value: r.section_count}
                    ],
                    ready: lockedReady,
                    cta: lockCta,
                    href: lockedReady ? 'document.php?id=' + r.id : null
                },
                {
                    num: 5, title: window.t('contracts.rfp.view.phase5_title'), phase: 5,
                    desc: window.t('contracts.rfp.view.phase5_desc'),
                    stats: [{label: window.t('contracts.nav.suppliers'), value: r.supplier_count}],
                    ready: lockedReady,
                    cta: lockCta,
                    href: lockedReady ? 'suppliers.php?id=' + r.id : null
                },
                {
                    num: 6, title: window.t('contracts.rfp.view.phase6_title'), phase: 6,
                    desc: window.t('contracts.rfp.view.phase6_desc'),
                    stats: [],
                    ready: r.supplier_count > 0,
                    cta: r.supplier_count === 0 ? window.t('contracts.rfp.view.cta_add_suppliers') : '',
                    href: r.supplier_count > 0 ? 'compare.php?id=' + r.id : null
                }
            ];

            const grid = document.getElementById('phaseGrid');
            grid.innerHTML = phases.map(p => `
                <div class="phase-tile ${p.ready ? 'ready' : ''}">
                    <div class="phase-tile-header">
                        <div class="phase-tile-num">${p.num}</div>
                        <div class="phase-tile-title">${p.title}</div>
                    </div>
                    <div class="phase-tile-desc">${p.desc}</div>
                    ${p.stats.length ? `
                        <div class="phase-tile-stats">
                            ${p.stats.map(s => `<div><strong>${s.value}</strong> <span style="color:var(--text-dim,#888);">${s.label}</span></div>`).join('')}
                        </div>
                    ` : ''}
                    <div class="phase-tile-cta">
                        ${p.href
                            ? `<a class="btn btn-primary" href="${p.href}">${escapeHtml(window.t('common.open'))}</a>`
                            : `<div class="placeholder">${escapeHtml(p.cta)}</div>`}
                    </div>
                </div>
            `).join('');
        }

        function editRfp() {
            // Edit modal lives on the list page; bounce back with a hash so the list could open it.
            // For now, just go back — the list page has the edit modal.
            window.location.href = './?edit=' + currentRfp.id;
        }

        async function deleteRfp() {
            if (!(await showConfirm({ title: window.t('common.delete'), message: window.t('contracts.rfp.list.delete_confirm', { name: currentRfp.name }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
            try {
                const res = await fetch(API_BASE + 'delete_rfp.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: currentRfp.id})
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.list.delete_failed_short'));
                window.location.href = './';
            } catch (err) {
                showToast(window.t('contracts.rfp.list.delete_failed') + ' ' + err.message, 'error');
            }
        }

        function showError(html) {
            document.getElementById('loadingEl').style.display = 'none';
            const el = document.getElementById('errorEl');
            el.innerHTML = html;
            el.style.display = 'block';
        }

        function formatDateTime(s) {
            if (!s) return '-';
            const d = parseUTCDate(s);
            if (isNaN(d)) return s;
            return d.toLocaleString('en-GB', tzOpts({ day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }));
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    </script>
</body>
</html>
