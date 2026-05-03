<?php
/**
 * RFP Builder — supplier scoring (Phase 5 step 5b).
 *
 * Score one supplier at a time against every consolidated requirement
 * with click-to-light score boxes (0–5, red-to-green colour gradient)
 * and a generously-sized notes field below each. Left sidebar carries
 * the supplier picker and a live "Score by category" panel that
 * updates as you score. Sticky bottom bar shows the running totals
 * plus a spider-web button that opens a full-screen radar chart of
 * the per-category averages.
 *
 * All scores autosave on change. Multi-analyst rollup is single-blind
 * (other analysts' counts and averages, never names or individual
 * scores).
 */
session_start();
require_once '../../config.php';

$current_page = 'rfp-builder';
$path_prefix  = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Scoring</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/chart.min.js"></script>
    <style>
        body { overflow: auto; height: auto; }
        .page-wrap { padding: 30px 40px; background: #f5f5f5; min-height: calc(100vh - 48px); box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: #888; margin-bottom: 8px; }
        .breadcrumb a { color: #666; text-decoration: none; }
        .breadcrumb a:hover { color: #f59e0b; }
        .breadcrumb span.sep { margin: 0 6px; color: #ccc; }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #222; }
        .page-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s; font-family: inherit;
        }
        .btn-secondary { background: white; color: #333; border-color: #ddd; }
        .btn-secondary:hover { background: #f5f5f5; }

        /* ─── Layout ─────────────────────────────────────────────── */

        .scoring-layout {
            display: grid; grid-template-columns: 300px 1fr; gap: 16px;
            margin-bottom: 80px; /* clear of sticky bottom bar */
        }
        .scoring-sidebar {
            background: white; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            position: sticky; top: 16px; align-self: start;
            max-height: calc(100vh - 80px); overflow-y: auto;
        }
        .sidebar-section { padding: 14px 16px; }
        .sidebar-section + .sidebar-section { border-top: 1px solid #eef0f2; }
        .sidebar-section h3 {
            font-size: 11px; color: #888; text-transform: uppercase;
            letter-spacing: 0.5px; margin: 0 0 8px 0;
        }

        .supplier-link {
            display: block; padding: 7px 10px; border-radius: 5px;
            font-size: 13px; color: #333; text-decoration: none;
            margin-bottom: 2px; line-height: 1.3;
        }
        .supplier-link:hover { background: #f5f5f5; }
        .supplier-link.active { background: #fff7ed; color: #f59e0b; font-weight: 600; }

        .cat-score-row {
            display: flex; align-items: center; gap: 8px;
            padding: 7px 10px; border-radius: 5px;
            font-size: 13px; cursor: pointer; user-select: none;
            margin-bottom: 2px; line-height: 1.3;
        }
        .cat-score-row:hover { background: #f5f5f5; }
        .cat-score-row .cat-name { flex: 1; color: #333; min-width: 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .cat-score-row .cat-avg {
            font-weight: 700; font-size: 13px; font-variant-numeric: tabular-nums;
            color: #888; min-width: 32px; text-align: right;
        }
        .cat-score-row.has-score .cat-avg { color: #047857; }
        .cat-score-row .cat-count {
            font-size: 11px; color: #aaa; font-variant-numeric: tabular-nums;
        }

        .scoring-main { display: flex; flex-direction: column; gap: 16px; }

        .supplier-banner {
            background: white; border-radius: 10px; padding: 18px 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex; gap: 18px; align-items: center;
        }
        .supplier-banner .name { flex: 1; }
        .supplier-banner .name .display { font-size: 18px; font-weight: 700; color: #222; }
        .supplier-banner .name .legal { font-size: 12px; color: #888; margin-top: 2px; }
        .supplier-banner .summary { display: flex; gap: 18px; font-size: 12px; color: #666; }
        .supplier-banner .summary .sm-block strong {
            display: block; color: #222; font-size: 18px; font-variant-numeric: tabular-nums;
        }

        .category-card {
            background: white; border-radius: 10px; overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            scroll-margin-top: 16px;
        }
        .cat-header {
            padding: 12px 22px; background: #fafbfc; border-bottom: 1px solid #f0f0f0;
            display: flex; align-items: center; gap: 12px;
        }
        .cat-header h2 {
            margin: 0; font-size: 15px; font-weight: 600; color: #222; flex: 1;
        }
        .cat-header .cat-avg-inline {
            font-size: 13px; color: #555; font-variant-numeric: tabular-nums;
        }
        .cat-header .cat-avg-inline strong { color: #222; }

        .req-row {
            padding: 18px 22px; border-bottom: 1px solid #f5f5f5;
            display: flex; flex-direction: column; gap: 12px;
        }
        .req-row:last-child { border-bottom: none; }

        .req-text {
            font-size: 14px; color: #222; line-height: 1.55;
        }
        .req-text .pills { display: flex; gap: 6px; margin-bottom: 6px; }
        .pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .pill.type-requirement { background: #dbeafe; color: #1e40af; }
        .pill.type-pain_point  { background: #fef3c7; color: #92400e; }
        .pill.type-challenge   { background: #ede9fe; color: #5b21b6; }
        .pill.prio-critical { background: #fee2e2; color: #991b1b; }
        .pill.prio-high     { background: #fed7aa; color: #9a3412; }
        .pill.prio-medium   { background: #e5e7eb; color: #374151; }
        .pill.prio-low      { background: #f3f4f6; color: #6b7280; }
        .req-text .others-tag {
            display: inline-block; margin-top: 6px;
            background: #ede9fe; color: #5b21b6;
            padding: 2px 8px; border-radius: 9px;
            font-size: 11px; font-weight: 500;
        }

        /* ─── Score boxes (0-5 click-to-light) ───────────────────── */

        .score-row { display: flex; align-items: center; gap: 14px; }
        .score-row .score-label {
            font-size: 11px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.4px;
            min-width: 50px;
        }
        .score-boxes { display: flex; gap: 6px; }
        .score-box {
            width: 42px; height: 42px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #d1d5db; border-radius: 8px;
            background: white; color: #6b7280;
            font-size: 16px; font-weight: 700;
            cursor: pointer; user-select: none;
            transition: all 0.12s;
            font-family: inherit;
        }
        .score-box:hover {
            border-color: #9ca3af; color: #374151;
            transform: translateY(-1px);
        }
        /* Selected state: each score gets its own colour on the
           red→green spectrum. Lit-up box uses solid background + white
           text + matching darker border + small shadow for tactile feel. */
        .score-box.selected.s0 { background: #ef4444; border-color: #b91c1c; color: white; box-shadow: 0 2px 6px rgba(239,68,68,0.4); }
        .score-box.selected.s1 { background: #f97316; border-color: #c2410c; color: white; box-shadow: 0 2px 6px rgba(249,115,22,0.4); }
        .score-box.selected.s2 { background: #eab308; border-color: #a16207; color: white; box-shadow: 0 2px 6px rgba(234,179,8,0.4); }
        .score-box.selected.s3 { background: #84cc16; border-color: #4d7c0f; color: white; box-shadow: 0 2px 6px rgba(132,204,22,0.4); }
        .score-box.selected.s4 { background: #22c55e; border-color: #15803d; color: white; box-shadow: 0 2px 6px rgba(34,197,94,0.4); }
        .score-box.selected.s5 { background: #16a34a; border-color: #166534; color: white; box-shadow: 0 2px 6px rgba(22,163,74,0.5); }

        .score-row .save-status {
            font-size: 11px; color: #888; min-width: 80px;
        }
        .score-row .save-status.saving { color: #b45309; }
        .score-row .save-status.saved  { color: #047857; }
        .score-row .save-status.error  { color: #b91c1c; }

        /* ─── Notes (full width) ─────────────────────────────────── */

        .notes-block { display: flex; flex-direction: column; gap: 4px; }
        .notes-block label {
            font-size: 11px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .notes-block textarea {
            padding: 10px 12px; font-size: 13px; font-family: inherit;
            border: 1px solid #d1d5db; border-radius: 6px;
            resize: vertical; min-height: 70px; line-height: 1.5;
            width: 100%; box-sizing: border-box;
        }

        /* ─── Sticky bottom bar ──────────────────────────────────── */

        .averages-bar {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: #1f2937; color: white; padding: 12px 24px;
            display: flex; align-items: center; gap: 24px;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.15);
            z-index: 800;
            font-size: 13px;
        }
        .averages-bar .ab-block {
            display: flex; flex-direction: column;
        }
        .averages-bar .ab-label {
            font-size: 11px; color: rgba(255,255,255,0.6);
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .averages-bar .ab-value {
            font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums;
            margin-top: 2px;
        }
        .averages-bar .ab-grow { flex: 1; }
        .averages-bar .ab-spider {
            background: rgba(255,255,255,0.1); color: white;
            border: 1px solid rgba(255,255,255,0.2); border-radius: 8px;
            padding: 8px 14px; font-size: 13px; cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            font-family: inherit;
        }
        .averages-bar .ab-spider:hover { background: rgba(255,255,255,0.2); }
        .averages-bar .ab-spider svg { width: 18px; height: 18px; }

        /* ─── Spider modal (full screen) ─────────────────────────── */

        .spider-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.7);
            display: flex; align-items: center; justify-content: center;
            z-index: 1000;
        }
        .spider-modal {
            background: white; border-radius: 12px;
            width: 90vw; height: 90vh; max-width: 1100px;
            display: flex; flex-direction: column;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4); overflow: hidden;
        }
        .spider-header {
            padding: 14px 22px; border-bottom: 1px solid #eee;
            display: flex; align-items: center; gap: 12px;
        }
        .spider-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #222; flex: 1; }
        .spider-header .close-x {
            background: none; border: none; font-size: 24px; color: #888;
            cursor: pointer; padding: 0; line-height: 1;
        }
        .spider-body {
            flex: 1; padding: 24px; display: flex; align-items: center; justify-content: center;
            min-height: 0; /* required so canvas inside doesn't blow out */
        }
        .spider-body canvas { max-width: 100%; max-height: 100%; }
        .spider-empty {
            color: #999; font-size: 14px; font-style: italic; text-align: center;
        }

        .empty-card, .loading, .error-state {
            background: white; border-radius: 10px; padding: 40px 24px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            color: #888;
        }
        .error-state { color: #d13438; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../">Contracts</a><span class="sep">›</span>
            <a href="./">RFP Builder</a><span class="sep">›</span>
            <a id="bcRfp" href="#">-</a><span class="sep">›</span>
            <a id="bcSuppliers" href="#">Suppliers</a><span class="sep">›</span>
            <span>Scoring</span>
        </div>

        <div class="page-header">
            <h1>Scoring</h1>
            <div class="page-actions">
                <a id="suppliersLink" href="#" class="btn btn-secondary">&larr; Suppliers</a>
            </div>
        </div>

        <div id="loadingEl" class="loading">Loading…</div>
        <div id="contentEl" class="scoring-layout" style="display:none;">
            <aside class="scoring-sidebar">
                <div class="sidebar-section">
                    <h3>Suppliers</h3>
                    <div id="sbSuppliers"></div>
                </div>
                <div class="sidebar-section">
                    <h3>Score by category</h3>
                    <div id="sbCategories"></div>
                </div>
            </aside>
            <main class="scoring-main" id="scoringMain"></main>
        </div>
        <div id="errorEl" class="error-state" style="display:none;"></div>
    </div>

    <div id="averagesBar" class="averages-bar" style="display:none;">
        <div class="ab-block">
            <span class="ab-label">My overall</span>
            <span class="ab-value" id="abMyOverall">—</span>
        </div>
        <div class="ab-block">
            <span class="ab-label">Scored</span>
            <span class="ab-value" id="abScoredCount">— / —</span>
        </div>
        <div class="ab-block" id="abTeamBlock" style="display:none;">
            <span class="ab-label">Team avg (incl. me)</span>
            <span class="ab-value" id="abTeamOverall">—</span>
        </div>
        <div class="ab-grow"></div>
        <div class="ab-block" style="text-align:right;">
            <span class="ab-label">Autosaved</span>
            <span class="ab-value" id="abLastSaved" style="font-size:13px;">—</span>
        </div>
        <button class="ab-spider" onclick="openSpiderModal()" title="Show category radar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="12 3 21 9 19 19 5 19 3 9 12 3"/>
                <polygon points="12 7 17.5 10.5 16.5 16 7.5 16 6.5 10.5 12 7"/>
                <line x1="12" y1="3"  x2="12" y2="19"/>
                <line x1="3"  y1="9"  x2="21" y2="9"/>
                <line x1="5"  y1="19" x2="19" y2="19"/>
                <line x1="3"  y1="9"  x2="19" y2="19"/>
                <line x1="21" y1="9"  x2="5"  y2="19"/>
            </svg>
            Spider
        </button>
    </div>

    <!-- Spider modal -->
    <div id="spiderModal" class="spider-backdrop" style="display:none;">
        <div class="spider-modal">
            <div class="spider-header">
                <h3 id="spiderTitle">Score by category</h3>
                <button class="close-x" onclick="closeSpiderModal()">&times;</button>
            </div>
            <div class="spider-body">
                <canvas id="spiderCanvas"></canvas>
                <div id="spiderEmpty" class="spider-empty" style="display:none;">
                    No scores yet — score a few requirements and the chart will fill in.
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const params  = new URLSearchParams(location.search);
        const rfpId      = params.get('id');
        const supplierId = params.get('supplier');
        let pageData = null;
        let suppliersList = [];
        const noteSaveTimers = {};
        let spiderChart = null;

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId || !supplierId) {
                showError('Missing rfp or supplier id. <a href="./">Back to list</a>.');
                return;
            }
            document.getElementById('suppliersLink').href = 'suppliers.php?id=' + encodeURIComponent(rfpId);
            document.getElementById('bcSuppliers').href   = 'suppliers.php?id=' + encodeURIComponent(rfpId);
            loadAll();
        });

        async function loadAll() {
            try {
                const [rfpRes, scoresRes, invRes] = await Promise.all([
                    fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId)).then(r => r.json()),
                    fetch(API_BASE + 'get_scores.php?rfp_id=' + encodeURIComponent(rfpId) + '&supplier_id=' + encodeURIComponent(supplierId)).then(r => r.json()),
                    fetch(API_BASE + 'get_invited_suppliers.php?rfp_id=' + encodeURIComponent(rfpId)).then(r => r.json())
                ]);
                if (!rfpRes.success)    throw new Error(rfpRes.error    || 'Failed to load RFP');
                if (!scoresRes.success) throw new Error(scoresRes.error || 'Failed to load scores');
                if (!invRes.success)    throw new Error(invRes.error    || 'Failed to load suppliers');

                const bc = document.getElementById('bcRfp');
                bc.textContent = rfpRes.rfp.name;
                bc.href = 'view.php?id=' + encodeURIComponent(rfpId);

                pageData = scoresRes;
                suppliersList = invRes.invited;
                document.getElementById('loadingEl').style.display = 'none';
                render();
            } catch (err) {
                showError(escapeHtml(err.message));
            }
        }

        function render() {
            document.getElementById('contentEl').style.display = 'grid';
            document.getElementById('averagesBar').style.display = 'flex';

            renderSidebarSuppliers();
            renderMain();
            updateAverages();
            renderSidebarCategories(); // depends on the per-category averages
        }

        function renderSidebarSuppliers() {
            const sb = document.getElementById('sbSuppliers');
            sb.innerHTML = suppliersList.map(s => {
                const isActive = String(s.supplier_id) === String(supplierId);
                return `
                    <a class="supplier-link ${isActive ? 'active' : ''}"
                       href="?id=${encodeURIComponent(rfpId)}&supplier=${s.supplier_id}">
                        ${escapeHtml(s.display_name)}
                    </a>
                `;
            }).join('');
        }

        function renderSidebarCategories() {
            // Build a row per category that has at least one requirement,
            // showing my running average and "X / Y scored" count. Click
            // scrolls the main pane to that category.
            const reqsByCat = groupReqsByCategory();
            const cats = pageData.categories.concat(
                reqsByCat.has(0) ? [{ id: 0, name: 'Uncategorised' }] : []
            );

            const sb = document.getElementById('sbCategories');
            sb.innerHTML = cats.map(c => {
                const reqs = reqsByCat.get(c.id) || [];
                if (reqs.length === 0) return '';
                const scored = reqs.filter(r => r.my && r.my.score !== null);
                const avg = scored.length ? (scored.reduce((s, r) => s + r.my.score, 0) / scored.length) : null;
                const hasScore = scored.length > 0;
                const avgText = avg === null ? '—' : avg.toFixed(2);
                return `
                    <div class="cat-score-row ${hasScore ? 'has-score' : ''}" onclick="scrollToCategory(${c.id})">
                        <div class="cat-name" title="${escapeHtml(c.name)}">${escapeHtml(c.name)}</div>
                        <div class="cat-count">${scored.length}/${reqs.length}</div>
                        <div class="cat-avg">${avgText}</div>
                    </div>
                `;
            }).join('');
        }

        function scrollToCategory(catId) {
            const el = document.getElementById('cat-card-' + catId);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function renderMain() {
            const main = document.getElementById('scoringMain');
            const sup  = pageData.supplier;
            const agg  = pageData.aggregate;

            const banner = `
                <div class="supplier-banner">
                    <div class="name">
                        <div class="display">${escapeHtml(sup.display_name)}</div>
                        ${sup.legal_name && sup.legal_name !== sup.display_name
                            ? `<div class="legal">Legal: ${escapeHtml(sup.legal_name)}</div>`
                            : ''}
                    </div>
                    <div class="summary">
                        <div class="sm-block">
                            <strong>${agg.scorer_count}</strong>
                            <div>analyst${agg.scorer_count === 1 ? '' : 's'} scoring</div>
                        </div>
                        ${agg.avg_score !== null ? `
                            <div class="sm-block">
                                <strong>${agg.avg_score.toFixed(2)}</strong>
                                <div>team average</div>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;

            const reqsByCat = groupReqsByCategory();
            const blocks = [];
            pageData.categories.forEach(c => {
                const rs = reqsByCat.get(c.id) || [];
                if (!rs.length) return;
                blocks.push(renderCategoryBlock(c, rs));
            });
            const orphans = reqsByCat.get(0) || [];
            if (orphans.length) {
                blocks.push(renderCategoryBlock({ id: 0, name: 'Uncategorised' }, orphans));
            }

            main.innerHTML = banner + blocks.join('');
        }

        function groupReqsByCategory() {
            const m = new Map();
            pageData.requirements.forEach(r => {
                const key = r.category_id || 0;
                if (!m.has(key)) m.set(key, []);
                m.get(key).push(r);
            });
            return m;
        }

        function renderCategoryBlock(cat, reqs) {
            return `
                <div class="category-card" id="cat-card-${cat.id}">
                    <div class="cat-header">
                        <h2>${escapeHtml(cat.name)}</h2>
                        <div class="cat-avg-inline" id="cat-avg-${cat.id}">—</div>
                    </div>
                    ${reqs.map(r => renderReqRow(r)).join('')}
                </div>
            `;
        }

        function renderReqRow(r) {
            const myScore = r.my && r.my.score !== null ? r.my.score : null;
            const myNotes = r.my && r.my.notes ? r.my.notes : '';
            const others  = r.others;

            const othersTag = others
                ? `<span class="others-tag">${others.scorer_count} other${others.scorer_count === 1 ? '' : 's'} avg ${others.avg_score.toFixed(2)}</span>`
                : '';

            const scoreBoxes = [0,1,2,3,4,5].map(n => {
                const selected = myScore === n;
                return `<button type="button" class="score-box ${selected ? 'selected s' + n : ''}" data-score="${n}" onclick="selectScore(${r.id}, ${n})">${n}</button>`;
            }).join('');

            return `
                <div class="req-row" data-req-id="${r.id}" data-cat-id="${r.category_id || 0}">
                    <div class="req-text">
                        <div class="pills">
                            <span class="pill type-${escapeHtml(r.requirement_type)}">${escapeHtml(r.requirement_type.replace('_', ' '))}</span>
                            <span class="pill prio-${escapeHtml(r.priority)}">${escapeHtml(r.priority)}</span>
                        </div>
                        ${escapeHtml(r.requirement_text)}
                        ${othersTag}
                    </div>
                    <div class="score-row">
                        <span class="score-label">Score</span>
                        <div class="score-boxes" id="boxes-${r.id}">${scoreBoxes}</div>
                        <span class="save-status" id="ss-${r.id}"></span>
                    </div>
                    <div class="notes-block">
                        <label>Notes</label>
                        <textarea rows="3" oninput="onNotesChange(${r.id}, this)" placeholder="Why this score, evidence, caveats…">${escapeHtml(myNotes)}</textarea>
                    </div>
                </div>
            `;
        }

        // ─── Score selection ─────────────────────────────────────

        function selectScore(reqId, value) {
            // Click-to-toggle: clicking the already-selected box clears it.
            const r = pageData.requirements.find(x => x.id === reqId);
            const wasSelected = r && r.my && r.my.score === value;
            const newScore = wasSelected ? null : value;

            // Optimistic UI — update boxes immediately.
            const boxesEl = document.getElementById('boxes-' + reqId);
            if (boxesEl) {
                boxesEl.querySelectorAll('.score-box').forEach(btn => {
                    const n = parseInt(btn.dataset.score, 10);
                    const isSel = newScore !== null && n === newScore;
                    btn.classList.toggle('selected', isSel);
                    // Strip any prior s0..s5 class then add the right one
                    [...btn.classList].forEach(c => { if (/^s[0-5]$/.test(c)) btn.classList.remove(c); });
                    if (isSel) btn.classList.add('s' + n);
                });
            }

            saveScore(reqId, newScore, getNotesFor(reqId));
        }

        function onNotesChange(reqId, taEl) {
            const key = reqId + '-notes';
            const statusEl = document.getElementById('ss-' + reqId);
            if (statusEl) {
                statusEl.textContent = 'saving…';
                statusEl.className = 'save-status saving';
            }
            clearTimeout(noteSaveTimers[key]);
            noteSaveTimers[key] = setTimeout(() => {
                saveScore(reqId, getScoreFor(reqId), taEl.value);
            }, 600);
        }

        async function saveScore(reqId, score, notes) {
            const statusEl = document.getElementById('ss-' + reqId);
            if (statusEl) {
                statusEl.textContent = 'saving…';
                statusEl.className = 'save-status saving';
            }
            try {
                const res = await fetch(API_BASE + 'save_score.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        rfp_id: parseInt(rfpId, 10),
                        supplier_id: parseInt(supplierId, 10),
                        consolidated_id: reqId,
                        score: score,
                        notes: notes || null
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Save failed');
                if (statusEl) {
                    statusEl.textContent = 'saved';
                    statusEl.className = 'save-status saved';
                    setTimeout(() => {
                        if (statusEl.textContent === 'saved') statusEl.textContent = '';
                    }, 1500);
                }
                // Update local data
                const r = pageData.requirements.find(x => x.id === reqId);
                if (r) {
                    if (!r.my) r.my = { score: null, notes: null };
                    r.my.score = (score === null || score === '') ? null : parseInt(score, 10);
                    r.my.notes = notes || null;
                }
                updateAverages();
                renderSidebarCategories();
                document.getElementById('abLastSaved').textContent = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            } catch (err) {
                if (statusEl) {
                    statusEl.textContent = 'error';
                    statusEl.className = 'save-status error';
                    statusEl.title = err.message;
                }
            }
        }

        function getScoreFor(reqId) {
            const r = pageData.requirements.find(x => x.id === reqId);
            return r && r.my && r.my.score !== null ? r.my.score : null;
        }
        function getNotesFor(reqId) {
            const ta = document.querySelector('.req-row[data-req-id="' + reqId + '"] textarea');
            return ta ? ta.value : '';
        }

        // ─── Averages ─────────────────────────────────────────────

        function updateAverages() {
            const scored = pageData.requirements.filter(r => r.my && r.my.score !== null);
            const myAvg = scored.length > 0
                ? (scored.reduce((sum, r) => sum + r.my.score, 0) / scored.length)
                : null;
            document.getElementById('abMyOverall').textContent  = myAvg === null ? '—' : myAvg.toFixed(2);
            document.getElementById('abScoredCount').textContent = scored.length + ' / ' + pageData.requirements.length;

            // Per-category inline averages
            const byCat = new Map();
            scored.forEach(r => {
                const k = r.category_id || 0;
                if (!byCat.has(k)) byCat.set(k, []);
                byCat.get(k).push(r.my.score);
            });
            pageData.categories.concat([{id: 0}]).forEach(c => {
                const cell = document.getElementById('cat-avg-' + c.id);
                if (!cell) return;
                const arr = byCat.get(c.id) || [];
                const tot = pageData.requirements.filter(r => (r.category_id || 0) === c.id).length;
                if (arr.length === 0) {
                    cell.innerHTML = '— · 0 / ' + tot;
                } else {
                    const a = arr.reduce((s, x) => s + x, 0) / arr.length;
                    cell.innerHTML = '<strong>' + a.toFixed(2) + '</strong> · ' + arr.length + ' / ' + tot;
                }
            });

            const agg = pageData.aggregate;
            if (agg && agg.scorer_count > 0 && agg.avg_score !== null) {
                document.getElementById('abTeamBlock').style.display = '';
                document.getElementById('abTeamOverall').textContent = agg.avg_score.toFixed(2);
            } else if (myAvg !== null) {
                document.getElementById('abTeamBlock').style.display = '';
                document.getElementById('abTeamOverall').textContent = myAvg.toFixed(2);
            } else {
                document.getElementById('abTeamBlock').style.display = 'none';
            }
        }

        // ─── Spider/radar modal ──────────────────────────────────

        function openSpiderModal() {
            document.getElementById('spiderModal').style.display = 'flex';
            document.getElementById('spiderTitle').textContent =
                'Score by category — ' + (pageData.supplier.display_name || '');
            // Defer so the modal has its size before Chart.js measures
            setTimeout(renderSpider, 30);
        }

        function closeSpiderModal() {
            document.getElementById('spiderModal').style.display = 'none';
            if (spiderChart) {
                spiderChart.destroy();
                spiderChart = null;
            }
        }

        function renderSpider() {
            const reqsByCat = groupReqsByCategory();
            const labels = [];
            const myData = [];
            const othersData = [];
            let anyOthers = false;

            pageData.categories.forEach(c => {
                const reqs = reqsByCat.get(c.id) || [];
                if (reqs.length === 0) return;
                labels.push(c.name);
                const myScored = reqs.filter(r => r.my && r.my.score !== null);
                myData.push(myScored.length ? (myScored.reduce((s, r) => s + r.my.score, 0) / myScored.length) : 0);

                // Others' avg per category — average of per-requirement avg_score
                const othersScored = reqs.filter(r => r.others && r.others.scorer_count > 0);
                if (othersScored.length > 0) {
                    anyOthers = true;
                    othersData.push(othersScored.reduce((s, r) => s + r.others.avg_score, 0) / othersScored.length);
                } else {
                    othersData.push(0);
                }
            });

            const empty = document.getElementById('spiderEmpty');
            const canvas = document.getElementById('spiderCanvas');
            const myAny = myData.some(v => v > 0);

            if (!myAny && !anyOthers) {
                canvas.style.display = 'none';
                empty.style.display = '';
                return;
            }
            canvas.style.display = '';
            empty.style.display = 'none';

            if (spiderChart) { spiderChart.destroy(); spiderChart = null; }

            const datasets = [{
                label: 'My scores',
                data: myData,
                backgroundColor: 'rgba(245, 158, 11, 0.25)',
                borderColor: '#f59e0b',
                borderWidth: 2,
                pointBackgroundColor: '#f59e0b',
                pointRadius: 4,
            }];
            if (anyOthers) {
                datasets.push({
                    label: 'Other analysts (avg)',
                    data: othersData,
                    backgroundColor: 'rgba(139, 92, 246, 0.15)',
                    borderColor: '#8b5cf6',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    pointBackgroundColor: '#8b5cf6',
                    pointRadius: 3,
                });
            }

            spiderChart = new Chart(canvas, {
                type: 'radar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            min: 0, max: 5,
                            ticks: { stepSize: 1, color: '#666', backdropColor: 'transparent' },
                            angleLines: { color: '#ddd' },
                            grid: { color: '#eee' },
                            pointLabels: { font: { size: 12, family: 'Segoe UI, Tahoma, sans-serif' } }
                        }
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { family: 'Segoe UI, Tahoma, sans-serif' } } },
                        tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ' + (ctx.raw === 0 ? '—' : ctx.raw.toFixed(2)) } }
                    }
                }
            });
        }

        // ─── Helpers ─────────────────────────────────────────────

        function showError(html) {
            document.getElementById('loadingEl').style.display = 'none';
            const el = document.getElementById('errorEl');
            el.innerHTML = html;
            el.style.display = 'block';
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
