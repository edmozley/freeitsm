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
require_once __DIR__ . '/../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'rfp-builder';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.rfp.document.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=16">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/tinymce/tinymce.min.js"></script>
    <style>
        body { --accent: var(--con-accent, #f59e0b); }
        .page-wrap { padding: 30px 40px; background: var(--app-bg, #f5f5f5); height: calc(100vh - 48px); overflow-y: auto; box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: var(--text-dim, #888); margin-bottom: 8px; }
        .breadcrumb a { color: var(--text-muted, #666); text-decoration: none; }
        .breadcrumb a:hover { color: var(--con-accent, #f59e0b); }
        .breadcrumb span.sep { margin: 0 6px; color: var(--text-faint, #ccc); }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: var(--text, #222); }
        .page-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s; font-family: inherit;
        }
        .btn-primary { background: var(--con-accent, #f59e0b); color: white; }
        .btn-primary:hover:not(:disabled) { background: var(--con-accent-hover, #d97706); }
        .btn-primary:disabled { background: #fcd34d; cursor: not-allowed; }
        .btn-secondary { background: var(--surface, white); color: var(--text, #333); border-color: var(--border, #ddd); }
        .btn-secondary:hover { background: var(--surface-hover, #f5f5f5); }

        .stats-strip {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: var(--surface, white); border-radius: 8px; padding: 14px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-left: 4px solid var(--border, #ddd);
        }
        .stat-card.cats     { border-left-color: #6b7280; }
        .stat-card.gen      { border-left-color: #10b981; }
        .stat-card.pending  { border-left-color: #f59e0b; }
        .stat-card.edited   { border-left-color: #8b5cf6; }
        .stat-card .stat-value { font-size: 22px; font-weight: 700; color: var(--text, #222); line-height: 1; }
        .stat-card .stat-label { font-size: 12px; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

        .gate-banner {
            background: var(--warning-bg, #fef3c7); border: 1px solid var(--warning-border, #fcd34d); color: var(--warning-text, #92400e);
            padding: 14px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;
            display: flex; align-items: center; gap: 14px;
        }
        .gate-banner .gate-icon { font-size: 20px; }
        .gate-banner .gate-msg  { flex: 1; }

        .empty-card {
            background: var(--surface, white); border-radius: 10px; padding: 40px 24px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .empty-card p { color: var(--text-muted, #666); margin: 6px 0; }

        /* Document framing panel — sits above the category cards */
        .framing-panel {
            background: var(--surface, white); border-radius: 10px; margin-bottom: 24px;
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
            padding: 12px 22px; background: var(--surface-2, #fafbfc); font-size: 13px;
            color: var(--text-muted, #444); line-height: 1.5; border-bottom: 1px solid var(--border-soft, #eef0f2);
        }
        .framing-context-block .ctx-label {
            font-size: 11px; color: var(--text-dim, #888); text-transform: uppercase;
            letter-spacing: 0.5px; margin-bottom: 4px;
        }
        .framing-context-block .ctx-empty { color: var(--text-dim, #999); font-style: italic; }
        .framing-card {
            padding: 14px 22px; border-bottom: 1px solid var(--border-soft, #f0f0f0);
        }
        .framing-card:last-child { border-bottom: none; }
        .framing-card-header {
            display: flex; align-items: flex-start; gap: 12px; margin-bottom: 8px;
        }
        .framing-card-header h3 {
            margin: 0; font-size: 14px; font-weight: 700; color: var(--text, #222);
            flex: 1;
        }
        .framing-card-header .meta {
            font-size: 11px; color: var(--text-dim, #999);
            display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
        }
        .framing-card-header .meta .badge {
            padding: 1px 7px; border-radius: 8px; background: var(--surface-3, #eef0f2); color: var(--text-muted, #555);
        }
        .framing-card-header .meta .badge.edited { background: #ede9fe; color: #5b21b6; }
        .framing-card-header .meta .badge.fresh  { background: #d1fae5; color: #047857; }
        .framing-card-header .meta .badge.empty  { background: #fef3c7; color: #92400e; }
        .framing-card-header .actions {
            display: flex; gap: 6px; flex-shrink: 0;
        }
        .framing-card-body { font-size: 13px; line-height: 1.55; color: var(--text, #333); }
        .framing-card-body h3 {
            font-size: 13px; font-weight: 700; color: var(--text, #1f2937);
            margin: 12px 0 6px 0;
        }
        .framing-card-body p { margin: 0 0 8px 0; }
        .framing-card-body ul, .framing-card-body ol { margin: 0 0 8px 22px; }
        .framing-card-body li { margin-bottom: 3px; }
        .framing-card-body.empty {
            color: var(--text-dim, #999); font-style: italic;
            padding: 14px 0; text-align: center; background: var(--surface-2, #fafbfc);
            border-radius: 6px;
        }

        /* Generic edit modal (re-used for context note and framing edit) */
        .modal-edit-shell {
            background: var(--surface, white); border-radius: 12px; width: 720px; max-width: 92vw;
            max-height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .modal-edit-header {
            padding: 14px 22px; border-bottom: 1px solid var(--border-soft, #eee);
            display: flex; align-items: center; justify-content: space-between;
        }
        .modal-edit-header h3 { margin: 0; font-size: 15px; font-weight: 600; color: var(--text, #222); }
        .modal-edit-header .close-x {
            background: none; border: none; font-size: 22px; color: var(--text-dim, #888); cursor: pointer; padding: 0; line-height: 1;
        }
        .modal-edit-body { padding: 18px 22px; overflow-y: auto; flex: 1; }
        .modal-edit-footer {
            padding: 12px 22px; border-top: 1px solid var(--border-soft, #eee);
            display: flex; justify-content: flex-end; gap: 8px;
        }
        .form-row { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
        .form-row label {
            font-size: 12px; font-weight: 600; color: var(--text-muted, #555);
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .form-row .help { font-size: 12px; color: var(--text-dim, #888); }
        .form-row textarea {
            padding: 10px 12px; font-size: 13px; font-family: inherit;
            border: 1px solid var(--border, #d1d5db); border-radius: 6px; line-height: 1.5;
            resize: vertical; min-height: 100px;
        }
        .form-row textarea.tall { min-height: 280px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }

        /* Version history modal */
        .modal-history-shell {
            background: var(--surface, white); border-radius: 12px; width: 820px; max-width: 92vw;
            height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .history-list-empty {
            padding: 28px 20px; text-align: center; color: var(--text-dim, #999); font-size: 13px;
        }
        .history-row {
            border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
            margin-bottom: 10px; overflow: hidden;
        }
        .history-row.current { border-color: #6ee7b7; background: #f0fdf4; }
        .history-row-header {
            padding: 10px 14px; display: flex; align-items: center; gap: 12px;
            cursor: pointer; user-select: none;
            background: var(--surface-2, #fafbfc); border-bottom: 1px solid var(--border-soft, #eef0f2);
        }
        .history-row.current .history-row-header { background: #ecfdf5; }
        .history-row-header:hover { background: var(--surface-hover, #f3f4f6); }
        .history-row-header .ver-pill {
            background: var(--surface-3, #e5e7eb); color: var(--text-muted, #374151);
            padding: 2px 9px; border-radius: 10px;
            font-size: 12px; font-weight: 700;
        }
        .history-row.current .ver-pill { background: #d1fae5; color: #047857; }
        .history-row-header .ver-meta {
            font-size: 12px; color: var(--text-muted, #666); flex: 1;
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
            padding: 12px 14px; font-size: 13px; line-height: 1.55; color: var(--text, #333);
            max-height: 320px; overflow-y: auto;
            background: var(--surface, white);
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
            background: var(--surface, white); border-radius: 10px; margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .cat-header {
            padding: 14px 22px; border-bottom: 1px solid var(--border-soft, #f0f0f0);
            background: var(--surface-2, #fafbfc);
            display: flex; align-items: flex-start; gap: 12px;
        }
        .cat-header .cat-info { flex: 1; min-width: 0; }
        .cat-header h2 {
            margin: 0; font-size: 17px; font-weight: 600; color: var(--text, #222);
        }
        .cat-header .cat-desc { font-size: 13px; color: var(--text-muted, #666); margin-top: 4px; line-height: 1.45; }
        .cat-header .cat-meta {
            font-size: 11px; color: var(--text-dim, #999); margin-top: 6px;
            display: flex; gap: 12px; flex-wrap: wrap;
        }
        .cat-header .cat-meta .badge {
            display: inline-block; padding: 1px 7px; border-radius: 8px;
            background: var(--surface-3, #eef0f2); color: var(--text-muted, #555);
        }
        .cat-header .cat-meta .badge.edited { background: #ede9fe; color: #5b21b6; }
        .cat-header .cat-meta .badge.fresh  { background: #d1fae5; color: #047857; }
        .cat-header .cat-meta .badge.empty  { background: #fef3c7; color: #92400e; }
        .cat-header .cat-actions {
            display: flex; gap: 6px; flex-shrink: 0;
        }

        .section-body {
            padding: 18px 28px; line-height: 1.6; color: var(--text, #222);
        }
        .section-body h3 {
            font-size: 15px; font-weight: 700; color: var(--text, #1f2937);
            margin: 18px 0 8px 0;
        }
        .section-body h3:first-child { margin-top: 0; }
        .section-body h4 { font-size: 13px; font-weight: 700; color: var(--text-muted, #374151); margin: 12px 0 6px 0; }
        .section-body p  { margin: 0 0 10px 0; font-size: 14px; }
        .section-body ul, .section-body ol { margin: 0 0 10px 22px; font-size: 14px; }
        .section-body li { margin-bottom: 4px; }
        .section-body table {
            border-collapse: collapse; margin: 10px 0; width: 100%; font-size: 13px;
        }
        .section-body th, .section-body td {
            border: 1px solid var(--border, #e5e7eb); padding: 6px 10px; text-align: left;
        }
        .section-body th { background: var(--surface-2, #f9fafb); font-weight: 600; }

        .section-empty {
            padding: 22px 28px; color: var(--text-dim, #999); font-size: 14px; font-style: italic;
        }

        /* ─── Generate-all batch modal ─────────────────────────────── */

        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: flex; align-items: center; justify-content: center; z-index: 1000;
        }
        .batch-modal {
            background: var(--surface, white); border-radius: 12px; width: 820px; max-width: 92vw;
            /* Fixed height (not max-height) so the modal doesn't jitter
               as the streaming text preview grows and resets between
               sections — internal panes scroll on overflow. */
            height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .batch-modal-header {
            padding: 14px 22px; border-bottom: 1px solid var(--border-soft, #eee);
            display: flex; align-items: center; gap: 12px;
        }
        .batch-modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #222); flex: 1; }

        .batch-summary {
            padding: 12px 22px; border-bottom: 1px solid var(--border-soft, #eee);
            display: flex; gap: 18px; font-size: 12px; color: var(--text-muted, #666); flex-wrap: wrap;
        }
        .batch-summary strong { color: var(--text, #222); font-variant-numeric: tabular-nums; }

        .batch-tasks { padding: 8px 22px 6px 22px; max-height: 280px; overflow-y: auto; }
        .batch-task {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0; font-size: 13px; border-bottom: 1px solid var(--border-soft, #f5f5f5);
        }
        .batch-task:last-child { border-bottom: none; }
        .batch-task .pico {
            width: 18px; height: 18px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; flex-shrink: 0;
            background: var(--surface-3, #f3f4f6); color: var(--text-faint, #aaa); border: 1px solid var(--border, #e5e7eb);
        }
        .batch-task.active .pico {
            background: #fef3c7; color: #b45309; border-color: #fcd34d;
            animation: pulse 1.2s ease-in-out infinite;
        }
        .batch-task.done   .pico { background: #d1fae5; color: #047857; border-color: #6ee7b7; }
        .batch-task.skip   .pico { background: #e5e7eb; color: #4b5563; border-color: #d1d5db; }
        .batch-task.error  .pico { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
        .batch-task .plabel { flex: 1; color: var(--text-muted, #555); }
        .batch-task.active .plabel,
        .batch-task.done   .plabel { color: var(--text, #222); }
        .batch-task.error  .plabel { color: #991b1b; }
        .batch-task .pcount {
            font-variant-numeric: tabular-nums; color: var(--text-dim, #888); font-size: 12px;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }

        .batch-stream {
            flex: 1; overflow-y: auto; padding: 12px 22px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px; line-height: 1.55; color: var(--text, #333);
            white-space: pre-wrap; word-break: break-word;
            background: var(--surface-2, #fafbfc); min-height: 160px;
            border-top: 1px solid var(--border-soft, #eee);
        }
        .batch-stream-label {
            font-size: 11px; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px;
            padding: 6px 22px 0 22px; background: var(--surface-2, #fafbfc);
        }

        .batch-modal-footer {
            padding: 12px 22px; border-top: 1px solid var(--border-soft, #eee);
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

        .loading, .error-state { text-align: center; padding: 40px; color: var(--text-dim, #999); }
        .error-state { color: #d13438; }

        /* Dark-mode overrides for the pale-blue framing-panel header
           (kept hardcoded in light so light mode is unchanged). */
        [data-theme-mode="dark"] .framing-panel-header { background: #10222e; border-bottom-color: #16394d; }
        [data-theme-mode="dark"] .framing-panel-header h2 { color: #7dd3fc; }
        /* Green "current version" row highlight — kept as a light green
           tint in light; darkened here so it isn't a near-white block. */
        [data-theme-mode="dark"] .history-row.current { background: #0f241b; }
        [data-theme-mode="dark"] .history-row.current .history-row-header { background: #102c20; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../"><?php echo htmlspecialchars(t('contracts.title')); ?></a><span class="sep">›</span>
            <a href="./"><?php echo htmlspecialchars(t('contracts.nav.rfp_builder')); ?></a><span class="sep">›</span>
            <a id="bcRfp" href="#">-</a><span class="sep">›</span>
            <span><?php echo htmlspecialchars(t('contracts.rfp.document.heading')); ?></span>
        </div>

        <div class="page-header">
            <h1><?php echo htmlspecialchars(t('contracts.rfp.document.heading')); ?></h1>
            <div class="page-actions">
                <a id="backLink" href="#" class="btn btn-secondary">&larr; <?php echo htmlspecialchars(t('contracts.rfp.suppliers.overview')); ?></a>
                <a id="previewLink" class="btn btn-secondary" target="_blank" style="display:none;"><?php echo htmlspecialchars(t('contracts.rfp.document.preview_document')); ?></a>
                <button id="generateAllBtn" class="btn btn-primary" onclick="generateAll(false)" style="display:none;"><?php echo htmlspecialchars(t('contracts.rfp.document.generate_all')); ?></button>
            </div>
        </div>

        <div id="gateBanner" class="gate-banner" style="display:none;">
            <span class="gate-icon">&#9888;</span>
            <div class="gate-msg" id="gateMsg"></div>
        </div>

        <div class="stats-strip" id="statsStrip" style="display:none;">
            <div class="stat-card cats">
                <div class="stat-value" id="statCats">0</div>
                <div class="stat-label"><?php echo htmlspecialchars(t('contracts.rfp.view.stat_categories')); ?></div>
            </div>
            <div class="stat-card gen">
                <div class="stat-value" id="statGen">0</div>
                <div class="stat-label"><?php echo htmlspecialchars(t('contracts.rfp.document.generated')); ?></div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value" id="statPending">0</div>
                <div class="stat-label"><?php echo htmlspecialchars(t('contracts.rfp.document.pending')); ?></div>
            </div>
            <div class="stat-card edited">
                <div class="stat-value" id="statEdited">0</div>
                <div class="stat-label"><?php echo htmlspecialchars(t('contracts.rfp.document.manually_edited')); ?></div>
            </div>
        </div>

        <div id="loadingEl" class="loading"><?php echo htmlspecialchars(t('common.loading')); ?></div>
        <div id="contentEl" style="display:none;"></div>
        <div id="errorEl" class="error-state" style="display:none;"></div>
    </div>

    <!-- Framing context modal — analyst's optional "why we're procuring this" note -->
    <div id="contextModal" class="modal-backdrop" style="display:none;">
        <div class="modal-edit-shell">
            <div class="modal-edit-header">
                <h3><?php echo htmlspecialchars(t('contracts.rfp.document.procurement_context')); ?></h3>
            </div>
            <div class="modal-edit-body">
                <div class="form-row">
                    <label for="ctxField"><?php echo htmlspecialchars(t('contracts.rfp.document.context_label')); ?></label>
                    <div class="help"><?php echo htmlspecialchars(t('contracts.rfp.document.context_help')); ?></div>
                    <textarea id="ctxField" rows="6" placeholder="<?php echo htmlspecialchars(t('contracts.rfp.document.context_ph')); ?>"></textarea>
                </div>
            </div>
            <div class="modal-edit-footer">
                <button class="btn btn-secondary" onclick="closeContextModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" id="ctxSaveBtn" onclick="saveContext()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Framing edit modal — manual edit of one framing section's HTML, TinyMCE-backed -->
    <div id="framingEditModal" class="modal-backdrop" style="display:none;">
        <div class="modal-edit-shell">
            <div class="modal-edit-header">
                <h3 id="framingEditTitle"><?php echo htmlspecialchars(t('contracts.rfp.document.edit_framing')); ?></h3>
            </div>
            <div class="modal-edit-body">
                <div class="form-row">
                    <label><?php echo htmlspecialchars(t('contracts.rfp.document.content')); ?></label>
                    <div class="help"><?php echo htmlspecialchars(t('contracts.rfp.document.framing_edit_help')); ?></div>
                    <textarea id="framingEditField"></textarea>
                </div>
            </div>
            <div class="modal-edit-footer">
                <button class="btn btn-secondary" onclick="closeFramingEdit()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" id="framingEditSaveBtn" onclick="saveFramingEdit()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Category section edit modal — TinyMCE-backed -->
    <div id="sectionEditModal" class="modal-backdrop" style="display:none;">
        <div class="modal-edit-shell">
            <div class="modal-edit-header">
                <h3 id="sectionEditTitle"><?php echo htmlspecialchars(t('contracts.rfp.document.edit_section')); ?></h3>
            </div>
            <div class="modal-edit-body">
                <div class="form-row">
                    <label><?php echo htmlspecialchars(t('contracts.rfp.document.section_content')); ?></label>
                    <div class="help"><?php echo htmlspecialchars(t('contracts.rfp.document.section_edit_help')); ?></div>
                    <textarea id="sectionEditField"></textarea>
                </div>
            </div>
            <div class="modal-edit-footer">
                <button class="btn btn-secondary" onclick="closeSectionEdit()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" id="sectionEditSaveBtn" onclick="saveSectionEdit()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Version history modal -->
    <div id="historyModal" class="modal-backdrop" style="display:none;">
        <div class="modal-history-shell">
            <div class="modal-edit-header">
                <h3 id="historyTitle"><?php echo htmlspecialchars(t('contracts.rfp.document.version_history')); ?></h3>
            </div>
            <div class="modal-edit-body" id="historyBody">
                <div class="loading" style="padding:40px 0;"><?php echo htmlspecialchars(t('common.loading')); ?></div>
            </div>
            <div class="modal-edit-footer">
                <button class="btn btn-secondary" onclick="closeHistoryModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- Batch generation modal -->
    <div id="batchModal" class="modal-backdrop" style="display:none;">
        <div class="batch-modal">
            <div class="batch-modal-header">
                <div id="batchSpinner" class="spinner"></div>
                <h3 id="batchTitle"><?php echo htmlspecialchars(t('contracts.rfp.document.generating_sections')); ?></h3>
            </div>
            <div class="batch-summary">
                <span><?php echo htmlspecialchars(t('contracts.rfp.document.done')); ?>: <strong id="batchDone">0</strong> / <strong id="batchTotal">0</strong></span>
                <span><?php echo htmlspecialchars(t('contracts.rfp.document.tokens_in')); ?>: <strong id="batchTokensIn">0</strong></span>
                <span><?php echo htmlspecialchars(t('contracts.rfp.document.tokens_out')); ?>: <strong id="batchTokensOut">0</strong></span>
                <span><?php echo htmlspecialchars(t('contracts.rfp.document.cached')); ?>: <strong id="batchCacheRead">0</strong></span>
                <span><?php echo htmlspecialchars(t('contracts.rfp.document.elapsed')); ?>: <strong id="batchElapsed">0s</strong></span>
            </div>
            <div class="batch-tasks" id="batchTasks"></div>
            <div class="batch-stream-label"><?php echo htmlspecialchars(t('contracts.rfp.document.live_output')); ?></div>
            <div class="batch-stream" id="batchStream"></div>
            <div class="batch-modal-footer">
                <button id="batchCancelBtn" class="btn btn-secondary" onclick="cancelBatch()"><?php echo htmlspecialchars(t('contracts.rfp.document.stop_after_current')); ?></button>
                <div class="right-actions">
                    <button id="batchCloseBtn" class="btn btn-primary" onclick="closeBatchModal()" disabled><?php echo htmlspecialchars(t('common.close')); ?></button>
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
            { key: 'introduction',          label: window.t('contracts.rfp.document.framing_introduction') },
            { key: 'scope',                 label: window.t('contracts.rfp.document.framing_scope') },
            { key: 'response_instructions', label: window.t('contracts.rfp.document.framing_response_instructions') }
        ];

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId) {
                showError(window.t('contracts.rfp.view.no_id') + ' <a href="./">' + window.t('contracts.rfp.view.back_to_list') + '</a>.');
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
                if (!rfpRes.success) throw new Error(rfpRes.error || window.t('contracts.rfp.suppliers.load_rfp_failed'));
                if (!secRes.success) throw new Error(secRes.error || window.t('contracts.rfp.document.load_sections_failed'));

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
                    window.t('contracts.rfp.document.gate_no_categories');
                generateBtn.style.display = 'none';
                previewLink.style.display = 'none';
            } else if (!data.lock.all_locked) {
                banner.style.display = 'flex';
                document.getElementById('gateMsg').innerHTML =
                    window.t('contracts.rfp.document.gate_not_locked') + ' ' +
                    '<a href="consolidate.php?id=' + encodeURIComponent(rfpId) + '" style="color:var(--warning-text, #92400e);text-decoration:underline;">' + window.t('contracts.rfp.document.open_consolidation') + '</a> ' + window.t('contracts.rfp.document.to_lock');
                generateBtn.style.display = 'none';
                previewLink.style.display = 'none';
            } else {
                banner.style.display = 'none';
                generateBtn.style.display = '';
                generateBtn.textContent = totalGenerated === 0
                    ? window.t('contracts.rfp.document.generate_all')
                    : (totalPending > 0 ? window.t('contracts.rfp.document.generate_pending') : window.t('contracts.rfp.document.regenerate_all'));
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
                    ? '<span class="badge edited">' + escapeHtml(window.t('contracts.rfp.document.badge_edited')) + '</span>'
                    : '';
                const stateBadge = hasContent
                    ? '<span class="badge fresh">' + escapeHtml(window.t('contracts.rfp.document.badge_drafted')) + '</span>'
                    : '<span class="badge empty">' + escapeHtml(window.t('contracts.rfp.document.badge_not_drafted')) + '</span>';
                const generatedAt = f && f.generated_datetime
                    ? '<span>' + escapeHtml(window.t('contracts.rfp.document.generated_prefix', { when: formatDateTime(f.generated_datetime) })) + '</span>'
                    : '';
                const actions = allLocked ? `
                    <button class="btn btn-secondary" onclick="generateFraming('${spec.key}', ${hasContent ? 'true' : 'false'})">${hasContent ? escapeHtml(window.t('contracts.rfp.document.regenerate')) : escapeHtml(window.t('contracts.rfp.document.generate'))}</button>
                    ${hasContent ? `<button class="btn btn-secondary" onclick="restyleFraming('${spec.key}')">${escapeHtml(window.t('contracts.rfp.document.restyle'))}</button>` : ''}
                    ${hasContent ? `<button class="btn btn-secondary" onclick="openFramingEdit(${f.id})">${escapeHtml(window.t('common.edit'))}</button>` : ''}
                ` : '';
                const body = hasContent
                    ? `<div class="framing-card-body">${f.section_content}</div>`
                    : '<div class="framing-card-body empty">' + escapeHtml(window.t('contracts.rfp.document.framing_not_drafted', { name: spec.label })) + '</div>';

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
                    <div class="ctx-label">${escapeHtml(window.t('contracts.rfp.document.ctx_label'))}</div>
                    ${ctx
                        ? '<div>' + escapeHtml(ctx) + '</div>'
                        : '<div class="ctx-empty">' + escapeHtml(window.t('contracts.rfp.document.ctx_empty')) + '</div>'}
                </div>
            `;

            const headerActions = allLocked
                ? `<button class="btn btn-secondary" onclick="openContextModal()">${escapeHtml(window.t('contracts.rfp.document.set_context'))}</button>`
                : '';

            return `
                <div class="framing-panel">
                    <div class="framing-panel-header">
                        <h2>${escapeHtml(window.t('contracts.rfp.document.document_framing'))}</h2>
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
                ? '<span class="badge edited">' + escapeHtml(window.t('contracts.rfp.document.badge_edited')) + '</span>'
                : '';
            const versionBadge = hasSection
                ? '<span class="badge fresh">v' + c.version + '</span>'
                : '<span class="badge empty">' + escapeHtml(window.t('contracts.rfp.document.badge_not_generated')) + '</span>';
            const reqBadge = '<span class="badge">' + escapeHtml(c.req_count === 1 ? window.t('contracts.rfp.document.req_count_one', { n: c.req_count }) : window.t('contracts.rfp.document.req_count_other', { n: c.req_count })) + '</span>';
            const generatedAt = c.generated_datetime ? formatDateTime(c.generated_datetime) : '';

            const canGenerate = pageData.lock.all_locked && c.req_count > 0;

            const actions = canGenerate ? `
                <button class="btn btn-secondary" onclick="generateOne(${c.id}, ${hasSection})">${hasSection ? escapeHtml(window.t('contracts.rfp.document.regenerate')) : escapeHtml(window.t('contracts.rfp.document.generate'))}</button>
                ${hasSection ? `<button class="btn btn-secondary" onclick="restyleSection(${c.section_id})">${escapeHtml(window.t('contracts.rfp.document.restyle'))}</button>` : ''}
                ${hasSection ? `<button class="btn btn-secondary" onclick="openSectionEdit(${c.section_id})">${escapeHtml(window.t('common.edit'))}</button>` : ''}
                ${hasSection && c.version > 1 ? `<button class="btn btn-secondary" onclick="openHistoryModal(${c.section_id})">${escapeHtml(window.t('contracts.rfp.document.history'))}</button>` : ''}
            ` : '';

            const body = hasSection
                ? `<div class="section-body">${c.section_content || ''}</div>`
                : `<div class="section-empty">${escapeHtml(window.t('contracts.rfp.document.section_not_generated'))}${c.req_count === 0 ? ' ' + escapeHtml(window.t('contracts.rfp.document.no_reqs_assigned')) : ''}</div>`;

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
                                ${generatedAt ? '<span>' + escapeHtml(window.t('contracts.rfp.document.generated_prefix', { when: generatedAt })) + '</span>' : ''}
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
            startBatch([{ kind: 'category', action: 'generate', id: categoryId, label: cat ? cat.name : window.t('contracts.rfp.document.category_n', { n: categoryId }) }], !!force);
        }

        function generateFraming(sectionKey, exists) {
            // Per-row Generate / Re-generate on the framing panel.
            // If a section already has content we always force regen,
            // otherwise the API would skip and the user wouldn't see
            // any change.
            const spec = FRAMING_KEYS.find(s => s.key === sectionKey);
            startBatch([{ kind: 'framing', action: 'generate', key: sectionKey, label: spec ? spec.label : sectionKey }], !!exists);
        }

        async function restyleSection(sectionId) {
            const cat = pageData.categories.find(c => c.section_id === sectionId);
            if (!(await showConfirm({ title: window.t('contracts.rfp.document.restyle_section'), message: window.t('contracts.rfp.document.restyle_section_msg', { name: (cat ? cat.name : window.t('contracts.rfp.document.this_section')) }), okLabel: window.t('contracts.rfp.document.restyle'), okClass: 'primary' }))) return;
            startBatch([{
                kind: 'category', action: 'restyle',
                id: sectionId, // for restyle action this is section_id, not category_id
                label: (cat ? cat.name : window.t('contracts.rfp.document.section_n', { n: sectionId }))
            }], false);
        }

        async function restyleFraming(sectionKey) {
            const spec = FRAMING_KEYS.find(s => s.key === sectionKey);
            const ok = await showConfirm({
                title: window.t('contracts.rfp.document.restyle'),
                message: window.t('contracts.rfp.document.restyle_framing_msg', { name: (spec ? spec.label.toLowerCase() : sectionKey) }),
                okLabel: window.t('contracts.rfp.document.restyle'),
                okClass: 'primary'
            });
            if (!ok) return;
            startBatch([{ kind: 'framing', action: 'restyle', key: sectionKey, label: spec ? spec.label : sectionKey }], false);
        }

        async function generateAll(forceAll) {
            // Queue framing first (intro / scope / response_instructions),
            // then every category that has consolidated requirements. The
            // hash-skip optimisation in each endpoint cheaply skips
            // up-to-date sections, so re-running on an already-generated
            // RFP costs nothing for unchanged work.
            const queue = [];
            FRAMING_KEYS.forEach(spec => queue.push({ kind: 'framing', action: 'generate', key: spec.key, label: spec.label }));
            pageData.categories
                .filter(c => c.req_count > 0)
                .forEach(c => queue.push({ kind: 'category', action: 'generate', id: c.id, label: c.name }));
            if (queue.length === 0) {
                showToast(window.t('contracts.rfp.document.nothing_to_generate'), 'error');
                return;
            }
            if (!(await showConfirm({ title: window.t('contracts.rfp.document.generate_sections'), message: window.t('contracts.rfp.document.generate_confirm', { total: queue.length, framing: FRAMING_KEYS.length, cats: (queue.length - FRAMING_KEYS.length) }), okLabel: window.t('contracts.rfp.document.generate'), okClass: 'primary' }))) return;
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
                const action = item.action || 'generate';
                const framingTag = item.kind === 'framing' ? '<em style="color:#0369a1;">' + escapeHtml(window.t('contracts.rfp.document.tag_framing')) + ' </em>' : '';
                const actionTag  = action === 'restyle'
                    ? '<em style="color:#7c3aed;">' + escapeHtml(window.t('contracts.rfp.document.tag_restyle')) + ' </em>'
                    : '';
                return `
                    <div class="batch-task" id="${tid}">
                        <div class="pico">${i + 1}</div>
                        <div class="plabel">${actionTag}${framingTag}${escapeHtml(item.label)}</div>
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
            const suffix = item.kind === 'framing' ? ('fr-' + item.key) : ('cat-' + item.id);
            // Include the action so a generate row and a restyle row in
            // the same batch don't collide on DOM ids (rare case but
            // possible if we ever queue both for the same target).
            return 'btask-' + (item.action || 'generate') + '-' + suffix;
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

            const action = item.action || 'generate';
            let url;
            if (action === 'restyle') {
                url = item.kind === 'framing'
                    ? API_BASE + 'restyle_framing.php?rfp_id=' + encodeURIComponent(rfpId)
                        + '&section_key=' + encodeURIComponent(item.key)
                    : API_BASE + 'restyle_section.php?rfp_id=' + encodeURIComponent(rfpId)
                        + '&section_id=' + encodeURIComponent(item.id);
            } else {
                url = item.kind === 'framing'
                    ? API_BASE + 'generate_framing.php?rfp_id=' + encodeURIComponent(rfpId)
                        + '&section_key=' + encodeURIComponent(item.key)
                        + (batchForceRegen ? '&force=1' : '')
                    : API_BASE + 'generate_section.php?rfp_id=' + encodeURIComponent(rfpId)
                        + '&category_id=' + encodeURIComponent(item.id)
                        + (batchForceRegen ? '&force=1' : '');
            }
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
                document.getElementById(tid + '-count').textContent = window.t('contracts.rfp.document.skipped_unchanged');
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
                let msg = window.t('contracts.rfp.document.connection_error');
                if (e.data) {
                    try { msg = JSON.parse(e.data).error || msg; } catch (_) { msg = e.data; }
                }
                taskEl.classList.remove('active');
                taskEl.classList.add('error');
                document.getElementById(tid + '-count').textContent = window.t('contracts.rfp.document.error_prefix') + ' ' + msg.slice(0, 80);
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
            document.getElementById('batchCancelBtn').textContent = window.t('contracts.rfp.document.stopping');
        }

        function finishBatch() {
            if (batchActiveStream) { batchActiveStream.close(); batchActiveStream = null; }
            if (batchElapsedTimer) { clearInterval(batchElapsedTimer); batchElapsedTimer = null; }
            document.getElementById('batchSpinner').classList.add('done');
            document.getElementById('batchTitle').textContent =
                batchCancelRequested ? window.t('contracts.rfp.document.batch_stopped', { done: batchDone, total: batchTotal })
                                     : window.t('contracts.rfp.document.batch_generated', { done: batchDone, total: batchTotal });
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
            document.getElementById('batchTitle').textContent = window.t('contracts.rfp.document.generating_sections');
            document.getElementById('batchTokensIn').textContent  = '0';
            document.getElementById('batchTokensOut').textContent = '0';
            document.getElementById('batchCacheRead').textContent = '0';
            document.getElementById('batchElapsed').textContent   = '0s';
            document.getElementById('batchDone').textContent      = '0';
            document.getElementById('batchStream').textContent    = '';
            document.getElementById('batchCloseBtn').disabled     = true;
            document.getElementById('batchCancelBtn').disabled    = false;
            document.getElementById('batchCancelBtn').textContent = window.t('contracts.rfp.document.stop_after_current');
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
            const d = parseUTCDate(s);
            if (isNaN(d)) return s;
            return d.toLocaleString('en-GB', tzOpts({ day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }));
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
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.suppliers.save_failed_short'));
                closeContextModal();
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.list.save_failed') + ' ' + err.message, 'error');
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
            const tinyDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';
            return new Promise((resolve) => {
                tinymce.init({
                    target: document.getElementById(textareaId),
                    license_key: 'gpl',
                    skin: tinyDark ? 'oxide-dark' : 'oxide',
                    content_css: 'default',
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
            document.getElementById('framingEditTitle').textContent = window.t('contracts.rfp.document.edit_prefix', { name: (f.section_title || window.t('contracts.rfp.document.framing_section')) });
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
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.suppliers.save_failed_short'));
                closeFramingEdit();
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.list.save_failed') + ' ' + err.message, 'error');
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
            document.getElementById('sectionEditTitle').textContent = window.t('contracts.rfp.document.edit_section_named', { name: cat.name });
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
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.suppliers.save_failed_short'));
                closeSectionEdit();
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.list.save_failed') + ' ' + err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        // ─── Version history modal ────────────────────────────────────

        let historySectionId = null;

        async function openHistoryModal(sectionId) {
            historySectionId = sectionId;
            document.getElementById('historyModal').style.display = 'flex';
            document.getElementById('historyBody').innerHTML = '<div class="loading" style="padding:40px 0;">' + escapeHtml(window.t('common.loading')) + '</div>';
            try {
                const res = await fetch(API_BASE + 'get_section_history.php?section_id=' + encodeURIComponent(sectionId));
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.document.load_failed'));
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
                window.t('contracts.rfp.document.history_title', { name: (cur.category_name || window.t('contracts.rfp.document.section_word')) });

            const currentRow = `
                <div class="history-row current open" data-h="current">
                    <div class="history-row-header" onclick="toggleHistoryRow(this)">
                        <span class="ver-pill">v${cur.version}</span>
                        <div class="ver-meta">
                            <strong>${escapeHtml(window.t('contracts.rfp.document.current'))}</strong>
                            ${cur.is_manually_edited ? '<span class="edited-tag">' + escapeHtml(window.t('contracts.rfp.document.badge_edited')) + '</span>' : ''}
                            · ${escapeHtml(formatDateTime(cur.edited_datetime || cur.generated_datetime))}
                        </div>
                        <div class="ver-actions"></div>
                    </div>
                    <div class="history-row-body">${cur.section_content || '<em>' + escapeHtml(window.t('contracts.rfp.document.empty_paren')) + '</em>'}</div>
                </div>
            `;

            const histRows = hist.map(h => `
                <div class="history-row" data-h="${h.id}">
                    <div class="history-row-header" onclick="toggleHistoryRow(this)">
                        <span class="ver-pill">v${h.version}</span>
                        <div class="ver-meta">
                            ${h.is_manually_edited ? '<span class="edited-tag">' + escapeHtml(window.t('contracts.rfp.document.badge_edited')) + '</span>' : escapeHtml(window.t('contracts.rfp.document.ai_generated'))}
                            · ${escapeHtml(formatDateTime(h.created_datetime))}
                        </div>
                        <div class="ver-actions">
                            <button class="btn btn-secondary" onclick="event.stopPropagation(); restoreVersion(${h.id});">${escapeHtml(window.t('contracts.rfp.document.restore'))}</button>
                        </div>
                    </div>
                    <div class="history-row-body">${h.section_content || '<em>' + escapeHtml(window.t('contracts.rfp.document.empty_paren')) + '</em>'}</div>
                </div>
            `).join('');

            const empty = hist.length === 0
                ? '<div class="history-list-empty">' + escapeHtml(window.t('contracts.rfp.document.no_earlier_versions')) + '</div>'
                : '';

            document.getElementById('historyBody').innerHTML = currentRow + histRows + empty;
        }

        function toggleHistoryRow(headerEl) {
            headerEl.parentElement.classList.toggle('open');
        }

        async function restoreVersion(historyId) {
            if (!(await showConfirm({ title: window.t('contracts.rfp.document.confirm'), message: window.t('contracts.rfp.document.restore_confirm'), okLabel: window.t('common.ok'), okClass: 'primary' }))) return;
            try {
                const res = await fetch(API_BASE + 'restore_section_version.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ section_id: historySectionId, history_id: historyId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.document.restore_failed_short'));
                closeHistoryModal();
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.document.restore_failed') + ' ' + err.message, 'error');
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
