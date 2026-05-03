<?php
/**
 * RFP Builder — generated document page (Phase 4 step 4a).
 *
 * Lists every category from the consolidation pass with the AI-
 * generated section content underneath (or a "not yet generated"
 * placeholder). "Generate all" fires N sequential SSE calls (one per
 * category), each with the streaming-modal pattern from Phase 3a but
 * extended to a checklist of categories with per-row state.
 * Per-section "Generate" / "Re-generate" buttons handle one category
 * at a time. TinyMCE editing + version history land in 4b; preview
 * and PDF export land in 4d.
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
    <title>Service Desk - RFP Document</title>
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
        .stat-card.gen      { border-left-color: #10b981; }
        .stat-card.pending  { border-left-color: #f59e0b; }
        .stat-card.edited   { border-left-color: #8b5cf6; }
        .stat-card .stat-value { font-size: 22px; font-weight: 700; color: #222; line-height: 1; }
        .stat-card .stat-label { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

        .gate-banner {
            background: #fef3c7; border: 1px solid #fcd34d; color: #92400e;
            padding: 14px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;
            display: flex; align-items: center; gap: 14px;
        }
        .gate-banner .gate-icon { font-size: 20px; }
        .gate-banner .gate-msg  { flex: 1; }

        .empty-card {
            background: white; border-radius: 10px; padding: 40px 24px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .empty-card p { color: #666; margin: 6px 0; }

        .category-card {
            background: white; border-radius: 10px; margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .cat-header {
            padding: 14px 22px; border-bottom: 1px solid #f0f0f0;
            background: #fafbfc;
            display: flex; align-items: flex-start; gap: 12px;
        }
        .cat-header .cat-info { flex: 1; min-width: 0; }
        .cat-header h2 {
            margin: 0; font-size: 17px; font-weight: 600; color: #222;
        }
        .cat-header .cat-desc { font-size: 13px; color: #666; margin-top: 4px; line-height: 1.45; }
        .cat-header .cat-meta {
            font-size: 11px; color: #999; margin-top: 6px;
            display: flex; gap: 12px; flex-wrap: wrap;
        }
        .cat-header .cat-meta .badge {
            display: inline-block; padding: 1px 7px; border-radius: 8px;
            background: #eef0f2; color: #555;
        }
        .cat-header .cat-meta .badge.edited { background: #ede9fe; color: #5b21b6; }
        .cat-header .cat-meta .badge.fresh  { background: #d1fae5; color: #047857; }
        .cat-header .cat-meta .badge.empty  { background: #fef3c7; color: #92400e; }
        .cat-header .cat-actions {
            display: flex; gap: 6px; flex-shrink: 0;
        }

        .section-body {
            padding: 18px 28px; line-height: 1.6; color: #222;
        }
        .section-body h3 {
            font-size: 15px; font-weight: 700; color: #1f2937;
            margin: 18px 0 8px 0;
        }
        .section-body h3:first-child { margin-top: 0; }
        .section-body h4 { font-size: 13px; font-weight: 700; color: #374151; margin: 12px 0 6px 0; }
        .section-body p  { margin: 0 0 10px 0; font-size: 14px; }
        .section-body ul, .section-body ol { margin: 0 0 10px 22px; font-size: 14px; }
        .section-body li { margin-bottom: 4px; }
        .section-body table {
            border-collapse: collapse; margin: 10px 0; width: 100%; font-size: 13px;
        }
        .section-body th, .section-body td {
            border: 1px solid #e5e7eb; padding: 6px 10px; text-align: left;
        }
        .section-body th { background: #f9fafb; font-weight: 600; }

        .section-empty {
            padding: 22px 28px; color: #999; font-size: 14px; font-style: italic;
        }

        /* ─── Generate-all batch modal ─────────────────────────────── */

        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: flex; align-items: center; justify-content: center; z-index: 1000;
        }
        .batch-modal {
            background: white; border-radius: 12px; width: 820px; max-width: 92vw;
            /* Fixed height (not max-height) so the modal doesn't jitter
               as the streaming text preview grows and resets between
               sections — internal panes scroll on overflow. */
            height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .batch-modal-header {
            padding: 14px 22px; border-bottom: 1px solid #eee;
            display: flex; align-items: center; gap: 12px;
        }
        .batch-modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #222; flex: 1; }

        .batch-summary {
            padding: 12px 22px; border-bottom: 1px solid #eee;
            display: flex; gap: 18px; font-size: 12px; color: #666; flex-wrap: wrap;
        }
        .batch-summary strong { color: #222; font-variant-numeric: tabular-nums; }

        .batch-tasks { padding: 8px 22px 6px 22px; max-height: 280px; overflow-y: auto; }
        .batch-task {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0; font-size: 13px; border-bottom: 1px solid #f5f5f5;
        }
        .batch-task:last-child { border-bottom: none; }
        .batch-task .pico {
            width: 18px; height: 18px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; flex-shrink: 0;
            background: #f3f4f6; color: #aaa; border: 1px solid #e5e7eb;
        }
        .batch-task.active .pico {
            background: #fef3c7; color: #b45309; border-color: #fcd34d;
            animation: pulse 1.2s ease-in-out infinite;
        }
        .batch-task.done   .pico { background: #d1fae5; color: #047857; border-color: #6ee7b7; }
        .batch-task.skip   .pico { background: #e5e7eb; color: #4b5563; border-color: #d1d5db; }
        .batch-task.error  .pico { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
        .batch-task .plabel { flex: 1; color: #555; }
        .batch-task.active .plabel,
        .batch-task.done   .plabel { color: #222; }
        .batch-task.error  .plabel { color: #991b1b; }
        .batch-task .pcount {
            font-variant-numeric: tabular-nums; color: #888; font-size: 12px;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }

        .batch-stream {
            flex: 1; overflow-y: auto; padding: 12px 22px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px; line-height: 1.55; color: #333;
            white-space: pre-wrap; word-break: break-word;
            background: #fafbfc; min-height: 160px;
            border-top: 1px solid #eee;
        }
        .batch-stream-label {
            font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 6px 22px 0 22px; background: #fafbfc;
        }

        .batch-modal-footer {
            padding: 12px 22px; border-top: 1px solid #eee;
            display: flex; justify-content: space-between; gap: 8px;
        }
        .batch-modal-footer .right-actions { display: flex; gap: 8px; }

        .spinner {
            width: 16px; height: 16px; border: 2px solid #fed7aa;
            border-top-color: #9a3412; border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        .spinner.done { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .loading, .error-state { text-align: center; padding: 40px; color: #999; }
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
            <span>Generated document</span>
        </div>

        <div class="page-header">
            <h1>Generated document</h1>
            <div class="page-actions">
                <a id="backLink" href="#" class="btn btn-secondary">&larr; Overview</a>
                <button id="generateAllBtn" class="btn btn-primary" onclick="generateAll(false)" style="display:none;">Generate all</button>
            </div>
        </div>

        <div id="gateBanner" class="gate-banner" style="display:none;">
            <span class="gate-icon">&#9888;</span>
            <div class="gate-msg" id="gateMsg"></div>
        </div>

        <div class="stats-strip" id="statsStrip" style="display:none;">
            <div class="stat-card cats">
                <div class="stat-value" id="statCats">0</div>
                <div class="stat-label">Categories</div>
            </div>
            <div class="stat-card gen">
                <div class="stat-value" id="statGen">0</div>
                <div class="stat-label">Generated</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value" id="statPending">0</div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card edited">
                <div class="stat-value" id="statEdited">0</div>
                <div class="stat-label">Manually edited</div>
            </div>
        </div>

        <div id="loadingEl" class="loading">Loading…</div>
        <div id="contentEl" style="display:none;"></div>
        <div id="errorEl" class="error-state" style="display:none;"></div>
    </div>

    <!-- Batch generation modal -->
    <div id="batchModal" class="modal-backdrop" style="display:none;">
        <div class="batch-modal">
            <div class="batch-modal-header">
                <div id="batchSpinner" class="spinner"></div>
                <h3 id="batchTitle">Generating sections…</h3>
            </div>
            <div class="batch-summary">
                <span>Done: <strong id="batchDone">0</strong> / <strong id="batchTotal">0</strong></span>
                <span>Tokens in: <strong id="batchTokensIn">0</strong></span>
                <span>Tokens out: <strong id="batchTokensOut">0</strong></span>
                <span>Cached: <strong id="batchCacheRead">0</strong></span>
                <span>Elapsed: <strong id="batchElapsed">0s</strong></span>
            </div>
            <div class="batch-tasks" id="batchTasks"></div>
            <div class="batch-stream-label">Live output (current section)</div>
            <div class="batch-stream" id="batchStream"></div>
            <div class="batch-modal-footer">
                <button id="batchCancelBtn" class="btn btn-secondary" onclick="cancelBatch()">Stop after current</button>
                <div class="right-actions">
                    <button id="batchCloseBtn" class="btn btn-primary" onclick="closeBatchModal()" disabled>Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let pageData = { categories: [], lock: { all_locked: false } };

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
                const [rfpRes, secRes] = await Promise.all([
                    fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId)).then(r => r.json()),
                    fetch(API_BASE + 'get_sections.php?rfp_id=' + encodeURIComponent(rfpId)).then(r => r.json())
                ]);
                if (!rfpRes.success) throw new Error(rfpRes.error || 'Failed to load RFP');
                if (!secRes.success) throw new Error(secRes.error || 'Failed to load sections');

                const bc = document.getElementById('bcRfp');
                bc.textContent = rfpRes.rfp.name;
                bc.href = 'view.php?id=' + encodeURIComponent(rfpId);

                pageData = secRes;
                render(secRes);
                document.getElementById('loadingEl').style.display = 'none';
            } catch (err) {
                showError(escapeHtml(err.message));
            }
        }

        function render(data) {
            const cats = data.categories;
            const generated = cats.filter(c => c.section_id !== null).length;
            const pending   = cats.length - generated;
            const edited    = cats.filter(c => c.is_manually_edited === true).length;

            document.getElementById('statCats').textContent    = cats.length;
            document.getElementById('statGen').textContent     = generated;
            document.getElementById('statPending').textContent = pending;
            document.getElementById('statEdited').textContent  = edited;
            document.getElementById('statsStrip').style.display = cats.length > 0 ? 'grid' : 'none';

            const banner = document.getElementById('gateBanner');
            const generateBtn = document.getElementById('generateAllBtn');

            if (cats.length === 0) {
                banner.style.display = 'flex';
                document.getElementById('gateMsg').innerHTML =
                    '<strong>No categories yet.</strong> Run consolidation on this RFP first to produce a category structure.';
                generateBtn.style.display = 'none';
            } else if (!data.lock.all_locked) {
                banner.style.display = 'flex';
                document.getElementById('gateMsg').innerHTML =
                    '<strong>Consolidated requirements are not locked.</strong> Section generation is gated on a fully-locked consolidation set so the inputs do not drift mid-generation. ' +
                    '<a href="consolidate.php?id=' + encodeURIComponent(rfpId) + '" style="color:#92400e;text-decoration:underline;">Open consolidation</a> to lock.';
                generateBtn.style.display = 'none';
            } else {
                banner.style.display = 'none';
                generateBtn.style.display = '';
                generateBtn.textContent = generated === 0 ? 'Generate all' : (pending > 0 ? 'Generate pending' : 'Re-generate all');
            }

            const contentEl = document.getElementById('contentEl');
            contentEl.style.display = 'block';

            if (cats.length === 0) {
                contentEl.innerHTML = '';
                return;
            }

            contentEl.innerHTML = cats.map(c => renderCategoryCard(c)).join('');
        }

        function renderCategoryCard(c) {
            const hasSection = c.section_id !== null;
            const editedBadge = c.is_manually_edited
                ? '<span class="badge edited">manually edited</span>'
                : '';
            const versionBadge = hasSection
                ? '<span class="badge fresh">v' + c.version + '</span>'
                : '<span class="badge empty">not generated</span>';
            const reqBadge = '<span class="badge">' + c.req_count + ' req' + (c.req_count === 1 ? '' : 's') + '</span>';
            const generatedAt = c.generated_datetime ? formatDateTime(c.generated_datetime) : '';

            const canGenerate = pageData.lock.all_locked && c.req_count > 0;

            const actions = canGenerate ? `
                <button class="btn btn-secondary" onclick="generateOne(${c.id}, ${hasSection})">${hasSection ? 'Re-generate' : 'Generate'}</button>
            ` : '';

            const body = hasSection
                ? `<div class="section-body">${c.section_content || ''}</div>`
                : `<div class="section-empty">Section not yet generated.${c.req_count === 0 ? ' (No consolidated requirements assigned to this category.)' : ''}</div>`;

            return `
                <div class="category-card">
                    <div class="cat-header">
                        <div class="cat-info">
                            <h2>${escapeHtml(c.name)}</h2>
                            ${c.description ? `<div class="cat-desc">${escapeHtml(c.description)}</div>` : ''}
                            <div class="cat-meta">
                                ${reqBadge}
                                ${versionBadge}
                                ${editedBadge}
                                ${generatedAt ? '<span>generated ' + escapeHtml(generatedAt) + '</span>' : ''}
                            </div>
                        </div>
                        <div class="cat-actions">${actions}</div>
                    </div>
                    ${body}
                </div>
            `;
        }

        // ─── Batch generation (one EventSource at a time, sequential) ──

        let batchQueue = [];      // category IDs still to process
        let batchIndex = 0;       // index in queue currently active
        let batchTotal = 0;       // total queued at start
        let batchDone  = 0;       // count completed (success or skipped)
        let batchTokensIn = 0, batchTokensOut = 0, batchCacheRead = 0;
        let batchStart = 0;
        let batchElapsedTimer = null;
        let batchActiveStream = null;
        let batchCancelRequested = false;
        let batchForceRegen = false;

        function generateOne(categoryId, force) {
            startBatch([categoryId], !!force);
        }

        function generateAll(forceAll) {
            // Queue every category that has at least one consolidated req.
            // The generate_section endpoint will hash-skip sections that
            // haven't changed unless force is set, so re-running over an
            // already-generated set is cheap.
            const queue = pageData.categories
                .filter(c => c.req_count > 0)
                .map(c => c.id);
            if (queue.length === 0) {
                alert('No categories with consolidated requirements to generate.');
                return;
            }
            if (!confirm('Generate ' + queue.length + ' sections?\n\nEach takes 30-90 seconds. Already-generated sections whose inputs have not changed will be skipped automatically.')) {
                return;
            }
            startBatch(queue, !!forceAll);
        }

        function startBatch(queue, force) {
            batchQueue   = queue.slice();
            batchIndex   = 0;
            batchTotal   = queue.length;
            batchDone    = 0;
            batchTokensIn = 0;
            batchTokensOut = 0;
            batchCacheRead = 0;
            batchCancelRequested = false;
            batchForceRegen = force;

            openBatchModal();

            // Render task list
            const tasksEl = document.getElementById('batchTasks');
            tasksEl.innerHTML = queue.map((catId, i) => {
                const cat = pageData.categories.find(c => c.id === catId);
                return `
                    <div class="batch-task" id="btask-${catId}" data-cat-id="${catId}">
                        <div class="pico">${i + 1}</div>
                        <div class="plabel">${escapeHtml(cat ? cat.name : 'Category #' + catId)}</div>
                        <div class="pcount" id="btask-count-${catId}"></div>
                    </div>
                `;
            }).join('');

            batchStart = Date.now();
            batchElapsedTimer = setInterval(updateBatchElapsed, 250);
            document.getElementById('batchTotal').textContent = batchTotal;

            processNext();
        }

        function processNext() {
            if (batchCancelRequested || batchIndex >= batchQueue.length) {
                finishBatch();
                return;
            }
            const catId = batchQueue[batchIndex];
            const taskEl = document.getElementById('btask-' + catId);
            taskEl.classList.add('active');
            document.getElementById('batchStream').textContent = '';

            const url = API_BASE + 'generate_section.php?rfp_id=' + encodeURIComponent(rfpId)
                      + '&category_id=' + encodeURIComponent(catId)
                      + (batchForceRegen ? '&force=1' : '');
            batchActiveStream = new EventSource(url);

            batchActiveStream.addEventListener('phase', (e) => {
                const data = JSON.parse(e.data);
                document.getElementById('btask-count-' + catId).textContent = data.message || data.phase;
            });

            batchActiveStream.addEventListener('text', (e) => {
                const data = JSON.parse(e.data);
                appendBatchStream(data.delta || '');
            });

            batchActiveStream.addEventListener('usage', (e) => {
                const data = JSON.parse(e.data);
                if (data.tokens_in  != null) batchTokensIn  += 0; // tokens are per-call cumulative; below we track on complete
            });

            batchActiveStream.addEventListener('skipped', (e) => {
                const data = JSON.parse(e.data);
                taskEl.classList.remove('active');
                taskEl.classList.add('skip');
                document.getElementById('btask-count-' + catId).textContent = 'skipped (unchanged)';
                advanceBatch();
            });

            batchActiveStream.addEventListener('complete', (e) => {
                const data = JSON.parse(e.data);
                batchTokensIn  += Number(data.tokens_in)  || 0;
                batchTokensOut += Number(data.tokens_out) || 0;
                batchCacheRead += Number(data.cache_read) || 0;
                document.getElementById('batchTokensIn').textContent  = batchTokensIn.toLocaleString();
                document.getElementById('batchTokensOut').textContent = batchTokensOut.toLocaleString();
                document.getElementById('batchCacheRead').textContent = batchCacheRead.toLocaleString();
                taskEl.classList.remove('active');
                taskEl.classList.add('done');
                document.getElementById('btask-count-' + catId).textContent =
                    'v' + data.version + ' · ' + (data.duration_ms / 1000).toFixed(1) + 's';
                advanceBatch();
            });

            batchActiveStream.addEventListener('error', (e) => {
                let msg = 'Connection error';
                if (e.data) {
                    try { msg = JSON.parse(e.data).error || msg; } catch (_) { msg = e.data; }
                }
                taskEl.classList.remove('active');
                taskEl.classList.add('error');
                document.getElementById('btask-count-' + catId).textContent = 'error: ' + msg.slice(0, 80);
                advanceBatch();
            });
        }

        function advanceBatch() {
            if (batchActiveStream) { batchActiveStream.close(); batchActiveStream = null; }
            batchDone++;
            document.getElementById('batchDone').textContent = batchDone;
            batchIndex++;
            // Defer to next tick so the DOM updates settle visibly
            setTimeout(processNext, 80);
        }

        function cancelBatch() {
            batchCancelRequested = true;
            document.getElementById('batchCancelBtn').disabled = true;
            document.getElementById('batchCancelBtn').textContent = 'Stopping…';
        }

        function finishBatch() {
            if (batchActiveStream) { batchActiveStream.close(); batchActiveStream = null; }
            if (batchElapsedTimer) { clearInterval(batchElapsedTimer); batchElapsedTimer = null; }
            document.getElementById('batchSpinner').classList.add('done');
            document.getElementById('batchTitle').textContent =
                batchCancelRequested ? 'Stopped — ' + batchDone + ' / ' + batchTotal + ' sections done'
                                     : 'Generated ' + batchDone + ' / ' + batchTotal + ' sections';
            document.getElementById('batchCloseBtn').disabled = false;
            document.getElementById('batchCancelBtn').disabled = true;
            // Refresh page data so closing the modal reveals the populated sections.
            loadAll();
        }

        function appendBatchStream(delta) {
            const body = document.getElementById('batchStream');
            const wasAtBottom = (body.scrollHeight - body.scrollTop - body.clientHeight) < 30;
            body.textContent += delta;
            if (wasAtBottom) body.scrollTop = body.scrollHeight;
        }

        function updateBatchElapsed() {
            const sec = Math.floor((Date.now() - batchStart) / 1000);
            document.getElementById('batchElapsed').textContent = sec + 's';
        }

        function openBatchModal() {
            document.getElementById('batchModal').style.display = 'flex';
            document.getElementById('batchSpinner').classList.remove('done');
            document.getElementById('batchTitle').textContent = 'Generating sections…';
            document.getElementById('batchTokensIn').textContent  = '0';
            document.getElementById('batchTokensOut').textContent = '0';
            document.getElementById('batchCacheRead').textContent = '0';
            document.getElementById('batchElapsed').textContent   = '0s';
            document.getElementById('batchDone').textContent      = '0';
            document.getElementById('batchStream').textContent    = '';
            document.getElementById('batchCloseBtn').disabled     = true;
            document.getElementById('batchCancelBtn').disabled    = false;
            document.getElementById('batchCancelBtn').textContent = 'Stop after current';
            document.getElementById('generateAllBtn').disabled    = true;
        }

        function closeBatchModal() {
            document.getElementById('batchModal').style.display = 'none';
            document.getElementById('generateAllBtn').disabled  = false;
        }

        function showError(html) {
            document.getElementById('loadingEl').style.display = 'none';
            const el = document.getElementById('errorEl');
            el.innerHTML = html;
            el.style.display = 'block';
        }

        function formatDateTime(s) {
            if (!s) return '';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
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
