<?php
/**
 * Forms — dedicated edit page (replaces the old inline editor in
 * forms/index.php and the now-deleted forms/builder.php). Pretty URL:
 *
 *   /forms/edit/         → create a new form
 *   /forms/edit/?id=42   → edit form #42
 *
 * Mounted at its own path so an edit session is a real URL the user can
 * bookmark, share, refresh, and back/forward through cleanly. The
 * inline editor in forms/index.php stays as-is for now per the
 * "don't delete anything yet" instruction — once this page is
 * confirmed good we'll cut it over.
 *
 * Includes the versioning metadata panel from #434 (which never made
 * it into the inline editor in forms/index.php — that was the bug we
 * spotted).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/branding.php';   // the organisation's logo (GH #87)
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('forms');

$current_page = 'forms';
$path_prefix = '../../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.editor.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=2"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=5"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/forms.css?v=<?= time() ?>">
    <style>
        /* Module accent (teal). */
        body { --accent: var(--forms-accent, #00897b); --accent-hover: var(--forms-accent-hover, #00695c); }

        /* The dedicated edit page doesn't use the sidebar/list layout
           of forms/index.php — it's a single full-width main panel
           laid out as a flex column so the sticky footer pins at the
           bottom and only .forms-main scrolls. */
        .forms-edit-page {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 48px);
        }
        .forms-edit-page .forms-main {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        /* Versions dropdown — opens below the Versions button in the
           top toolbar. Each row is a version in chain order with a
           "current" badge on the leaf. */
        .versions-wrap { position: relative; }
        .versions-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 340px;
            max-width: 420px;
            max-height: 380px;
            overflow-y: auto;
            background: var(--surface, white);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 6px;
            box-shadow: 0 8px 24px var(--shadow, rgba(0,0,0,0.12));
            z-index: 100;
        }
        .versions-dropdown .vd-loading,
        .versions-dropdown .vd-empty {
            padding: 20px 16px;
            text-align: center;
            color: var(--text-faint, #9ca3af);
            font-size: 13px;
        }
        .versions-dropdown .vd-row {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-soft, #f3f4f6);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }
        .versions-dropdown .vd-row:last-child { border-bottom: 0; }
        .versions-dropdown .vd-row:hover { background: var(--forms-accent-soft, #e0f2f1); }
        .versions-dropdown .vd-row.active { background: var(--forms-accent-soft, #e0f2f1); }
        .versions-dropdown .vd-row-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        .versions-dropdown .vd-row-title {
            font-weight: 600;
            color: var(--text, #111827);
            font-size: 13px;
        }
        .versions-dropdown .vd-row-meta {
            font-size: 11px;
            color: var(--text-muted, #6b7280);
        }
        .versions-dropdown .vd-pill {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            background: var(--forms-accent, #00897b);
            color: white;
        }
        .versions-dropdown .vd-pill.current {
            background: #16a34a;
        }

        /* Read-only banner under the toolbar for frozen versions. */
        .readonly-banner {
            margin: 0 0 12px 0;
            padding: 10px 14px;
            background: var(--warning-bg, #fff7ed);
            border: 1px solid var(--warning-border, #fed7aa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--warning-text, #9a3412);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .readonly-banner a {
            color: var(--warning-text, #9a3412);
            text-decoration: underline;
            font-weight: 600;
        }

        /* Sticky footer for the form-completion actions (Cancel + Save).
           Pinned via flex-shrink: 0 so the scrollbar inside .forms-main
           stops at this strip's top edge. */
        .editor-footer {
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 30px 19px;   /* +3px bottom for breathing room */
            border-top: 1px solid var(--border, #e0e0e0);
            background: var(--app-bg, #f5f7fa);
        }

        /* Properties drawer — right-side slide-out. Holds the version
           metadata (#434). Hidden off-screen by default, slides in
           when toggled via the Properties button in the top toolbar.
           Backdrop dims the rest of the screen and closes on click. */
        .properties-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.25);
            opacity: 0; pointer-events: none;
            transition: opacity 0.18s;
            z-index: 2400;
        }
        .properties-backdrop.open { opacity: 1; pointer-events: auto; }
        .properties-drawer {
            position: fixed;
            /* top is set inline by openPropertiesDrawer() to the
               actual measured .header height — assuming a fixed value
               here would mean any header restyle (e.g. nav-btn font
               change) leaves the drawer overlapping the navbar.
               Sensible fallback of 62px in case JS fails. */
            top: 62px;
            right: 0; bottom: 0;
            width: 360px;
            max-width: 90vw;
            background: var(--surface, white);
            box-shadow: -4px 0 16px var(--shadow, rgba(0,0,0,0.08));
            transform: translateX(100%);
            transition: transform 0.22s ease;
            z-index: 2450;
            display: flex;
            flex-direction: column;
        }
        .properties-drawer.open { transform: translateX(0); }
        .properties-drawer-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-soft, #eee);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .properties-drawer-header h3 {
            margin: 0; font-size: 15px; color: var(--text, #333);
        }
        .properties-close {
            background: none; border: none;
            font-size: 22px; line-height: 1;
            color: var(--text-faint, #999); cursor: pointer; padding: 0 4px;
        }
        .properties-close:hover { color: var(--text, #333); }
        .properties-drawer-body {
            padding: 18px;
            overflow-y: auto;
            flex: 1;
        }
        .properties-empty {
            font-size: 13px; color: var(--text-dim, #888); line-height: 1.6;
        }
        .properties-empty p { margin: 0; }

        /* Versioning metadata panel (#434). Lives in the drawer now;
           visible whenever the drawer is open and the form has been
           saved at least once. */
        .form-meta {
            padding: 4px 0;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            display: grid;
            grid-template-columns: max-content 1fr;
            column-gap: 14px;
            row-gap: 4px;
            margin: 0;
        }
        .form-meta dt { color: var(--text-dim, #888); font-weight: 500; margin: 0; }
        .form-meta dd { margin: 0; color: var(--text, #333); }
        .form-meta .form-meta-version {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            background: var(--forms-accent, #00897b);
            color: white;
            font-weight: 600;
            font-size: 12px;
        }

        /* AI Assist — copied from forms/index.php so this page is
           self-contained and we don't have to chase css across files
           if we later modify the AI modal. */
        .btn-ai-assist {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
        }
        .btn-ai-assist:hover { background: linear-gradient(135deg, #4f46e5, #4338ca); }

        .ai-modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: none; align-items: center; justify-content: center; z-index: 2500;
        }
        .ai-modal-overlay.active { display: flex; }
        .ai-modal {
            background: var(--surface, #fff); border-radius: 8px; box-shadow: 0 10px 40px var(--shadow, rgba(0,0,0,0.2));
            width: 640px; max-width: calc(100vw - 40px); max-height: calc(100vh - 40px); overflow: hidden;
            display: flex; flex-direction: column;
        }
        .ai-modal-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border-soft, #eee);
            display: flex; justify-content: space-between; align-items: center;
        }
        .ai-modal-header h3 {
            margin: 0; font-size: 16px; color: var(--text, #333);
            display: flex; align-items: center; gap: 8px;
        }
        .ai-sparkle {
            display: inline-block; font-size: 16px;
            background: linear-gradient(135deg, #6366f1, #ec4899);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .ai-modal-close {
            background: none; border: none; font-size: 22px; line-height: 1;
            color: var(--text-faint, #999); cursor: pointer; padding: 0;
        }
        .ai-modal-close:hover { color: var(--text, #333); }
        .ai-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
        .ai-modal-body label {
            display: block; margin-bottom: 6px; font-weight: 500;
            font-size: 13px; color: var(--text, #333);
        }
        .ai-modal-body textarea {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border, #ddd); border-radius: 5px;
            font-size: 13px; box-sizing: border-box; font-family: inherit;
            min-height: 110px; resize: vertical;
        }
        .ai-modal-body textarea:focus {
            outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,0.12);
        }
        .ai-modal-body .ai-hint {
            color: var(--text-dim, #888); font-size: 12px; margin-top: 6px;
        }
        .ai-modal-body .ai-examples {
            font-size: 12px; color: var(--text-muted, #6b7280); margin-top: 14px;
        }
        .ai-modal-body .ai-examples strong { color: var(--text-muted, #4b5563); }
        .ai-modal-body .ai-examples ul { margin: 6px 0 0 0; padding-left: 18px; }
        .ai-modal-body .ai-examples li { margin-bottom: 3px; cursor: pointer; }
        .ai-modal-body .ai-examples li:hover { color: #4f46e5; text-decoration: underline; }

        .ai-progress {
            margin-top: 16px; padding: 14px;
            background: var(--surface-2, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 6px;
            font-size: 12px; color: var(--text-muted, #475569);
        }
        .ai-progress .ai-progress-status {
            display: flex; align-items: center; gap: 8px; font-weight: 500; margin-bottom: 8px;
        }
        .ai-progress .ai-spinner {
            width: 12px; height: 12px; border-radius: 50%;
            border: 2px solid #c7d2fe; border-top-color: #4f46e5;
            animation: ai-spin 0.8s linear infinite;
        }
        @keyframes ai-spin { to { transform: rotate(360deg); } }
        .ai-progress .ai-progress-counters {
            display: flex; gap: 14px; font-size: 11px; color: var(--text-muted, #6b7280); margin-bottom: 8px;
        }
        .ai-progress .ai-progress-counters span strong { color: var(--text, #1f2937); }
        .ai-progress pre.ai-stream {
            margin: 0; max-height: 180px; overflow: auto;
            background: #0f172a; color: #cbd5e1; padding: 10px;
            border-radius: 4px; font-size: 11px; line-height: 1.45;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            white-space: pre-wrap; word-break: break-word;
        }
        .ai-progress.error {
            background: var(--danger-bg, #fef2f2); border-color: #fecaca; color: var(--danger-text, #991b1b);
        }

        .ai-modal-footer {
            padding: 14px 20px; border-top: 1px solid var(--border-soft, #eee);
            display: flex; justify-content: flex-end; gap: 8px;
        }
        .ai-modal-footer .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Proposal panel — shown after the AI streaming completes. Lists
           the proposed fields with a colour-coded badge per row
           (added / changed / unchanged / removed) so the user can see
           exactly what's about to happen before clicking Apply. */
        .ai-proposal {
            margin-top: 14px;
            padding: 14px;
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 6px;
            font-size: 13px;
            color: #0c4a6e;
        }
        .ai-proposal .ai-prop-head {
            font-size: 13px;
            margin-bottom: 10px;
            color: #0c4a6e;
        }
        .ai-proposal .ai-prop-list {
            list-style: none;
            margin: 0 0 12px 0;
            padding: 0;
            max-height: 220px;
            overflow-y: auto;
            background: var(--surface, white);
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 4px;
        }
        .ai-proposal .ai-prop-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-soft, #f1f5f9);
            font-size: 12.5px;
            color: var(--text, #1e293b);
        }
        .ai-proposal .ai-prop-list li:last-child { border-bottom: none; }
        .ai-proposal .ai-prop-badge {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            flex-shrink: 0;
            min-width: 70px;
            text-align: center;
        }
        .ai-proposal .ai-prop-meta {
            color: var(--text-muted, #64748b);
            font-size: 11px;
            margin-left: auto;
        }
        .ai-proposal .ai-prop-note {
            font-size: 12px;
            color: var(--text-muted, #475569);
        }
    </style>
    <!-- Mobile layer. Linked AFTER this page's inline <style> on purpose: the
         mobile rules must win on equal specificity, and a link placed above it
         would silently lose to the desktop block below (the load-order trap). -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css?v=129">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="forms-container forms-edit-page">
        <div class="forms-main">
            <!-- Top toolbar holds INSPECTION tools — AI Assist, Versions
                 dropdown, Save as new version, Properties. Regular
                 Save + Cancel sit in the sticky footer where the eye
                 naturally lands after finishing the form. -->
            <div class="editor-toolbar">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <h2 id="editorTitle"><?php echo htmlspecialchars(t('forms.editor.title_new')); ?></h2>
                    <!-- v-pill from #434 — quick at-a-glance version
                         indicator next to the title. Click to open the
                         full properties drawer. -->
                    <span id="versionPill" class="form-meta-version" style="display:none; cursor:pointer;" onclick="togglePropertiesDrawer()" title="<?php echo htmlspecialchars(t('forms.editor.open_properties')); ?>"></span>
                    <div class="unsaved-indicator" id="unsavedIndicator">
                        <span class="unsaved-dot"></span>
                        <?php echo htmlspecialchars(t('forms.editor.unsaved')); ?>
                    </div>
                </div>
                <div class="editor-toolbar-actions">
                    <button class="btn btn-ai-assist" onclick="openAiModal()" title="<?php echo htmlspecialchars(t('forms.editor.ai_assist_title')); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.9 5.8L20 10l-5.8 1.9L12 18l-1.9-5.8L4 10l6.1-2.2z"></path><path d="M19 14l1 3 3 1-3 1-1 3-1-3-3-1 3-1z"></path><path d="M5 16l.6 1.8L7.5 18l-1.9.6L5 20l-.6-1.4L2.5 18l2-.2z"></path></svg>
                        <?php echo htmlspecialchars(t('forms.editor.ai_assist')); ?>
                    </button>
                    <!-- Versions dropdown — hidden for brand-new forms,
                         populated on first open via list_versions.php. -->
                    <div class="versions-wrap" id="versionsWrap" style="display:none;">
                        <button class="btn btn-secondary" id="versionsBtn" onclick="toggleVersionsDropdown(event)" title="<?php echo htmlspecialchars(t('forms.editor.versions_title')); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"></path><circle cx="12" cy="12" r="10"></circle></svg>
                            <?php echo htmlspecialchars(t('forms.editor.versions')); ?> <span id="versionsBtnCount" style="opacity:0.7;"></span>
                        </button>
                        <div class="versions-dropdown" id="versionsDropdown" style="display:none;"></div>
                    </div>
                    <!-- Save as new version — only shown for the leaf
                         (current) version; hidden for frozen snapshots
                         (the read-only banner explains how to fork from
                         the current version instead). -->
                    <button class="btn btn-secondary" id="newVersionBtn" onclick="createNewVersion()" style="display:none;" title="<?php echo htmlspecialchars(t('forms.editor.new_version_title')); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                        <?php echo htmlspecialchars(t('forms.editor.new_version')); ?>
                    </button>
                    <button class="btn btn-secondary" id="propertiesBtn" onclick="togglePropertiesDrawer()" title="<?php echo htmlspecialchars(t('forms.editor.properties_title')); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                        <?php echo htmlspecialchars(t('forms.editor.properties')); ?>
                    </button>
                </div>
            </div>

            <!-- Read-only banner — shown when viewing a frozen (non-leaf)
                 version. Tells the user how to either edit the current
                 version or fork from it into a new one. -->
            <div class="readonly-banner" id="readonlyBanner" style="display:none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <span><?php echo t('forms.editor.readonly_banner'); ?></span>
            </div>

            <!-- Title & description. Versioning metadata moved to the
                 Properties drawer (slides in from the right via the
                 toolbar button) so the main editor stays uncluttered. -->
            <div class="form-settings-card">
                <div class="field-group">
                    <label><?php echo htmlspecialchars(t('forms.editor.form_title_label')); ?></label>
                    <input type="text" id="formTitle" placeholder="<?php echo htmlspecialchars(t('forms.editor.form_title_ph')); ?>">
                </div>
                <div class="field-group">
                    <label><?php echo htmlspecialchars(t('forms.editor.description_label')); ?></label>
                    <textarea id="formDesc" rows="2" placeholder="<?php echo htmlspecialchars(t('forms.editor.description_ph')); ?>"></textarea>
                </div>
            </div>

            <!-- Tabs: Fields | Preview -->
            <div class="form-tabs">
                <button class="form-tab active" onclick="switchFormTab('fields')" id="tabFields"><?php echo htmlspecialchars(t('forms.editor.tab_fields')); ?></button>
                <button class="form-tab" onclick="switchFormTab('preview')" id="tabPreview"><?php echo htmlspecialchars(t('forms.editor.tab_preview')); ?></button>
            </div>

            <!-- Fields tab -->
            <div class="form-tab-content active" id="tabContentFields">
                <div class="fields-header">
                    <h3><?php echo htmlspecialchars(t('forms.editor.fields_heading')); ?></h3>
                    <div class="add-field-btn">
                        <button class="btn btn-secondary" onclick="toggleAddMenu()" id="addFieldBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            <?php echo htmlspecialchars(t('forms.editor.add')); ?>
                        </button>
                        <div class="add-field-menu" id="addFieldMenu">
                            <button onclick="addField('text')"><span class="field-type-badge text">Abc</span> <?php echo htmlspecialchars(t('forms.fieldtypes.text')); ?></button>
                            <button onclick="addField('textarea')"><span class="field-type-badge textarea">Txt</span> <?php echo htmlspecialchars(t('forms.fieldtypes.textarea')); ?></button>
                            <button onclick="addField('email')"><span class="field-type-badge email">@</span> <?php echo htmlspecialchars(t('forms.fieldtypes.email')); ?></button>
                            <button onclick="addField('number')"><span class="field-type-badge number">123</span> <?php echo htmlspecialchars(t('forms.fieldtypes.number')); ?></button>
                            <button onclick="addField('dropdown')"><span class="field-type-badge dropdown">Sel</span> <?php echo htmlspecialchars(t('forms.fieldtypes.dropdown')); ?></button>
                            <button onclick="addField('radio')"><span class="field-type-badge radio">&#9673;</span> <?php echo htmlspecialchars(t('forms.fieldtypes.radio')); ?></button>
                            <button onclick="addField('checkbox')"><span class="field-type-badge checkbox">Chk</span> <?php echo htmlspecialchars(t('forms.fieldtypes.checkbox')); ?></button>
                            <button onclick="addField('checkboxes')"><span class="field-type-badge checkboxes">&#9745;</span> <?php echo htmlspecialchars(t('forms.fieldtypes.checkboxes')); ?></button>
                            <!-- ONE date field with a mode (date / time / date and time),
                                 not three types: field_type can't be changed after a field
                                 exists, so a wrong guess would mean retiring it. -->
                            <button onclick="addField('datetime')"><span class="field-type-badge datetime">&#128197;</span> <?php echo htmlspecialchars(t('forms.fieldtypes.datetime')); ?></button>
                            <!-- A heading rather than a question: it groups everything
                                 below it until the next one, and a condition on it
                                 hides that whole group at once. -->
                            <!-- Asks "which one?" against records we already hold —
                                 assets, CMDB objects, people. ONE type with a source in
                                 config, for the same reason the date field has a mode. -->
                            <button onclick="addField('lookup')"><span class="field-type-badge lookup">&#128269;</span> <?php echo htmlspecialchars(t('forms.fieldtypes.lookup')); ?></button>
                            <button onclick="addField('section')"><span class="field-type-badge section">&#9647;</span> <?php echo htmlspecialchars(t('forms.fieldtypes.section')); ?></button>
                        </div>
                    </div>
                </div>
                <ul class="field-list" id="fieldList">
                    <li class="no-fields"><?php echo htmlspecialchars(t('forms.editor.no_fields')); ?></li>
                </ul>
            </div>

            <!-- Preview tab -->
            <div class="form-tab-content" id="tabContentPreview">
                <div id="previewContent">
                    <p class="preview-empty"><?php echo htmlspecialchars(t('forms.editor.preview_empty')); ?></p>
                </div>
            </div>
        </div>

        <!-- Sticky footer with the form-completion actions. .forms-edit-page
             is a flex column so this pins at the bottom; the scrollbar in
             .forms-main stops at the footer's top edge. -->
        <div class="editor-footer">
            <button class="btn btn-secondary" onclick="cancelEdit()"><?php echo htmlspecialchars(t('forms.editor.cancel')); ?></button>
            <button class="btn btn-primary save-btn" id="saveBtn" onclick="saveForm()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                <?php echo htmlspecialchars(t('forms.editor.save')); ?>
            </button>
        </div>
    </div>

    <!-- Properties drawer (right-side slide-out). Hidden by default;
         toggled via the Properties button in the top toolbar. ESC and
         backdrop click close it. -->
    <div class="properties-backdrop" id="propertiesBackdrop" onclick="closePropertiesDrawer()"></div>
    <aside class="properties-drawer" id="propertiesDrawer" aria-hidden="true">
        <div class="properties-drawer-header">
            <h3><?php echo htmlspecialchars(t('forms.editor.properties_heading')); ?></h3>
            <button class="properties-close" onclick="closePropertiesDrawer()" title="<?php echo htmlspecialchars(t('forms.editor.close')); ?>" aria-label="<?php echo htmlspecialchars(t('forms.editor.close')); ?>">&times;</button>
        </div>
        <div class="properties-drawer-body">
            <!-- Populated by renderFormMeta() on load + after every save.
                 Shows a placeholder message until the form has been saved
                 at least once (and therefore has a version + author). -->
            <div id="propertiesEmpty" class="properties-empty">
                <p><?php echo htmlspecialchars(t('forms.editor.properties_unsaved')); ?></p>
            </div>
            <dl class="form-meta" id="formMeta" style="display:none;">
                <dt><?php echo htmlspecialchars(t('forms.editor.meta_version')); ?></dt>
                <dd><span class="form-meta-version" id="formMetaVersion">v1</span></dd>
                <dt><?php echo htmlspecialchars(t('forms.editor.meta_author')); ?></dt>
                <dd id="formMetaAuthor">&mdash;</dd>
                <dt><?php echo htmlspecialchars(t('forms.editor.meta_created')); ?></dt>
                <dd id="formMetaCreated">&mdash;</dd>
                <dt><?php echo htmlspecialchars(t('forms.editor.meta_modified')); ?></dt>
                <dd id="formMetaModified">&mdash;</dd>
                <dt><?php echo htmlspecialchars(t('forms.editor.meta_modified_by')); ?></dt>
                <dd id="formMetaModifiedBy">&mdash;</dd>
            </dl>
        </div>
    </aside>

    <!-- Toast notification -->
    <script>
        const API_BASE = '<?php echo BASE_URL; ?>api/forms/';
        // Resolve the form id from the URL once on load. Saved into a
        // mutable variable so a successful create can pick up the new id.
        let currentFormId = (() => {
            const v = new URLSearchParams(window.location.search).get('id');
            const n = v ? parseInt(v, 10) : NaN;
            return Number.isFinite(n) && n > 0 ? n : null;
        })();
        let fields = [];
        let isDirty = false;
        let logoAlignment = 'center';

        // Client-side identity for a field, handed out on load and on add. Conditions
        // point at a _key rather than at an id or an index: an id doesn't exist yet for
        // a field added in this session, and an index changes the moment anything is
        // dragged. _key is never sent — buildRulesForSave() converts it at the last
        // moment into either the real field id or "idx:N".
        let nextFieldKey = 1;
        function newFieldKey() { return nextFieldKey++; }

        // Track which element initiated a drag — the drag handle on a
        // field row vs an option row — so dragstart on the wrong target
        // doesn't fire.
        let fieldDragAllowed = false;
        let optDragAllowed = false;
        document.addEventListener('mousedown', function(e) {
            fieldDragAllowed = !!e.target.closest('.field-drag');
            optDragAllowed = !!e.target.closest('.option-drag');
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();

            if (currentFormId) {
                document.getElementById('editorTitle').textContent = window.t('forms.editor.title_edit');
                loadFormForEdit(currentFormId);
            } else {
                document.getElementById('editorTitle').textContent = window.t('forms.editor.title_new');
                renderFields();
                updatePreview();
            }

            // Close the add-field popup on outside click
            document.addEventListener('click', function(e) {
                const menu = document.getElementById('addFieldMenu');
                if (menu && !e.target.closest('.add-field-btn')) {
                    menu.classList.remove('open');
                }
            });

            document.getElementById('formTitle').addEventListener('input', function() {
                markDirty(); updatePreview();
            });
            document.getElementById('formDesc').addEventListener('input', function() {
                markDirty(); updatePreview();
            });

            // Warn before navigating away with unsaved work
            window.addEventListener('beforeunload', function(e) {
                if (isDirty) { e.preventDefault(); e.returnValue = ''; }
            });
        });

        // ===== Dirty state =====
        function markDirty() {
            if (isDirty) return;
            isDirty = true;
            document.getElementById('unsavedIndicator').classList.add('visible');
            document.getElementById('saveBtn').classList.add('has-changes');
        }
        function clearDirty() {
            isDirty = false;
            document.getElementById('unsavedIndicator').classList.remove('visible');
            document.getElementById('saveBtn').classList.remove('has-changes');
        }

        // ===== Load & versioning metadata =====
        async function loadFormForEdit(id) {
            try {
                const res = await fetch(API_BASE + 'get_form.php?id=' + id);
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || window.t('forms.toast.form_not_found'), 'error');
                    return;
                }
                document.getElementById('formTitle').value = data.form.title;
                document.getElementById('formDesc').value = data.form.description || '';
                // `id` is carried through the whole edit and sent back on save. It is
                // what keeps each answer attached to the question it was given for:
                // without it the server can only match fields by position, so dragging
                // one silently re-pointed every historical submission.
                fields = data.form.fields.map(f => ({
                    id: f.id ? parseInt(f.id, 10) : null,
                    _key: newFieldKey(),
                    field_type: f.field_type,
                    label: f.label,
                    options: f.options ? JSON.parse(f.options) : [],
                    is_required: f.is_required == 1,
                    config: f.config ? (typeof f.config === 'string' ? JSON.parse(f.config) : f.config) : null
                }));
                // Stored conditions reference field IDs; the builder works in _key.
                rehydrateRuleRefs(fields);
                renderFields();
                updatePreview();
                renderFormMeta(data.form);
            } catch (e) {
                showToast(window.t('forms.toast.load_failed', { message: e.message }), 'error');
            }
        }

        // Versioning state (#442). currentForm holds the most recent
        // get_form.php response so we know whether to enable Save (only
        // when is_leaf is true) and which versions chain to show.
        let currentForm = null;

        // Populate the Properties drawer's version metadata section.
        // Called after a successful load + after a save (so the new
        // version_number / modified_by show up immediately). Also
        // updates the toolbar v-pill + Versions dropdown count.
        function renderFormMeta(form) {
            currentForm = form || null;
            const meta  = document.getElementById('formMeta');
            const empty = document.getElementById('propertiesEmpty');
            const pill  = document.getElementById('versionPill');
            const wrap  = document.getElementById('versionsWrap');
            const newBtn = document.getElementById('newVersionBtn');
            const banner = document.getElementById('readonlyBanner');
            const saveBtn = document.getElementById('saveBtn');

            if (!form || !form.id) {
                if (meta)   meta.style.display = 'none';
                if (empty)  empty.style.display = '';
                if (pill)   pill.style.display = 'none';
                if (wrap)   wrap.style.display = 'none';
                if (newBtn) newBtn.style.display = 'none';
                if (banner) banner.style.display = 'none';
                if (saveBtn) { saveBtn.disabled = false; saveBtn.title = ''; }
                return;
            }
            // created_date / modified_date are server-stamped UTC (kind 1):
            // parse as UTC, show in the analyst's zone.
            const fmt = (s) => {
                if (!s) return '—';
                const d = parseUTCDate(s);
                if (!d || isNaN(d.getTime())) return s;
                return fmtDateTime(d);
            };
            const vLabel = 'v' + (form.version_number || 1);
            document.getElementById('formMetaVersion').textContent    = vLabel;
            document.getElementById('formMetaAuthor').textContent     = form.created_by_name || '—';
            document.getElementById('formMetaCreated').textContent    = fmt(form.created_date);
            document.getElementById('formMetaModified').textContent   = fmt(form.modified_date);
            document.getElementById('formMetaModifiedBy').textContent = form.modified_by_name || '—';
            if (meta)  meta.style.display = '';
            if (empty) empty.style.display = 'none';

            // Toolbar v-pill + read-only state vs leaf
            if (pill) { pill.textContent = vLabel; pill.style.display = ''; }
            if (wrap) wrap.style.display = '';

            const isLeaf = form.is_leaf !== false;
            if (newBtn) newBtn.style.display = isLeaf ? '' : 'none';
            if (banner) banner.style.display = isLeaf ? 'none' : '';
            if (saveBtn) {
                saveBtn.disabled = !isLeaf;
                saveBtn.title = isLeaf ? '' : window.t('forms.editor.readonly_save_title');
            }

            // Refresh the version dropdown's cached count + (if open)
            // its rows. Loaded lazily — the API call only fires when
            // the dropdown is opened OR after a chain-mutating action.
            cachedVersions = null;
            const dd = document.getElementById('versionsDropdown');
            if (dd && dd.style.display !== 'none') loadVersions();
        }

        // ===== Versions dropdown =====
        // Cached so flicking it open + closed doesn't re-fetch each
        // time. Invalidated by renderFormMeta() after any chain change.
        let cachedVersions = null;

        function toggleVersionsDropdown(e) {
            if (e) e.stopPropagation();
            const dd = document.getElementById('versionsDropdown');
            if (!dd) return;
            const willOpen = dd.style.display === 'none';
            dd.style.display = willOpen ? 'block' : 'none';
            if (willOpen) loadVersions();
        }

        // Close the dropdown if you click outside it.
        document.addEventListener('click', function(e) {
            const wrap = document.getElementById('versionsWrap');
            if (wrap && !wrap.contains(e.target)) {
                const dd = document.getElementById('versionsDropdown');
                if (dd) dd.style.display = 'none';
            }
        });

        async function loadVersions() {
            const dd = document.getElementById('versionsDropdown');
            if (!dd) return;
            if (!currentFormId) { dd.innerHTML = '<div class="vd-empty">' + esc(window.t('forms.versions.save_first_history')) + '</div>'; return; }
            if (cachedVersions) {
                renderVersionsList(cachedVersions);
                return;
            }
            dd.innerHTML = '<div class="vd-loading">' + esc(window.t('forms.versions.loading')) + '</div>';
            try {
                const res = await fetch(API_BASE + 'list_versions.php?id=' + currentFormId);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('forms.versions.failed'));
                cachedVersions = data.versions || [];
                // Update the count next to the toolbar Versions button
                const countEl = document.getElementById('versionsBtnCount');
                if (countEl) countEl.textContent = '(' + cachedVersions.length + ')';
                renderVersionsList(cachedVersions);
            } catch (e) {
                dd.innerHTML = '<div class="vd-empty">' + escHtml(window.t('forms.versions.load_failed', { message: e.message })) + '</div>';
            }
        }

        function renderVersionsList(versions) {
            const dd = document.getElementById('versionsDropdown');
            if (!dd) return;
            if (!versions.length) { dd.innerHTML = '<div class="vd-empty">' + esc(window.t('forms.versions.none')) + '</div>'; return; }
            // modified_date is server-stamped UTC (kind 1): parse as UTC,
            // show in the analyst's zone.
            const fmt = (s) => {
                if (!s) return '';
                const d = parseUTCDate(s);
                if (!d || isNaN(d.getTime())) return s;
                return fmtDateTime(d);
            };
            // Most recent first reads more naturally in a dropdown
            // (you'd expect "current" + recent forks at the top).
            const sorted = versions.slice().sort((a, b) => b.version_number - a.version_number);
            dd.innerHTML = sorted.map(v => {
                const isActive = v.id === currentFormId;
                const pillClass = v.is_current ? 'vd-pill current' : 'vd-pill';
                const pillText  = v.is_current ? window.t('forms.versions.current') : ('v' + v.version_number);
                return `<a href="?id=${v.id}" class="vd-row ${isActive ? 'active' : ''}">
                    <div class="vd-row-top">
                        <span class="vd-row-title">v${v.version_number} &middot; ${escHtml(v.title || window.t('forms.versions.untitled'))}</span>
                        <span class="${pillClass}">${escHtml(pillText)}</span>
                    </div>
                    <div class="vd-row-meta">
                        ${escHtml(window.t('forms.versions.edited_by', { name: v.modified_by_name || window.t('forms.versions.unknown'), date: fmt(v.modified_date) }))}
                    </div>
                </a>`;
            }).join('');
        }

        // Jump to the leaf (current) version from the read-only banner link.
        function jumpToCurrentVersion(e) {
            if (e) e.preventDefault();
            (async () => {
                if (!cachedVersions) await loadVersions();
                const cur = (cachedVersions || []).find(v => v.is_current);
                if (cur) window.location.href = '?id=' + cur.id;
            })();
        }

        // ===== Save as new version =====
        async function createNewVersion() {
            if (!currentFormId) {
                showToast(window.t('forms.toast.save_first'), 'error');
                return;
            }
            if (isDirty) {
                const proceed = await showConfirm({
                    title: window.t('forms.newversion.unsaved_title'),
                    message: window.t('forms.newversion.unsaved_message'),
                    okLabel: window.t('forms.newversion.unsaved_ok'),
                    okClass: 'primary'
                });
                if (!proceed) return;
            }
            // If the user has unsaved changes, persist them first so
            // the new version snapshot reflects what they see.
            if (isDirty) {
                const ok = await saveForm();
                if (!ok) return;
            }
            if (!(await showConfirm({ title: window.t('forms.newversion.confirm_title'), message: window.t('forms.newversion.confirm_message'), okLabel: window.t('forms.newversion.confirm_ok'), okClass: 'primary' }))) return;
            try {
                const res = await fetch(API_BASE + 'create_version.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ parent_form_id: currentFormId })
                });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || window.t('forms.toast.version_failed'), 'error');
                    return;
                }
                showToast(window.t('forms.toast.version_created', { n: data.version_number }));
                // Jump to the new version
                window.location.href = '?id=' + data.id;
            } catch (e) {
                showToast(window.t('forms.toast.version_failed'), 'error');
            }
        }

        // Properties drawer — slide-in from the right with the form's
        // version metadata. Toggled by the Properties button in the
        // top toolbar. Closed by the X, the backdrop, or ESC.
        function togglePropertiesDrawer() {
            const drawer   = document.getElementById('propertiesDrawer');
            const backdrop = document.getElementById('propertiesBackdrop');
            const open = drawer.classList.contains('open');
            if (open) closePropertiesDrawer();
            else openPropertiesDrawer();
        }
        function openPropertiesDrawer() {
            const drawer = document.getElementById('propertiesDrawer');
            // Measure the global header so the drawer always tucks
            // under it, regardless of how tall the navbar actually
            // renders (it's ~60px today but I'd rather not bake that
            // in — see #415 for the same trap we hit on morning-checks).
            const header = document.querySelector('.header');
            if (header) drawer.style.top = header.offsetHeight + 'px';
            drawer.classList.add('open');
            document.getElementById('propertiesBackdrop').classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
        }
        function closePropertiesDrawer() {
            document.getElementById('propertiesDrawer').classList.remove('open');
            document.getElementById('propertiesBackdrop').classList.remove('open');
            document.getElementById('propertiesDrawer').setAttribute('aria-hidden', 'true');
        }
        // ESC closes the drawer (but only if it's open and no other
        // modal owns the keypress — the AI modal handles its own).
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            if (document.getElementById('aiModal').classList.contains('active')) return;
            if (document.getElementById('propertiesDrawer').classList.contains('open')) {
                closePropertiesDrawer();
            }
        });

        // ===== Cancel / back =====
        async function cancelEdit() {
            if (isDirty) {
                const ok = await showConfirm({
                    title: window.t('forms.cancel.title'),
                    message: window.t('forms.cancel.message'),
                    okLabel: window.t('forms.cancel.ok'),
                    okClass: 'danger'
                });
                if (!ok) return;
            }
            isDirty = false;   // skip the beforeunload warning
            window.location.href = '<?php echo BASE_URL; ?>forms/';
        }

        // ===== Tabs =====
        function switchFormTab(tab) {
            document.getElementById('tabFields').classList.toggle('active', tab === 'fields');
            document.getElementById('tabPreview').classList.toggle('active', tab === 'preview');
            document.getElementById('tabContentFields').classList.toggle('active', tab === 'fields');
            document.getElementById('tabContentPreview').classList.toggle('active', tab === 'preview');
            if (tab === 'preview') updatePreview();
        }

        // ===== Fields =====
        function toggleAddMenu() {
            document.getElementById('addFieldMenu').classList.toggle('open');
        }
        // The four "list" types (dropdown, radio, checkboxes — and the
        // legacy single 'checkbox' is NOT in this set, it's just yes/no)
        // share the editable options list in the field row and produce
        // selectable items in the preview / fill flows.
        const FIELD_TYPES_WITH_OPTIONS = ['dropdown', 'radio', 'checkboxes'];
        function hasOptions(type) { return FIELD_TYPES_WITH_OPTIONS.includes(type); }

        // Multi-value field types — the user can pick more than one
        // answer, so the submitted value is an array (serialised as
        // JSON before saving). Drives the fill page + submissions
        // display.
        const FIELD_TYPES_MULTI_VALUE = ['checkboxes'];
        function isMultiValue(type) { return FIELD_TYPES_MULTI_VALUE.includes(type); }

        // A section is a heading: it takes no answer, so it has no options, no
        // "required" toggle, and nothing may depend on what was typed into it.
        function isSection(type) { return type === 'section'; }

        // ===== Date / time fields =====
        // One field type carrying a mode, rather than three separate types. field_type
        // cannot be changed once a field exists, so three types would force an
        // irreversible guess at add-time: realising later that you needed the time too
        // would mean deleting the field, which retires it and strands every answer
        // already given to it under a separate column. A mode is just a setting.
        const DATE_MODES = ['date', 'time', 'datetime'];
        function isDateField(type) { return type === 'datetime'; }
        function isLookupField(type) { return type === 'lookup'; }
        // Keep in step with FormsService::LOOKUP_SOURCES. `portalSafe` mirrors the
        // server's flag: the tickbox is hidden for a source a customer must never
        // browse, so it cannot even be asked for.
        const LOOKUP_SOURCES = [
            { key: 'asset', portalSafe: true },
            { key: 'cmdb',  portalSafe: true },
            { key: 'user',  portalSafe: false }
        ];
        function lookupSourceOf(f) {
            const s = f.config && f.config.lookup_source;
            return LOOKUP_SOURCES.some(x => x.key === s) ? s : '';
        }
        function setLookupSource(i, src) {
            fields[i].config = fields[i].config || {};
            fields[i].config.lookup_source = src;
            // Changing source can invalidate the portal tick (user is never
            // portal-safe), so drop it rather than leave a tick that does nothing.
            const meta = LOOKUP_SOURCES.find(x => x.key === src);
            if (!meta || !meta.portalSafe) delete fields[i].config.portal_lookup;
            renderFields(); markDirty();
        }
        function setLookupPortal(i, on) {
            fields[i].config = fields[i].config || {};
            if (on) fields[i].config.portal_lookup = true; else delete fields[i].config.portal_lookup;
            markDirty();
        }
        function dateModeOf(f) {
            const m = f.config && f.config.date_mode;
            return DATE_MODES.includes(m) ? m : 'date';
        }
        function setDateMode(i, mode) {
            if (!DATE_MODES.includes(mode)) return;
            if (!fields[i].config) fields[i].config = {};
            fields[i].config.date_mode = mode;
            // Any condition testing THIS field holds a value in the old shape (a date
            // where a time is now wanted), so those rules are cleared rather than left
            // pointing at something that can never match again.
            const key = fields[i]._key;
            fields.forEach(other => {
                const vif = other.config && other.config.visible_if;
                if (!vif) return;
                vif.rules.forEach(r => { if (r._ref === key) r.value = ''; });
            });
            markDirty(); renderFields(); updatePreview();
        }

        // ===== Conditional visibility =====
        // Stored shape (server + API):  rules[].field = form_fields.id
        // Builder shape (this file):    rules[]._ref  = a field's _key
        // The two conversions live in rehydrateRuleRefs() and buildRulesForSave().

        const CONDITION_OPS = ['equals', 'not_equals', 'contains', 'is_empty', 'is_not_empty', 'greater_than', 'less_than', 'is_after', 'is_before'];
        function opNeedsValue(op) { return op !== 'is_empty' && op !== 'is_not_empty'; }

        /**
         * The operators worth offering for a given trigger field.
         *
         * All of them are valid on anything — the server accepts the full list — but a
         * menu that offers "is more than" against a date, or "is after" against a
         * dropdown, is a menu that invites nonsense. This narrows the CHOICE without
         * narrowing what the engine can do.
         */
        function opsFor(trigger) {
            if (!trigger) return CONDITION_OPS;
            if (isDateField(trigger.field_type)) {
                return ['equals', 'not_equals', 'is_after', 'is_before', 'is_empty', 'is_not_empty'];
            }
            if (trigger.field_type === 'number') {
                return ['equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'];
            }
            if (hasOptions(trigger.field_type) || trigger.field_type === 'checkbox') {
                return ['equals', 'not_equals', 'is_empty', 'is_not_empty'];
            }
            return ['equals', 'not_equals', 'contains', 'is_empty', 'is_not_empty'];
        }

        /** Stored field ids -> builder _keys, once after a load. */
        function rehydrateRuleRefs(list) {
            const keyById = {};
            list.forEach(f => { if (f.id) keyById[f.id] = f._key; });
            list.forEach(f => {
                const rules = (f.config && f.config.visible_if && f.config.visible_if.rules) || [];
                rules.forEach(r => { r._ref = keyById[parseInt(r.field, 10)]; });
            });
        }

        /**
         * Builder _keys -> what the server stores. A rule pointing at a field that
         * already exists sends its id; one pointing at a field being created by this
         * same save sends "idx:N", which syncFields resolves once the ids exist.
         * A rule whose trigger has since been deleted (or left unlabelled, so it never
         * reaches the payload) is dropped rather than sent as a dangling reference.
         */
        function buildRulesForSave(f, payloadFields) {
            // Start from everything else the field's config holds (today: date_mode) so
            // that adding a condition to a date field doesn't wipe the mode it is in.
            const out = {};
            if (isDateField(f.field_type)) out.date_mode = dateModeOf(f);
            if (isLookupField(f.field_type)) {
                out.lookup_source = lookupSourceOf(f);
                if (f.config && f.config.portal_lookup) out.portal_lookup = true;
            }

            const vif = f.config && f.config.visible_if;
            if (vif && Array.isArray(vif.rules) && vif.rules.length) {
                const posByKey = {};
                payloadFields.forEach((pf, i) => { posByKey[pf._key] = i; });

                const rules = [];
                vif.rules.forEach(r => {
                    const pos = posByKey[r._ref];
                    if (pos === undefined) return;
                    const target = payloadFields[pos];
                    rules.push({
                        field: target.id ? target.id : ('idx:' + pos),
                        op: r.op || 'equals',
                        value: opNeedsValue(r.op) ? (r.value || '') : ''
                    });
                });
                if (rules.length) {
                    out.visible_if = { match: vif.match === 'any' ? 'any' : 'all', rules: rules };
                }
            }
            return Object.keys(out).length ? out : null;
        }

        /**
         * The fields a condition on `index` may point at: earlier ones only, and only
         * ones that collect an answer. Restricting it to earlier fields is what makes
         * a circular condition impossible to build in the first place — the server
         * enforces the same rule, so a hand-crafted API call can't sneak one in.
         */
        function availableTriggers(index) {
            return fields.slice(0, index)
                .map((f, i) => ({ f: f, i: i }))
                .filter(x => !isSection(x.f.field_type) && x.f.label.trim());
        }

        function conditionRules(i) {
            const f = fields[i];
            return (f.config && f.config.visible_if && f.config.visible_if.rules) || [];
        }
        function ensureCondition(i) {
            const f = fields[i];
            if (!f.config) f.config = {};
            if (!f.config.visible_if) f.config.visible_if = { match: 'all', rules: [] };
            return f.config.visible_if;
        }
        function addCondition(i) {
            const triggers = availableTriggers(i);
            if (!triggers.length) {
                showToast(window.t('forms.cond.needs_earlier_field'), 'error');
                return;
            }
            ensureCondition(i).rules.push({ _ref: triggers[0].f._key, op: 'equals', value: '' });
            markDirty(); renderFields(); updatePreview();
        }
        function removeCondition(i, ri) {
            const vif = fields[i].config && fields[i].config.visible_if;
            if (!vif) return;
            vif.rules.splice(ri, 1);
            if (!vif.rules.length) delete fields[i].config.visible_if;
            markDirty(); renderFields(); updatePreview();
        }
        function updateConditionMatch(i, val) {
            ensureCondition(i).match = val === 'any' ? 'any' : 'all';
            markDirty(); updatePreview();
        }
        function updateConditionRef(i, ri, key) {
            conditionRules(i)[ri]._ref = parseInt(key, 10);
            conditionRules(i)[ri].value = '';   // the old value belonged to another question
            markDirty(); renderFields(); updatePreview();
        }
        function updateConditionOp(i, ri, op) {
            conditionRules(i)[ri].op = op;
            markDirty(); renderFields(); updatePreview();
        }
        function updateConditionValue(i, ri, val) {
            conditionRules(i)[ri].value = val;
            markDirty(); updatePreview();
        }

        /** The value control matches the TRIGGER field's type — a list for a list, Yes/No for a tickbox. */
        function conditionValueControl(i, ri, rule) {
            if (!opNeedsValue(rule.op)) return '';
            const trigger = fields.find(f => f._key === rule._ref);
            const onchange = `onchange="updateConditionValue(${i}, ${ri}, this.value)"`;
            if (trigger && hasOptions(trigger.field_type)) {
                const opts = (trigger.options || []).filter(o => o && o.trim());
                return `<select ${onchange}>
                    <option value="">${esc(window.t('forms.cond.pick_value'))}</option>
                    ${opts.map(o => `<option value="${escAttr(o)}" ${rule.value === o ? 'selected' : ''}>${esc(o)}</option>`).join('')}
                </select>`;
            }
            if (trigger && trigger.field_type === 'checkbox') {
                return `<select ${onchange}>
                    <option value="1" ${rule.value === '1' ? 'selected' : ''}>${esc(window.t('forms.cond.yes'))}</option>
                    <option value="0" ${rule.value === '0' ? 'selected' : ''}>${esc(window.t('forms.cond.no'))}</option>
                </select>`;
            }
            if (trigger && isDateField(trigger.field_type)) {
                // The same picker the person filling the form will see, so the value
                // stored in the rule is in exactly the shape their answer will be.
                const t = dateModeOf(trigger) === 'time' ? 'time'
                        : (dateModeOf(trigger) === 'datetime' ? 'datetime-local' : 'date');
                return `<input type="${t}" value="${escAttr(rule.value || '')}" ${onchange}>`;
            }
            return `<input type="text" value="${escAttr(rule.value || '')}"
                           placeholder="${escAttr(window.t('forms.cond.value_ph'))}" ${onchange}>`;
        }

        function renderConditionEditor(f, i) {
            const rules = conditionRules(i);
            const triggers = availableTriggers(i);
            // The first field on a form has nothing earlier to depend on, so the
            // control would only ever be able to say no.
            if (!triggers.length && !rules.length) return '';

            const vif = f.config && f.config.visible_if;
            const matchSel = rules.length > 1
                ? `<select class="cond-match" onchange="updateConditionMatch(${i}, this.value)">
                       <option value="all" ${(!vif || vif.match !== 'any') ? 'selected' : ''}>${esc(window.t('forms.cond.match_all'))}</option>
                       <option value="any" ${(vif && vif.match === 'any') ? 'selected' : ''}>${esc(window.t('forms.cond.match_any'))}</option>
                   </select>` : '';

            const rows = rules.map((rule, ri) => {
                const trigger = fields.find(x => x._key === rule._ref);
                // Keep whatever op is stored in the list even if it isn't one this
                // trigger would normally be offered (an op set before the field's type
                // or date mode changed), so switching a field never silently rewrites
                // a rule the author wrote deliberately.
                const ops = opsFor(trigger);
                if (rule.op && !ops.includes(rule.op)) ops.push(rule.op);
                return `
                <div class="cond-row">
                    <select onchange="updateConditionRef(${i}, ${ri}, this.value)">
                        ${triggers.map(x => `<option value="${x.f._key}" ${x.f._key === rule._ref ? 'selected' : ''}>${esc(x.f.label)}</option>`).join('')}
                    </select>
                    <select onchange="updateConditionOp(${i}, ${ri}, this.value)">
                        ${ops.map(op => `<option value="${op}" ${rule.op === op ? 'selected' : ''}>${esc(window.t('forms.cond.op.' + op))}</option>`).join('')}
                    </select>
                    ${conditionValueControl(i, ri, rule)}
                    <button class="option-remove" onclick="removeCondition(${i}, ${ri})" title="${escAttr(window.t('forms.cond.remove'))}">&times;</button>
                </div>`;
            }).join('');

            return `
                <div class="field-conditions">
                    <div class="field-options-label">
                        ${esc(isSection(f.field_type) ? window.t('forms.cond.show_section_when') : window.t('forms.cond.show_when'))}
                        ${matchSel}
                    </div>
                    ${rows}
                    <button class="add-option-btn" onclick="addCondition(${i})">${esc(window.t('forms.cond.add'))}</button>
                </div>`;
        }

        function addField(type) {
            document.getElementById('addFieldMenu').classList.remove('open');
            fields.push({
                // No id yet — the server mints one on save and hands it back.
                id: null,
                _key: newFieldKey(),
                field_type: type,
                label: '',
                options: hasOptions(type) ? [window.t('forms.field.default_option')] : [],
                is_required: false,
                config: null
            });
            markDirty();
            renderFields();
            updatePreview();
            setTimeout(() => {
                const inputs = document.querySelectorAll('.field-label-input');
                if (inputs.length) inputs[inputs.length - 1].focus();
            }, 50);
        }
        function renderFields() {
            const list = document.getElementById('fieldList');
            if (fields.length === 0) {
                list.innerHTML = '<li class="no-fields">' + esc(window.t('forms.editor.no_fields')) + '</li>';
                return;
            }
            list.innerHTML = fields.map((f, i) => {
                let optionsHtml = '';
                if (hasOptions(f.field_type)) {
                    const optsLabel = f.field_type === 'dropdown' ? window.t('forms.field.options_dropdown')
                                    : f.field_type === 'radio'    ? window.t('forms.field.options_radio')
                                    :                               window.t('forms.field.options_checkbox');
                    optionsHtml = `
                        <div class="field-options">
                            <div class="field-options-label">${optsLabel}</div>
                            ${(f.options || []).map((opt, oi) => `
                                <div class="option-item" draggable="true"
                                     ondragstart="onOptDragStart(event, ${i}, ${oi})"
                                     ondragend="onOptDragEnd(event)"
                                     ondragover="onOptDragOver(event, ${i}, ${oi})"
                                     ondrop="onOptDrop(event, ${i}, ${oi})">
                                    <span class="option-drag" title="${escAttr(window.t('forms.field.drag_reorder'))}">⠿</span>
                                    <input type="text" value="${esc(opt)}"
                                           onchange="updateOption(${i}, ${oi}, this.value)"
                                           onkeydown="onOptionKeydown(event, ${i}, ${oi})"
                                           placeholder="${escAttr(window.t('forms.field.option_ph', { n: oi + 1 }))}">
                                    <button class="option-remove" onclick="removeOption(${i}, ${oi})">&times;</button>
                                </div>
                            `).join('')}
                            <button class="add-option-btn" onclick="addOption(${i})">${esc(window.t('forms.field.add_option'))}</button>
                        </div>`;
                }
                // What this date field actually asks for. Sits with the field rather
                // than in the add menu, so it stays changeable.
                let dateModeHtml = '';
                if (isDateField(f.field_type)) {
                    const mode = dateModeOf(f);
                    dateModeHtml = `
                        <div class="field-options">
                            <div class="field-options-label">${esc(window.t('forms.field.date_mode'))}</div>
                            <div class="date-mode-row">
                                ${DATE_MODES.map(m => `
                                    <label class="date-mode-opt">
                                        <input type="radio" name="dm_${i}" value="${m}" ${mode === m ? 'checked' : ''}
                                               onchange="setDateMode(${i}, this.value)">
                                        ${esc(window.t('forms.field.date_mode_' + m))}
                                    </label>`).join('')}
                            </div>
                        </div>`;
                }

                // Which records this lookup searches, and whether a customer may
                // use it. Both live with the field so they stay changeable.
                let lookupHtml = '';
                if (isLookupField(f.field_type)) {
                    const src = lookupSourceOf(f);
                    const meta = LOOKUP_SOURCES.find(x => x.key === src);
                    lookupHtml = `
                        <div class="field-options">
                            <div class="field-options-label">${esc(window.t('forms.field.lookup_source'))}</div>
                            <select onchange="setLookupSource(${i}, this.value)">
                                <option value="">${esc(window.t('forms.field.lookup_pick'))}</option>
                                ${LOOKUP_SOURCES.map(o => `<option value="${o.key}" ${src === o.key ? 'selected' : ''}>`
                                    + esc(window.t('forms.field.lookup_src_' + o.key)) + `</option>`).join('')}
                            </select>
                            ${meta && meta.portalSafe ? `
                                <label class="field-required-toggle" style="margin-top:8px;">
                                    <input type="checkbox" ${f.config && f.config.portal_lookup ? 'checked' : ''}
                                           onchange="setLookupPortal(${i}, this.checked)">
                                    ${esc(window.t('forms.field.lookup_portal'))}
                                </label>` : ''}
                        </div>`;
                }

                // A heading has no answer, so "required" would mean nothing — the
                // server rejects it outright, and the toggle is left off here.
                const requiredToggle = isSection(f.field_type) ? '' : `
                                <label class="field-required-toggle">
                                    <input type="checkbox" ${f.is_required ? 'checked' : ''} onchange="toggleRequired(${i}, this.checked)">
                                    ${esc(window.t('forms.field.required'))}
                                </label>`;

                return `
                    <li class="field-item${isSection(f.field_type) ? ' field-item-section' : ''}" data-index="${i}" draggable="true"
                        ondragstart="onFieldDragStart(event, ${i})"
                        ondragend="onFieldDragEnd(event)"
                        ondragover="onFieldDragOver(event, ${i})"
                        ondrop="onFieldDrop(event, ${i})">
                        <div class="field-item-header">
                            <span class="field-drag" title="${escAttr(window.t('forms.field.drag_reorder'))}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                            </span>
                            <span class="field-type-badge ${f.field_type}">${isDateField(f.field_type) ? esc(window.t('forms.typename.date_' + dateModeOf(f))) : typeName(f.field_type)}</span>
                            <input type="text" class="field-label-input" value="${esc(f.label)}" placeholder="${escAttr(isSection(f.field_type) ? window.t('forms.field.section_ph') : window.t('forms.field.label_ph'))}" onchange="updateLabel(${i}, this.value)">
                            <div class="field-controls">
                                ${requiredToggle}
                                <button class="field-delete-btn" onclick="deleteField(${i})" title="${escAttr(window.t('forms.field.remove_field'))}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </div>
                        </div>
                        ${optionsHtml}
                        ${dateModeHtml}${lookupHtml}
                        ${renderConditionEditor(f, i)}
                    </li>`;
            }).join('');
        }
        function typeName(type) {
            const known = ['text', 'textarea', 'checkbox', 'dropdown', 'email', 'number', 'checkboxes', 'radio', 'datetime', 'lookup', 'section'];
            return known.includes(type) ? window.t('forms.typename.' + type) : type;
        }
        function updateLabel(i, val)    { fields[i].label = val;       markDirty(); updatePreview(); }
        function toggleRequired(i, val) { fields[i].is_required = val; markDirty(); updatePreview(); }
        // #1290 — Ed: "if you delete something it would be good if there was a
        // confirm box". Nothing is committed until Save, which is why there was
        // no confirmation here; but a mis-tap still loses the field's label,
        // its options and its visibility rule, and on a phone the delete icon
        // sits a few pixels from the Required checkbox. The dialog says both of
        // the things that make it safe to answer: existing answers are KEPT
        // (save_form retires a removed field rather than deleting it), and
        // nothing is written until you save.
        async function deleteField(i) {
            const goneKey = fields[i]._key;
            const label   = (fields[i].label || '').trim() || window.t('forms.field.untitled_field');

            // Count the rules on OTHER fields that point at this one. They are
            // silently dropped below, and until now nothing ever said so —
            // which is the part of this action a person cannot see coming.
            let refs = 0;
            fields.forEach((f, fi) => {
                if (fi === i) return;
                const vif = f.config && f.config.visible_if;
                if (!vif || !vif.rules) return;
                refs += vif.rules.filter(r => r._ref === goneKey).length;
            });

            const message = refs === 0
                ? window.t('forms.field.remove_confirm_message', { label })
                : window.t(refs === 1 ? 'forms.field.remove_confirm_refs'
                                      : 'forms.field.remove_confirm_refs_plural', { label, n: refs });

            const ok = await showConfirm({
                title:   window.t('forms.field.remove_confirm_title'),
                message: message,
                okLabel: window.t('forms.field.remove_confirm_ok'),
                okClass: 'danger'
            });
            if (!ok) return;

            fields.splice(i, 1);
            // Drop any condition that pointed at it, so nothing is left referring to a
            // question that is no longer on the form.
            fields.forEach(f => {
                const vif = f.config && f.config.visible_if;
                if (!vif) return;
                vif.rules = vif.rules.filter(r => r._ref !== goneKey);
                if (!vif.rules.length) delete f.config.visible_if;
            });
            markDirty(); renderFields(); updatePreview();
        }
        function addOption(fi) {
            fields[fi].options.push('');
            markDirty(); renderFields();
            setTimeout(() => {
                const items = document.querySelectorAll(`.field-item[data-index="${fi}"] .option-item input[type="text"]`);
                if (items.length) items[items.length - 1].focus();
            }, 50);
        }
        function updateOption(fi, oi, val) {
            fields[fi].options[oi] = val;
            markDirty(); updatePreview();
        }
        // #1290 — Ed asked for the confirm on dropdown options too.
        // ⚠️ NOT on a blank one. Adding an option creates an empty row, and
        // "+ Add option" then "×" is a normal thing to do a second after
        // doing it by accident — a dialog there is a nag guarding nothing,
        // and a dialog people learn to dismiss without reading stops
        // protecting the case that matters. An option with text typed into
        // it is the one worth asking about.
        async function removeOption(fi, oi) {
            const opt = (fields[fi].options[oi] || '').trim();
            if (opt) {
                const ok = await showConfirm({
                    title:   window.t('forms.field.remove_option_confirm_title'),
                    message: window.t('forms.field.remove_option_confirm_message', { label: opt }),
                    okLabel: window.t('forms.field.remove_confirm_ok'),
                    okClass: 'danger'
                });
                if (!ok) return;
            }
            fields[fi].options.splice(oi, 1);
            markDirty(); renderFields(); updatePreview();
        }
        function onOptionKeydown(e, fi, oi) {
            if (e.key === 'Enter') {
                e.preventDefault();
                fields[fi].options[oi] = e.target.value;
                addOption(fi);
            }
        }

        // ===== Field drag & drop =====
        let dragFieldIndex = null;
        function onFieldDragStart(e, i) {
            if (!fieldDragAllowed) { e.preventDefault(); return; }
            dragFieldIndex = i;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'field');
            requestAnimationFrame(() => {
                const item = document.querySelector(`.field-item[data-index="${i}"]`);
                if (item) item.classList.add('dragging');
            });
        }
        function onFieldDragEnd(e) {
            dragFieldIndex = null;
            document.querySelectorAll('.field-item').forEach(el => {
                el.classList.remove('dragging', 'drag-over-top', 'drag-over-bottom');
            });
        }
        function onFieldDragOver(e, i) {
            if (dragFieldIndex === null || dragFieldIndex === i) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            document.querySelectorAll('.field-item').forEach(el => {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            e.currentTarget.classList.add(e.clientY < midY ? 'drag-over-top' : 'drag-over-bottom');
        }
        function onFieldDrop(e, i) {
            e.preventDefault();
            if (dragFieldIndex === null || dragFieldIndex === i) return;
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            let targetIndex = e.clientY < midY ? i : i + 1;
            if (dragFieldIndex < targetIndex) targetIndex--;
            const [moved] = fields.splice(dragFieldIndex, 1);
            fields.splice(targetIndex, 0, moved);
            dragFieldIndex = null;
            pruneInvalidConditions();
            markDirty(); renderFields(); updatePreview();
        }

        /**
         * Drop any condition that now points at a LATER question.
         *
         * Dragging is the only way to create one: a condition can only ever be built
         * against an earlier field, but moving either field can invert that. The
         * server rejects a forwards reference, so without this a reorder would fail
         * to save with a message about a question the user wasn't thinking about.
         * Dropping it is the honest outcome — the rule genuinely can't be answered
         * before it is needed.
         */
        function pruneInvalidConditions() {
            const posByKey = {};
            fields.forEach((f, i) => { posByKey[f._key] = i; });
            let dropped = 0;
            fields.forEach((f, i) => {
                const vif = f.config && f.config.visible_if;
                if (!vif) return;
                const before = vif.rules.length;
                vif.rules = vif.rules.filter(r => {
                    const pos = posByKey[r._ref];
                    return pos !== undefined && pos < i;
                });
                dropped += before - vif.rules.length;
                if (!vif.rules.length) delete f.config.visible_if;
            });
            if (dropped) showToast(window.t('forms.cond.dropped_forward', { count: dropped }), 'error');
        }

        // ===== Option drag & drop =====
        let dragOptFieldIndex = null;
        let dragOptIndex = null;
        function onOptDragStart(e, fi, oi) {
            if (!optDragAllowed) { e.preventDefault(); return; }
            e.stopPropagation();
            dragOptFieldIndex = fi;
            dragOptIndex = oi;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'option');
            requestAnimationFrame(() => e.currentTarget.classList.add('dragging'));
        }
        function onOptDragEnd(e) {
            dragOptFieldIndex = null;
            dragOptIndex = null;
            document.querySelectorAll('.option-item').forEach(el => {
                el.classList.remove('dragging', 'drag-over-top', 'drag-over-bottom');
            });
        }
        function onOptDragOver(e, fi, oi) {
            if (dragOptFieldIndex !== fi || dragOptIndex === null || dragOptIndex === oi) return;
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            e.currentTarget.closest('.field-options').querySelectorAll('.option-item').forEach(el => {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            e.currentTarget.classList.add(e.clientY < midY ? 'drag-over-top' : 'drag-over-bottom');
        }
        function onOptDrop(e, fi, oi) {
            e.preventDefault();
            e.stopPropagation();
            if (dragOptFieldIndex !== fi || dragOptIndex === null || dragOptIndex === oi) return;
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            let targetIndex = e.clientY < midY ? oi : oi + 1;
            if (dragOptIndex < targetIndex) targetIndex--;
            const opts = fields[fi].options;
            const [moved] = opts.splice(dragOptIndex, 1);
            opts.splice(targetIndex, 0, moved);
            dragOptFieldIndex = null;
            dragOptIndex = null;
            markDirty(); renderFields(); updatePreview();
        }

        // ===== Preview =====
        function updatePreview() {
            const title = document.getElementById('formTitle').value || window.t('forms.preview.untitled_form');
            const desc = document.getElementById('formDesc').value;
            const preview = document.getElementById('previewContent');
            if (fields.length === 0) {
                preview.innerHTML = '<p class="preview-empty">' + esc(window.t('forms.editor.preview_empty')) + '</p>';
                return;
            }
            const alignClass = 'align-' + logoAlignment;
            let html = `<img src="<?php echo htmlspecialchars(brandingLogoUrl()); ?>" alt="${escAttr(window.t('forms.preview.logo_alt'))}" class="preview-logo ${alignClass}">`;
            html += `<p class="preview-title">${esc(title)}</p>`;
            if (desc) html += `<p class="preview-desc">${esc(desc)}</p>`;
            html += fields.map(f => {
                const reqStar = f.is_required ? '<span class="required-star">*</span>' : '';
                const label = esc(f.label || window.t('forms.field.untitled_field'));
                // Conditional fields are SHOWN here, with a marker, rather than
                // evaluated. Nothing has been answered in a preview, so every
                // condition would be false and the form would look broken — the
                // author needs to see what they have built, not an empty page.
                const condFlag = (f.config && f.config.visible_if && f.config.visible_if.rules.length)
                    ? `<span class="preview-cond" title="${escAttr(window.t('forms.preview.conditional_hint'))}">${esc(window.t('forms.preview.conditional'))}</span>` : '';
                switch (f.field_type) {
                    case 'section':
                        return `<div class="preview-section"><h3>${label}</h3>${condFlag}</div>`;
                    case 'text':
                        return `<div class="preview-field"><label>${label}${reqStar}${condFlag}</label><input type="text" disabled placeholder="${escAttr(window.t('forms.preview.text_ph'))}"></div>`;
                    case 'textarea':
                        return `<div class="preview-field"><label>${label}${reqStar}${condFlag}</label><textarea disabled placeholder="${escAttr(window.t('forms.preview.textarea_ph'))}"></textarea></div>`;
                    case 'email':
                        return `<div class="preview-field"><label>${label}${reqStar}${condFlag}</label><input type="email" disabled placeholder="${escAttr(window.t('forms.preview.email_ph'))}"></div>`;
                    case 'number':
                        return `<div class="preview-field"><label>${label}${reqStar}${condFlag}</label><input type="number" disabled placeholder="${escAttr(window.t('forms.preview.number_ph'))}"></div>`;
                    case 'lookup':
                        // Preview only — the real control searches live records.
                        html += `<input type="text" disabled placeholder="${esc(window.t('forms.fill.lookup_placeholder'))}">`;
                        break;
                    case 'datetime': {
                        const m = dateModeOf(f);
                        const t = m === 'time' ? 'time' : (m === 'datetime' ? 'datetime-local' : 'date');
                        return `<div class="preview-field"><label>${label}${reqStar}${condFlag}</label><input type="${t}" disabled></div>`;
                    }
                    case 'checkbox':
                        return `<div class="preview-field"><div class="checkbox-row"><input type="checkbox" disabled> <label>${label}${reqStar}${condFlag}</label></div></div>`;
                    case 'dropdown': {
                        const opts = (f.options || []).filter(o => o).map(o => `<option>${esc(o)}</option>`).join('');
                        return `<div class="preview-field"><label>${label}${reqStar}${condFlag}</label><select disabled><option value="">${esc(window.t('forms.preview.select_ph'))}</option>${opts}</select></div>`;
                    }
                    case 'radio': {
                        const items = (f.options || []).filter(o => o).map(o =>
                            `<div class="checkbox-row"><input type="radio" disabled> <label>${esc(o)}</label></div>`).join('');
                        return `<div class="preview-field"><label>${label}${reqStar}${condFlag}</label>${items || '<small style="color:var(--text-faint, #999)">' + esc(window.t('forms.preview.no_options')) + '</small>'}</div>`;
                    }
                    case 'checkboxes': {
                        const items = (f.options || []).filter(o => o).map(o =>
                            `<div class="checkbox-row"><input type="checkbox" disabled> <label>${esc(o)}</label></div>`).join('');
                        return `<div class="preview-field"><label>${label}${reqStar}${condFlag}</label>${items || '<small style="color:var(--text-faint, #999)">' + esc(window.t('forms.preview.no_options')) + '</small>'}</div>`;
                    }
                    default:
                        return '';
                }
            }).join('');
            preview.innerHTML = html;
        }

        // ===== Save =====
        // Returns a Promise<boolean> indicating success — callers like
        // createNewVersion() chain a save before forking so the new
        // version snapshot reflects exactly what the user sees.
        async function saveForm() {
            const title = document.getElementById('formTitle').value.trim();
            if (!title) { showToast(window.t('forms.save.need_title'), 'error'); return false; }
            const validFields = fields.filter(f => f.label.trim());
            if (validFields.length === 0) {
                showToast(window.t('forms.save.need_field'), 'error');
                return false;
            }
            const payload = {
                title: title,
                description: document.getElementById('formDesc').value.trim(),
                fields: validFields.map(f => ({
                    // THE important line. Sending the id back is what lets the server
                    // update this exact field instead of whatever now sits in this
                    // position — which is how a reorder used to re-point every
                    // historical answer at the wrong question. null = a new field.
                    id: f.id || null,
                    field_type: f.field_type,
                    label: f.label.trim(),
                    // hasOptions() covers dropdown + radio + checkboxes
                    // (the legacy single 'checkbox' has no options).
                    // Without this the new types from #441 would lose
                    // their options on save.
                    options: hasOptions(f.field_type) ? f.options.filter(o => o && o.trim()) : null,
                    is_required: (f.is_required && !isSection(f.field_type)) ? 1 : 0,
                    config: buildRulesForSave(f, validFields)
                }))
            };
            if (currentFormId) payload.id = currentFormId;
            try {
                const res = await fetch(API_BASE + 'save_form.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    if (!currentFormId) {
                        currentFormId = data.form_id;
                        // Promote the URL from /forms/edit/ to /forms/edit/?id=N
                        // so a refresh keeps the user in the same form.
                        history.replaceState(null, '', './?id=' + currentFormId);
                        document.getElementById('editorTitle').textContent = window.t('forms.editor.title_edit');
                    }
                    clearDirty();
                    showToast(window.t('forms.toast.form_saved'));
                    // Reload so the version pill / modified-by reflect
                    // the latest values from the DB.
                    loadFormForEdit(currentFormId);
                    return true;
                }
                showToast(window.t('forms.toast.error_prefix', { message: data.error }), 'error');
                return false;
            } catch (e) {
                showToast(window.t('forms.toast.save_failed'), 'error');
                return false;
            }
        }

        // ===== Settings (logo alignment for preview) =====
        async function loadSettings() {
            try {
                const res = await fetch(API_BASE + 'get_settings.php');
                const data = await res.json();
                if (data.success && data.settings) {
                    logoAlignment = data.settings.logo_alignment || 'center';
                }
            } catch (e) {
                // Defaults stand
            }
        }

        // ===== Utility =====
        function esc(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ===== AI Assist (streaming SSE) =====
        // Two flavours, switched at modal-open time based on whether
        // there's already a form to modify:
        //  - NEW mode: user describes the form they want, AI builds
        //    it from scratch.
        //  - EDIT mode: user describes a CHANGE, AI receives the
        //    current form as context and returns an updated copy.
        // The backend (ai_generate.php) keys off whether current_form
        // is sent in the payload — see #439.
        let aiAbortController = null;

        // True when there's enough form content to treat the AI call
        // as an edit rather than a fresh build.
        function isEditingExistingForm() {
            const hasFields = fields.length > 0;
            const hasTitle  = (document.getElementById('formTitle').value || '').trim() !== '';
            return hasFields || hasTitle;
        }

        // Suggested-prompt lists shown inside the modal. Swapped at
        // open-time so the user sees relevant ideas for the mode
        // they're in.
        const AI_EXAMPLES_NEW = [
            { label: window.t('forms.ai.ex_new1_label'), text: window.t('forms.ai.ex_new1_text') },
            { label: window.t('forms.ai.ex_new2_label'), text: window.t('forms.ai.ex_new2_text') },
            { label: window.t('forms.ai.ex_new3_label'), text: window.t('forms.ai.ex_new3_text') },
        ];
        const AI_EXAMPLES_EDIT = [
            { label: window.t('forms.ai.ex_edit1_label'), text: window.t('forms.ai.ex_edit1_text') },
            { label: window.t('forms.ai.ex_edit2_label'), text: window.t('forms.ai.ex_edit2_text') },
            { label: window.t('forms.ai.ex_edit3_label'), text: window.t('forms.ai.ex_edit3_text') },
            { label: window.t('forms.ai.ex_edit4_label'), text: window.t('forms.ai.ex_edit4_text') },
            { label: window.t('forms.ai.ex_edit5_label'), text: window.t('forms.ai.ex_edit5_text') },
        ];

        function openAiModal() {
            const editing = isEditingExistingForm();

            // Toggle modal copy to match the mode.
            document.getElementById('aiModalTitle').innerHTML = '<span class="ai-sparkle">&#10024;</span> ' + escHtml(editing
                ? window.t('forms.ai.title_edit')
                : window.t('forms.ai.title_new'));
            document.getElementById('aiPromptLabel').textContent = editing
                ? window.t('forms.ai.prompt_edit')
                : window.t('forms.ai.prompt_new');
            const ta = document.getElementById('aiDescription');
            ta.placeholder = editing
                ? window.t('forms.ai.ta_ph_edit')
                : window.t('forms.ai.ta_ph_new');
            document.getElementById('aiHint').textContent = editing
                ? window.t('forms.ai.hint_edit')
                : window.t('forms.ai.hint_new');

            // Swap the suggested-prompt list.
            const list = document.getElementById('aiExamplesList');
            const examples = editing ? AI_EXAMPLES_EDIT : AI_EXAMPLES_NEW;
            list.innerHTML = examples.map(e =>
                `<li class="ai-example" data-text="${escAttr(e.text)}">${escHtml(e.label)}</li>`
            ).join('');
            // (Re-)wire each example. We re-bind on every open because
            // the list contents change between modes.
            document.querySelectorAll('.ai-example').forEach(el => {
                el.addEventListener('click', () => {
                    document.getElementById('aiDescription').value = el.dataset.text || '';
                    document.getElementById('aiDescription').focus();
                });
            });

            document.getElementById('aiModal').classList.add('active');
            ta.value = '';
            setTimeout(() => ta.focus(), 50);
            resetAiProgress();
        }

        // Small attribute escape helper for the example data-text values
        // (the existing esc() is fine for inner-text but we need quote
        // escaping for attribute values).
        function escAttr(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }
        function escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }
        function closeAiModal() {
            if (aiAbortController) { aiAbortController.abort(); aiAbortController = null; }
            document.getElementById('aiModal').classList.remove('active');
        }
        function resetAiProgress() {
            const prog = document.getElementById('aiProgress');
            prog.style.display = 'none';
            prog.classList.remove('error');
            document.getElementById('aiStream').textContent = '';
            document.getElementById('aiStatus').textContent = '';
            document.getElementById('aiTokensIn').textContent = '0';
            document.getElementById('aiTokensOut').textContent = '0';
            document.getElementById('aiCacheRead').textContent = '0';
            document.getElementById('aiFieldCount').textContent = '0';
            // Hide the proposal panel + reset buttons to default state
            const prop = document.getElementById('aiProposal');
            if (prop) prop.style.display = 'none';
            const applyBtn = document.getElementById('aiApplyBtn');
            const genBtn   = document.getElementById('aiGenerateBtn');
            if (applyBtn) applyBtn.style.display = 'none';
            if (genBtn)   { genBtn.style.display = ''; genBtn.disabled = false; }
            aiProposedForm = null;
        }

        // Holds the AI's most recent proposal so the Apply button can
        // commit it without making another request. Cleared by
        // resetAiProgress (on modal open/close) and after Apply.
        let aiProposedForm = null;

        // Render a summary of what the AI is proposing — title, field
        // count, brief field-by-field diff against the current form so
        // the user can see at-a-glance whether the change matches what
        // they asked for. User must click Apply to commit.
        function showAiProposal(proposedForm, editing, seconds) {
            aiProposedForm = proposedForm;
            const prop = document.getElementById('aiProposal');
            const summary = document.getElementById('aiProposalSummary');
            const fieldsCount = (proposedForm.fields || []).length;
            const fw = fieldsCount === 1 ? window.t('forms.ai.prop_field') : window.t('forms.ai.prop_fields');

            // Build a tiny diff view: list each proposed field with a
            // colour-coded badge (added / changed / unchanged / removed
            // compared to the current state).
            const currentByLabel = new Map();
            if (editing) {
                fields.forEach(f => currentByLabel.set((f.label || '').trim().toLowerCase(), f));
            }
            const seen = new Set();
            const rows = (proposedForm.fields || []).map(f => {
                const labelKey = (f.label || '').trim().toLowerCase();
                seen.add(labelKey);
                const cur = currentByLabel.get(labelKey);
                let badge, badgeBg;
                if (!editing) { badge = window.t('forms.ai.badge_new'); badgeBg = '#3b82f6'; }
                else if (!cur) { badge = window.t('forms.ai.badge_added'); badgeBg = '#16a34a'; }
                else if (cur.field_type !== f.field_type || !!cur.is_required !== !!f.is_required) {
                    badge = window.t('forms.ai.badge_changed'); badgeBg = '#f59e0b';
                } else {
                    badge = window.t('forms.ai.badge_unchanged'); badgeBg = '#94a3b8';
                }
                return `<li>
                    <span class="ai-prop-badge" style="background:${badgeBg};">${escHtml(badge)}</span>
                    <strong>${escHtml(f.label || window.t('forms.field.untitled_field'))}</strong>
                    <span class="ai-prop-meta">${escHtml(typeName(f.field_type))}${f.is_required ? ' · ' + escHtml(window.t('forms.ai.prop_required')) : ''}</span>
                </li>`;
            });
            // Removed fields — present in the current form but not in
            // the proposal.
            if (editing) {
                fields.forEach(f => {
                    const labelKey = (f.label || '').trim().toLowerCase();
                    if (!seen.has(labelKey)) {
                        rows.push(`<li>
                            <span class="ai-prop-badge" style="background:#dc2626;">${escHtml(window.t('forms.ai.badge_removed'))}</span>
                            <strong>${escHtml(f.label || window.t('forms.field.untitled_field'))}</strong>
                            <span class="ai-prop-meta">${escHtml(typeName(f.field_type))}</span>
                        </li>`);
                    }
                });
            }

            const propHead = window.t('forms.ai.prop_head', {
                what: editing ? window.t('forms.ai.prop_head_change') : window.t('forms.ai.prop_head_new'),
                seconds: seconds
            });
            const propCount = window.t('forms.ai.prop_count', { count: fieldsCount, fields: fw });
            summary.innerHTML = `
                <div class="ai-prop-head">
                    ${escHtml(propHead)}
                    <strong>${escHtml(proposedForm.title || window.t('forms.preview.untitled_form'))}</strong>
                    ${escHtml(propCount)}
                </div>
                <ul class="ai-prop-list">${rows.join('')}</ul>
                <div class="ai-prop-note">${window.t('forms.ai.prop_note')}</div>
            `;
            prop.style.display = 'block';
            // Swap buttons: hide Generate, show Apply
            document.getElementById('aiGenerateBtn').style.display = 'none';
            const applyBtn = document.getElementById('aiApplyBtn');
            applyBtn.style.display = '';
            applyBtn.disabled = false;
            applyBtn.focus();
        }

        // Commit the previously-proposed form. Only enabled after the
        // AI's done event populates aiProposedForm via showAiProposal().
        function applyProposedForm() {
            if (!aiProposedForm) return;
            applyGeneratedForm(aiProposedForm);
            switchFormTab('preview');
            closeAiModal();
            showToast(isEditingExistingForm() ? window.t('forms.toast.form_updated') : window.t('forms.toast.form_built'), 'success');
            aiProposedForm = null;
        }
        async function runAiGeneration() {
            const description = document.getElementById('aiDescription').value.trim();
            const editing = isEditingExistingForm();
            if (!description) {
                showToast(editing ? window.t('forms.ai.need_change') : window.t('forms.ai.need_describe'), 'error');
                return;
            }
            if (description.length > 2000) {
                showToast(window.t('forms.ai.too_long'), 'error');
                return;
            }
            // No destructive-replace warning in edit mode — the backend
            // (ai_generate.php #439) preserves the existing form and
            // applies the user's modification, so this isn't a nuke.
            // For brand-new forms there's nothing to lose either.

            const generateBtn = document.getElementById('aiGenerateBtn');
            generateBtn.disabled = true;
            const prog = document.getElementById('aiProgress');
            prog.style.display = 'block';
            prog.classList.remove('error');
            const status = document.getElementById('aiStatus');
            const stream = document.getElementById('aiStream');
            stream.textContent = '';
            status.textContent = editing ? window.t('forms.ai.status_applying') : window.t('forms.ai.status_designing');
            aiAbortController = new AbortController();

            // Snapshot the current form to send as context when editing.
            // Same shape as the request payload we send to save_form so
            // the AI sees clean, normalised data.
            const payload = { description: description };
            if (editing) {
                payload.current_form = {
                    title:       document.getElementById('formTitle').value.trim(),
                    description: document.getElementById('formDesc').value.trim(),
                    fields:      fields.map(f => ({
                        field_type:  f.field_type,
                        label:       (f.label || '').trim(),
                        options:     f.field_type === 'dropdown' ? (f.options || []).filter(o => o && o.trim()) : [],
                        is_required: !!f.is_required,
                    })),
                };
            }

            try {
                const resp = await fetch(API_BASE + 'ai_generate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                    signal: aiAbortController.signal
                });
                if (!resp.body) throw new Error(window.t('forms.ai.streaming_unsupported'));
                const reader = resp.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let acc = '';
                let detectedFields = 0;
                const handleEvent = (eventName, dataStr) => {
                    if (!dataStr) return;
                    let data;
                    try { data = JSON.parse(dataStr); } catch (e) { return; }
                    switch (eventName) {
                        case 'text': {
                            const delta = data.delta || '';
                            acc += delta;
                            stream.textContent = acc;
                            stream.scrollTop = stream.scrollHeight;
                            const matches = acc.match(/"field_type"\s*:/g);
                            const newCount = matches ? matches.length : 0;
                            if (newCount !== detectedFields) {
                                detectedFields = newCount;
                                document.getElementById('aiFieldCount').textContent = String(detectedFields);
                            }
                            break;
                        }
                        case 'usage':
                            if (data.tokens_in != null)  document.getElementById('aiTokensIn').textContent  = String(data.tokens_in);
                            if (data.tokens_out != null) document.getElementById('aiTokensOut').textContent = String(data.tokens_out);
                            if (data.cache_read != null) document.getElementById('aiCacheRead').textContent  = String(data.cache_read);
                            break;
                        case 'done': {
                            // Don't auto-apply — show a preview of what
                            // changed and let the user click Apply. This
                            // is the "nuked without warning" safety net
                            // from #440: the user always sees the
                            // proposed form before it lands in the
                            // editor, and can Discard if it's wrong.
                            const seconds = data.duration_ms ? (data.duration_ms / 1000).toFixed(1) : '?';
                            showAiProposal(data.form, editing, seconds);
                            break;
                        }
                        case 'error':
                            throw new Error(data.message || window.t('forms.ai.request_failed'));
                    }
                };
                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    let idx;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        const block = buffer.slice(0, idx);
                        buffer = buffer.slice(idx + 2);
                        let eventName = '';
                        let dataStr = '';
                        for (const line of block.split('\n')) {
                            if (line.startsWith('event: ')) eventName = line.slice(7).trim();
                            else if (line.startsWith('data: ')) dataStr += line.slice(6);
                        }
                        if (eventName) handleEvent(eventName, dataStr);
                    }
                }
            } catch (err) {
                if (err.name === 'AbortError') {
                    // user cancelled
                } else {
                    prog.classList.add('error');
                    document.getElementById('aiStatus').textContent = window.t('forms.ai.error_status', { message: err.message });
                    showToast(window.t('forms.ai.failed', { message: err.message }), 'error');
                }
            } finally {
                generateBtn.disabled = false;
                aiAbortController = null;
            }
        }
        /**
         * The author's existing config wins on everything except date_mode, which only
         * the generator has an opinion about. Returns null rather than an empty object
         * so a field with nothing to say still stores NULL config.
         */
        function mergeGeneratedConfig(prevConfig, generated) {
            const out = Object.assign({}, prevConfig || {});
            if (generated.field_type === 'datetime') {
                const m = generated.config && generated.config.date_mode;
                out.date_mode = DATE_MODES.includes(m) ? m : (out.date_mode || 'date');
            } else {
                delete out.date_mode;
            }
            return Object.keys(out).length ? out : null;
        }

        function applyGeneratedForm(form) {
            document.getElementById('formTitle').value = form.title || '';
            document.getElementById('formDesc').value  = form.description || '';

            // Carry existing field ids across by label, the same way the proposal diff
            // decides what counts as "unchanged". Without this, asking the AI to tweak
            // a live form would hand back a list with no ids — and the server would
            // read that as "retire every question and create new ones", quietly
            // detaching the form from every answer already given to it.
            const prevByLabel = new Map();
            const prevByKey   = new Map();
            fields.forEach(f => {
                const key = (f.label || '').trim().toLowerCase();
                if (key) prevByLabel.set(key, f);
                prevByKey.set(f._key, f);
            });

            fields = (form.fields || []).map(f => {
                const prev = prevByLabel.get((f.label || '').trim().toLowerCase());
                return {
                    id:          prev ? prev.id : null,
                    _key:        newFieldKey(),
                    field_type:  f.field_type || 'text',
                    label:       f.label || '',
                    options:     Array.isArray(f.options) ? f.options.slice() : [],
                    is_required: !!f.is_required,
                    // The generator doesn't propose conditions, so a field it kept keeps
                    // the rule the author had already put on it. It DOES propose a
                    // date_mode, which has to survive onto the field or every generated
                    // date question would silently fall back to a plain date.
                    config:      mergeGeneratedConfig(prev ? prev.config : null, f)
                };
            });

            // Every field object was just replaced, so a carried-over rule's _ref
            // points at a _key that no longer exists. Re-point it via the LABEL of the
            // field it used to mean, which works for rules loaded from the server and
            // for ones the author added in this session but hasn't saved yet.
            const newKeyByLabel = new Map();
            fields.forEach(f => {
                const key = (f.label || '').trim().toLowerCase();
                if (key) newKeyByLabel.set(key, f._key);
            });
            fields.forEach(f => {
                const vif = f.config && f.config.visible_if;
                if (!vif) return;
                vif.rules = vif.rules
                    .map(r => {
                        const oldTrigger = prevByKey.get(r._ref);
                        const label = oldTrigger ? (oldTrigger.label || '').trim().toLowerCase() : '';
                        return Object.assign({}, r, { _ref: newKeyByLabel.get(label) });
                    })
                    .filter(r => r._ref !== undefined);   // the AI removed the trigger
                if (!vif.rules.length) delete f.config.visible_if;
            });

            pruneInvalidConditions();
            renderFields();
            updatePreview();
            markDirty();
        }
    </script>

    <!-- AI Assist Modal — copy + examples swap between New and Edit
         modes when the modal opens (see openAiModal). -->
    <div class="ai-modal-overlay" id="aiModal">
        <div class="ai-modal">
            <div class="ai-modal-header">
                <h3 id="aiModalTitle"><span class="ai-sparkle">&#10024;</span> <?php echo htmlspecialchars(t('forms.ai.title_new')); ?></h3>
            </div>
            <div class="ai-modal-body">
                <label for="aiDescription" id="aiPromptLabel"><?php echo htmlspecialchars(t('forms.ai.prompt_new')); ?></label>
                <textarea id="aiDescription"></textarea>
                <div class="ai-hint" id="aiHint"><?php echo htmlspecialchars(t('forms.ai.hint_new')); ?></div>

                <div class="ai-examples">
                    <strong><?php echo htmlspecialchars(t('forms.ai.try')); ?></strong>
                    <ul id="aiExamplesList"></ul>
                </div>

                <div class="ai-progress" id="aiProgress" style="display:none;">
                    <div class="ai-progress-status">
                        <div class="ai-spinner"></div>
                        <span id="aiStatus"><?php echo htmlspecialchars(t('forms.ai.status_designing')); ?></span>
                    </div>
                    <div class="ai-progress-counters">
                        <span><?php echo htmlspecialchars(t('forms.ai.fields_detected')); ?> <strong id="aiFieldCount">0</strong></span>
                        <span><?php echo htmlspecialchars(t('forms.ai.tokens_in')); ?> <strong id="aiTokensIn">0</strong></span>
                        <span><?php echo htmlspecialchars(t('forms.ai.tokens_out')); ?> <strong id="aiTokensOut">0</strong></span>
                        <span><?php echo htmlspecialchars(t('forms.ai.cached')); ?> <strong id="aiCacheRead">0</strong></span>
                    </div>
                    <pre class="ai-stream" id="aiStream"></pre>
                </div>

                <!-- Proposal panel — populated by showAiProposal() once
                     the AI's done event arrives. Nothing touches the
                     editor until the user clicks Apply (#440). -->
                <div class="ai-proposal" id="aiProposal" style="display:none;">
                    <div id="aiProposalSummary"></div>
                </div>
            </div>
            <div class="ai-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAiModal()"><?php echo htmlspecialchars(t('forms.ai.cancel')); ?></button>
                <button type="button" class="btn btn-ai-assist" id="aiGenerateBtn" onclick="runAiGeneration()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.9 5.8L20 10l-5.8 1.9L12 18l-1.9-5.8L4 10l6.1-2.2z"></path></svg>
                    <?php echo htmlspecialchars(t('forms.ai.generate')); ?>
                </button>
                <button type="button" class="btn btn-primary" id="aiApplyBtn" onclick="applyProposedForm()" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <?php echo htmlspecialchars(t('forms.ai.apply')); ?>
                </button>
            </div>
        </div>
    </div>
    <!-- Mobile layer. Adds the views hamburger and the module drawer on a phone.
         Loaded last so it can wrap the page's own globals rather than edit them. -->
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=52"></script>
</body>
</html>
