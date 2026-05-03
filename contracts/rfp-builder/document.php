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
    <script src="../../assets/js/tinymce/tinymce.min.js"></script>
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

        /* Document framing panel — sits above the category cards */
        .framing-panel {
            background: white; border-radius: 10px; margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
            border-left: 4px solid #0ea5e9;
        }
        .framing-panel-header {
            padding: 14px 22px; background: #f0f9ff; border-bottom: 1px solid #e0f2fe;
            display: flex; align-items: center; gap: 12px;
        }
        .framing-panel-header h2 {
            margin: 0; font-size: 15px; font-weight: 700; color: #075985;
            letter-spacing: 0.3px; text-transform: uppercase;
            flex: 1;
        }
        .framing-panel-header .header-actions { display: flex; gap: 8px; }
        .framing-context-block {
            padding: 12px 22px; background: #fafbfc; font-size: 13px;
            color: #444; line-height: 1.5; border-bottom: 1px solid #eef0f2;
        }
        .framing-context-block .ctx-label {
            font-size: 11px; color: #888; text-transform: uppercase;
            letter-spacing: 0.5px; margin-bottom: 4px;
        }
        .framing-context-block .ctx-empty { color: #999; font-style: italic; }
        .framing-card {
            padding: 14px 22px; border-bottom: 1px solid #f0f0f0;
        }
        .framing-card:last-child { border-bottom: none; }
        .framing-card-header {
            display: flex; align-items: flex-start; gap: 12px; margin-bottom: 8px;
        }
        .framing-card-header h3 {
            margin: 0; font-size: 14px; font-weight: 700; color: #222;
            flex: 1;
        }
        .framing-card-header .meta {
            font-size: 11px; color: #999;
            display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
        }
        .framing-card-header .meta .badge {
            padding: 1px 7px; border-radius: 8px; background: #eef0f2; color: #555;
        }
        .framing-card-header .meta .badge.edited { background: #ede9fe; color: #5b21b6; }
        .framing-card-header .meta .badge.fresh  { background: #d1fae5; color: #047857; }
        .framing-card-header .meta .badge.empty  { background: #fef3c7; color: #92400e; }
        .framing-card-header .actions {
            display: flex; gap: 6px; flex-shrink: 0;
        }
        .framing-card-body { font-size: 13px; line-height: 1.55; color: #333; }
        .framing-card-body h3 {
            font-size: 13px; font-weight: 700; color: #1f2937;
            margin: 12px 0 6px 0;
        }
        .framing-card-body p { margin: 0 0 8px 0; }
        .framing-card-body ul, .framing-card-body ol { margin: 0 0 8px 22px; }
        .framing-card-body li { margin-bottom: 3px; }
        .framing-card-body.empty {
            color: #999; font-style: italic;
            padding: 14px 0; text-align: center; background: #fafbfc;
            border-radius: 6px;
        }

        /* Generic edit modal (re-used for context note and framing edit) */
        .modal-edit-shell {
            background: white; border-radius: 12px; width: 720px; max-width: 92vw;
            max-height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .modal-edit-header {
            padding: 14px 22px; border-bottom: 1px solid #eee;
            display: flex; align-items: center; justify-content: space-between;
        }
        .modal-edit-header h3 { margin: 0; font-size: 15px; font-weight: 600; color: #222; }
        .modal-edit-header .close-x {
            background: none; border: none; font-size: 22px; color: #888; cursor: pointer; padding: 0; line-height: 1;
        }
        .modal-edit-body { padding: 18px 22px; overflow-y: auto; flex: 1; }
        .modal-edit-footer {
            padding: 12px 22px; border-top: 1px solid #eee;
            display: flex; justify-content: flex-end; gap: 8px;
        }
        .form-row { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
        .form-row label {
            font-size: 12px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .form-row .help { font-size: 12px; color: #888; }
        .form-row textarea {
            padding: 10px 12px; font-size: 13px; font-family: inherit;
            border: 1px solid #d1d5db; border-radius: 6px; line-height: 1.5;
            resize: vertical; min-height: 100px;
        }
        .form-row textarea.tall { min-height: 280px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }

        /* Version history modal */
        .modal-history-shell {
            background: white; border-radius: 12px; width: 820px; max-width: 92vw;
            height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .history-list-empty {
            padding: 28px 20px; text-align: center; color: #999; font-size: 13px;
        }
        .history-row {
            border: 1px solid #e5e7eb; border-radius: 8px;
            margin-bottom: 10px; overflow: hidden;
        }
        .history-row.current { border-color: #6ee7b7; background: #f0fdf4; }
        .history-row-header {
            padding: 10px 14px; display: flex; align-items: center; gap: 12px;
            cursor: pointer; user-select: none;
            background: #fafbfc; border-bottom: 1px solid #eef0f2;
        }
        .history-row.current .history-row-header { background: #ecfdf5; }
        .history-row-header:hover { background: #f3f4f6; }
        .history-row-header .ver-pill {
            background: #e5e7eb; color: #374151;
            padding: 2px 9px; border-radius: 10px;
            font-size: 12px; font-weight: 700;
        }
        .history-row.current .ver-pill { background: #d1fae5; color: #047857; }
        .history-row-header .ver-meta {
            font-size: 12px; color: #666; flex: 1;
        }
        .history-row-header .ver-meta .edited-tag {
            background: #ede9fe; color: #5b21b6;
            padding: 1px 7px; border-radius: 9px; font-size: 11px; font-weight: 600;
            margin-left: 6px;
        }
        .history-row-header .ver-actions {
            display: flex; gap: 6px;
        }
        .history-row-body {
            display: none;
            padding: 12px 14px; font-size: 13px; line-height: 1.55; color: #333;
            max-height: 320px; overflow-y: auto;
            background: white;
        }
        .history-row.open .history-row-body { display: block; }
        .history-row-body h3 { font-size: 13px; font-weight: 700; margin: 10px 0 6px 0; }
        .history-row-body h4 { font-size: 12px; font-weight: 700; margin: 8px 0 4px 0; }
        .history-row-body p  { margin: 0 0 8px 0; }
        .history-row-body ul, .history-row-body ol { margin: 0 0 8px 22px; }
        .history-row-body li { margin-bottom: 3px; }

        /* The Edit button on category cards mirrors the framing actions area */
        .cat-header .cat-actions .btn { white-space: nowrap; }

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
                <a id="previewLink" class="btn btn-secondary" target="_blank" style="display:none;">Preview document</a>
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

    <!-- Framing context modal — analyst's optional "why we're procuring this" note -->
    <div id="contextModal" class="modal-backdrop" style="display:none;">
        <div class="modal-edit-shell">
            <div class="modal-edit-header">
                <h3>Procurement context</h3>
                <button class="close-x" onclick="closeContextModal()">&times;</button>
            </div>
            <div class="modal-edit-body">
                <div class="form-row">
                    <label for="ctxField">Optional context note</label>
                    <div class="help">A short paragraph telling the AI why the organisation is procuring this — e.g. replacing a legacy system, scaling up, compliance change. Used to ground the introduction. Two or three sentences is plenty.</div>
                    <textarea id="ctxField" rows="6" placeholder="Example: We are replacing our existing on-premise ITSM tool, which has reached end of life and no longer supports the integrations needed by our hybrid workforce. Driven by the move to cloud-first under the IT strategy refresh."></textarea>
                </div>
            </div>
            <div class="modal-edit-footer">
                <button class="btn btn-secondary" onclick="closeContextModal()">Cancel</button>
                <button class="btn btn-primary" id="ctxSaveBtn" onclick="saveContext()">Save</button>
            </div>
        </div>
    </div>

    <!-- Framing edit modal — manual edit of one framing section's HTML, TinyMCE-backed -->
    <div id="framingEditModal" class="modal-backdrop" style="display:none;">
        <div class="modal-edit-shell">
            <div class="modal-edit-header">
                <h3 id="framingEditTitle">Edit framing section</h3>
                <button class="close-x" onclick="closeFramingEdit()">&times;</button>
            </div>
            <div class="modal-edit-body">
                <div class="form-row">
                    <label>Content</label>
                    <div class="help">WYSIWYG editor — what you see is what the document will show. Saving marks the section "manually edited" so it won't be overwritten by Generate-all unless you re-generate it explicitly.</div>
                    <textarea id="framingEditField"></textarea>
                </div>
            </div>
            <div class="modal-edit-footer">
                <button class="btn btn-secondary" onclick="closeFramingEdit()">Cancel</button>
                <button class="btn btn-primary" id="framingEditSaveBtn" onclick="saveFramingEdit()">Save</button>
            </div>
        </div>
    </div>

    <!-- Category section edit modal — TinyMCE-backed -->
    <div id="sectionEditModal" class="modal-backdrop" style="display:none;">
        <div class="modal-edit-shell">
            <div class="modal-edit-header">
                <h3 id="sectionEditTitle">Edit section</h3>
                <button class="close-x" onclick="closeSectionEdit()">&times;</button>
            </div>
            <div class="modal-edit-body">
                <div class="form-row">
                    <label>Section content</label>
                    <div class="help">Edit the AI-generated HTML directly. Saving creates a new version (the prior version is kept in history) and marks the section "manually edited".</div>
                    <textarea id="sectionEditField"></textarea>
                </div>
            </div>
            <div class="modal-edit-footer">
                <button class="btn btn-secondary" onclick="closeSectionEdit()">Cancel</button>
                <button class="btn btn-primary" id="sectionEditSaveBtn" onclick="saveSectionEdit()">Save</button>
            </div>
        </div>
    </div>

    <!-- Version history modal -->
    <div id="historyModal" class="modal-backdrop" style="display:none;">
        <div class="modal-history-shell">
            <div class="modal-edit-header">
                <h3 id="historyTitle">Version history</h3>
                <button class="close-x" onclick="closeHistoryModal()">&times;</button>
            </div>
            <div class="modal-edit-body" id="historyBody">
                <div class="loading" style="padding:40px 0;">Loading…</div>
            </div>
            <div class="modal-edit-footer">
                <button class="btn btn-secondary" onclick="closeHistoryModal()">Close</button>
            </div>
        </div>
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
        let pageData = { categories: [], framing: [], lock: { all_locked: false }, framing_context: null };

        // Framing has a fixed list of three section keys, each with a
        // user-facing label. Order matters in the document.
        const FRAMING_KEYS = [
            { key: 'introduction',          label: 'Introduction' },
            { key: 'scope',                 label: 'Scope' },
            { key: 'response_instructions', label: 'Response instructions' }
        ];

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
            const framing = data.framing || [];
            const framingByKey = new Map(framing.map(f => [f.section_key, f]));

            const catGenerated = cats.filter(c => c.section_id !== null).length;
            const catPending   = cats.length - catGenerated;
            const framingGenerated = FRAMING_KEYS.filter(k => framingByKey.has(k.key)).length;
            const framingPending   = FRAMING_KEYS.length - framingGenerated;
            const totalGenerated = catGenerated + framingGenerated;
            const totalPending   = catPending + framingPending;
            const edited =
                cats.filter(c => c.is_manually_edited === true).length +
                framing.filter(f => f.is_manually_edited === true).length;

            document.getElementById('statCats').textContent    = cats.length;
            document.getElementById('statGen').textContent     = totalGenerated;
            document.getElementById('statPending').textContent = totalPending;
            document.getElementById('statEdited').textContent  = edited;
            document.getElementById('statsStrip').style.display = cats.length > 0 ? 'grid' : 'none';

            const banner       = document.getElementById('gateBanner');
            const generateBtn  = document.getElementById('generateAllBtn');
            const previewLink  = document.getElementById('previewLink');

            if (cats.length === 0) {
                banner.style.display = 'flex';
                document.getElementById('gateMsg').innerHTML =
                    '<strong>No categories yet.</strong> Run consolidation on this RFP first to produce a category structure.';
                generateBtn.style.display = 'none';
                previewLink.style.display = 'none';
            } else if (!data.lock.all_locked) {
                banner.style.display = 'flex';
                document.getElementById('gateMsg').innerHTML =
                    '<strong>Consolidated requirements are not locked.</strong> Section generation is gated on a fully-locked consolidation set so the inputs do not drift mid-generation. ' +
                    '<a href="consolidate.php?id=' + encodeURIComponent(rfpId) + '" style="color:#92400e;text-decoration:underline;">Open consolidation</a> to lock.';
                generateBtn.style.display = 'none';
                previewLink.style.display = 'none';
            } else {
                banner.style.display = 'none';
                generateBtn.style.display = '';
                generateBtn.textContent = totalGenerated === 0
                    ? 'Generate all'
                    : (totalPending > 0 ? 'Generate pending' : 'Re-generate all');
                previewLink.style.display = totalGenerated > 0 ? '' : 'none';
                previewLink.href = 'preview.php?id=' + encodeURIComponent(rfpId);
            }

            const contentEl = document.getElementById('contentEl');
            contentEl.style.display = 'block';

            if (cats.length === 0) {
                contentEl.innerHTML = '';
                return;
            }

            contentEl.innerHTML =
                renderFramingPanel(data.lock.all_locked, framingByKey, data.framing_context) +
                cats.map(c => renderCategoryCard(c)).join('');
        }

        function renderFramingPanel(allLocked, framingByKey, contextNote) {
            const cards = FRAMING_KEYS.map(spec => {
                const f = framingByKey.get(spec.key);
                const hasContent = !!f && !!f.section_content;
                const editedBadge = (f && f.is_manually_edited)
                    ? '<span class="badge edited">manually edited</span>'
                    : '';
                const stateBadge = hasContent
                    ? '<span class="badge fresh">drafted</span>'
                    : '<span class="badge empty">not yet drafted</span>';
                const generatedAt = f && f.generated_datetime
                    ? '<span>generated ' + escapeHtml(formatDateTime(f.generated_datetime)) + '</span>'
                    : '';
                const actions = allLocked ? `
                    <button class="btn btn-secondary" onclick="generateFraming('${spec.key}', ${hasContent ? 'true' : 'false'})">${hasContent ? 'Re-generate' : 'Generate'}</button>
                    ${hasContent ? `<button class="btn btn-secondary" onclick="openFramingEdit(${f.id})">Edit</button>` : ''}
                ` : '';
                const body = hasContent
                    ? `<div class="framing-card-body">${f.section_content}</div>`
                    : '<div class="framing-card-body empty">' + escapeHtml(spec.label) + ' has not been drafted yet.</div>';

                return `
                    <div class="framing-card" data-key="${spec.key}">
                        <div class="framing-card-header">
                            <h3>${escapeHtml(spec.label)}</h3>
                            <div class="meta">
                                ${stateBadge}
                                ${editedBadge}
                                ${generatedAt}
                            </div>
                            <div class="actions">${actions}</div>
                        </div>
                        ${body}
                    </div>
                `;
            }).join('');

            const ctx = contextNote && contextNote.trim() !== '' ? contextNote.trim() : '';
            const ctxBlock = `
                <div class="framing-context-block">
                    <div class="ctx-label">Procurement context (used by the AI when drafting framing)</div>
                    ${ctx
                        ? '<div>' + escapeHtml(ctx) + '</div>'
                        : '<div class="ctx-empty">None set. The AI will infer context from the categories alone — better intros come from a short note here.</div>'}
                </div>
            `;

            const headerActions = allLocked
                ? `<button class="btn btn-secondary" onclick="openContextModal()">Set context</button>`
                : '';

            return `
                <div class="framing-panel">
                    <div class="framing-panel-header">
                        <h2>Document framing</h2>
                        <div class="header-actions">${headerActions}</div>
                    </div>
                    ${ctxBlock}
                    ${cards}
                </div>
            `;
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
                ${hasSection ? `<button class="btn btn-secondary" onclick="openSectionEdit(${c.section_id})">Edit</button>` : ''}
                ${hasSection && c.version > 1 ? `<button class="btn btn-secondary" onclick="openHistoryModal(${c.section_id})">History</button>` : ''}
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

        // Batch queue items have one of two shapes so we can mix
        // framing and category jobs in one run:
        //   { kind: 'framing',  key: 'introduction', label: 'Introduction' }
        //   { kind: 'category', id: 7, label: 'Identity and access management' }

        function generateOne(categoryId, force) {
            const cat = pageData.categories.find(c => c.id === categoryId);
            startBatch([{ kind: 'category', id: categoryId, label: cat ? cat.name : 'Category #' + categoryId }], !!force);
        }

        function generateFraming(sectionKey, exists) {
            // Per-row Generate / Re-generate on the framing panel.
            // If a section already has content we always force regen,
            // otherwise the API would skip and the user wouldn't see
            // any change.
            const spec = FRAMING_KEYS.find(s => s.key === sectionKey);
            startBatch([{ kind: 'framing', key: sectionKey, label: spec ? spec.label : sectionKey }], !!exists);
        }

        function generateAll(forceAll) {
            // Queue framing first (intro / scope / response_instructions),
            // then every category that has consolidated requirements. The
            // hash-skip optimisation in each endpoint cheaply skips
            // up-to-date sections, so re-running on an already-generated
            // RFP costs nothing for unchanged work.
            const queue = [];
            FRAMING_KEYS.forEach(spec => queue.push({ kind: 'framing', key: spec.key, label: spec.label }));
            pageData.categories
                .filter(c => c.req_count > 0)
                .forEach(c => queue.push({ kind: 'category', id: c.id, label: c.name }));
            if (queue.length === 0) {
                alert('Nothing to generate yet — run consolidation first.');
                return;
            }
            if (!confirm('Generate ' + queue.length + ' sections?\n\n• ' + FRAMING_KEYS.length + ' framing sections (introduction, scope, response instructions)\n• ' + (queue.length - FRAMING_KEYS.length) + ' category sections\n\nEach takes 30-90 seconds. Already-generated sections whose inputs have not changed will be skipped automatically.')) {
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

            const tasksEl = document.getElementById('batchTasks');
            tasksEl.innerHTML = queue.map((item, i) => {
                const tid = taskIdFor(item);
                const labelPrefix = item.kind === 'framing' ? '<em style="color:#0369a1;">Framing — </em>' : '';
                return `
                    <div class="batch-task" id="${tid}">
                        <div class="pico">${i + 1}</div>
                        <div class="plabel">${labelPrefix}${escapeHtml(item.label)}</div>
                        <div class="pcount" id="${tid}-count"></div>
                    </div>
                `;
            }).join('');

            batchStart = Date.now();
            batchElapsedTimer = setInterval(updateBatchElapsed, 250);
            document.getElementById('batchTotal').textContent = batchTotal;

            processNext();
        }

        function taskIdFor(item) {
            return item.kind === 'framing'
                ? 'btask-fr-' + item.key
                : 'btask-cat-' + item.id;
        }

        function processNext() {
            if (batchCancelRequested || batchIndex >= batchQueue.length) {
                finishBatch();
                return;
            }
            const item   = batchQueue[batchIndex];
            const tid    = taskIdFor(item);
            const taskEl = document.getElementById(tid);
            taskEl.classList.add('active');
            document.getElementById('batchStream').textContent = '';

            const url = item.kind === 'framing'
                ? API_BASE + 'generate_framing.php?rfp_id=' + encodeURIComponent(rfpId)
                    + '&section_key=' + encodeURIComponent(item.key)
                    + (batchForceRegen ? '&force=1' : '')
                : API_BASE + 'generate_section.php?rfp_id=' + encodeURIComponent(rfpId)
                    + '&category_id=' + encodeURIComponent(item.id)
                    + (batchForceRegen ? '&force=1' : '');
            batchActiveStream = new EventSource(url);

            batchActiveStream.addEventListener('phase', (e) => {
                const data = JSON.parse(e.data);
                document.getElementById(tid + '-count').textContent = data.message || data.phase;
            });

            batchActiveStream.addEventListener('text', (e) => {
                const data = JSON.parse(e.data);
                appendBatchStream(data.delta || '');
            });

            batchActiveStream.addEventListener('skipped', (e) => {
                taskEl.classList.remove('active');
                taskEl.classList.add('skip');
                document.getElementById(tid + '-count').textContent = 'skipped (unchanged)';
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
                const versionLabel = data.version ? ('v' + data.version + ' · ') : '';
                document.getElementById(tid + '-count').textContent =
                    versionLabel + (data.duration_ms / 1000).toFixed(1) + 's';
                advanceBatch();
            });

            batchActiveStream.addEventListener('error', (e) => {
                let msg = 'Connection error';
                if (e.data) {
                    try { msg = JSON.parse(e.data).error || msg; } catch (_) { msg = e.data; }
                }
                taskEl.classList.remove('active');
                taskEl.classList.add('error');
                document.getElementById(tid + '-count').textContent = 'error: ' + msg.slice(0, 80);
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

        // ─── Procurement context modal ────────────────────────────────

        function openContextModal() {
            document.getElementById('ctxField').value = pageData.framing_context || '';
            document.getElementById('contextModal').style.display = 'flex';
        }
        function closeContextModal() {
            document.getElementById('contextModal').style.display = 'none';
        }
        async function saveContext() {
            const btn = document.getElementById('ctxSaveBtn');
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'update_framing_context.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        rfp_id: parseInt(rfpId, 10),
                        context: document.getElementById('ctxField').value
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Save failed');
                closeContextModal();
                loadAll();
            } catch (err) {
                alert('Save failed: ' + err.message);
            } finally {
                btn.disabled = false;
            }
        }

        // ─── TinyMCE editor lifecycle ─────────────────────────────────
        //
        // Both edit modals (framing + category section) use the same
        // editor configuration. We init TinyMCE on demand when a modal
        // opens against the visible textarea, then destroy on close so
        // the next open gets a fresh editor on the right textarea.

        function initTinyOn(textareaId, initialHtml) {
            // Destroy any existing instance — TinyMCE happily reattaches
            // to whichever textarea you tell it to but we want a clean
            // editor each time.
            destroyAllTiny();
            return new Promise((resolve) => {
                tinymce.init({
                    target: document.getElementById(textareaId),
                    license_key: 'gpl',
                    menubar: false,
                    statusbar: false,
                    height: 460,
                    plugins: 'lists link table',
                    // h1/h2 deliberately absent — the document layer renders
                    // those for us. Section content uses h3/h4 internally.
                    block_formats: 'Paragraph=p; Heading 3=h3; Heading 4=h4',
                    toolbar: 'undo redo | blocks | bold italic | bullist numlist | link table | removeformat',
                    content_style: 'body { font-family: Georgia, "Times New Roman", serif; font-size: 14px; line-height: 1.55; color: #1f2937; } h3 { font-family: "Segoe UI", Tahoma, sans-serif; font-size: 15px; margin: 16px 0 6px 0; } h4 { font-family: "Segoe UI", Tahoma, sans-serif; font-size: 13px; margin: 12px 0 4px 0; } p { margin: 0 0 10px 0; } ul, ol { margin: 0 0 10px 22px; }',
                    setup: editor => {
                        editor.on('init', () => {
                            editor.setContent(initialHtml || '');
                            resolve(editor);
                        });
                    }
                });
            });
        }

        function destroyAllTiny() {
            if (window.tinymce && tinymce.editors) {
                // Iterate over a copy — destroying mutates the array.
                tinymce.editors.slice().forEach(e => e.remove());
            }
        }

        function getTinyContent(textareaId) {
            const ed = tinymce.get(textareaId);
            return ed ? ed.getContent() : (document.getElementById(textareaId).value || '');
        }

        // ─── Framing edit modal ───────────────────────────────────────

        let editingFramingId = null;

        function openFramingEdit(framingId) {
            const f = (pageData.framing || []).find(x => x.id === framingId);
            if (!f) return;
            editingFramingId = framingId;
            document.getElementById('framingEditTitle').textContent = 'Edit ' + (f.section_title || 'framing section');
            document.getElementById('framingEditModal').style.display = 'flex';
            // initTinyOn returns a promise, but we don't need to await —
            // the editor populates itself on init.
            initTinyOn('framingEditField', f.section_content || '');
        }
        function closeFramingEdit() {
            document.getElementById('framingEditModal').style.display = 'none';
            destroyAllTiny();
        }
        async function saveFramingEdit() {
            const btn = document.getElementById('framingEditSaveBtn');
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'update_framing.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        id: editingFramingId,
                        section_content: getTinyContent('framingEditField')
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Save failed');
                closeFramingEdit();
                loadAll();
            } catch (err) {
                alert('Save failed: ' + err.message);
            } finally {
                btn.disabled = false;
            }
        }

        // ─── Category section edit modal ──────────────────────────────

        let editingSectionId = null;

        function openSectionEdit(sectionId) {
            const cat = pageData.categories.find(c => c.section_id === sectionId);
            if (!cat) return;
            editingSectionId = sectionId;
            document.getElementById('sectionEditTitle').textContent = 'Edit "' + cat.name + '" section';
            document.getElementById('sectionEditModal').style.display = 'flex';
            initTinyOn('sectionEditField', cat.section_content || '');
        }
        function closeSectionEdit() {
            document.getElementById('sectionEditModal').style.display = 'none';
            destroyAllTiny();
        }
        async function saveSectionEdit() {
            const btn = document.getElementById('sectionEditSaveBtn');
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'update_section.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        id: editingSectionId,
                        section_content: getTinyContent('sectionEditField')
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Save failed');
                closeSectionEdit();
                loadAll();
            } catch (err) {
                alert('Save failed: ' + err.message);
            } finally {
                btn.disabled = false;
            }
        }

        // ─── Version history modal ────────────────────────────────────

        let historySectionId = null;

        async function openHistoryModal(sectionId) {
            historySectionId = sectionId;
            document.getElementById('historyModal').style.display = 'flex';
            document.getElementById('historyBody').innerHTML = '<div class="loading" style="padding:40px 0;">Loading…</div>';
            try {
                const res = await fetch(API_BASE + 'get_section_history.php?section_id=' + encodeURIComponent(sectionId));
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Load failed');
                renderHistory(data);
            } catch (err) {
                document.getElementById('historyBody').innerHTML =
                    '<div class="error-state" style="padding:40px 0;">' + escapeHtml(err.message) + '</div>';
            }
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').style.display = 'none';
        }

        function renderHistory(data) {
            const cur = data.current;
            const hist = data.history || [];

            document.getElementById('historyTitle').textContent =
                'Version history — ' + (cur.category_name || 'Section');

            const currentRow = `
                <div class="history-row current open" data-h="current">
                    <div class="history-row-header" onclick="toggleHistoryRow(this)">
                        <span class="ver-pill">v${cur.version}</span>
                        <div class="ver-meta">
                            <strong>Current</strong>
                            ${cur.is_manually_edited ? '<span class="edited-tag">manually edited</span>' : ''}
                            · ${escapeHtml(formatDateTime(cur.edited_datetime || cur.generated_datetime))}
                        </div>
                        <div class="ver-actions"></div>
                    </div>
                    <div class="history-row-body">${cur.section_content || '<em>(empty)</em>'}</div>
                </div>
            `;

            const histRows = hist.map(h => `
                <div class="history-row" data-h="${h.id}">
                    <div class="history-row-header" onclick="toggleHistoryRow(this)">
                        <span class="ver-pill">v${h.version}</span>
                        <div class="ver-meta">
                            ${h.is_manually_edited ? '<span class="edited-tag">manually edited</span>' : 'AI-generated'}
                            · ${escapeHtml(formatDateTime(h.created_datetime))}
                        </div>
                        <div class="ver-actions">
                            <button class="btn btn-secondary" onclick="event.stopPropagation(); restoreVersion(${h.id});">Restore</button>
                        </div>
                    </div>
                    <div class="history-row-body">${h.section_content || '<em>(empty)</em>'}</div>
                </div>
            `).join('');

            const empty = hist.length === 0
                ? '<div class="history-list-empty">No earlier versions yet — every save and re-generation will appear here.</div>'
                : '';

            document.getElementById('historyBody').innerHTML = currentRow + histRows + empty;
        }

        function toggleHistoryRow(headerEl) {
            headerEl.parentElement.classList.toggle('open');
        }

        async function restoreVersion(historyId) {
            if (!confirm('Restore this earlier version?\n\nThe current version will be snapshotted into history first, so this is reversible.')) {
                return;
            }
            try {
                const res = await fetch(API_BASE + 'restore_section_version.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ section_id: historySectionId, history_id: historyId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Restore failed');
                closeHistoryModal();
                loadAll();
            } catch (err) {
                alert('Restore failed: ' + err.message);
            }
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
