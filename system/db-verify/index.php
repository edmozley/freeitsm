<?php
/**
 * System - Database Verification
 * Checks all tables and columns exist, creates any that are missing.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'db-verify';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// Auth check before any HTML output (prevents "headers already sent")
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.db_verify.heading')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* System module accent (blue-grey) */
        body {
            /* System is the FIRST module whose DARK accent is a LIGHT colour (#90a4ae).
               inbox.css renders .btn-primary/.add-btn as background:var(--accent) +
               color:var(--on-accent) — and the global --on-accent stays WHITE in dark.
               So pinning --accent alone would put white text on a light button. Pin
               --on-accent too: it flips to near-black in dark. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        .db-verify-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 24px 20px 0;
        }

        .db-verify-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .db-verify-header h2 {
            margin: 0;
            font-size: 22px;
            color: var(--text, #333);
        }

        .db-verify-header p {
            margin: 5px 0 0 0;
            font-size: 13px;
            color: var(--text-dim, #888);
        }

        .verify-btn {
            background: var(--sys-accent, #546e7a);
            color: var(--sys-on-accent, #fff);
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .verify-btn:hover { background: var(--sys-accent-hover, #37474f); }
        .verify-btn:disabled { background: #999; color: #fff; cursor: not-allowed; }

        .results-summary {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-card {
            flex: 1;
            padding: 16px 20px;
            border-radius: 8px;
            text-align: center;
        }

        .summary-card .count {
            font-size: 28px;
            font-weight: 700;
            display: block;
        }

        .summary-card .label {
            font-size: 12px;
            text-transform: uppercase;
            margin-top: 4px;
            display: block;
        }

        .summary-ok { background: #d4edda; color: #155724; }
        .summary-created { background: #fff3cd; color: #856404; }
        .summary-updated { background: #cce5ff; color: #004085; }
        .summary-error { background: #f8d7da; color: #721c24; }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface, #fff);
            color: var(--text, #333);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08));
        }

        .results-table th {
            background: var(--surface-3, #f8f9fa);
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-muted, #666);
            border-bottom: 2px solid var(--border, #e0e0e0);
        }

        .results-table td {
            padding: 10px 16px;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            font-size: 14px;
        }

        .results-table tr:last-child td { border-bottom: none; }

        .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pill.ok { background: #d4edda; color: #155724; }
        .status-pill.created { background: #fff3cd; color: #856404; }
        .status-pill.updated { background: #cce5ff; color: #004085; }
        .status-pill.error { background: #f8d7da; color: #721c24; }
        .status-pill.pending { background: #ffe0b2; color: #8a4b00; }

        .detail-text { font-size: 12px; color: var(--text-muted, #666); }

        .fix-btn {
            margin-left: 10px;
            background: #8a4b00;
            color: #fff;
            border: none;
            padding: 5px 14px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .fix-btn:hover { background: #6d3b00; }
        .fix-btn:disabled { background: #bbb; cursor: not-allowed; }

        .placeholder-msg {
            text-align: center;
            padding: 60px 20px;
            background: var(--surface, #fff);
            border-radius: 8px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08));
            color: var(--text-dim, #888);
        }

        .placeholder-msg svg { color: #ccc; margin-bottom: 15px; }
        .placeholder-msg p { margin: 0; font-size: 14px; }

        /* The error variant of the placeholder (set inline from JS). */
        .placeholder-msg.is-error { color: #d13438; }

        .spinner-inline {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: currentColor; /* follows --sys-on-accent on the button */
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* ---- Dark mode overrides ----------------------------------------
           The result states (ok / created / updated / error / pending) are DATA:
           the green / amber / blue / red / orange hues are kept, but the pale
           wash is sunk and the saturated text lifted so they don't glow. */
        [data-theme-mode="dark"] .verify-btn:disabled { background: #3a4048; color: var(--text-faint, #999); }
        [data-theme-mode="dark"] .spinner-inline { border-color: rgba(0,0,0,0.2); border-top-color: currentColor; }
        [data-theme-mode="dark"] .placeholder-msg svg { color: var(--border, #3a4048); }
        [data-theme-mode="dark"] .placeholder-msg.is-error { color: var(--danger-text, #fca5a5); }

        [data-theme-mode="dark"] .summary-ok,
        [data-theme-mode="dark"] .status-pill.ok { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .summary-created,
        [data-theme-mode="dark"] .status-pill.created { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .summary-updated,
        [data-theme-mode="dark"] .status-pill.updated { background: #16293f; color: #93c5fd; }
        [data-theme-mode="dark"] .summary-error,
        [data-theme-mode="dark"] .status-pill.error { background: #3a1a1d; color: #fca5a5; }
        [data-theme-mode="dark"] .status-pill.pending { background: #3d2a10; color: #ffcc80; }

        [data-theme-mode="dark"] .fix-btn { background: #b26500; color: #fff; }
        [data-theme-mode="dark"] .fix-btn:hover { background: #8a4b00; }
        [data-theme-mode="dark"] .fix-btn:disabled { background: #4a505a; color: var(--text-faint, #999); }

        /* ---- Toolbar: search + module filter ---------------------------- */
        .verify-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .verify-toolbar input[type="search"],
        .verify-toolbar select {
            padding: 8px 12px;
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 6px;
            background: var(--surface, #fff);
            color: var(--text, #333);
            font-size: 13px;
        }
        .verify-toolbar input[type="search"] { min-width: 220px; flex: 0 1 260px; }
        .verify-toolbar select { min-width: 160px; }
        .verify-toolbar .toolbar-count {
            font-size: 12px;
            color: var(--text-dim, #888);
            margin-left: auto;
        }

        /* ---- Card grid -------------------------------------------------- */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
            gap: 10px;
        }
        .tcard {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 7px;
            padding: 10px 12px;
            cursor: pointer;
            transition: box-shadow 0.15s, transform 0.15s;
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-height: 62px;
        }
        .tcard:hover {
            box-shadow: 0 3px 10px var(--shadow, rgba(0,0,0,0.12));
            transform: translateY(-1px);
        }
        .tcard.is-hidden { display: none; }
        .tcard-name {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            line-height: 1.35;
            color: var(--text, #333);
            word-break: break-word;
        }
        .tcard-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            margin-top: auto;
        }
        .tcard-mod {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-width: 0;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--text-dim, #999);
        }
        .tcard-mod .mod-label {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Module colour as a small solid swatch — reads the same on any card
           background or theme, unlike a thin edge stripe that vanishes for the
           muted module accents (System's grey-blue, etc.). */
        .tcard-swatch {
            width: 9px; height: 9px;
            border-radius: 2px;
            flex: 0 0 auto;
            background: var(--mod-color, #90a4ae);
        }
        .tcard-dot {
            width: 9px; height: 9px;
            border-radius: 50%;
            flex: 0 0 auto;
            background: #7cb342;
        }
        .tcard-dot.ok { background: #2e9e44; }
        .tcard-dot.created { background: #d69e00; }
        .tcard-dot.updated { background: #1a73e8; }
        .tcard-dot.error { background: #d13438; }
        .tcard-dot.pending { background: #e8871e; }
        .tcard.has-fix { border-color: #e8871e; }
        .tcard .fix-flag {
            font-size: 10px; font-weight: 700; color: #8a4b00;
        }
        [data-theme-mode="dark"] .tcard-dot.ok { background: #4ade80; }
        [data-theme-mode="dark"] .tcard-dot.created { background: #fcd34d; }
        [data-theme-mode="dark"] .tcard-dot.updated { background: #93c5fd; }
        [data-theme-mode="dark"] .tcard-dot.error { background: #fca5a5; }
        [data-theme-mode="dark"] .tcard-dot.pending { background: #ffcc80; }
        [data-theme-mode="dark"] .tcard .fix-flag { color: #ffcc80; }

        .grid-empty {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px 20px;
            color: var(--text-dim, #888);
            font-size: 14px;
        }

        /* ---- Detail modal ----------------------------------------------- */
        .detail-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            display: none;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 20px;
            z-index: 1000;
            overflow-y: auto;
        }
        .detail-overlay.open { display: flex; }
        .detail-modal {
            background: var(--surface, #fff);
            color: var(--text, #333);
            border-radius: 10px;
            width: 100%;
            max-width: 660px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .detail-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background: var(--surface-3, #f8f9fa);
        }
        .detail-title {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 15px;
            font-weight: 700;
            word-break: break-word;
        }
        .detail-head-meta { display: flex; align-items: center; gap: 10px; }
        .detail-close {
            background: none; border: none; cursor: pointer;
            font-size: 22px; line-height: 1; color: var(--text-muted, #666);
            padding: 2px 6px; border-radius: 5px;
        }
        .detail-close:hover { background: var(--border-soft, #f0f0f0); color: var(--text, #333); }
        .detail-body { padding: 16px 20px; max-height: 68vh; overflow-y: auto; }
        .detail-section { margin-bottom: 22px; }
        .detail-section:last-child { margin-bottom: 0; }
        .detail-section h4 {
            margin: 0 0 10px 0;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted, #666);
        }
        .detail-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .detail-tbl th {
            text-align: left; padding: 6px 10px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            color: var(--text-muted, #666); font-weight: 600;
        }
        .detail-tbl td {
            padding: 6px 10px;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            vertical-align: top;
        }
        .detail-tbl tr:last-child td { border-bottom: none; }
        .detail-tbl code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
        }
        .kbadge {
            display: inline-block;
            font-size: 10px; font-weight: 700;
            padding: 1px 6px; border-radius: 4px;
            background: var(--surface-3, #eef1f4); color: var(--text-muted, #555);
            margin-left: 4px;
        }
        .kbadge.pri { background: #fff3cd; color: #856404; }
        .kbadge.uni { background: #cce5ff; color: #004085; }
        .detail-empty { font-size: 13px; color: var(--text-dim, #999); font-style: italic; }
        .detail-loading { text-align: center; padding: 30px; color: var(--text-dim, #888); }
        .detail-fixbar {
            margin-top: 4px; padding: 12px 16px;
            background: #fff8ef; border: 1px solid #ffd9a8; border-radius: 7px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            font-size: 13px; color: #8a4b00;
        }
        [data-theme-mode="dark"] .detail-fixbar { background: #2e2410; border-color: #4a3a18; color: #ffcc80; }
        [data-theme-mode="dark"] .kbadge.pri { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .kbadge.uni { background: #16293f; color: #93c5fd; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="db-verify-container">
        <div class="db-verify-header">
            <div>
                <h2><?php echo htmlspecialchars(t('system.db_verify.heading')); ?></h2>
                <p><?php echo htmlspecialchars(t('system.db_verify.intro')); ?></p>
            </div>
            <button class="verify-btn" id="verifyBtn" onclick="runVerification()">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <?php echo htmlspecialchars(t('system.db_verify.run')); ?>
            </button>
        </div>

        <div id="summaryArea"></div>
        <div id="toolbarArea"></div>
        <div id="resultsArea">
            <div class="placeholder-msg">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                </svg>
                <p><?php echo htmlspecialchars(t('system.db_verify.placeholder')); ?></p>
            </div>
        </div>
    </div>

    <!-- Table detail modal (live structure, populated on card click) -->
    <div class="detail-overlay" id="detailOverlay" onclick="if(event.target===this)closeDetail()">
        <div class="detail-modal" role="dialog" aria-modal="true">
            <div class="detail-head">
                <span class="detail-title" id="detailTitle"></span>
                <div class="detail-head-meta">
                    <span id="detailStatus"></span>
                    <button class="detail-close" onclick="closeDetail()" aria-label="Close">&times;</button>
                </div>
            </div>
            <div class="detail-body" id="detailBody"></div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
        async function runVerification() {
            const btn = document.getElementById('verifyBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-inline"></span> ' + window.t('system.db_verify.verifying');

            document.getElementById('summaryArea').innerHTML = '';
            document.getElementById('resultsArea').innerHTML = '<div class="placeholder-msg"><p>' + window.t('system.db_verify.checking') + '</p></div>';

            try {
                const response = await fetch('../../api/system/db_verify.php');
                const data = await response.json();

                if (data.success) {
                    MODULE_META = data.modules || {};
                    renderResults(data.results, data.total_tables);
                } else {
                    document.getElementById('resultsArea').innerHTML =
                        '<div class="placeholder-msg is-error"><p>' + escapeHtml(window.t('system.db_verify.error', { message: data.error })) + '</p></div>';
                }
            } catch (error) {
                document.getElementById('resultsArea').innerHTML =
                    '<div class="placeholder-msg is-error"><p>' + escapeHtml(window.t('system.db_verify.connect_fail', { message: error.message })) + '</p></div>';
            }

            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> ' + window.t('system.db_verify.run');
        }

        // Latest verify results + module metadata, held for filtering and the detail modal.
        let VERIFY_RESULTS = [];
        let MODULE_META = {};

        function renderResults(results, total) {
            VERIFY_RESULTS = results;

            const counts = { ok: 0, created: 0, updated: 0, error: 0 };
            results.forEach(r => counts[r.status] = (counts[r.status] || 0) + 1);

            let summaryHtml = '<div class="results-summary">';
            summaryHtml += `<div class="summary-card summary-ok"><span class="count">${counts.ok}</span><span class="label">${window.t('system.db_verify.count_ok')}</span></div>`;
            summaryHtml += `<div class="summary-card summary-created"><span class="count">${counts.created}</span><span class="label">${window.t('system.db_verify.count_created')}</span></div>`;
            summaryHtml += `<div class="summary-card summary-updated"><span class="count">${counts.updated}</span><span class="label">${window.t('system.db_verify.count_updated')}</span></div>`;
            summaryHtml += `<div class="summary-card summary-error"><span class="count">${counts.error}</span><span class="label">${window.t('system.db_verify.count_errors')}</span></div>`;
            summaryHtml += '</div>';
            document.getElementById('summaryArea').innerHTML = summaryHtml;

            // Module options: only the groups that actually have a table, sorted by label.
            const present = {};
            results.forEach(r => { present[r.module || 'other'] = true; });
            const modKeys = Object.keys(present).sort((a, b) => {
                const la = (MODULE_META[a] && MODULE_META[a].label) || a;
                const lb = (MODULE_META[b] && MODULE_META[b].label) || b;
                return la.localeCompare(lb);
            });
            let opts = `<option value="">${dt('system.db_verify.all_modules', 'All modules')}</option>`;
            modKeys.forEach(k => {
                const label = (MODULE_META[k] && MODULE_META[k].label) || k;
                const n = results.filter(r => (r.module || 'other') === k).length;
                opts += `<option value="${escapeHtml(k)}">${escapeHtml(label)} (${n})</option>`;
            });

            document.getElementById('toolbarArea').innerHTML =
                `<div class="verify-toolbar">
                    <input type="search" id="tableSearch" placeholder="${dt('system.db_verify.search_ph', 'Search tables…')}" oninput="applyFilters()" autocomplete="off">
                    <select id="moduleFilter" onchange="applyFilters()">${opts}</select>
                    <span class="toolbar-count" id="toolbarCount"></span>
                </div>`;

            renderGrid();
        }

        // Build the card grid from VERIFY_RESULTS (unfiltered — filtering just
        // toggles .is-hidden so we never rebuild the DOM on each keystroke).
        function renderGrid() {
            const grid = document.createElement('div');
            grid.className = 'card-grid';
            grid.id = 'cardGrid';

            VERIFY_RESULTS.forEach((r, i) => {
                const mod = r.module || 'other';
                const meta = MODULE_META[mod] || {};
                const modLabel = meta.label || mod;
                const color = meta.color || '#90a4ae';
                const hasFix = r.fix && r.fix.type === 'delete_orphans';

                const card = document.createElement('div');
                card.className = 'tcard' + (hasFix ? ' has-fix' : '');
                card.style.setProperty('--mod-color', color);
                card.dataset.table = r.table;
                card.dataset.module = mod;
                card.dataset.index = i;
                card.onclick = () => openDetail(i);
                card.innerHTML =
                    `<div class="tcard-name">${escapeHtml(r.table)}</div>
                     <div class="tcard-foot">
                        <span class="tcard-mod" title="${escapeHtml(modLabel)}"><span class="tcard-swatch"></span><span class="mod-label">${escapeHtml(modLabel)}</span></span>
                        ${hasFix ? '<span class="fix-flag">FIX</span>' : ''}
                        <span class="tcard-dot ${r.status}" title="${escapeHtml(statusLabel(r.status))}"></span>
                     </div>`;
                grid.appendChild(card);
            });

            const empty = document.createElement('div');
            empty.className = 'grid-empty is-hidden';
            empty.id = 'gridEmpty';
            empty.textContent = dt('system.db_verify.no_match', 'No tables match your filter.');
            grid.appendChild(empty);

            const area = document.getElementById('resultsArea');
            area.innerHTML = '';
            area.appendChild(grid);
            applyFilters();
        }

        // Client-side filter: table-name substring + module. Toggles visibility only.
        function applyFilters() {
            const q = (document.getElementById('tableSearch')?.value || '').trim().toLowerCase();
            const mod = document.getElementById('moduleFilter')?.value || '';
            let shown = 0;
            document.querySelectorAll('#cardGrid .tcard').forEach(card => {
                const matchQ = !q || card.dataset.table.toLowerCase().includes(q);
                const matchM = !mod || card.dataset.module === mod;
                const show = matchQ && matchM;
                card.classList.toggle('is-hidden', !show);
                if (show) shown++;
            });
            const emptyEl = document.getElementById('gridEmpty');
            if (emptyEl) emptyEl.classList.toggle('is-hidden', shown > 0);
            const countEl = document.getElementById('toolbarCount');
            if (countEl) countEl.textContent = dt('system.db_verify.showing', 'Showing {n} of {total}')
                .replace('{n}', shown).replace('{total}', VERIFY_RESULTS.length);
        }

        function statusLabel(status) {
            return status === 'ok'
                ? window.t('system.db_verify.status_ok')
                : status.charAt(0).toUpperCase() + status.slice(1);
        }

        // ---- Detail modal: live structure for one table --------------------
        async function openDetail(index) {
            const r = VERIFY_RESULTS[index];
            if (!r) return;
            const overlay = document.getElementById('detailOverlay');
            document.getElementById('detailTitle').textContent = r.table;
            document.getElementById('detailStatus').innerHTML =
                `<span class="status-pill ${r.status}">${escapeHtml(statusLabel(r.status))}</span>`;

            // Fix bar (if this table had orphaned rows) + verify details, then load structure.
            let head = '';
            if (r.details && r.details.length) {
                head += `<div class="detail-section"><h4>${dt('system.db_verify.verify_notes', 'Verification notes')}</h4>
                         <div class="detail-text">${escapeHtml(r.details.join('; '))}</div></div>`;
            }
            if (r.fix && r.fix.type === 'delete_orphans') {
                head += `<div class="detail-fixbar">
                    <span>${dt('system.db_verify.orphans_found', '{count} orphaned row(s) whose parent no longer exists.').replace('{count}', r.fix.count)}</span>
                    <button class="fix-btn" data-fix-table="${escapeHtml(r.fix.table)}" data-fix-count="${r.fix.count}">${dt('system.db_verify.fix', 'Fix')}</button>
                 </div>`;
            }
            document.getElementById('detailBody').innerHTML = head +
                `<div class="detail-loading">${dt('system.db_verify.loading_structure', 'Loading structure…')}</div>`;
            overlay.classList.add('open');

            // Wire the fix button (if present) before the async load replaces nothing below it.
            const fixBtn = document.querySelector('#detailBody .fix-btn');
            if (fixBtn) fixBtn.addEventListener('click', () => fixOrphans(fixBtn));

            try {
                const res = await fetch('../../api/system/db_describe.php?table=' + encodeURIComponent(r.table));
                const data = await res.json();
                const loading = document.querySelector('#detailBody .detail-loading');
                if (!data.success) {
                    if (loading) loading.textContent = data.missing
                        ? dt('system.db_verify.detail_missing', 'This table does not exist in the database yet.')
                        : (data.error || 'Could not load table structure.');
                    return;
                }
                if (loading) loading.outerHTML = renderStructure(data);
            } catch (e) {
                const loading = document.querySelector('#detailBody .detail-loading');
                if (loading) loading.textContent = dt('system.db_verify.detail_failed', 'Failed to load structure: {message}').replace('{message}', e.message);
            }
        }

        function renderStructure(d) {
            let html = '';

            // Columns
            html += `<div class="detail-section"><h4>${dt('system.db_verify.cols', 'Columns')} (${d.columns.length})</h4>`;
            html += '<table class="detail-tbl"><thead><tr>' +
                `<th>${dt('system.db_verify.col_name', 'Name')}</th><th>${dt('system.db_verify.col_type', 'Type')}</th><th>${dt('system.db_verify.col_null', 'Null')}</th><th>${dt('system.db_verify.col_default', 'Default')}</th></tr></thead><tbody>`;
            d.columns.forEach(c => {
                let badge = '';
                if (c.key === 'PRI') badge = '<span class="kbadge pri">PK</span>';
                else if (c.key === 'UNI') badge = '<span class="kbadge uni">UQ</span>';
                else if (c.key === 'MUL') badge = '<span class="kbadge">IDX</span>';
                const def = c.default === null ? '<span class="detail-empty">NULL</span>' : escapeHtml(String(c.default));
                html += `<tr>
                    <td><code>${escapeHtml(c.name)}</code>${badge}</td>
                    <td><code>${escapeHtml(c.type)}</code></td>
                    <td>${c.nullable ? 'yes' : 'no'}</td>
                    <td>${def}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';

            // Indexes
            html += `<div class="detail-section"><h4>${dt('system.db_verify.indexes', 'Indexes')} (${d.indexes.length})</h4>`;
            if (d.indexes.length) {
                html += '<table class="detail-tbl"><tbody>';
                d.indexes.forEach(ix => {
                    let badge = ix.primary ? '<span class="kbadge pri">PK</span>' : (ix.unique ? '<span class="kbadge uni">UNIQUE</span>' : '');
                    html += `<tr><td><code>${escapeHtml(ix.name)}</code>${badge}</td><td><code>${escapeHtml(ix.columns.join(', '))}</code></td></tr>`;
                });
                html += '</tbody></table>';
            } else {
                html += `<div class="detail-empty">${dt('system.db_verify.none', 'None')}</div>`;
            }
            html += '</div>';

            // Foreign keys
            html += `<div class="detail-section"><h4>${dt('system.db_verify.fks', 'Foreign keys')} (${d.foreign_keys.length})</h4>`;
            if (d.foreign_keys.length) {
                html += '<table class="detail-tbl"><thead><tr>' +
                    `<th>${dt('system.db_verify.col_name', 'Name')}</th><th>${dt('system.db_verify.fk_col', 'Column')}</th><th>${dt('system.db_verify.fk_ref', 'References')}</th><th>${dt('system.db_verify.fk_ondelete', 'On delete')}</th></tr></thead><tbody>`;
                d.foreign_keys.forEach(fk => {
                    html += `<tr>
                        <td><code>${escapeHtml(fk.name)}</code></td>
                        <td><code>${escapeHtml(fk.column)}</code></td>
                        <td><code>${escapeHtml(fk.references)}</code></td>
                        <td>${escapeHtml(fk.onDelete)}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            } else {
                html += `<div class="detail-empty">${dt('system.db_verify.none', 'None')}</div>`;
            }
            html += '</div>';

            return html;
        }

        function closeDetail() {
            document.getElementById('detailOverlay').classList.remove('open');
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeDetail();
        });

        // Delete the orphaned rows behind a 'pending' FK, then re-run verification.
        async function fixOrphans(btn) {
            const table = btn.getAttribute('data-fix-table');
            const count = btn.getAttribute('data-fix-count');
            const msg = dt('system.db_verify.fix_confirm', 'Permanently delete {count} orphaned row(s) from {table}? Their parent record no longer exists, so this data is unreachable.')
                .replace('{count}', count).replace('{table}', table);
            if (!confirm(msg)) return;

            btn.disabled = true;
            btn.textContent = dt('system.db_verify.fixing', 'Fixing…');
            try {
                const res = await fetch('../../api/system/fix_orphans.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ table })
                });
                const data = await res.json();
                if (!data.success) {
                    alert(dt('system.db_verify.fix_failed', 'Fix failed: {message}').replace('{message}', data.error || 'unknown error'));
                    btn.disabled = false;
                    btn.textContent = dt('system.db_verify.fix', 'Fix');
                    return;
                }
                // Re-run verification — the FK should now add cleanly and the row clears.
                runVerification();
            } catch (e) {
                alert(dt('system.db_verify.fix_failed', 'Fix failed: {message}').replace('{message}', e.message));
                btn.disabled = false;
                btn.textContent = dt('system.db_verify.fix', 'Fix');
            }
        }

        // t() with an English fallback so untranslated locales don't show raw keys.
        function dt(key, fallback) {
            const v = window.t(key);
            return (v && v !== key) ? v : fallback;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
