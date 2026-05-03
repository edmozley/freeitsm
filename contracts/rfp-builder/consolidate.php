<?php
/**
 * RFP Builder — consolidated requirements browser (Phase 3 step 3a).
 * Read-only flat-ish view of the Pass 2 AI output: consolidated
 * requirements grouped by category, with source-quote expand,
 * AI rationale, and a conflicts section. Editing tools (split/
 * merge/edit/add-custom) and conflict resolution land in 3b/3c/3d.
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
    <title>Service Desk - Consolidated requirements</title>
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
        .btn-primary { background: #f59e0b; color: white; }
        .btn-primary:hover:not(:disabled) { background: #d97706; }
        .btn-primary:disabled { background: #fcd34d; cursor: not-allowed; }
        .btn-secondary { background: white; color: #333; border-color: #ddd; }
        .btn-secondary:hover { background: #f5f5f5; }
        .btn-link {
            background: none; color: #2563eb; border: none; padding: 0;
            font-size: 13px; cursor: pointer; font-family: inherit;
        }
        .btn-link:hover { text-decoration: underline; }

        .stats-strip {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: white; border-radius: 8px; padding: 14px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-left: 4px solid #ddd;
        }
        .stat-card.cats     { border-left-color: #6b7280; }
        .stat-card.cons     { border-left-color: #3b82f6; }
        .stat-card.conf     { border-left-color: #ef4444; }
        .stat-card.linked   { border-left-color: #10b981; }
        .stat-card .stat-value { font-size: 22px; font-weight: 700; color: #222; line-height: 1; }
        .stat-card .stat-label { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

        .empty-card {
            background: white; border-radius: 10px; padding: 40px 24px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .empty-card p { color: #666; margin: 6px 0; }
        .empty-card .hint { font-size: 13px; color: #999; margin-top: 14px; }

        .category-card {
            background: white; border-radius: 10px; margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .category-header {
            padding: 14px 22px; border-bottom: 1px solid #f0f0f0;
            background: #fafbfc;
        }
        .category-header h2 {
            margin: 0; font-size: 16px; font-weight: 600; color: #222;
            display: flex; align-items: center; gap: 10px;
        }
        .category-header h2 .req-count {
            font-size: 12px; color: #888; font-weight: 500;
            background: #eef0f2; padding: 2px 8px; border-radius: 10px;
        }
        .category-desc { font-size: 13px; color: #666; margin-top: 4px; }

        .req-row {
            padding: 14px 22px; border-bottom: 1px solid #f5f5f5;
        }
        .req-row:last-child { border-bottom: none; }
        .req-row-top { display: flex; gap: 10px; align-items: flex-start; }
        .req-row-text { flex: 1; font-size: 14px; color: #222; line-height: 1.5; }
        .req-row-rationale {
            font-size: 12px; color: #888; font-style: italic;
            margin-top: 6px; line-height: 1.5;
        }

        .pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;
            white-space: nowrap;
        }
        .pill.type-requirement { background: #dbeafe; color: #1e40af; }
        .pill.type-pain_point  { background: #fef3c7; color: #92400e; }
        .pill.type-challenge   { background: #ede9fe; color: #5b21b6; }
        .pill.prio-critical { background: #fee2e2; color: #991b1b; }
        .pill.prio-high     { background: #fed7aa; color: #9a3412; }
        .pill.prio-medium   { background: #e5e7eb; color: #374151; }
        .pill.prio-low      { background: #f3f4f6; color: #6b7280; }

        .source-toggle { margin-top: 8px; }
        .source-list {
            margin-top: 10px; padding: 10px 14px;
            background: #fafbfc; border: 1px solid #eef0f2; border-radius: 6px;
            display: none;
        }
        .source-list.open { display: block; }
        .source-item { padding: 6px 0; border-bottom: 1px dashed #e5e7eb; font-size: 13px; }
        .source-item:last-child { border-bottom: none; }
        .source-dept {
            display: inline-block; padding: 1px 7px; border-radius: 9px;
            font-size: 11px; font-weight: 600; margin-right: 6px;
            background: #e5e7eb; color: #374151;
        }
        .source-quote {
            color: #555; font-style: italic; margin-top: 3px;
            border-left: 2px solid #ddd; padding-left: 8px;
        }
        .source-doc { font-size: 11px; color: #999; margin-top: 2px; }

        .conflicts-card {
            background: white; border-radius: 10px; margin-top: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
            border-left: 4px solid #ef4444;
        }
        .conflicts-card .conflicts-header {
            padding: 14px 22px; background: #fef2f2; border-bottom: 1px solid #fee2e2;
        }
        .conflicts-card h2 {
            margin: 0; font-size: 16px; font-weight: 600; color: #991b1b;
        }
        .conflict-row { padding: 14px 22px; border-bottom: 1px solid #f5f5f5; }
        .conflict-row:last-child { border-bottom: none; }
        .conflict-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .conflict-side {
            background: #fafbfc; border: 1px solid #eef0f2; border-radius: 6px;
            padding: 10px 12px;
        }
        .conflict-side .side-label {
            font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .conflict-side .side-text { font-size: 13px; color: #222; line-height: 1.5; }
        .conflict-explanation {
            margin-top: 10px; padding: 10px 12px;
            background: #fff7ed; border-left: 3px solid #f59e0b; border-radius: 4px;
            font-size: 13px; color: #555; line-height: 1.5;
        }
        .conflict-resolution {
            margin-top: 8px; font-size: 12px; color: #888;
        }
        .conflict-resolution.resolved { color: #047857; }

        .loading, .error-state { text-align: center; padding: 40px; color: #999; }
        .error-state { color: #d13438; }

        /* Streaming progress modal */
        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: flex; align-items: center; justify-content: center;
            z-index: 1000;
        }
        .stream-modal {
            background: white; border-radius: 12px; width: 720px; max-width: 92vw;
            max-height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .stream-modal-header {
            padding: 16px 22px; border-bottom: 1px solid #eee;
            display: flex; align-items: center; gap: 12px;
        }
        .stream-modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #222; flex: 1; }
        .stream-modal-header .spinner {
            width: 16px; height: 16px; border: 2px solid #fed7aa;
            border-top-color: #9a3412; border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        .stream-modal-header .spinner.done { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .stream-phase {
            padding: 10px 22px; background: #fff7ed; color: #9a3412;
            font-size: 13px; border-bottom: 1px solid #fed7aa;
        }
        .stream-phase.done    { background: #ecfdf5; color: #047857; border-bottom-color: #a7f3d0; }
        .stream-phase.error   { background: #fef2f2; color: #991b1b; border-bottom-color: #fecaca; }

        .progress-tracker {
            padding: 12px 22px; border-bottom: 1px solid #eee;
            display: flex; flex-direction: column; gap: 7px;
            background: white;
        }
        .ptask {
            display: flex; align-items: center; gap: 12px;
            font-size: 13px; color: #555;
        }
        .ptask .pico {
            width: 18px; height: 18px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; flex-shrink: 0;
            background: #f3f4f6; color: #aaa; border: 1px solid #e5e7eb;
        }
        .ptask.active .pico {
            background: #fef3c7; color: #b45309; border-color: #fcd34d;
            animation: pulse 1.2s ease-in-out infinite;
        }
        .ptask.done .pico {
            background: #d1fae5; color: #047857; border-color: #6ee7b7;
        }
        .ptask.active .plabel { color: #222; font-weight: 600; }
        .ptask.done   .plabel { color: #047857; }
        .ptask .pcount {
            margin-left: auto; font-variant-numeric: tabular-nums;
            color: #888; font-size: 12px;
        }
        .ptask.active .pcount, .ptask.done .pcount { color: #444; }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }

        .stream-body {
            flex: 1; overflow-y: auto; padding: 14px 22px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px; line-height: 1.55; color: #333;
            white-space: pre-wrap; word-break: break-word;
            background: #fafbfc; min-height: 200px;
        }

        .stream-meta {
            padding: 10px 22px; border-top: 1px solid #eee; background: #fafbfc;
            display: flex; gap: 18px; font-size: 12px; color: #666;
            justify-content: space-between; flex-wrap: wrap;
        }
        .stream-meta .meta-item strong { color: #222; font-variant-numeric: tabular-nums; }

        .stream-modal-footer {
            padding: 12px 22px; border-top: 1px solid #eee;
            display: flex; justify-content: flex-end; gap: 8px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../">Contracts</a><span class="sep">›</span>
            <a href="./">RFP Builder</a><span class="sep">›</span>
            <a id="bcRfp" href="#">-</a><span class="sep">›</span>
            <span>Consolidated</span>
        </div>

        <div class="page-header">
            <h1>Consolidated requirements</h1>
            <div class="page-actions">
                <a id="backLink" href="#" class="btn btn-secondary">&larr; Overview</a>
                <button id="runBtn" class="btn btn-primary" onclick="runConsolidation()">Run consolidation</button>
            </div>
        </div>

        <div id="streamModal" class="modal-backdrop" style="display:none;">
            <div class="stream-modal">
                <div class="stream-modal-header">
                    <div id="streamSpinner" class="spinner"></div>
                    <h3 id="streamTitle">Running consolidation</h3>
                </div>
                <div id="streamPhase" class="stream-phase">Starting…</div>
                <div class="progress-tracker">
                    <div id="ptaskCats" class="ptask">
                        <div class="pico">1</div>
                        <div class="plabel">Categorising</div>
                        <div class="pcount" id="pcountCats">—</div>
                    </div>
                    <div id="ptaskCons" class="ptask">
                        <div class="pico">2</div>
                        <div class="plabel">Consolidating requirements</div>
                        <div class="pcount" id="pcountCons">—</div>
                    </div>
                    <div id="ptaskConf" class="ptask">
                        <div class="pico">3</div>
                        <div class="plabel">Detecting conflicts</div>
                        <div class="pcount" id="pcountConf">—</div>
                    </div>
                </div>
                <div id="streamBody" class="stream-body"></div>
                <div class="stream-meta">
                    <span class="meta-item">Tokens in: <strong id="streamTokensIn">0</strong></span>
                    <span class="meta-item">Tokens out: <strong id="streamTokensOut">0</strong></span>
                    <span class="meta-item">Cached: <strong id="streamCacheRead">0</strong></span>
                    <span class="meta-item">Elapsed: <strong id="streamElapsed">0s</strong></span>
                </div>
                <div class="stream-modal-footer">
                    <button id="streamCloseBtn" class="btn btn-secondary" onclick="closeStreamModal()" disabled>Close</button>
                </div>
            </div>
        </div>

        <div class="stats-strip" id="statsStrip" style="display:none;">
            <div class="stat-card cats">
                <div class="stat-value" id="statCats">0</div>
                <div class="stat-label">Categories</div>
            </div>
            <div class="stat-card cons">
                <div class="stat-value" id="statCons">0</div>
                <div class="stat-label">Consolidated</div>
            </div>
            <div class="stat-card conf">
                <div class="stat-value" id="statConf">0</div>
                <div class="stat-label">Open conflicts</div>
            </div>
            <div class="stat-card linked">
                <div class="stat-value" id="statLinked">0</div>
                <div class="stat-label">Source items linked</div>
            </div>
        </div>

        <div id="loadingEl" class="loading">Loading…</div>
        <div id="contentEl" style="display:none;"></div>
        <div id="errorEl" class="error-state" style="display:none;"></div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let rfpName = '';

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId) {
                showError('No RFP id supplied. <a href="./">Back to list</a>.');
                return;
            }
            document.getElementById('backLink').href = 'view.php?id=' + encodeURIComponent(rfpId);
            loadAll();
        });

        async function loadAll() {
            try {
                const [rfpRes, conRes] = await Promise.all([
                    fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId)).then(r => r.json()),
                    fetch(API_BASE + 'get_consolidated.php?rfp_id=' + encodeURIComponent(rfpId)).then(r => r.json())
                ]);
                if (!rfpRes.success) throw new Error(rfpRes.error || 'Failed to load RFP');
                if (!conRes.success) throw new Error(conRes.error || 'Failed to load consolidated');
                rfpName = rfpRes.rfp.name;
                const bc = document.getElementById('bcRfp');
                bc.textContent = rfpName;
                bc.href = 'view.php?id=' + encodeURIComponent(rfpId);
                render(conRes);
                document.getElementById('loadingEl').style.display = 'none';
            } catch (err) {
                showError(escapeHtml(err.message));
            }
        }

        function render(data) {
            const consByCat = new Map();
            data.consolidated.forEach(c => {
                const k = c.category_id || 0;
                if (!consByCat.has(k)) consByCat.set(k, []);
                consByCat.get(k).push(c);
            });

            const openConf = data.conflicts.filter(c => c.resolution === 'open').length;
            const linked = new Set();
            data.consolidated.forEach(c => (c.sources || []).forEach(s => linked.add(s.extracted_id)));

            document.getElementById('statCats').textContent   = data.categories.length;
            document.getElementById('statCons').textContent   = data.consolidated.length;
            document.getElementById('statConf').textContent   = openConf;
            document.getElementById('statLinked').textContent = linked.size;
            document.getElementById('statsStrip').style.display = 'grid';

            const runBtn = document.getElementById('runBtn');
            runBtn.textContent = data.consolidated.length > 0 ? 'Re-run consolidation' : 'Run consolidation';

            const contentEl = document.getElementById('contentEl');
            contentEl.style.display = 'block';

            if (data.consolidated.length === 0) {
                contentEl.innerHTML = `
                    <div class="empty-card">
                        <p><strong>No consolidation yet.</strong></p>
                        <p>Click <em>Run consolidation</em> to have the AI deduplicate, categorise and detect conflicts across every extracted requirement.</p>
                        <p class="hint">Typical run: 60-120 seconds, ~£0.50-2 in tokens.</p>
                    </div>
                `;
                return;
            }

            const catBlocks = data.categories.map(cat => {
                const reqs = consByCat.get(cat.id) || [];
                if (reqs.length === 0) return '';
                return renderCategoryBlock(cat, reqs);
            });

            const orphans = consByCat.get(0) || [];
            if (orphans.length > 0) {
                catBlocks.push(renderCategoryBlock(
                    { id: 0, name: 'Uncategorised', description: 'AI did not assign a category to these requirements.' },
                    orphans
                ));
            }

            const consById = new Map(data.consolidated.map(c => [c.id, c]));
            const conflictsHtml = data.conflicts.length > 0
                ? renderConflicts(data.conflicts, consById)
                : '';

            contentEl.innerHTML = catBlocks.join('') + conflictsHtml;
        }

        function renderCategoryBlock(cat, reqs) {
            return `
                <div class="category-card">
                    <div class="category-header">
                        <h2>
                            ${escapeHtml(cat.name)}
                            <span class="req-count">${reqs.length} req${reqs.length === 1 ? '' : 's'}</span>
                        </h2>
                        ${cat.description ? `<div class="category-desc">${escapeHtml(cat.description)}</div>` : ''}
                    </div>
                    ${reqs.map(r => renderReqRow(r)).join('')}
                </div>
            `;
        }

        function renderReqRow(r) {
            const sources = r.sources || [];
            return `
                <div class="req-row" data-id="${r.id}">
                    <div class="req-row-top">
                        <span class="pill type-${escapeHtml(r.requirement_type)}">${escapeHtml(r.requirement_type.replace('_', ' '))}</span>
                        <span class="pill prio-${escapeHtml(r.priority)}">${escapeHtml(r.priority)}</span>
                        <div class="req-row-text">
                            ${escapeHtml(r.requirement_text)}
                            ${r.ai_rationale ? `<div class="req-row-rationale">AI: ${escapeHtml(r.ai_rationale)}</div>` : ''}
                        </div>
                    </div>
                    <div class="source-toggle">
                        <button class="btn-link" onclick="toggleSources(${r.id})">
                            <span id="srcLabel-${r.id}">Show sources (${sources.length})</span>
                        </button>
                    </div>
                    <div id="srcList-${r.id}" class="source-list">
                        ${sources.map(s => `
                            <div class="source-item">
                                <span class="source-dept" style="${s.department_colour ? 'background:' + escapeHtml(s.department_colour) + '20; color:' + escapeHtml(s.department_colour) : ''}">
                                    ${escapeHtml(s.department_name || 'Unassigned')}
                                </span>
                                <span style="color:#555;">${escapeHtml(s.requirement_text)}</span>
                                ${s.source_quote ? `<div class="source-quote">"${escapeHtml(s.source_quote)}"</div>` : ''}
                                <div class="source-doc">${escapeHtml(s.document_filename || '')} · extracted #${s.extracted_id}</div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        function renderConflicts(conflicts, consById) {
            return `
                <div class="conflicts-card">
                    <div class="conflicts-header">
                        <h2>Flagged conflicts (${conflicts.length})</h2>
                    </div>
                    ${conflicts.map(c => {
                        const aText = c.a_text || '<em>(deleted)</em>';
                        const bText = c.b_text || '<em>(deleted)</em>';
                        const resolved = c.resolution !== 'open';
                        return `
                            <div class="conflict-row">
                                <div class="conflict-pair">
                                    <div class="conflict-side">
                                        <div class="side-label">Side A · ${escapeHtml(c.a_priority || '')}</div>
                                        <div class="side-text">${typeof aText === 'string' && aText.startsWith('<em>') ? aText : escapeHtml(aText)}</div>
                                    </div>
                                    <div class="conflict-side">
                                        <div class="side-label">Side B · ${escapeHtml(c.b_priority || '')}</div>
                                        <div class="side-text">${typeof bText === 'string' && bText.startsWith('<em>') ? bText : escapeHtml(bText)}</div>
                                    </div>
                                </div>
                                ${c.ai_explanation ? `<div class="conflict-explanation"><strong>Why this conflicts:</strong> ${escapeHtml(c.ai_explanation)}</div>` : ''}
                                <div class="conflict-resolution ${resolved ? 'resolved' : ''}">
                                    Status: ${escapeHtml(c.resolution)}${c.resolved_by_name ? ' by ' + escapeHtml(c.resolved_by_name) : ''}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }

        function toggleSources(consId) {
            const list = document.getElementById('srcList-' + consId);
            const label = document.getElementById('srcLabel-' + consId);
            const isOpen = list.classList.toggle('open');
            const count = list.querySelectorAll('.source-item').length;
            label.textContent = (isOpen ? 'Hide' : 'Show') + ' sources (' + count + ')';
        }

        let activeStream = null;
        let streamStart = 0;
        let elapsedTimer = null;
        let streamAccumulated = '';

        function runConsolidation() {
            if (!confirm('Run AI consolidation now?\n\nThis takes 60-180 seconds and replaces any existing consolidation, categories and conflicts for this RFP.\n\nYou can watch the AI work live in the modal that opens.')) {
                return;
            }
            openStreamModal();

            // EventSource is the simplest browser API for SSE — connection
            // automatically reopens on transient drops and parses the
            // event/data framing for us. GET-only, so rfp_id goes in the URL.
            const url = API_BASE + 'run_consolidation.php?rfp_id=' + encodeURIComponent(rfpId);
            activeStream = new EventSource(url);
            streamStart = Date.now();
            elapsedTimer = setInterval(updateElapsed, 250);

            activeStream.addEventListener('phase', (e) => {
                const data = JSON.parse(e.data);
                setPhase(data.message || data.phase, false);
            });

            activeStream.addEventListener('text', (e) => {
                const data = JSON.parse(e.data);
                const delta = data.delta || '';
                streamAccumulated += delta;
                appendStreamText(delta);
                updateProgressTracker(streamAccumulated);
            });

            activeStream.addEventListener('usage', (e) => {
                const data = JSON.parse(e.data);
                if (data.tokens_in  != null) document.getElementById('streamTokensIn').textContent  = formatNum(data.tokens_in);
                if (data.tokens_out != null) document.getElementById('streamTokensOut').textContent = formatNum(data.tokens_out);
                if (data.cache_read != null) document.getElementById('streamCacheRead').textContent = formatNum(data.cache_read);
            });

            activeStream.addEventListener('complete', (e) => {
                const data = JSON.parse(e.data);
                finishStream(data);
            });

            activeStream.addEventListener('error', (e) => {
                // Two ways this fires: (a) explicit `event: error` from the
                // server with a JSON message, or (b) connection-level
                // failures where e.data is undefined. Handle both.
                let msg = 'Connection error';
                if (e.data) {
                    try { msg = (JSON.parse(e.data).error) || msg; } catch (_) { msg = e.data; }
                }
                failStream(msg);
            });
        }

        function openStreamModal() {
            document.getElementById('streamModal').style.display = 'flex';
            document.getElementById('streamBody').textContent = '';
            document.getElementById('streamTokensIn').textContent  = '0';
            document.getElementById('streamTokensOut').textContent = '0';
            document.getElementById('streamCacheRead').textContent = '0';
            document.getElementById('streamElapsed').textContent   = '0s';
            const phase = document.getElementById('streamPhase');
            phase.textContent = 'Starting…';
            phase.className = 'stream-phase';
            document.getElementById('streamSpinner').classList.remove('done');
            document.getElementById('streamCloseBtn').disabled = true;
            document.getElementById('streamTitle').textContent = 'Running consolidation';
            document.getElementById('runBtn').disabled = true;
            // Reset tracker
            streamAccumulated = '';
            ['Cats', 'Cons', 'Conf'].forEach(k => {
                document.getElementById('ptask' + k).className = 'ptask';
                document.getElementById('pcount' + k).textContent = '—';
            });
        }

        // Parse the accumulated streamed JSON for progress markers. JSON
        // escaping guarantees these byte sequences only appear as actual
        // top-level keys, not inside string content (where they'd be
        // \"name\": etc), so a plain match is safe.
        function updateProgressTracker(text) {
            const catCount  = (text.match(/"name"\s*:/g)                 || []).length;
            const consCount = (text.match(/"requirement_text"\s*:/g)     || []).length;
            const confCount = (text.match(/"consolidated_a_index"\s*:/g) || []).length;

            const hasCons = text.includes('"consolidated_requirements"');
            const hasConf = text.includes('"conflicts"');

            // Categories: active until the consolidated section opens, then done.
            // Consolidated: pending until the consolidated key arrives, active until conflicts opens, then done.
            // Conflicts: pending until the conflicts key arrives, active thereafter (becomes done on stream complete).
            setPTask('Cats', hasCons ? 'done' : 'active', catCount);
            setPTask('Cons',
                hasConf ? 'done' : (hasCons ? 'active' : 'pending'),
                hasCons ? consCount : null);
            setPTask('Conf',
                hasConf ? 'active' : 'pending',
                hasConf ? confCount : null);
        }

        function setPTask(key, state, count) {
            const row = document.getElementById('ptask' + key);
            row.className = 'ptask' + (state === 'pending' ? '' : ' ' + state);
            const cnt = document.getElementById('pcount' + key);
            if (count === null || count === undefined) {
                cnt.textContent = state === 'pending' ? '—' : '0';
            } else {
                const noun = key === 'Cats' ? 'category' : (key === 'Cons' ? 'req' : 'conflict');
                const plural = count === 1 ? noun : noun.replace(/y$/, 'ie') + 's';
                cnt.textContent = count + ' ' + plural;
            }
        }

        function closeStreamModal() {
            if (activeStream) { activeStream.close(); activeStream = null; }
            if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
            document.getElementById('streamModal').style.display = 'none';
            document.getElementById('runBtn').disabled = false;
        }

        function setPhase(message, isDone) {
            const phase = document.getElementById('streamPhase');
            phase.textContent = message;
            phase.classList.toggle('done', isDone === true);
            phase.classList.toggle('error', isDone === 'error');
        }

        function appendStreamText(delta) {
            const body = document.getElementById('streamBody');
            const wasAtBottom = (body.scrollHeight - body.scrollTop - body.clientHeight) < 30;
            body.textContent += delta;
            if (wasAtBottom) body.scrollTop = body.scrollHeight;
        }

        function updateElapsed() {
            const sec = Math.floor((Date.now() - streamStart) / 1000);
            document.getElementById('streamElapsed').textContent = sec + 's';
        }

        function finishStream(data) {
            if (activeStream) { activeStream.close(); activeStream = null; }
            if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
            document.getElementById('streamSpinner').classList.add('done');
            document.getElementById('streamTitle').textContent = 'Consolidation complete';
            // Mark all three tasks done with their final committed counts
            // (these come from the server, which match the actual DB state
            // — slightly more authoritative than the live stream parsing).
            setPTask('Cats', 'done', data.counts.categories);
            setPTask('Cons', 'done', data.counts.consolidated);
            setPTask('Conf', 'done', data.counts.conflicts);
            setPhase(
                data.counts.categories + ' categories · ' +
                data.counts.consolidated + ' consolidated · ' +
                data.counts.conflicts + ' conflicts · ' +
                (data.counts.orphan_extracted > 0 ? data.counts.orphan_extracted + ' orphans · ' : '') +
                (data.duration_ms / 1000).toFixed(1) + 's',
                true
            );
            document.getElementById('streamCloseBtn').disabled = false;
            // Refresh page data behind the modal — when user closes the
            // modal they see the populated tree instantly.
            loadAll();
        }

        function failStream(msg) {
            if (activeStream) { activeStream.close(); activeStream = null; }
            if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
            document.getElementById('streamSpinner').classList.add('done');
            document.getElementById('streamTitle').textContent = 'Consolidation failed';
            setPhase('Error: ' + msg, 'error');
            document.getElementById('streamCloseBtn').disabled = false;
        }

        function formatNum(n) {
            n = Number(n) || 0;
            return n.toLocaleString();
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
    </script>
</body>
</html>
