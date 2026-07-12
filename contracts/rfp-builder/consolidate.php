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
    <title><?php echo htmlspecialchars(t('contracts.rfp.consolidate.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
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
            background: var(--surface, white); border-radius: 8px; padding: 14px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-left: 4px solid var(--border, #ddd);
        }
        .stat-card.cats     { border-left-color: #6b7280; }
        .stat-card.cons     { border-left-color: #3b82f6; }
        .stat-card.conf     { border-left-color: #ef4444; }
        .stat-card.linked   { border-left-color: #10b981; }
        .stat-card .stat-value { font-size: 22px; font-weight: 700; color: var(--text, #222); line-height: 1; }
        .stat-card .stat-label { font-size: 12px; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

        .empty-card {
            background: var(--surface, white); border-radius: 10px; padding: 40px 24px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .empty-card p { color: var(--text-muted, #666); margin: 6px 0; }
        .empty-card .hint { font-size: 13px; color: var(--text-dim, #999); margin-top: 14px; }

        .category-card {
            background: var(--surface, white); border-radius: 10px; margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .category-header {
            padding: 14px 22px; border-bottom: 1px solid var(--border-soft, #f0f0f0);
            background: var(--surface-2, #fafbfc);
        }
        .category-header h2 {
            margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #222);
            display: flex; align-items: center; gap: 10px;
        }
        .category-header h2 .req-count {
            font-size: 12px; color: var(--text-dim, #888); font-weight: 500;
            background: var(--surface-3, #eef0f2); padding: 2px 8px; border-radius: 10px;
        }
        .category-desc { font-size: 13px; color: var(--text-muted, #666); margin-top: 4px; }

        .req-row {
            padding: 14px 22px; border-bottom: 1px solid var(--border-soft, #f5f5f5);
            position: relative;
        }
        .req-row:last-child { border-bottom: none; }
        .req-row.selected { background: #fffbeb; }
        .req-row-top { display: flex; gap: 10px; align-items: flex-start; }
        .req-select {
            margin-top: 4px; cursor: pointer; flex-shrink: 0;
        }
        .req-row-text { flex: 1; font-size: 14px; color: var(--text, #222); line-height: 1.5; }
        .req-row-rationale {
            font-size: 12px; color: var(--text-dim, #888); font-style: italic;
            margin-top: 6px; line-height: 1.5;
        }
        .req-row-actions {
            display: flex; gap: 4px; flex-shrink: 0;
            opacity: 0; transition: opacity 0.15s;
        }
        .req-row:hover .req-row-actions { opacity: 1; }
        .req-row-actions .icon-btn {
            background: var(--surface, white); border: 1px solid var(--border, #ddd); border-radius: 5px;
            padding: 3px 8px; font-size: 12px; color: var(--text-muted, #555); cursor: pointer;
            font-family: inherit;
        }
        .req-row-actions .icon-btn:hover { background: var(--surface-hover, #f5f5f5); color: var(--text, #222); }
        .req-row-actions .icon-btn.danger:hover { background: #fef2f2; color: #b91c1c; border-color: #fca5a5; }

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
            background: var(--surface-2, #fafbfc); border: 1px solid var(--border-soft, #eef0f2); border-radius: 6px;
            display: none;
        }
        .source-list.open { display: block; }
        .source-item { padding: 6px 0; border-bottom: 1px dashed var(--border, #e5e7eb); font-size: 13px; }
        .source-item:last-child { border-bottom: none; }
        .source-dept {
            display: inline-block; padding: 1px 7px; border-radius: 9px;
            font-size: 11px; font-weight: 600; margin-right: 6px;
            background: var(--surface-3, #e5e7eb); color: var(--text-muted, #374151);
        }
        .source-quote {
            color: var(--text-muted, #555); font-style: italic; margin-top: 3px;
            border-left: 2px solid var(--border, #ddd); padding-left: 8px;
        }
        .source-doc { font-size: 11px; color: var(--text-dim, #999); margin-top: 2px; }

        .conflicts-card {
            background: var(--surface, white); border-radius: 10px; margin-top: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
            border-left: 4px solid #ef4444;
        }
        .conflicts-card .conflicts-header {
            padding: 14px 22px; background: var(--danger-bg, #fef2f2); border-bottom: 1px solid #fee2e2;
        }
        .conflicts-card h2 {
            margin: 0; font-size: 16px; font-weight: 600; color: var(--danger-text, #991b1b);
        }
        .conflict-row { padding: 14px 22px; border-bottom: 1px solid var(--border-soft, #f5f5f5); }
        .conflict-row:last-child { border-bottom: none; }
        .conflict-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .conflict-side {
            background: var(--surface-2, #fafbfc); border: 1px solid var(--border-soft, #eef0f2); border-radius: 6px;
            padding: 10px 12px;
        }
        .conflict-side .side-label {
            font-size: 11px; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .conflict-side .side-text { font-size: 13px; color: var(--text, #222); line-height: 1.5; }
        .conflict-explanation {
            margin-top: 10px; padding: 10px 12px;
            background: #fff7ed; border-left: 3px solid var(--con-accent, #f59e0b); border-radius: 4px;
            font-size: 13px; color: var(--text-muted, #555); line-height: 1.5;
        }
        .conflict-resolution {
            margin-top: 8px; font-size: 12px; color: var(--text-dim, #888);
        }
        .conflict-resolution.resolved { color: #047857; }
        .conflict-actions {
            margin-top: 12px; display: flex; gap: 6px; flex-wrap: wrap;
        }
        .conflict-actions .btn-resolve {
            background: var(--surface, white); border: 1px solid var(--border, #d1d5db); border-radius: 6px;
            padding: 5px 10px; font-size: 12px; color: var(--text-muted, #374151); cursor: pointer;
            font-family: inherit;
        }
        .conflict-actions .btn-resolve:hover { background: var(--surface-hover, #f5f5f5); color: var(--text, #111827); }
        .conflict-actions .btn-resolve.choose-a { border-color: #93c5fd; color: #1e40af; }
        .conflict-actions .btn-resolve.choose-a:hover { background: #eff6ff; }
        .conflict-actions .btn-resolve.choose-b { border-color: #c4b5fd; color: #5b21b6; }
        .conflict-actions .btn-resolve.choose-b:hover { background: #f5f3ff; }
        .conflict-actions .btn-resolve.merge    { border-color: #86efac; color: #047857; }
        .conflict-actions .btn-resolve.merge:hover    { background: #ecfdf5; }
        .conflict-actions .btn-resolve.dismiss  { border-color: var(--border, #d1d5db); color: #6b7280; }
        .conflict-actions .btn-resolve.reopen   { border-color: #fbbf24; color: #b45309; }

        .resolved-section {
            margin-top: 18px; border-top: 1px dashed var(--border, #e5e7eb); padding-top: 12px;
        }
        .resolved-section-label {
            font-size: 11px; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px;
            padding: 0 22px; margin-bottom: 6px;
        }
        .conflict-row.resolved-row { opacity: 0.65; }
        .conflict-row.resolved-row .conflict-pair { background: var(--surface-2, #fafbfc); }
        .resolution-badge {
            display: inline-block; padding: 1px 8px; border-radius: 9px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;
            margin-right: 6px;
        }
        .resolution-badge.chose_a   { background: #dbeafe; color: #1e40af; }
        .resolution-badge.chose_b   { background: #ede9fe; color: #5b21b6; }
        .resolution-badge.merged    { background: #d1fae5; color: #047857; }
        .resolution-badge.split     { background: #fef3c7; color: #92400e; }
        .resolution-badge.dismissed { background: #e5e7eb; color: #4b5563; }

        .conflict-resolution-notes {
            margin-top: 6px; padding: 6px 10px; background: var(--surface-2, #f9fafb);
            border-left: 3px solid #6b7280; border-radius: 4px;
            font-size: 12px; color: var(--text-muted, #555);
        }

        .loading, .error-state { text-align: center; padding: 40px; color: var(--text-dim, #999); }
        .error-state { color: #d13438; }

        /* Streaming progress modal */
        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: flex; align-items: center; justify-content: center;
            z-index: 1000;
        }
        .stream-modal {
            background: var(--surface, white); border-radius: 12px; width: 720px; max-width: 92vw;
            /* Fixed height (not max-height) so the modal doesn't jitter
               as the streaming text preview grows — the inner body
               scrolls on overflow instead. */
            height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .stream-modal-header {
            padding: 16px 22px; border-bottom: 1px solid var(--border-soft, #eee);
            display: flex; align-items: center; gap: 12px;
        }
        .stream-modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #222); flex: 1; }
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
            padding: 12px 22px; border-bottom: 1px solid var(--border-soft, #eee);
            display: flex; flex-direction: column; gap: 7px;
            background: var(--surface, white);
        }
        .ptask {
            display: flex; align-items: center; gap: 12px;
            font-size: 13px; color: var(--text-muted, #555);
        }
        .ptask .pico {
            width: 18px; height: 18px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; flex-shrink: 0;
            background: var(--surface-hover, #f3f4f6); color: var(--text-faint, #aaa); border: 1px solid var(--border, #e5e7eb);
        }
        .ptask.active .pico {
            background: #fef3c7; color: #b45309; border-color: #fcd34d;
            animation: pulse 1.2s ease-in-out infinite;
        }
        .ptask.done .pico {
            background: #d1fae5; color: #047857; border-color: #6ee7b7;
        }
        .ptask.active .plabel { color: var(--text, #222); font-weight: 600; }
        .ptask.done   .plabel { color: #047857; }
        .ptask .pcount {
            margin-left: auto; font-variant-numeric: tabular-nums;
            color: var(--text-dim, #888); font-size: 12px;
        }
        .ptask.active .pcount, .ptask.done .pcount { color: var(--text-muted, #444); }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }

        .stream-body {
            flex: 1; overflow-y: auto; padding: 14px 22px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px; line-height: 1.55; color: var(--text, #333);
            white-space: pre-wrap; word-break: break-word;
            background: var(--surface-2, #fafbfc); min-height: 200px;
        }

        .stream-meta {
            padding: 10px 22px; border-top: 1px solid var(--border-soft, #eee); background: var(--surface-2, #fafbfc);
            display: flex; gap: 18px; font-size: 12px; color: var(--text-muted, #666);
            justify-content: space-between; flex-wrap: wrap;
        }
        .stream-meta .meta-item strong { color: var(--text, #222); font-variant-numeric: tabular-nums; }

        .stream-modal-footer {
            padding: 12px 22px; border-top: 1px solid var(--border-soft, #eee);
            display: flex; justify-content: flex-end; gap: 8px;
        }

        /* Merge selection bar — fixed at bottom when 2+ rows are checked */
        .merge-bar {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: #1f2937; color: white; padding: 12px 24px;
            display: none; align-items: center; gap: 14px;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.15);
            z-index: 800;
        }
        .merge-bar.active { display: flex; }
        .merge-bar .merge-count {
            font-weight: 600; font-size: 14px;
        }
        .merge-bar .spacer { flex: 1; }
        .merge-bar .btn-primary { background: var(--con-accent, #f59e0b); }
        .merge-bar .btn-secondary { background: transparent; color: white; border-color: rgba(255,255,255,0.3); }
        .merge-bar .btn-secondary:hover { background: rgba(255,255,255,0.1); }

        /* Generic edit/add/split/merge modal */
        .edit-modal {
            background: var(--surface, white); border-radius: 12px; width: 640px; max-width: 92vw;
            max-height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .edit-modal.wide { width: 820px; }
        .edit-modal-header {
            padding: 14px 22px; border-bottom: 1px solid var(--border-soft, #eee);
            font-size: 16px; font-weight: 600; color: var(--text, #222);
            display: flex; align-items: center; justify-content: space-between;
        }
        .edit-modal-header .close-x {
            background: none; border: none; font-size: 22px; color: var(--text-dim, #888);
            cursor: pointer; padding: 0; line-height: 1;
        }
        .edit-modal-body {
            padding: 18px 22px; overflow-y: auto; flex: 1;
        }
        .edit-modal-footer {
            padding: 12px 22px; border-top: 1px solid var(--border-soft, #eee);
            display: flex; justify-content: flex-end; gap: 8px;
        }
        .form-row {
            display: flex; flex-direction: column; gap: 5px;
            margin-bottom: 14px;
        }
        .form-row label {
            font-size: 12px; font-weight: 600; color: var(--text-muted, #555);
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .form-row input, .form-row select, .form-row textarea {
            padding: 8px 10px; font-size: 14px; font-family: inherit;
            border: 1px solid var(--border, #d1d5db); border-radius: 6px;
            color: var(--text, #222); background: var(--surface, white);
        }
        .form-row textarea { resize: vertical; min-height: 70px; line-height: 1.5; }
        .form-row .form-help {
            font-size: 12px; color: var(--text-dim, #888);
        }
        .form-row-grid {
            display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;
            margin-bottom: 14px;
        }
        .form-row-grid .form-row { margin-bottom: 0; }

        .source-pick-list {
            max-height: 260px; overflow-y: auto;
            border: 1px solid var(--border, #e5e7eb); border-radius: 6px;
            background: var(--surface-2, #fafbfc);
        }
        .source-pick-row {
            padding: 9px 12px; border-bottom: 1px solid var(--border-soft, #eef0f2);
            display: flex; align-items: flex-start; gap: 10px;
            font-size: 13px;
        }
        .source-pick-row:last-child { border-bottom: none; }
        .source-pick-row .source-pick-info { flex: 1; min-width: 0; }
        .source-pick-row .source-pick-info .source-text {
            color: var(--text, #333); line-height: 1.45;
        }
        .source-pick-row .source-pick-info .source-meta {
            font-size: 11px; color: var(--text-dim, #888); margin-top: 2px;
        }
        .source-pick-row select {
            flex-shrink: 0; padding: 4px 8px; font-size: 12px;
            border: 1px solid var(--border, #d1d5db); border-radius: 5px;
            font-family: inherit; background: var(--surface, white);
        }

        .split-row-card {
            border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
            padding: 12px 14px; margin-bottom: 12px; background: var(--surface-2, #fafbfc);
            position: relative;
        }
        .split-row-card .split-row-num {
            display: inline-block; background: #6b7280; color: white;
            border-radius: 10px; padding: 1px 9px; font-size: 11px; font-weight: 600;
            margin-bottom: 8px;
        }
        .split-row-card .split-row-remove {
            position: absolute; top: 8px; right: 10px;
            background: none; border: none; color: var(--text-dim, #999); cursor: pointer;
            font-size: 18px; line-height: 1;
        }
        .split-row-card .split-row-remove:hover { color: #b91c1c; }

        .merge-summary {
            margin-bottom: 14px; padding: 10px 12px;
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px;
            font-size: 13px; color: var(--con-accent-hover, #92400e);
        }
        .merge-summary ul { margin: 6px 0 0 18px; padding: 0; }
        .merge-summary li { margin-bottom: 3px; }

        /* Dark-mode overrides for pale amber tints whose light value differs
           from the --con-accent-soft token (so light mode stays unshifted). */
        [data-theme-mode="dark"] .req-row.selected      { background: #3a2e12; }
        [data-theme-mode="dark"] .conflict-explanation  { background: #3a2e12; }
        [data-theme-mode="dark"] .merge-summary         { background: #3a2e12; border-color: #5a4a1e; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../"><?php echo htmlspecialchars(t('contracts.title')); ?></a><span class="sep">›</span>
            <a href="./"><?php echo htmlspecialchars(t('contracts.nav.rfp_builder')); ?></a><span class="sep">›</span>
            <a id="bcRfp" href="#">-</a><span class="sep">›</span>
            <span><?php echo htmlspecialchars(t('contracts.rfp.consolidate.consolidated')); ?></span>
        </div>

        <div class="page-header">
            <h1><?php echo htmlspecialchars(t('contracts.rfp.consolidate.heading')); ?></h1>
            <div class="page-actions">
                <a id="backLink" href="#" class="btn btn-secondary">&larr; <?php echo htmlspecialchars(t('contracts.rfp.suppliers.overview')); ?></a>
                <a id="coverageLink" href="#" class="btn btn-secondary" style="display:none;"><?php echo htmlspecialchars(t('contracts.rfp.coverage.heading')); ?></a>
                <button id="addBtn" class="btn btn-secondary" onclick="openAddModal()" style="display:none;">+ <?php echo htmlspecialchars(t('contracts.rfp.consolidate.add_custom')); ?></button>
                <button id="runBtn" class="btn btn-primary" onclick="runConsolidation()"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.run')); ?></button>
                <button id="lockBtn" class="btn btn-primary" onclick="toggleLock()" style="display:none;"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.lock_for_generation')); ?></button>
            </div>
        </div>

        <div id="lockedBanner" style="display:none;background:var(--success-bg,#ecfdf5);border:1px solid #a7f3d0;color:var(--success-text,#065f46);padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;">
            <?php echo t('contracts.rfp.consolidate.locked_banner'); ?>
        </div>

        <div id="streamModal" class="modal-backdrop" style="display:none;">
            <div class="stream-modal">
                <div class="stream-modal-header">
                    <div id="streamSpinner" class="spinner"></div>
                    <h3 id="streamTitle"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.running')); ?></h3>
                </div>
                <div id="streamPhase" class="stream-phase"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.starting')); ?></div>
                <div class="progress-tracker">
                    <div id="ptaskCats" class="ptask">
                        <div class="pico">1</div>
                        <div class="plabel"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.task_categorising')); ?></div>
                        <div class="pcount" id="pcountCats">—</div>
                    </div>
                    <div id="ptaskCons" class="ptask">
                        <div class="pico">2</div>
                        <div class="plabel"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.task_consolidating')); ?></div>
                        <div class="pcount" id="pcountCons">—</div>
                    </div>
                    <div id="ptaskConf" class="ptask">
                        <div class="pico">3</div>
                        <div class="plabel"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.task_conflicts')); ?></div>
                        <div class="pcount" id="pcountConf">—</div>
                    </div>
                </div>
                <div id="streamBody" class="stream-body"></div>
                <div class="stream-meta">
                    <span class="meta-item"><?php echo htmlspecialchars(t('contracts.rfp.document.tokens_in')); ?>: <strong id="streamTokensIn">0</strong></span>
                    <span class="meta-item"><?php echo htmlspecialchars(t('contracts.rfp.document.tokens_out')); ?>: <strong id="streamTokensOut">0</strong></span>
                    <span class="meta-item"><?php echo htmlspecialchars(t('contracts.rfp.document.cached')); ?>: <strong id="streamCacheRead">0</strong></span>
                    <span class="meta-item"><?php echo htmlspecialchars(t('contracts.rfp.document.elapsed')); ?>: <strong id="streamElapsed">0s</strong></span>
                </div>
                <div class="stream-modal-footer">
                    <button id="streamCloseBtn" class="btn btn-secondary" onclick="closeStreamModal()" disabled><?php echo htmlspecialchars(t('common.close')); ?></button>
                </div>
            </div>
        </div>

        <div class="stats-strip" id="statsStrip" style="display:none;">
            <div class="stat-card cats">
                <div class="stat-value" id="statCats">0</div>
                <div class="stat-label"><?php echo htmlspecialchars(t('contracts.rfp.view.stat_categories')); ?></div>
            </div>
            <div class="stat-card cons">
                <div class="stat-value" id="statCons">0</div>
                <div class="stat-label"><?php echo htmlspecialchars(t('contracts.rfp.view.stat_consolidated')); ?></div>
            </div>
            <div class="stat-card conf">
                <div class="stat-value" id="statConf">0</div>
                <div class="stat-label"><?php echo htmlspecialchars(t('contracts.rfp.view.stat_open_conflicts')); ?></div>
            </div>
            <div class="stat-card linked">
                <div class="stat-value" id="statLinked">0</div>
                <div class="stat-label"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.stat_linked')); ?></div>
            </div>
        </div>

        <div id="loadingEl" class="loading"><?php echo htmlspecialchars(t('common.loading')); ?></div>
        <div id="contentEl" style="display:none;"></div>
        <div id="errorEl" class="error-state" style="display:none;"></div>
    </div>

    <!-- Merge selection bar -->
    <div id="mergeBar" class="merge-bar">
        <span class="merge-count" id="mergeCount">0 selected</span>
        <span class="spacer"></span>
        <button class="btn btn-secondary" onclick="clearSelection()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
        <button class="btn btn-primary" onclick="openMergeModal()"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.merge')); ?></button>
    </div>

    <!-- Edit / Add modal (shared form, mode flag controls behaviour) -->
    <div id="editModal" class="modal-backdrop" style="display:none;">
        <div class="edit-modal">
            <div class="edit-modal-header">
                <span id="editModalTitle"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.edit_requirement')); ?></span>
            </div>
            <div class="edit-modal-body">
                <div class="form-row">
                    <label for="editText"><?php echo htmlspecialchars(t('contracts.rfp.extracted.requirement_text')); ?></label>
                    <textarea id="editText" rows="4"></textarea>
                </div>
                <div class="form-row-grid">
                    <div class="form-row">
                        <label for="editType"><?php echo htmlspecialchars(t('contracts.rfp.extracted.type')); ?></label>
                        <select id="editType">
                            <option value="requirement"><?php echo htmlspecialchars(t('contracts.rfp.extracted.type_requirement')); ?></option>
                            <option value="pain_point"><?php echo htmlspecialchars(t('contracts.rfp.extracted.type_pain_point')); ?></option>
                            <option value="challenge"><?php echo htmlspecialchars(t('contracts.rfp.extracted.type_challenge')); ?></option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="editPriority"><?php echo htmlspecialchars(t('contracts.detail.field_priority')); ?></label>
                        <select id="editPriority">
                            <option value="critical"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.prio_critical')); ?></option>
                            <option value="high"><?php echo htmlspecialchars(t('contracts.priority.high')); ?></option>
                            <option value="medium"><?php echo htmlspecialchars(t('contracts.priority.medium')); ?></option>
                            <option value="low"><?php echo htmlspecialchars(t('contracts.priority.low')); ?></option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="editCategory"><?php echo htmlspecialchars(t('contracts.rfp.compare.col_category')); ?></label>
                        <select id="editCategory"></select>
                    </div>
                </div>
                <div class="form-row">
                    <label for="editRationale"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.rationale_optional')); ?></label>
                    <textarea id="editRationale" rows="2" placeholder="<?php echo htmlspecialchars(t('contracts.rfp.consolidate.rationale_ph')); ?>"></textarea>
                </div>
            </div>
            <div class="edit-modal-footer">
                <button class="btn btn-secondary" onclick="closeEditModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" id="editSaveBtn" onclick="saveEdit()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Split modal -->
    <div id="splitModal" class="modal-backdrop" style="display:none;">
        <div class="edit-modal wide">
            <div class="edit-modal-header">
                <span><?php echo htmlspecialchars(t('contracts.rfp.consolidate.split_requirement')); ?></span>
            </div>
            <div class="edit-modal-body">
                <div class="form-row">
                    <label><?php echo htmlspecialchars(t('contracts.rfp.consolidate.split_original')); ?></label>
                    <div id="splitOriginalText" style="padding:10px 12px;background:var(--surface-hover,#f3f4f6);border-radius:6px;font-size:13px;color:var(--text-muted,#555);line-height:1.5;"></div>
                </div>

                <div class="form-row">
                    <label><?php echo htmlspecialchars(t('contracts.rfp.consolidate.split_assign')); ?></label>
                    <div class="form-help"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.split_assign_help')); ?></div>
                    <div id="splitSourceList" class="source-pick-list" style="margin-top:6px;"></div>
                </div>

                <div class="form-row">
                    <label><?php echo htmlspecialchars(t('contracts.rfp.consolidate.split_new_rows')); ?></label>
                    <div id="splitRowsContainer"></div>
                    <button class="btn btn-secondary" onclick="addSplitRow()" style="align-self:flex-start;">+ <?php echo htmlspecialchars(t('contracts.rfp.consolidate.add_another_row')); ?></button>
                </div>
            </div>
            <div class="edit-modal-footer">
                <button class="btn btn-secondary" onclick="closeSplitModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" onclick="saveSplit()"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.split')); ?></button>
            </div>
        </div>
    </div>

    <!-- Resolve conflict modal (notes only — actual resolution flag set by caller) -->
    <div id="resolveModal" class="modal-backdrop" style="display:none;">
        <div class="edit-modal">
            <div class="edit-modal-header">
                <span id="resolveModalTitle"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.resolve_conflict')); ?></span>
            </div>
            <div class="edit-modal-body">
                <div id="resolveContext" style="margin-bottom:14px;font-size:13px;color:var(--text-muted,#555);"></div>
                <div class="form-row">
                    <label for="resolveNotes"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.resolve_notes')); ?></label>
                    <textarea id="resolveNotes" rows="3" placeholder="<?php echo htmlspecialchars(t('contracts.rfp.consolidate.resolve_notes_ph')); ?>"></textarea>
                </div>
            </div>
            <div class="edit-modal-footer">
                <button class="btn btn-secondary" onclick="closeResolveModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" id="resolveConfirmBtn" onclick="saveResolve()"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.resolve')); ?></button>
            </div>
        </div>
    </div>

    <!-- Merge modal -->
    <div id="mergeModal" class="modal-backdrop" style="display:none;">
        <div class="edit-modal">
            <div class="edit-modal-header">
                <span><?php echo htmlspecialchars(t('contracts.rfp.consolidate.merge_selected')); ?></span>
            </div>
            <div class="edit-modal-body">
                <div id="mergeSummary" class="merge-summary"></div>

                <div class="form-row">
                    <label for="mergeText"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.merged_text')); ?></label>
                    <textarea id="mergeText" rows="4"></textarea>
                </div>
                <div class="form-row-grid">
                    <div class="form-row">
                        <label for="mergeType"><?php echo htmlspecialchars(t('contracts.rfp.extracted.type')); ?></label>
                        <select id="mergeType">
                            <option value="requirement"><?php echo htmlspecialchars(t('contracts.rfp.extracted.type_requirement')); ?></option>
                            <option value="pain_point"><?php echo htmlspecialchars(t('contracts.rfp.extracted.type_pain_point')); ?></option>
                            <option value="challenge"><?php echo htmlspecialchars(t('contracts.rfp.extracted.type_challenge')); ?></option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="mergePriority"><?php echo htmlspecialchars(t('contracts.detail.field_priority')); ?></label>
                        <select id="mergePriority">
                            <option value="critical"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.prio_critical')); ?></option>
                            <option value="high"><?php echo htmlspecialchars(t('contracts.priority.high')); ?></option>
                            <option value="medium"><?php echo htmlspecialchars(t('contracts.priority.medium')); ?></option>
                            <option value="low"><?php echo htmlspecialchars(t('contracts.priority.low')); ?></option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="mergeCategory"><?php echo htmlspecialchars(t('contracts.rfp.compare.col_category')); ?></label>
                        <select id="mergeCategory"></select>
                    </div>
                </div>
                <div class="form-row">
                    <label for="mergeRationale"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.rationale_optional')); ?></label>
                    <textarea id="mergeRationale" rows="2"></textarea>
                </div>
            </div>
            <div class="edit-modal-footer">
                <button class="btn btn-secondary" onclick="closeMergeModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" onclick="saveMerge()"><?php echo htmlspecialchars(t('contracts.rfp.consolidate.merge')); ?></button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let rfpName = '';
        // Cached page data so action handlers can find rows / categories
        // without re-fetching. Refreshed by loadAll() after every mutation.
        let pageData = { categories: [], consolidated: [], conflicts: [] };
        // Set of selected consolidated IDs for merge mode
        const selectedIds = new Set();

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
                const [rfpRes, conRes] = await Promise.all([
                    fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId)).then(r => r.json()),
                    fetch(API_BASE + 'get_consolidated.php?rfp_id=' + encodeURIComponent(rfpId)).then(r => r.json())
                ]);
                if (!rfpRes.success) throw new Error(rfpRes.error || window.t('contracts.rfp.suppliers.load_rfp_failed'));
                if (!conRes.success) throw new Error(conRes.error || window.t('contracts.rfp.consolidate.load_failed'));
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
            pageData = data;

            // Lock state is true if we have rows AND every row is_locked.
            // Empty RFPs are always treated as unlocked.
            const lockState = data.consolidated.length > 0
                && data.consolidated.every(c => c.is_locked);
            applyLockState(lockState, data.consolidated.length);

            // Selection survives a refresh only for rows that still exist,
            // and cleared entirely when locked (no merging while locked).
            for (const id of [...selectedIds]) {
                if (!data.consolidated.find(c => c.id === id)) selectedIds.delete(id);
            }
            if (lockState) selectedIds.clear();
            updateMergeBar();

            // Show "+ Add custom" once we have at least one category to put a custom req into,
            // and we're not locked.
            document.getElementById('addBtn').style.display =
                (data.categories.length > 0 && !lockState) ? '' : 'none';

            // Coverage link visible once we have any consolidated data.
            const coverageLink = document.getElementById('coverageLink');
            if (data.consolidated.length > 0) {
                coverageLink.style.display = '';
                coverageLink.href = 'coverage.php?id=' + encodeURIComponent(rfpId);
            } else {
                coverageLink.style.display = 'none';
            }

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
            runBtn.textContent = data.consolidated.length > 0 ? window.t('contracts.rfp.consolidate.rerun') : window.t('contracts.rfp.consolidate.run');

            const contentEl = document.getElementById('contentEl');
            contentEl.style.display = 'block';

            if (data.consolidated.length === 0) {
                contentEl.innerHTML = `
                    <div class="empty-card">
                        <p><strong>${escapeHtml(window.t('contracts.rfp.consolidate.empty_title'))}</strong></p>
                        <p>${window.t('contracts.rfp.consolidate.empty_body')}</p>
                        <p class="hint">${escapeHtml(window.t('contracts.rfp.consolidate.empty_hint'))}</p>
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
                    { id: 0, name: window.t('contracts.rfp.compare.uncategorised'), description: window.t('contracts.rfp.consolidate.orphan_desc') },
                    orphans
                ));
            }

            const consById = new Map(data.consolidated.map(c => [c.id, c]));
            const conflictsHtml = data.conflicts.length > 0
                ? renderConflicts(data.conflicts, consById)
                : '';

            contentEl.innerHTML = catBlocks.join('') + conflictsHtml;

            // Re-apply selection state after the DOM was re-rendered
            selectedIds.forEach(id => {
                const cb = document.querySelector('.req-select[data-id="' + id + '"]');
                const row = document.getElementById('row-' + id);
                if (cb)  cb.checked = true;
                if (row) row.classList.add('selected');
            });
        }

        function renderCategoryBlock(cat, reqs) {
            return `
                <div class="category-card">
                    <div class="category-header">
                        <h2>
                            ${escapeHtml(cat.name)}
                            <span class="req-count">${escapeHtml(reqs.length === 1 ? window.t('contracts.rfp.document.req_count_one', { n: reqs.length }) : window.t('contracts.rfp.document.req_count_other', { n: reqs.length }))}</span>
                        </h2>
                        ${cat.description ? `<div class="category-desc">${escapeHtml(cat.description)}</div>` : ''}
                    </div>
                    ${reqs.map(r => renderReqRow(r)).join('')}
                </div>
            `;
        }

        function renderReqRow(r) {
            const sources  = r.sources || [];
            const canSplit = sources.length >= 2;
            const locked   = !!r.is_locked;
            return `
                <div class="req-row" data-id="${r.id}" id="row-${r.id}">
                    <div class="req-row-top">
                        ${locked ? '' : `<input type="checkbox" class="req-select" data-id="${r.id}" onchange="onSelectRow(${r.id}, this.checked)">`}
                        <span class="pill type-${escapeHtml(r.requirement_type)}">${escapeHtml(reqTypeLabel(r.requirement_type))}</span>
                        <span class="pill prio-${escapeHtml(r.priority)}">${escapeHtml(priorityLabel(r.priority))}</span>
                        <div class="req-row-text">
                            ${escapeHtml(r.requirement_text)}
                            ${r.ai_rationale ? `<div class="req-row-rationale">${escapeHtml(r.ai_rationale)}</div>` : ''}
                        </div>
                        ${locked ? '' : `
                            <div class="req-row-actions">
                                <button class="icon-btn" onclick="openEditModal(${r.id})">${escapeHtml(window.t('common.edit'))}</button>
                                ${canSplit ? `<button class="icon-btn" onclick="openSplitModal(${r.id})">${escapeHtml(window.t('contracts.rfp.consolidate.split'))}</button>` : ''}
                                <button class="icon-btn danger" onclick="deleteRow(${r.id})">${escapeHtml(window.t('common.delete'))}</button>
                            </div>
                        `}
                    </div>
                    <div class="source-toggle">
                        <button class="btn-link" onclick="toggleSources(${r.id})">
                            <span id="srcLabel-${r.id}">${escapeHtml(window.t('contracts.rfp.consolidate.show_sources', { n: sources.length }))}</span>
                        </button>
                    </div>
                    <div id="srcList-${r.id}" class="source-list">
                        ${sources.map(s => `
                            <div class="source-item">
                                <span class="source-dept" style="${s.department_colour ? 'background:' + escapeHtml(s.department_colour) + '20; color:' + escapeHtml(s.department_colour) : ''}">
                                    ${escapeHtml(s.department_name || window.t('contracts.rfp.documents.unassigned'))}
                                </span>
                                <span style="color:var(--text-muted,#555);">${escapeHtml(s.requirement_text)}</span>
                                ${s.source_quote ? `<div class="source-quote">"${escapeHtml(s.source_quote)}"</div>` : ''}
                                <div class="source-doc">${escapeHtml(s.document_filename || '')} · ${escapeHtml(window.t('contracts.rfp.consolidate.extracted_n', { n: s.extracted_id }))}</div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        function renderConflicts(conflicts, consById) {
            const open     = conflicts.filter(c => c.resolution === 'open');
            const resolved = conflicts.filter(c => c.resolution !== 'open');

            return `
                <div class="conflicts-card">
                    <div class="conflicts-header">
                        <h2>${escapeHtml(window.t('contracts.rfp.consolidate.flagged_conflicts_prefix') + ' (' + window.t('contracts.rfp.consolidate.n_open', { n: open.length }) + (resolved.length ? ' · ' + window.t('contracts.rfp.consolidate.n_resolved', { n: resolved.length }) : '') + ')')}</h2>
                    </div>
                    ${open.map(c => renderConflictRow(c, false)).join('')}
                    ${resolved.length ? `
                        <div class="resolved-section">
                            <div class="resolved-section-label">${escapeHtml(window.t('contracts.rfp.consolidate.resolved'))}</div>
                            ${resolved.map(c => renderConflictRow(c, true)).join('')}
                        </div>
                    ` : ''}
                </div>
            `;
        }

        function renderConflictRow(c, isResolved) {
            const aMissing = !c.a_text;
            const bMissing = !c.b_text;
            const aText = aMissing ? '<em>' + escapeHtml(window.t('contracts.rfp.consolidate.deleted')) + '</em>' : escapeHtml(c.a_text);
            const bText = bMissing ? '<em>' + escapeHtml(window.t('contracts.rfp.consolidate.deleted')) + '</em>' : escapeHtml(c.b_text);

            const resolutionBlock = isResolved ? `
                <div class="conflict-resolution resolved">
                    <span class="resolution-badge ${escapeHtml(c.resolution)}">${escapeHtml(resolutionLabel(c.resolution))}</span>
                    ${c.resolved_by_name ? escapeHtml(window.t('contracts.rfp.consolidate.by_prefix')) + ' ' + escapeHtml(c.resolved_by_name) : ''}
                    ${c.resolved_datetime ? ' · ' + escapeHtml(formatDateTime(c.resolved_datetime)) : ''}
                </div>
                ${c.resolution_notes ? `<div class="conflict-resolution-notes">${escapeHtml(c.resolution_notes)}</div>` : ''}
            ` : '';

            // While locked, no resolution actions — analyst must unlock first.
            const locked = pageData.consolidated.length > 0 && pageData.consolidated.every(r => r.is_locked);
            let actions = '';
            if (!locked) {
                actions = isResolved
                    ? `<div class="conflict-actions">
                           <button class="btn-resolve reopen" onclick="reopenConflict(${c.id})">${escapeHtml(window.t('contracts.rfp.consolidate.reopen'))}</button>
                       </div>`
                    : `<div class="conflict-actions">
                           ${aMissing || bMissing ? '' : `
                               <button class="btn-resolve choose-a" onclick="openResolveModal(${c.id}, 'chose_a')">${escapeHtml(window.t('contracts.rfp.consolidate.choose_a'))}</button>
                               <button class="btn-resolve choose-b" onclick="openResolveModal(${c.id}, 'chose_b')">${escapeHtml(window.t('contracts.rfp.consolidate.choose_b'))}</button>
                               <button class="btn-resolve merge"    onclick="mergeFromConflict(${c.id}, ${c.consolidated_id_a}, ${c.consolidated_id_b})">${escapeHtml(window.t('contracts.rfp.consolidate.merge_into_one'))}</button>
                           `}
                           <button class="btn-resolve dismiss" onclick="openResolveModal(${c.id}, 'dismissed')">${escapeHtml(window.t('contracts.rfp.consolidate.dismiss'))}</button>
                       </div>`;
            }

            return `
                <div class="conflict-row ${isResolved ? 'resolved-row' : ''}" data-conflict-id="${c.id}">
                    <div class="conflict-pair">
                        <div class="conflict-side">
                            <div class="side-label">${escapeHtml(window.t('contracts.rfp.consolidate.side_a'))} · ${escapeHtml(c.a_priority || '')}</div>
                            <div class="side-text">${aText}</div>
                        </div>
                        <div class="conflict-side">
                            <div class="side-label">${escapeHtml(window.t('contracts.rfp.consolidate.side_b'))} · ${escapeHtml(c.b_priority || '')}</div>
                            <div class="side-text">${bText}</div>
                        </div>
                    </div>
                    ${c.ai_explanation ? `<div class="conflict-explanation"><strong>${escapeHtml(window.t('contracts.rfp.consolidate.why_conflicts'))}</strong> ${escapeHtml(c.ai_explanation)}</div>` : ''}
                    ${resolutionBlock}
                    ${actions}
                </div>
            `;
        }

        function formatDateTime(s) {
            if (!s) return '';
            const d = parseUTCDate(s);
            if (isNaN(d)) return s;
            return d.toLocaleString('en-GB', tzOpts({ day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }));
        }

        function toggleSources(consId) {
            const list = document.getElementById('srcList-' + consId);
            const label = document.getElementById('srcLabel-' + consId);
            const isOpen = list.classList.toggle('open');
            const count = list.querySelectorAll('.source-item').length;
            label.textContent = isOpen ? window.t('contracts.rfp.consolidate.hide_sources', { n: count }) : window.t('contracts.rfp.consolidate.show_sources', { n: count });
        }

        let activeStream = null;
        let streamStart = 0;
        let elapsedTimer = null;
        let streamAccumulated = '';

        async function runConsolidation() {
            if (!(await showConfirm({ title: window.t('contracts.rfp.consolidate.run_ai_title'), message: window.t('contracts.rfp.consolidate.run_ai_msg'), okLabel: window.t('contracts.rfp.consolidate.run_label'), okClass: 'primary' }))) return;
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
                let msg = window.t('contracts.rfp.document.connection_error');
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
            phase.textContent = window.t('contracts.rfp.consolidate.starting');
            phase.className = 'stream-phase';
            document.getElementById('streamSpinner').classList.remove('done');
            document.getElementById('streamCloseBtn').disabled = true;
            document.getElementById('streamTitle').textContent = window.t('contracts.rfp.consolidate.running');
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
                let label;
                if (key === 'Cats') label = count === 1 ? window.t('contracts.rfp.consolidate.count_category_one', { n: count }) : window.t('contracts.rfp.consolidate.count_category_other', { n: count });
                else if (key === 'Cons') label = count === 1 ? window.t('contracts.rfp.consolidate.count_req_one', { n: count }) : window.t('contracts.rfp.consolidate.count_req_other', { n: count });
                else label = count === 1 ? window.t('contracts.rfp.consolidate.count_conflict_one', { n: count }) : window.t('contracts.rfp.consolidate.count_conflict_other', { n: count });
                cnt.textContent = label;
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
            document.getElementById('streamTitle').textContent = window.t('contracts.rfp.consolidate.complete');
            // Mark all three tasks done with their final committed counts
            // (these come from the server, which match the actual DB state
            // — slightly more authoritative than the live stream parsing).
            setPTask('Cats', 'done', data.counts.categories);
            setPTask('Cons', 'done', data.counts.consolidated);
            setPTask('Conf', 'done', data.counts.conflicts);
            setPhase(
                window.t('contracts.rfp.consolidate.summary_categories', { n: data.counts.categories }) + ' · ' +
                window.t('contracts.rfp.consolidate.summary_consolidated', { n: data.counts.consolidated }) + ' · ' +
                window.t('contracts.rfp.consolidate.summary_conflicts', { n: data.counts.conflicts }) + ' · ' +
                (data.counts.orphan_extracted > 0 ? window.t('contracts.rfp.consolidate.summary_orphans', { n: data.counts.orphan_extracted }) + ' · ' : '') +
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
            document.getElementById('streamTitle').textContent = window.t('contracts.rfp.consolidate.failed');
            setPhase(window.t('contracts.rfp.document.error_prefix') + ' ' + msg, 'error');
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

        // ─── Lock state ──────────────────────────────────────────────

        function applyLockState(locked, rowCount) {
            const banner  = document.getElementById('lockedBanner');
            const lockBtn = document.getElementById('lockBtn');
            const runBtn  = document.getElementById('runBtn');
            banner.style.display  = locked ? '' : 'none';
            // Lock button only after we have rows to lock.
            lockBtn.style.display = rowCount > 0 ? '' : 'none';
            lockBtn.textContent   = locked ? window.t('contracts.rfp.consolidate.unlock') : window.t('contracts.rfp.consolidate.lock_for_generation');
            lockBtn.classList.toggle('btn-secondary', locked);
            lockBtn.classList.toggle('btn-primary',   !locked);
            // Re-running consolidation while locked would wipe everything,
            // including the lock — surface intent by hiding the run button
            // until the user explicitly unlocks.
            runBtn.style.display = locked ? 'none' : '';
        }

        async function toggleLock() {
            const isLocked = pageData.consolidated.length > 0 && pageData.consolidated.every(r => r.is_locked);
            const wantLock = !isLocked;
            const msg = wantLock
                ? window.t('contracts.rfp.consolidate.lock_confirm', { n: pageData.consolidated.length })
                : window.t('contracts.rfp.consolidate.unlock_confirm');
            if (!(await showConfirm({ title: window.t('contracts.rfp.document.confirm'), message: msg, okLabel: window.t('common.ok'), okClass: 'primary' }))) return;

            const btn = document.getElementById('lockBtn');
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'lock_consolidation.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ rfp_id: parseInt(rfpId, 10), lock: wantLock })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.consolidate.lock_failed_short'));
                loadAll();
            } catch (err) {
                showToast(window.t('common.failed') + ' ' + err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        // ─── Selection / merge bar ───────────────────────────────────

        function onSelectRow(id, checked) {
            if (checked) selectedIds.add(id); else selectedIds.delete(id);
            const row = document.getElementById('row-' + id);
            if (row) row.classList.toggle('selected', checked);
            updateMergeBar();
        }

        function clearSelection() {
            selectedIds.clear();
            document.querySelectorAll('.req-select').forEach(cb => { cb.checked = false; });
            document.querySelectorAll('.req-row.selected').forEach(r => r.classList.remove('selected'));
            updateMergeBar();
        }

        function updateMergeBar() {
            const bar = document.getElementById('mergeBar');
            const count = selectedIds.size;
            if (count >= 2) {
                bar.classList.add('active');
                document.getElementById('mergeCount').textContent = window.t('contracts.rfp.consolidate.selected_for_merge', { n: count });
            } else {
                bar.classList.remove('active');
            }
        }

        // ─── Helpers ─────────────────────────────────────────────────

        function findCons(id) {
            return pageData.consolidated.find(c => c.id === id);
        }

        function populateCategoryDropdown(selectEl, selectedId) {
            const opts = ['<option value="">' + escapeHtml(window.t('contracts.rfp.consolidate.uncategorised_option')) + '</option>']
                .concat(pageData.categories.map(c =>
                    '<option value="' + c.id + '"' + (c.id === selectedId ? ' selected' : '') + '>' +
                    escapeHtml(c.name) + '</option>'
                ));
            selectEl.innerHTML = opts.join('');
        }

        // ─── Edit modal (also used for "Add custom") ─────────────────

        let editMode = 'edit';   // 'edit' or 'add'
        let editingId = null;

        function openEditModal(id) {
            const r = findCons(id);
            if (!r) return;
            editMode = 'edit';
            editingId = id;
            document.getElementById('editModalTitle').textContent = window.t('contracts.rfp.consolidate.edit_requirement');
            document.getElementById('editText').value      = r.requirement_text || '';
            document.getElementById('editType').value      = r.requirement_type || 'requirement';
            document.getElementById('editPriority').value  = r.priority || 'medium';
            populateCategoryDropdown(document.getElementById('editCategory'), r.category_id);
            document.getElementById('editRationale').value = r.ai_rationale || '';
            document.getElementById('editModal').style.display = 'flex';
        }

        function openAddModal() {
            editMode = 'add';
            editingId = null;
            document.getElementById('editModalTitle').textContent = window.t('contracts.rfp.consolidate.add_custom_requirement');
            document.getElementById('editText').value      = '';
            document.getElementById('editType').value      = 'requirement';
            document.getElementById('editPriority').value  = 'medium';
            populateCategoryDropdown(document.getElementById('editCategory'), null);
            document.getElementById('editRationale').value = '';
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        async function saveEdit() {
            const payload = {
                requirement_text: document.getElementById('editText').value.trim(),
                requirement_type: document.getElementById('editType').value,
                priority:         document.getElementById('editPriority').value,
                category_id:      document.getElementById('editCategory').value || null,
                ai_rationale:     document.getElementById('editRationale').value.trim()
            };
            if (!payload.requirement_text) { showToast(window.t('contracts.rfp.consolidate.text_required'), 'error'); return; }

            const btn = document.getElementById('editSaveBtn');
            btn.disabled = true;
            try {
                let url, body;
                if (editMode === 'edit') {
                    url  = API_BASE + 'update_consolidated.php';
                    body = JSON.stringify({ id: editingId, ...payload });
                } else {
                    url  = API_BASE + 'add_consolidated.php';
                    body = JSON.stringify({ rfp_id: parseInt(rfpId, 10), ...payload });
                }
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.suppliers.save_failed_short'));
                closeEditModal();
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.list.save_failed') + ' ' + err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        // ─── Delete ──────────────────────────────────────────────────

        async function deleteRow(id) {
            const r = findCons(id);
            if (!r) return;
            if (!(await showConfirm({ title: window.t('common.delete'), message: window.t('contracts.rfp.consolidate.delete_confirm', { preview: r.requirement_text.slice(0, 120) }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
            try {
                const res = await fetch(API_BASE + 'delete_consolidated.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.list.delete_failed_short'));
                selectedIds.delete(id);
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.list.delete_failed') + ' ' + err.message, 'error');
            }
        }

        // ─── Split modal ─────────────────────────────────────────────

        let splittingId = null;
        let splitRowCount = 0;

        function openSplitModal(id) {
            const r = findCons(id);
            if (!r) return;
            splittingId = id;
            splitRowCount = 0;

            document.getElementById('splitOriginalText').textContent = r.requirement_text;

            // Source pickers — each source gets a dropdown to pick which new row it belongs to.
            const srcList = document.getElementById('splitSourceList');
            const sources = r.sources || [];
            srcList.innerHTML = sources.map(s => `
                <div class="source-pick-row" data-extracted-id="${s.extracted_id}">
                    <div class="source-pick-info">
                        <div class="source-text">${escapeHtml(s.requirement_text)}</div>
                        <div class="source-meta">${escapeHtml(s.department_name || window.t('contracts.rfp.documents.unassigned'))} · ${escapeHtml(s.document_filename || '')} · ${escapeHtml(window.t('contracts.rfp.consolidate.extracted_n', { n: s.extracted_id }))}</div>
                    </div>
                    <select data-source-target>
                        <option value="">${escapeHtml(window.t('contracts.rfp.consolidate.drop_option'))}</option>
                    </select>
                </div>
            `).join('');

            // Reset rows container, start with two empty rows (most splits
            // are into two; user can add more).
            const container = document.getElementById('splitRowsContainer');
            container.innerHTML = '';
            addSplitRow();
            addSplitRow();
            // Default each source to "row 1" — analyst usually keeps
            // most sources together and reassigns a few to row 2.
            document.querySelectorAll('#splitSourceList select[data-source-target]').forEach(sel => {
                sel.value = '1';
            });

            document.getElementById('splitModal').style.display = 'flex';
        }

        function closeSplitModal() {
            document.getElementById('splitModal').style.display = 'none';
        }

        function addSplitRow() {
            splitRowCount++;
            const num = splitRowCount;
            const container = document.getElementById('splitRowsContainer');
            const div = document.createElement('div');
            div.className = 'split-row-card';
            div.dataset.splitRow = num;
            div.innerHTML = `
                <span class="split-row-num">${escapeHtml(window.t('contracts.rfp.consolidate.row_n', { n: num }))}</span>
                <button class="split-row-remove" type="button" title="${escapeHtml(window.t('contracts.rfp.consolidate.remove_row'))}" onclick="removeSplitRow(${num})">&times;</button>
                <div class="form-row">
                    <textarea data-split-text placeholder="${escapeHtml(window.t('contracts.rfp.extracted.requirement_text'))}"></textarea>
                </div>
                <div class="form-row-grid">
                    <div class="form-row">
                        <label>${escapeHtml(window.t('contracts.rfp.extracted.type'))}</label>
                        <select data-split-type>
                            <option value="requirement">${escapeHtml(window.t('contracts.rfp.extracted.type_requirement'))}</option>
                            <option value="pain_point">${escapeHtml(window.t('contracts.rfp.extracted.type_pain_point'))}</option>
                            <option value="challenge">${escapeHtml(window.t('contracts.rfp.extracted.type_challenge'))}</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>${escapeHtml(window.t('contracts.detail.field_priority'))}</label>
                        <select data-split-priority>
                            <option value="critical">${escapeHtml(window.t('contracts.rfp.consolidate.prio_critical'))}</option>
                            <option value="high">${escapeHtml(window.t('contracts.priority.high'))}</option>
                            <option value="medium" selected>${escapeHtml(window.t('contracts.priority.medium'))}</option>
                            <option value="low">${escapeHtml(window.t('contracts.priority.low'))}</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>${escapeHtml(window.t('contracts.rfp.compare.col_category'))}</label>
                        <select data-split-category></select>
                    </div>
                </div>
            `;
            container.appendChild(div);
            populateCategoryDropdown(div.querySelector('select[data-split-category]'), null);
            // Pre-fill type from the original row to save clicks
            const orig = findCons(splittingId);
            if (orig) {
                div.querySelector('select[data-split-type]').value     = orig.requirement_type || 'requirement';
                div.querySelector('select[data-split-priority]').value = orig.priority         || 'medium';
                const catSel = div.querySelector('select[data-split-category]');
                if (orig.category_id) catSel.value = String(orig.category_id);
            }
            // Refresh source-target dropdowns to include this row
            refreshSplitSourceOptions();
        }

        function removeSplitRow(num) {
            const card = document.querySelector('.split-row-card[data-split-row="' + num + '"]');
            if (!card) return;
            // Don't allow fewer than 2 rows
            if (document.querySelectorAll('.split-row-card').length <= 2) {
                showToast(window.t('contracts.rfp.consolidate.split_min_rows'), 'error');
                return;
            }
            card.remove();
            // Renumber visible cards
            renumberSplitRows();
            refreshSplitSourceOptions();
        }

        function renumberSplitRows() {
            const cards = document.querySelectorAll('.split-row-card');
            cards.forEach((card, i) => {
                const num = i + 1;
                card.dataset.splitRow = num;
                card.querySelector('.split-row-num').textContent = window.t('contracts.rfp.consolidate.row_n', { n: num });
                card.querySelector('.split-row-remove').setAttribute('onclick', 'removeSplitRow(' + num + ')');
            });
            splitRowCount = cards.length;
        }

        function refreshSplitSourceOptions() {
            const cards = document.querySelectorAll('.split-row-card');
            const opts = ['<option value="">' + escapeHtml(window.t('contracts.rfp.consolidate.drop_option')) + '</option>']
                .concat(Array.from(cards).map((_, i) => '<option value="' + (i + 1) + '">' + escapeHtml(window.t('contracts.rfp.consolidate.row_n', { n: (i + 1) })) + '</option>'));
            document.querySelectorAll('#splitSourceList select[data-source-target]').forEach(sel => {
                const prev = sel.value;
                sel.innerHTML = opts.join('');
                if (prev !== '' && parseInt(prev, 10) <= cards.length) sel.value = prev;
            });
        }

        async function saveSplit() {
            const cards = document.querySelectorAll('.split-row-card');
            const newRows = Array.from(cards).map((card, i) => {
                const num = i + 1;
                // Source IDs assigned to this row
                const sources = [];
                document.querySelectorAll('#splitSourceList .source-pick-row').forEach(row => {
                    const sel = row.querySelector('select[data-source-target]');
                    if (sel.value === String(num)) {
                        sources.push(parseInt(row.dataset.extractedId, 10));
                    }
                });
                return {
                    requirement_text:    card.querySelector('textarea[data-split-text]').value.trim(),
                    requirement_type:    card.querySelector('select[data-split-type]').value,
                    priority:            card.querySelector('select[data-split-priority]').value,
                    category_id:         card.querySelector('select[data-split-category]').value || null,
                    source_extracted_ids: sources
                };
            });

            if (newRows.some(r => !r.requirement_text)) {
                showToast(window.t('contracts.rfp.consolidate.every_row_text'), 'error');
                return;
            }

            try {
                const res = await fetch(API_BASE + 'split_consolidated.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id: splittingId, new_rows: newRows })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.consolidate.split_failed_short'));
                closeSplitModal();
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.consolidate.split_failed') + ' ' + err.message, 'error');
            }
        }

        // ─── Merge modal ─────────────────────────────────────────────

        function openMergeModal() {
            const ids = Array.from(selectedIds);
            if (ids.length < 2) {
                showToast(window.t('contracts.rfp.consolidate.select_two'), 'error');
                return;
            }
            const rows = ids.map(findCons).filter(Boolean);

            // Pre-fill the merged form from the first row, summarise the
            // others in a help block so the analyst can see what they're
            // merging.
            const first = rows[0];
            document.getElementById('mergeText').value      = first.requirement_text;
            document.getElementById('mergeType').value      = first.requirement_type;
            document.getElementById('mergePriority').value  = first.priority;
            populateCategoryDropdown(document.getElementById('mergeCategory'), first.category_id);
            document.getElementById('mergeRationale').value = '';

            document.getElementById('mergeSummary').innerHTML =
                '<strong>' + escapeHtml(window.t('contracts.rfp.consolidate.merging_n', { n: rows.length })) + '</strong> ' + escapeHtml(window.t('contracts.rfp.consolidate.merging_help')) +
                '<ul>' + rows.map(r => '<li>' + escapeHtml(r.requirement_text.slice(0, 140)) + (r.requirement_text.length > 140 ? '…' : '') + '</li>').join('') + '</ul>';

            document.getElementById('mergeModal').style.display = 'flex';
        }

        function closeMergeModal() {
            document.getElementById('mergeModal').style.display = 'none';
        }

        async function saveMerge() {
            const ids = Array.from(selectedIds);
            if (ids.length < 2) { closeMergeModal(); return; }
            const payload = {
                ids,
                merged: {
                    requirement_text: document.getElementById('mergeText').value.trim(),
                    requirement_type: document.getElementById('mergeType').value,
                    priority:         document.getElementById('mergePriority').value,
                    category_id:      document.getElementById('mergeCategory').value || null,
                    ai_rationale:     document.getElementById('mergeRationale').value.trim()
                }
            };
            if (!payload.merged.requirement_text) {
                showToast(window.t('contracts.rfp.consolidate.merged_text_required'), 'error');
                return;
            }

            try {
                const res = await fetch(API_BASE + 'merge_consolidated.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.consolidate.merge_failed_short'));
                closeMergeModal();
                clearSelection();
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.consolidate.merge_failed') + ' ' + err.message, 'error');
            }
        }

        // ─── Conflict resolution ─────────────────────────────────────

        let resolvingConflictId = null;
        let resolvingResolution = null;

        function openResolveModal(conflictId, resolution) {
            const conflict = pageData.conflicts.find(c => c.id === conflictId);
            if (!conflict) return;
            resolvingConflictId = conflictId;
            resolvingResolution = resolution;

            const titles = {
                chose_a:   window.t('contracts.rfp.consolidate.choose_side_a'),
                chose_b:   window.t('contracts.rfp.consolidate.choose_side_b'),
                dismissed: window.t('contracts.rfp.consolidate.dismiss_conflict')
            };
            document.getElementById('resolveModalTitle').textContent = titles[resolution] || window.t('contracts.rfp.consolidate.resolve_conflict');

            const aText = conflict.a_text || window.t('contracts.rfp.consolidate.deleted');
            const bText = conflict.b_text || window.t('contracts.rfp.consolidate.deleted');
            let context;
            if (resolution === 'chose_a') {
                context = '<strong>' + escapeHtml(window.t('contracts.rfp.consolidate.going_a')) + '</strong> ' + escapeHtml(aText) +
                          '<br><br><span style="color:var(--text-dim,#888);">' + escapeHtml(window.t('contracts.rfp.consolidate.side_b_remains')) + '</span>';
            } else if (resolution === 'chose_b') {
                context = '<strong>' + escapeHtml(window.t('contracts.rfp.consolidate.going_b')) + '</strong> ' + escapeHtml(bText) +
                          '<br><br><span style="color:var(--text-dim,#888);">' + escapeHtml(window.t('contracts.rfp.consolidate.side_a_remains')) + '</span>';
            } else {
                context = '<strong>' + escapeHtml(window.t('contracts.rfp.consolidate.dismissing')) + '</strong><br><br>' +
                          '<span style="color:var(--text-dim,#888);">' + escapeHtml(window.t('contracts.rfp.consolidate.dismissing_note')) + '</span>';
            }
            document.getElementById('resolveContext').innerHTML = context;
            document.getElementById('resolveNotes').value = '';
            document.getElementById('resolveModal').style.display = 'flex';
        }

        function closeResolveModal() {
            document.getElementById('resolveModal').style.display = 'none';
        }

        async function saveResolve() {
            const btn = document.getElementById('resolveConfirmBtn');
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'resolve_conflict.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        id: resolvingConflictId,
                        resolution: resolvingResolution,
                        notes: document.getElementById('resolveNotes').value.trim()
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.consolidate.resolve_failed_short'));
                closeResolveModal();
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.consolidate.resolve_failed') + ' ' + err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        async function reopenConflict(conflictId) {
            if (!(await showConfirm({ title: window.t('contracts.rfp.document.confirm'), message: window.t('contracts.rfp.consolidate.reopen_confirm'), okLabel: window.t('common.ok'), okClass: 'primary' }))) return;
            try {
                const res = await fetch(API_BASE + 'resolve_conflict.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id: conflictId, resolution: 'open' })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.consolidate.reopen_failed_short'));
                loadAll();
            } catch (err) {
                showToast(window.t('contracts.rfp.consolidate.reopen_failed') + ' ' + err.message, 'error');
            }
        }

        // "Merge into one" from a conflict pre-selects the two rows in
        // the global selection and pops the merge modal — reuses all
        // the existing merge plumbing. The conflict row cascade-deletes
        // when the merge succeeds (the rows it referenced are gone),
        // which is the natural audit trail: the merged row's rationale
        // says "Merged from #A, #B" and the AI explanation lives in
        // rfp_processing_log.
        function mergeFromConflict(conflictId, aId, bId) {
            clearSelection();
            selectedIds.add(aId);
            selectedIds.add(bId);
            updateMergeBar();
            // Tick the checkboxes so the visible state matches
            const cbA = document.querySelector('.req-select[data-id="' + aId + '"]');
            const cbB = document.querySelector('.req-select[data-id="' + bId + '"]');
            if (cbA) cbA.checked = true;
            if (cbB) cbB.checked = true;
            const rowA = document.getElementById('row-' + aId);
            const rowB = document.getElementById('row-' + bId);
            if (rowA) rowA.classList.add('selected');
            if (rowB) rowB.classList.add('selected');
            openMergeModal();
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function reqTypeLabel(t) {
            const key = 'contracts.rfp.req_type.' + t;
            const label = window.t(key);
            return label === key ? (t || '').replace('_', ' ') : label;
        }
        function priorityLabel(p) {
            const key = 'contracts.rfp.req_priority.' + p;
            const label = window.t(key);
            return label === key ? p : label;
        }
        function resolutionLabel(res) {
            const key = 'contracts.rfp.consolidate.resolution_' + res;
            const label = window.t(key);
            return label === key ? (res || '').replace('_', ' ') : label;
        }
    </script>
</body>
</html>
