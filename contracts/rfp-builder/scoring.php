<?php
/**
 * RFP Builder — supplier scoring (Phase 5 step 5b).
 * Score one supplier at a time against every consolidated requirement.
 * Sidebar lets you switch between suppliers without leaving the page.
 * Autosave on every score / notes change. Per-category and overall
 * averages live at the foot, plus the team's average from other
 * analysts (if any have also scored).
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

        /* Two-column layout: supplier sidebar | scoring main */
        .scoring-layout {
            display: grid; grid-template-columns: 240px 1fr; gap: 16px;
            margin-bottom: 80px; /* space for the sticky averages bar */
        }

        .supplier-sidebar {
            background: white; border-radius: 10px; padding: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            position: sticky; top: 16px; align-self: start;
            max-height: calc(100vh - 80px); overflow-y: auto;
        }
        .supplier-sidebar h3 {
            font-size: 11px; color: #888; text-transform: uppercase;
            letter-spacing: 0.5px; margin: 0 0 8px 0;
        }
        .supplier-link {
            display: block; padding: 8px 10px; border-radius: 6px;
            font-size: 14px; color: #333; text-decoration: none;
            margin-bottom: 3px;
        }
        .supplier-link:hover { background: #f5f5f5; }
        .supplier-link.active { background: #fff7ed; color: #f59e0b; font-weight: 600; }
        .supplier-link .avg {
            font-size: 11px; color: #888; margin-top: 2px;
            display: block;
        }
        .supplier-link.active .avg { color: #d97706; }

        .scoring-main {
            display: flex; flex-direction: column; gap: 16px;
        }

        .supplier-banner {
            background: white; border-radius: 10px; padding: 18px 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex; gap: 18px; align-items: center;
        }
        .supplier-banner .name { flex: 1; }
        .supplier-banner .name .display { font-size: 18px; font-weight: 700; color: #222; }
        .supplier-banner .name .legal { font-size: 12px; color: #888; margin-top: 2px; }
        .supplier-banner .summary { display: flex; gap: 18px; font-size: 12px; color: #666; }
        .supplier-banner .summary strong {
            display: block; color: #222; font-size: 18px; font-variant-numeric: tabular-nums;
        }

        .category-card {
            background: white; border-radius: 10px; overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .cat-header {
            padding: 12px 22px; background: #fafbfc; border-bottom: 1px solid #f0f0f0;
            display: flex; align-items: center; gap: 12px;
        }
        .cat-header h2 {
            margin: 0; font-size: 15px; font-weight: 600; color: #222; flex: 1;
        }
        .cat-header .cat-avg {
            font-size: 13px; color: #555;
        }
        .cat-header .cat-avg strong {
            color: #222; font-variant-numeric: tabular-nums;
        }

        .req-row {
            padding: 14px 22px; border-bottom: 1px solid #f5f5f5;
            display: grid; grid-template-columns: 1fr 100px 240px;
            gap: 16px; align-items: start;
        }
        .req-row:last-child { border-bottom: none; }
        .req-text {
            font-size: 14px; color: #222; line-height: 1.5;
        }
        .req-text .pills {
            display: flex; gap: 6px; margin-bottom: 6px;
        }
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

        .score-control {
            display: flex; flex-direction: column; gap: 4px;
        }
        .score-control label {
            font-size: 11px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .score-control select {
            padding: 7px 10px; font-size: 14px; font-family: inherit;
            border: 1px solid #d1d5db; border-radius: 5px;
            background: white;
        }
        .score-control select.scored { background: #ecfdf5; border-color: #6ee7b7; }
        .score-control .save-status {
            font-size: 11px; color: #888; min-height: 14px;
        }
        .score-control .save-status.saving { color: #b45309; }
        .score-control .save-status.saved  { color: #047857; }
        .score-control .save-status.error  { color: #b91c1c; }

        .score-notes {
            display: flex; flex-direction: column; gap: 4px;
        }
        .score-notes label {
            font-size: 11px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .score-notes textarea {
            padding: 7px 10px; font-size: 13px; font-family: inherit;
            border: 1px solid #d1d5db; border-radius: 5px;
            resize: vertical; min-height: 38px;
        }

        /* Sticky bottom bar — shows running totals */
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
            <aside class="supplier-sidebar" id="supplierSidebar"></aside>
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
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const params  = new URLSearchParams(location.search);
        const rfpId      = params.get('id');
        const supplierId = params.get('supplier');
        let pageData = null;
        let suppliersList = []; // for sidebar
        const saveTimers = {};

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

            renderSidebar();
            renderMain();
            updateAverages();
        }

        function renderSidebar() {
            const sb = document.getElementById('supplierSidebar');
            sb.innerHTML = '<h3>Suppliers</h3>' + suppliersList.map(s => {
                const isActive = String(s.supplier_id) === String(supplierId);
                return `
                    <a class="supplier-link ${isActive ? 'active' : ''}"
                       href="?id=${encodeURIComponent(rfpId)}&supplier=${s.supplier_id}">
                        ${escapeHtml(s.display_name)}
                    </a>
                `;
            }).join('');
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
                        <div>
                            <strong>${agg.scorer_count}</strong>
                            <div>analyst${agg.scorer_count === 1 ? '' : 's'} scoring</div>
                        </div>
                        ${agg.avg_score !== null ? `
                            <div>
                                <strong>${agg.avg_score.toFixed(2)}</strong>
                                <div>team average</div>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;

            // Group requirements by category in their original order
            const reqsByCat = new Map();
            pageData.requirements.forEach(r => {
                const key = r.category_id || 0;
                if (!reqsByCat.has(key)) reqsByCat.set(key, []);
                reqsByCat.get(key).push(r);
            });

            const blocks = [];
            pageData.categories.forEach(c => {
                const rs = reqsByCat.get(c.id) || [];
                if (!rs.length) return;
                blocks.push(renderCategoryBlock(c, rs));
            });
            // Orphans (uncategorised)
            const orphans = reqsByCat.get(0) || [];
            if (orphans.length) {
                blocks.push(renderCategoryBlock(
                    { id: 0, name: 'Uncategorised' },
                    orphans
                ));
            }

            main.innerHTML = banner + blocks.join('');
        }

        function renderCategoryBlock(cat, reqs) {
            return `
                <div class="category-card">
                    <div class="cat-header">
                        <h2>${escapeHtml(cat.name)}</h2>
                        <div class="cat-avg" id="cat-avg-${cat.id}">—</div>
                    </div>
                    ${reqs.map(r => renderReqRow(r)).join('')}
                </div>
            `;
        }

        function renderReqRow(r) {
            const myScore = r.my && r.my.score !== null ? r.my.score : '';
            const myNotes = r.my && r.my.notes ? r.my.notes : '';
            const others  = r.others;

            const othersTag = others
                ? `<span class="others-tag">${others.scorer_count} other${others.scorer_count === 1 ? '' : 's'} avg ${others.avg_score.toFixed(2)}</span>`
                : '';

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
                    <div class="score-control">
                        <label>Score</label>
                        <select class="${myScore !== '' ? 'scored' : ''}"
                                onchange="onScoreChange(${r.id}, this)">
                            <option value="">— Not scored —</option>
                            <option value="0">0 — Does not meet</option>
                            <option value="1">1 — Poor</option>
                            <option value="2">2 — Below par</option>
                            <option value="3">3 — Acceptable</option>
                            <option value="4">4 — Good</option>
                            <option value="5">5 — Excellent</option>
                        </select>
                        <div class="save-status" id="ss-${r.id}-score"></div>
                    </div>
                    <div class="score-notes">
                        <label>Notes</label>
                        <textarea rows="2" oninput="onNotesChange(${r.id}, this)" placeholder="Why this score, evidence, caveats…">${escapeHtml(myNotes)}</textarea>
                    </div>
                </div>
            `;
        }

        // After render, set selected option on each select. Doing this
        // post-render keeps the template above clean.
        function applySelections() {
            pageData.requirements.forEach(r => {
                if (r.my && r.my.score !== null) {
                    const sel = document.querySelector('.req-row[data-req-id="' + r.id + '"] select');
                    if (sel) sel.value = String(r.my.score);
                }
            });
        }

        // ─── Save handlers ────────────────────────────────────────

        function onScoreChange(reqId, selEl) {
            selEl.classList.toggle('scored', selEl.value !== '');
            saveScore(reqId, selEl.value, getNotesFor(reqId), 'score');
        }

        function onNotesChange(reqId, taEl) {
            const key = reqId + '-notes';
            const statusEl = document.getElementById('ss-' + reqId + '-score');
            if (statusEl) {
                statusEl.textContent = 'saving…';
                statusEl.className = 'save-status saving';
            }
            clearTimeout(saveTimers[key]);
            // Debounce notes — don't fire on every keystroke.
            saveTimers[key] = setTimeout(() => {
                saveScore(reqId, getScoreFor(reqId), taEl.value, 'notes');
            }, 600);
        }

        async function saveScore(reqId, score, notes, source) {
            const statusEl = document.getElementById('ss-' + reqId + '-score');
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
                        score: score === '' ? null : score,
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
                // Update local data so the running averages stay correct.
                const r = pageData.requirements.find(x => x.id === reqId);
                if (r) {
                    if (!r.my) r.my = { score: null, notes: null };
                    r.my.score = score === '' ? null : (typeof score === 'string' ? parseInt(score, 10) : score);
                    r.my.notes = notes || null;
                }
                updateAverages();
                document.getElementById('abLastSaved').textContent = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            } catch (err) {
                if (statusEl) {
                    statusEl.textContent = 'error: ' + err.message;
                    statusEl.className = 'save-status error';
                }
            }
        }

        function getScoreFor(reqId) {
            const sel = document.querySelector('.req-row[data-req-id="' + reqId + '"] select');
            return sel ? sel.value : '';
        }
        function getNotesFor(reqId) {
            const ta = document.querySelector('.req-row[data-req-id="' + reqId + '"] textarea');
            return ta ? ta.value : '';
        }

        // ─── Running averages ─────────────────────────────────────

        function updateAverages() {
            // My overall
            const scored = pageData.requirements.filter(r => r.my && r.my.score !== null);
            const myAvg = scored.length > 0
                ? (scored.reduce((sum, r) => sum + r.my.score, 0) / scored.length)
                : null;
            document.getElementById('abMyOverall').textContent = myAvg === null ? '—' : myAvg.toFixed(2);
            document.getElementById('abScoredCount').textContent = scored.length + ' / ' + pageData.requirements.length;

            // Per-category averages
            const byCat = new Map();
            scored.forEach(r => {
                const k = r.category_id || 0;
                if (!byCat.has(k)) byCat.set(k, []);
                byCat.get(k).push(r.my.score);
            });
            // Walk each category in DOM and update its avg label
            pageData.categories.concat([{id: 0}]).forEach(c => {
                const cell = document.getElementById('cat-avg-' + c.id);
                if (!cell) return;
                const arr = byCat.get(c.id) || [];
                if (arr.length === 0) {
                    cell.innerHTML = '— · 0 / ' + pageData.requirements.filter(r => (r.category_id || 0) === c.id).length;
                } else {
                    const a = arr.reduce((s, x) => s + x, 0) / arr.length;
                    const tot = pageData.requirements.filter(r => (r.category_id || 0) === c.id).length;
                    cell.innerHTML = '<strong>' + a.toFixed(2) + '</strong> · ' + arr.length + ' / ' + tot;
                }
            });

            // Team overall (mine averaged in with others' aggregate). The
            // server-side aggregate doesn't refresh as I score, so estimate
            // it locally: assume the team average updates by replacing my
            // contribution proportionally. For an MVP it's enough to show
            // the server's number until a refresh.
            const agg = pageData.aggregate;
            if (agg && agg.scorer_count > 0 && agg.avg_score !== null) {
                document.getElementById('abTeamBlock').style.display = '';
                document.getElementById('abTeamOverall').textContent = agg.avg_score.toFixed(2);
            } else if (myAvg !== null) {
                // I'm the first analyst. Show my own average in the team slot.
                document.getElementById('abTeamBlock').style.display = '';
                document.getElementById('abTeamOverall').textContent = myAvg.toFixed(2);
            } else {
                document.getElementById('abTeamBlock').style.display = 'none';
            }
        }

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

        // After initial render, apply selected option values on every select
        // (templating selected options in markup is fiddly; this is cleaner).
        const renderObserver = new MutationObserver(() => {
            if (pageData && document.querySelector('.req-row select')) {
                applySelections();
                renderObserver.disconnect();
            }
        });
        renderObserver.observe(document.getElementById('scoringMain'), { childList: true });
    </script>
</body>
</html>
