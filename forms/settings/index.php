<?php
/**
 * Forms Settings - Configure forms module settings
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/branding.php';   // the organisation's logo (GH #87)
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/ai_settings_panel.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}
require_once '../../includes/settings_manifest.php';
requireModuleAccess('forms');

// RBAC Layer 2: only the tabs this analyst may see are rendered.
$settingsManifest = settingsManifestFor('forms');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);

/* Honour ?tab=, so "Back" from a collection's submissions returns to the
   Collections tab rather than dumping you on Layout. Validated against the
   VISIBLE tabs, not just the manifest — a tab this analyst has no capability
   for is never rendered, and asking for it must not select nothing. */
if (!empty($_GET['tab']) && settingsTabVisible($visibleTabs, (string) $_GET['tab'])) {
    $activeTabId = (string) $_GET['tab'];
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.settings.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/ai-settings.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <style>
        /* Module accent (teal) — tabs, toggles, focus rings, shared buttons. */
        body { --accent: var(--forms-accent, #00897b); --accent-hover: var(--forms-accent-hover, #00695c); }
        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            margin: 0;
            /* 30px top padding pushed the tab bar off the global
               header; tightened to match the other modules' settings
               pages (16px 30px 24px). */
            padding: 16px 30px 24px;
        }

        /* Teal theme for tabs */
        .tab:hover { color: var(--accent, #00897b); }
        .tab.active { color: var(--accent, #00897b); border-bottom-color: var(--accent, #00897b); }

        .section-header h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: var(--text, #333);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: var(--text, #333);
        }

        .form-group small {
            display: block;
            margin-top: 4px;
            color: var(--text-dim, #888);
            font-size: 12px;
        }

        .alignment-options {
            display: flex;
            gap: 12px;
            max-width: 420px;
        }

        .alignment-option {
            flex: 1;
            padding: 16px 12px;
            border: 2px solid var(--border, #e0e0e0);
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s;
            background: var(--surface-2, #fafafa);
        }

        /* Hover stays a LIGHTER teal than .selected (kept hardcoded so light
           mode is unchanged and hover reads as distinct from selected); a dark
           override gives it a sensible dark-mode tint. */
        .alignment-option:hover {
            border-color: #80cbc4;
            background: #f0f7f6;
        }

        .alignment-option.selected {
            border-color: var(--forms-accent, #00897b);
            background: var(--forms-accent-soft, #e0f2f1);
        }

        [data-theme-mode="dark"] .alignment-option:hover {
            border-color: var(--forms-accent-hover);
            background: var(--forms-accent-soft);
        }

        .alignment-option svg {
            display: block;
            margin: 0 auto 6px;
            color: var(--text-muted, #666);
        }

        .alignment-option.selected svg {
            color: var(--forms-accent, #00897b);
        }

        .alignment-option span {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted, #666);
        }

        .alignment-option.selected span {
            color: var(--forms-accent, #00897b);
            font-weight: 600;
        }

        .logo-preview {
            margin-top: 20px;
            padding: 20px;
            background: var(--surface-2, #f9f9f9);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
        }

        .logo-preview-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-faint, #999);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .logo-preview img {
            display: block;
            max-width: 200px;
            height: auto;
            transition: margin 0.2s;
        }

        .logo-preview img.align-left { margin: 0 auto 0 0; }
        .logo-preview img.align-center { margin: 0 auto; }
        .logo-preview img.align-right { margin: 0 0 0 auto; }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border, #e0e0e0);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary { background: var(--forms-accent, #00897b); color: white; }
        .btn-primary:hover { background: var(--forms-accent-hover, #00695c); }

        /* AI tab — provider / model / key form. Matches the look of
           the Workflow + RFP Builder AI tabs so admins moving between
           modules see one consistent shape. */
        .ai-form { max-width: 640px; }
        .ai-form select,
        .ai-form input[type="text"] {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border, #ccc);
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            background: var(--surface, white);
        }
        .ai-form select:focus,
        .ai-form input:focus { outline: none; border-color: var(--forms-accent, #00897b); }
        .ai-form .toggle-row {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-weight: 500;
            color: var(--text, #333);
        }
        .ai-form .toggle-switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
        }
        .ai-form .toggle-switch input {
            opacity: 0; width: 0; height: 0;
        }
        .ai-form .toggle-slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: var(--border, #ccc);
            border-radius: 22px;
            transition: background 0.15s;
        }
        .ai-form .toggle-slider::before {
            content: '';
            position: absolute;
            height: 16px; width: 16px;
            left: 3px; bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: transform 0.15s;
        }
        .ai-form .toggle-switch input:checked + .toggle-slider { background: var(--forms-accent, #00897b); }
        .ai-form .toggle-switch input:checked + .toggle-slider::before { transform: translateX(18px); }
        .ai-form .ssl-warning {
            display: none;
            margin-top: 8px;
            padding: 10px 12px;
            background: var(--warning-bg, #fff7e0);
            border: 1px solid var(--warning-border, #ffd86b);
            border-radius: 6px;
            font-size: 12px;
            color: var(--warning-text, #6b4f00);
        }
        .ai-form .ai-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-top: 22px;
        }
        .ai-form .btn-test {
            background: var(--surface, white);
            border: 1px solid var(--border, #ddd);
            color: var(--text, #333);
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
        .ai-form .btn-test:hover { background: var(--surface-hover, #f5f5f5); border-color: var(--forms-accent, #00897b); color: var(--forms-accent, #00897b); }
        .ai-form .test-status { font-size: 13px; margin-left: 8px; }
        /* Collections. Every colour is a token from theme.css — a var() with a
           fallback fails SILENTLY to that fallback, so a name that does not
           exist paints a light panel on a dark page and nothing says so.

           🔴 NO max-width anywhere here. This tab first shipped with a 900px
           cap on the list "so it reads nicely"; the page container is already
           edge to edge and the cap was mine. A tasteful content cap is still a
           cap. The one exception is the modal, which inherits its own. */
        .coll-name { font-weight: 600; color: var(--text); }
        .coll-row-closed .coll-name { color: var(--text-muted); }
        .coll-desc { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
        .coll-meta { font-size: 12px; color: var(--text-dim); }
        .coll-num { text-align: right; white-space: nowrap; }

        .coll-state {
            font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;
            white-space: nowrap;
        }
        .coll-state.open   { background: var(--success-bg); color: var(--success-text); }
        .coll-state.closed { background: var(--surface-hover); color: var(--text-muted); }

        .coll-forms a { color: var(--forms-accent, #00897b); text-decoration: none; }
        .coll-forms a:hover { text-decoration: underline; }
        .coll-forms .sep { color: var(--text-faint); margin: 0 6px; }
        .coll-none { color: var(--text-dim); font-style: italic; }

        .coll-actions { text-align: right; white-space: nowrap; }

        /* The same .action-btn the other settings screens use — defined per page
           in this codebase, so this is tickets/settings' copy rather than a
           fourth invention. */
        .tab-content .action-btn {
            background: none;
            border: 1px solid var(--border, #ddd);
            color: var(--text-muted, #666);
            cursor: pointer;
            padding: 6px;
            margin-left: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .tab-content .action-btn:hover {
            background: var(--surface-hover, #f0f0f0);
            border-color: var(--accent, #00897b);
            color: var(--accent, #00897b);
        }
        .tab-content .action-btn.delete { color: var(--danger-accent, #d13438); }
        .tab-content .action-btn.delete:hover {
            background: var(--danger-bg, #fdf3f3);
            border-color: var(--danger-accent, #d13438);
            color: var(--danger-text, #a00);
        }
        /* Disabled means "there is a reason", not "this is broken" — the reason
           is the tooltip, so it must not look like a hover target. */
        .tab-content .action-btn[disabled] { opacity: .35; cursor: not-allowed; }
        .tab-content .action-btn[disabled]:hover {
            background: none; border-color: var(--border, #ddd); color: var(--text-muted, #666);
        }
        .tab-content .action-btn svg { width: 16px; height: 16px; }

        .coll-empty { color: var(--text-dim); font-size: 14px; padding: 24px 12px; }

        /* The form picker. A scroll pane, because an install with eighty
           forms must not produce a modal taller than the screen. */
        #collFormsList { max-height: 46vh; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px; }
        #collFormsList label {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 9px 12px; cursor: pointer; font-size: 13px; color: var(--text);
            border-bottom: 1px solid var(--border-soft);
        }
        #collFormsList label:last-child { border-bottom: none; }
        #collFormsList label:hover { background: var(--surface-hover); }
        #collFormsList input { margin-top: 2px; }
        #collFormsList .cf-where { display: block; font-size: 11px; color: var(--text-dim); margin-top: 2px; }
        #collFormsList .cf-inactive { color: var(--text-faint); }

        .coll-effect label {
            display: flex; gap: 10px; align-items: flex-start;
            padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px;
            margin-bottom: 8px; cursor: pointer; background: var(--surface);
        }
        .coll-effect label:hover { border-color: var(--forms-accent, #00897b); }
        .coll-effect input { margin-top: 2px; }
        .coll-effect .ce-text { font-size: 13px; color: var(--text); }

        .coll-unavailable {
            border: 1px solid var(--warning-border, var(--border));
            background: var(--warning-bg, var(--surface-2));
            color: var(--warning-text, var(--text));
            padding: 14px 16px; border-radius: 6px; font-size: 14px;
        }
    </style>
    <!-- Mobile layer. Linked AFTER this page's inline <style> on purpose: the
         mobile rules must win on equal specificity, and a link placed above it
         would silently lose to the desktop block below (the load-order trap). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=172">
</head>
<body data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <?php renderSettingsTabBar($visibleTabs, $activeTabId); ?>

        <!-- Layout Tab -->
        <?php if (settingsTabVisible($visibleTabs, 'layout')): ?>
        <div class="tab-content<?php echo $activeTabId === 'layout' ? ' active' : ''; ?>" id="layout-tab" data-capability="<?php echo Cap::FORMS_LAYOUT; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('forms.settings.layout_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 24px;"><?php echo htmlspecialchars(t('forms.settings.layout_intro')); ?></p>

            <div class="form-group">
                <label><?php echo htmlspecialchars(t('forms.settings.logo_alignment')); ?></label>
                <small><?php echo htmlspecialchars(t('forms.settings.logo_alignment_help')); ?></small>
                <div class="alignment-options" style="margin-top: 10px;">
                    <div class="alignment-option" data-align="left" onclick="selectAlignment('left')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>
                        <span><?php echo htmlspecialchars(t('forms.settings.align_left')); ?></span>
                    </div>
                    <div class="alignment-option selected" data-align="center" onclick="selectAlignment('center')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="10" x2="6" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="18" y1="18" x2="6" y2="18"></line></svg>
                        <span><?php echo htmlspecialchars(t('forms.settings.align_center')); ?></span>
                    </div>
                    <div class="alignment-option" data-align="right" onclick="selectAlignment('right')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="7" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="21" y1="18" x2="7" y2="18"></line></svg>
                        <span><?php echo htmlspecialchars(t('forms.settings.align_right')); ?></span>
                    </div>
                </div>
            </div>

            <div class="logo-preview">
                <div class="logo-preview-label"><?php echo htmlspecialchars(t('forms.settings.preview')); ?></div>
                <img id="logoPreview" src="<?php echo htmlspecialchars(brandingLogoUrl()); ?>" alt="<?php echo htmlspecialchars(t('forms.settings.logo_alt')); ?>" class="align-center">
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" onclick="saveSettings()"><?php echo htmlspecialchars(t('forms.settings.save')); ?></button>
            </div>
        </div>

        <!-- AI Tab — per-module billing. Provider, model, key + test
             connection. Saved settings drive api/forms/ai_generate.php. -->
        <?php endif; ?>

        <!-- Collections Tab — create / rename / close / delete. Pairing a form
             with one happens on the FORM, in the forms list, beside portal
             visibility and approval. -->
        <?php if (settingsTabVisible($visibleTabs, 'collections')): ?>
        <div class="tab-content<?php echo $activeTabId === 'collections' ? ' active' : ''; ?>" id="collections-tab" data-capability="<?php echo Cap::FORMS_COLLECTIONS; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('forms.collections.heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px; max-width: 760px;"><?php echo htmlspecialchars(t('forms.collections.intro')); ?></p>

            <!-- Shown instead of the list when the schema is not there yet, so
                 an un-migrated install is told what to do rather than being
                 shown an empty list that looks like a working feature. -->
            <div class="coll-unavailable" id="collUnavailable" style="display:none">
                <strong><?php echo htmlspecialchars(t('forms.collections.unavailable')); ?></strong><br>
                <?php echo htmlspecialchars(t('forms.collections.unavailable_hint')); ?>
            </div>

            <div id="collBody">
                <div style="margin-bottom: 14px;">
                    <button class="btn btn-primary" onclick="collOpenModal(0)"><?php echo htmlspecialchars(t('forms.collections.add')); ?></button>
                </div>

                <table class="settings-table" id="collTable">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('forms.collections.name')); ?></th>
                            <th><?php echo htmlspecialchars(t('forms.collections.col_forms')); ?></th>
                            <th class="coll-num"><?php echo htmlspecialchars(t('forms.collections.col_submissions')); ?></th>
                            <th><?php echo htmlspecialchars(t('forms.collections.col_status')); ?></th>
                            <th class="coll-actions"></th>
                        </tr>
                    </thead>
                    <tbody id="collRows"></tbody>
                </table>
                <div class="coll-empty" id="collEmpty" style="display:none"></div>

                <div class="coll-effect">
                    <div class="section-header" style="margin-top: 8px;">
                        <h2 style="font-size: 16px;"><?php echo htmlspecialchars(t('forms.collections.effect_heading')); ?></h2>
                    </div>
                    <p style="color: var(--text-muted, #666); margin-bottom: 14px; font-size: 13px;"><?php echo htmlspecialchars(t('forms.collections.effect_intro')); ?></p>

                    <label>
                        <input type="radio" name="closeEffect" value="reporting_only" onchange="collSaveEffect(this.value)">
                        <span class="ce-text"><?php echo htmlspecialchars(t('forms.collections.effect_reporting')); ?></span>
                    </label>
                    <label>
                        <input type="radio" name="closeEffect" value="stop_submissions" onchange="collSaveEffect(this.value)">
                        <span class="ce-text"><?php echo htmlspecialchars(t('forms.collections.effect_stop')); ?></span>
                    </label>
                    <label>
                        <input type="radio" name="closeEffect" value="stop_and_hide" onchange="collSaveEffect(this.value)">
                        <span class="ce-text"><?php echo htmlspecialchars(t('forms.collections.effect_hide')); ?></span>
                    </label>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <!-- Create / rename a collection. Uses the shared `.modal` + `.active`
         convention from inbox.css, as every other settings screen does. -->
    <!-- Which forms belong to this collection. The same field the forms list
         sets per form, edited from the other end; both write through
         saveForm/setCollectionForms, so they cannot disagree. -->
    <div class="modal" id="collFormsModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" id="collFormsTitle"><?php echo htmlspecialchars(t('forms.collections.manage_title')); ?></div>
            <div class="modal-body">
                <p style="margin: 0 0 6px; color: var(--text-muted, #666); font-size: 13px;"><?php echo htmlspecialchars(t('forms.collections.manage_intro')); ?></p>
                <!-- Said BEFORE they tick, not after they save. -->
                <p style="margin: 0 0 14px; color: var(--text-dim, #888); font-size: 12px;"><?php echo htmlspecialchars(t('forms.collections.steal_warning')); ?></p>
                <div id="collFormsList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="collCloseFormsModal()"><?php echo htmlspecialchars(t('forms.collections.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="collFormsSaveBtn" onclick="collSaveForms()"><?php echo htmlspecialchars(t('forms.collections.save')); ?></button>
            </div>
        </div>
    </div>
    <div class="modal" id="collModal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header" id="collModalTitle"><?php echo htmlspecialchars(t('forms.collections.add_title')); ?></div>
            <form id="collForm" onsubmit="event.preventDefault(); collSave();">
                <!-- ⚠️ `.modal-body` is not decoration: it is where the 24px
                     padding lives, and it is the scrolling pane between the
                     header and the footer. Without it the fields sit flush
                     against the edges (Ed's screenshot). inbox.css explicitly
                     anticipates this `form > .modal-body` arrangement with
                     `.modal-content:has(> form > .modal-body)`, which hands the
                     scrolling to the body instead of the whole modal. -->
                <div class="modal-body">
                    <div class="form-group">
                        <label for="collName"><?php echo htmlspecialchars(t('forms.collections.name')); ?></label>
                        <input type="text" id="collName" maxlength="255" required placeholder="<?php echo htmlspecialchars(t('forms.collections.name_ph')); ?>">
                    </div>
                    <!-- The last group's 20px bottom margin doubles up with the
                         body's own padding, so drop it on the final one. -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="collDesc"><?php echo htmlspecialchars(t('forms.collections.description')); ?></label>
                        <textarea id="collDesc" maxlength="500" rows="3" placeholder="<?php echo htmlspecialchars(t('forms.collections.description_ph')); ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="collCloseModal()"><?php echo htmlspecialchars(t('forms.collections.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="collSaveBtn"><?php echo htmlspecialchars(t('forms.collections.save')); ?></button>
                </div>
            </form>
        </div>
    </div>
        <?php if (settingsTabVisible($visibleTabs, 'ai')): ?>
        <div class="tab-content<?php echo $activeTabId === 'ai' ? ' active' : ''; ?>" id="ai-tab" data-capability="<?php echo Cap::FORMS_AI; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('forms.settings.ai_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 24px; max-width: 720px;">
                <?php echo htmlspecialchars(t('forms.settings.ai_intro')); ?>
            </p>

            <?php renderAiSettingsPanel('forms_ai'); ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Toast notification -->

    <script>
        const API_BASE = '../../api/forms/';
        let currentAlignment = 'center';

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            /* Only when the tab is actually on the page - it is behind its own
               capability, so somebody may be looking at a settings page that
               has no Collections tab at all.
               ⚠️ Keyed on the element the renderer WRITES TO. It was pointing at
               the old card list, which the table replaced, so this quietly
               stopped firing and the tab rendered empty - a guard that silently
               turns a feature off is worse than no guard. */
            if (document.getElementById('collRows')) loadCollections();
        });

        // AI provider/model/key for the form builder's AI Assist is now handled
        // by the shared panel (renderAiSettingsPanel('forms_ai') + ai-settings.js).

        function selectAlignment(align) {
            currentAlignment = align;
            document.querySelectorAll('.alignment-option').forEach(el => el.classList.remove('selected'));
            document.querySelector(`.alignment-option[data-align="${align}"]`).classList.add('selected');
            // Update preview
            const img = document.getElementById('logoPreview');
            img.className = 'align-' + align;
        }

        async function loadSettings() {
            try {
                const res = await fetch(API_BASE + 'get_settings.php');
                const data = await res.json();
                if (data.success && data.settings) {
                    const align = data.settings.logo_alignment || 'center';
                    selectAlignment(align);
                }
            } catch (e) {
                console.error(e);
            }
        }

        // ---------------------------------------------------------------- //
        //  Collections                                                     //
        // ---------------------------------------------------------------- //

        let collections = [];
        let collEditingId = 0;      // 0 = creating

        function collEsc(v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, c =>
                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        async function loadCollections() {
            try {
                const res = await fetch(API_BASE + 'get_collections.php');
                const data = await res.json();
                if (!data.success) { showToast(data.error, 'error'); return; }

                /* The schema is not there yet. Say so instead of showing an empty
                   list, which looks exactly like a working feature nobody has
                   used - the most misleading state this page could be in. */
                document.getElementById('collUnavailable').style.display = data.available ? 'none' : '';
                document.getElementById('collBody').style.display = data.available ? '' : 'none';
                if (!data.available) return;

                collections = data.collections || [];
                const eff = document.querySelector(`input[name="closeEffect"][value="${data.close_effect}"]`);
                if (eff) eff.checked = true;
                renderCollections();
            } catch (e) {
                console.error(e);
            }
        }

        /* Inline SVGs rather than a shared sprite, matching the other settings
           screens. Pencil / box-arrow / reopen-arrow / bin. */
        const COLL_ICON_SUBS   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        const COLL_ICON_FORMS  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>';
        const COLL_ICON_EDIT   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const COLL_ICON_CLOSE  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';
        const COLL_ICON_REOPEN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>';
        const COLL_ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

        function renderCollections() {
            const tbody = document.getElementById('collRows');
            const table = document.getElementById('collTable');
            const empty = document.getElementById('collEmpty');

            if (!collections.length) {
                table.style.display = 'none';
                empty.style.display = '';
                empty.textContent = window.t('forms.collections.empty') + ' ' + window.t('forms.collections.empty_hint');
                return;
            }
            table.style.display = '';
            empty.style.display = 'none';

            tbody.innerHTML = collections.map(c => {
                const closed = !!c.closed_datetime;
                const subs   = Number(c.submission_count) || 0;

                /* 🔑 fmtDate, not fmtNaiveDate: closed_datetime is a real instant
                   written by the server in UTC, so it converts into the reader's
                   own zone. */
                const closedOn = (closed && typeof window.fmtDate === 'function')
                    ? window.t('forms.collections.closed_on', {
                        date: window.fmtDate(c.closed_datetime),
                        who: c.closed_by_name || '—' })
                    : '';

                const formLinks = (c.forms || []).length
                    ? (c.forms || []).map(f =>
                        `<a href="../submissions.php?id=${f.id}">${collEsc(f.title)}</a>`).join('<span class="sep">·</span>')
                    : `<span class="coll-none">${collEsc(window.t('forms.collections.no_forms_short'))}</span>`;

                /* Delete is offered only while nothing is stamped into it. The
                   service and the database both refuse otherwise; disabling it
                   here means nobody has to discover that by pressing it, and the
                   reason is the tooltip rather than a toast after the fact. */
                const canDelete = subs === 0;

                return `<tr${closed ? ' class="coll-row-closed"' : ''}>
                    <td>
                        <div class="coll-name">${collEsc(c.name)}</div>
                        ${c.description ? `<div class="coll-desc">${collEsc(c.description)}</div>` : ''}
                        ${closedOn ? `<div class="coll-meta">${collEsc(closedOn)}</div>` : ''}
                    </td>
                    <td class="coll-forms">${formLinks}</td>
                    <td class="coll-num">${subs}</td>
                    <td><span class="coll-state ${closed ? 'closed' : 'open'}">${
                        collEsc(window.t(closed ? 'forms.collections.closed_badge' : 'forms.collections.state_open'))}</span></td>
                    <td class="coll-actions">
                        <a class="action-btn" href="../collection.php?id=${c.id}" onclick="event.stopPropagation()" title="${collEsc(window.t('forms.collections.view_submissions'))}">${COLL_ICON_SUBS}</a>
                        <button class="action-btn" onclick="collOpenFormsModal(${c.id})" title="${collEsc(window.t('forms.collections.manage_forms'))}">${COLL_ICON_FORMS}</button>
                        <button class="action-btn" onclick="collOpenModal(${c.id})" title="${collEsc(window.t('forms.collections.edit'))}">${COLL_ICON_EDIT}</button>
                        <button class="action-btn" onclick="collSetClosed(${c.id}, ${closed ? 'false' : 'true'})" title="${
                            collEsc(window.t(closed ? 'forms.collections.reopen' : 'forms.collections.close'))}">${
                            closed ? COLL_ICON_REOPEN : COLL_ICON_CLOSE}</button>
                        <button class="action-btn delete" onclick="collDelete(${c.id})"${canDelete
                            ? ` title="${collEsc(window.t('forms.collections.delete'))}"`
                            : ` disabled title="${collEsc(window.t('forms.collections.has_submissions'))}"`}>${COLL_ICON_DELETE}</button>
                    </td>
                </tr>`;
            }).join('');
        }

        /* id 0 = create. One modal for both, because "rename" and "new" differ
           only in what is already in the boxes. */
        function collOpenModal(id) {
            collEditingId = Number(id) || 0;
            const c = collEditingId ? collections.find(x => Number(x.id) === collEditingId) : null;

            document.getElementById('collModalTitle').textContent =
                window.t(collEditingId ? 'forms.collections.edit_title' : 'forms.collections.add_title');
            document.getElementById('collName').value = c ? (c.name || '') : '';
            document.getElementById('collDesc').value = c ? (c.description || '') : '';
            document.getElementById('collModal').classList.add('active');
            document.getElementById('collName').focus();
        }

        function collCloseModal() {
            document.getElementById('collModal').classList.remove('active');
            collEditingId = 0;
        }
        // ---------------------------------------------------------------- //
        //  Which forms are in a collection — the other end of the pairing   //
        //  control on the forms list. Both write the same column.           //
        // ---------------------------------------------------------------- //

        let collFormsId = 0;
        let collPickerForms = null;      // fetched once per page

        async function collOpenFormsModal(id) {
            collFormsId = Number(id);
            const c = collections.find(x => Number(x.id) === collFormsId);
            document.getElementById('collFormsTitle').textContent =
                (c ? c.name : window.t('forms.collections.manage_title'));

            if (collPickerForms === null) {
                try {
                    const res = await fetch(API_BASE + 'get_collection_form_picker.php');
                    const data = await res.json();
                    collPickerForms = (data.success ? data.forms : []) || [];
                } catch (e) { collPickerForms = []; }
            }

            const list = document.getElementById('collFormsList');
            if (!collPickerForms.length) {
                list.innerHTML = `<div style="padding:14px;color:var(--text-dim)">${collEsc(window.t('forms.collections.manage_none'))}</div>`;
            } else {
                list.innerHTML = collPickerForms.map(f => {
                    const mine = Number(f.collection_id) === collFormsId;
                    /* Where it lives NOW, said on the row rather than in a toast
                       afterwards: ticking it takes it from there. */
                    const elsewhere = (!mine && f.collection_id)
                        ? `<span class="cf-where">${collEsc(window.t('forms.collections.in_other', { name: f.collection_name || '' }))}</span>`
                        : '';
                    return `<label>
                        <input type="checkbox" value="${f.id}"${mine ? ' checked' : ''}>
                        <span class="${f.is_active == 1 ? '' : 'cf-inactive'}">${collEsc(f.title)}${elsewhere}</span>
                    </label>`;
                }).join('');
            }
            document.getElementById('collFormsModal').classList.add('active');
        }

        function collCloseFormsModal() {
            document.getElementById('collFormsModal').classList.remove('active');
            collFormsId = 0;
        }

        async function collSaveForms() {
            if (!collFormsId) return;
            const ids = Array.prototype.map.call(
                document.querySelectorAll('#collFormsList input:checked'),
                el => Number(el.value));

            const btn = document.getElementById('collFormsSaveBtn');
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'save_collection_forms.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ collection_id: collFormsId, form_ids: ids })
                });
                const data = await res.json();
                if (!data.success) { showToast(data.error || window.t('forms.collections.save_failed'), 'error'); btn.disabled = false; return; }

                /* Say what was taken from where. Moving a form out of another
                   collection is a real consequence and the person should see it
                   happened, not just that "forms updated". */
                if (data.moved && data.moved.length) {
                    showToast(window.t('forms.collections.moved_note', {
                        names: data.moved.map(m => m.title).join(', ')
                    }), 'info');
                } else {
                    showToast(window.t('forms.collections.forms_saved'), 'success');
                }
                collCloseFormsModal();
                // The counts and the member list both change.
                collPickerForms = null;
                loadCollections();
            } catch (e) {
                showToast(window.t('forms.collections.save_failed'), 'error');
            }
            btn.disabled = false;
        }

        async function collSave() {
            const name = document.getElementById('collName').value.trim();
            if (!name) { document.getElementById('collName').focus(); return; }
            try {
                const res = await fetch(API_BASE + 'save_collection.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: collEditingId,
                        name: name,
                        description: document.getElementById('collDesc').value.trim()
                    })
                });
                const data = await res.json();
                if (!data.success) { showToast(data.error || window.t('forms.collections.save_failed'), 'error'); return; }
                showToast(window.t('forms.collections.saved'), 'success');
                collCloseModal();
                loadCollections();
            } catch (e) {
                showToast(window.t('forms.collections.save_failed'), 'error');
            }
        }

        async function collSetClosed(id, closed) {
            if (closed) {
                const ok = await showConfirm({
                    title: window.t('forms.collections.confirm_close'),
                    message: window.t('forms.collections.confirm_close_body'),
                    okLabel: window.t('forms.collections.close')
                });
                if (!ok) return;
            }
            try {
                const res = await fetch(API_BASE + 'set_collection_closed.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, closed: closed })
                });
                const data = await res.json();
                if (!data.success) { showToast(data.error, 'error'); return; }
                loadCollections();
            } catch (e) {
                showToast(window.t('forms.collections.save_failed'), 'error');
            }
        }

        async function collDelete(id) {
            const ok = await showConfirm({
                title: window.t('forms.collections.confirm_delete'),
                message: window.t('forms.collections.confirm_delete_body'),
                okLabel: window.t('forms.collections.delete'),
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const res = await fetch(API_BASE + 'delete_collection.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await res.json();
                // The service explains WHY when it refuses (it holds submissions),
                // so show its sentence rather than a generic failure.
                if (!data.success) { showToast(data.error, 'error'); return; }
                loadCollections();
            } catch (e) {
                showToast(window.t('forms.collections.save_failed'), 'error');
            }
        }

        async function collSaveEffect(value) {
            try {
                const res = await fetch(API_BASE + 'save_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    // Only this key: the endpoint checks a capability PER key, and
                    // sending logo_alignment as well would demand a capability this
                    // tab's owner may not have.
                    body: JSON.stringify({ settings: { collection_close_effect: value } })
                });
                const data = await res.json();
                if (data.success) showToast(window.t('forms.toast.settings_saved'), 'success');
                else showToast(window.t('forms.toast.error_prefix', { message: data.error }), 'error');
            } catch (e) {
                showToast(window.t('forms.toast.settings_save_failed'), 'error');
            }
        }

        async function saveSettings() {
            try {
                const res = await fetch(API_BASE + 'save_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: { logo_alignment: currentAlignment } })
                });
                const data = await res.json();

                if (data.success) {
                    showToast(window.t('forms.toast.settings_saved'), 'success');
                } else {
                    showToast(window.t('forms.toast.error_prefix', { message: data.error }), 'error');
                }
            } catch (e) {
                showToast(window.t('forms.toast.settings_save_failed'), 'error');
            }
        }
    </script>
    <!-- Mobile layer. Adds the views hamburger and the module drawer on a phone.
         Loaded last so it can wrap the page's own globals rather than edit them. -->
    <script src="../../assets/js/mobile.js?v=71"></script>
</body>
</html>
