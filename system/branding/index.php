<?php
/**
 * System - Branding Settings
 *
 * Organisation-wide branding: logo + default header/footer template slots.
 * These act as the fallback for any module that renders branded output
 * (currently Network Mapper's diagram header/footer; future PDF/PNG export
 * surfaces will read the same settings).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
require_once '../../includes/branding.php';   // the login designer's field table + presets
I18n::initFromSession();
Tz::init();

$current_page = 'branding';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.branding.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <style>
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

        .branding-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 30px 20px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 6px 0;
        }

        .page-subtitle {
            font-size: 13px;
            color: var(--text-dim, #888);
            margin: 0 0 30px 0;
        }

        .settings-card {
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08));
            margin-bottom: 24px;
        }

        .settings-card h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 4px 0;
        }

        .settings-card .card-desc {
            font-size: 13px;
            color: var(--text-dim, #888);
            margin: 0 0 20px 0;
            line-height: 1.5;
        }

        /* Logo block */
        /* ---- login screen designer ---- */
        .scope-row { display: flex; align-items: center; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
        .scope {
            padding: 8px 16px; border: 1px solid var(--border, #ddd); border-radius: 999px;
            background: var(--surface, #fff); color: var(--text-muted, #666);
            font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .scope.active { background: var(--accent, #2b88d8); border-color: var(--accent, #2b88d8); color: var(--on-accent, #fff); }
        /* A control for a field the current screen does not have — a landing page
           has no sign-in form to position — is hidden rather than shown doing
           nothing. */
        [data-field-hidden="1"] { display: none !important; }

        .preset-row { display: flex; flex-wrap: wrap; gap: 10px; margin: 4px 0 20px; }
        .preset {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 12px 6px 6px; border: 1px solid var(--border, #ddd);
            border-radius: 999px; background: var(--surface, #fff);
            color: var(--text, #333); font: inherit; font-size: 13px; cursor: pointer;
        }
        .preset:hover { border-color: var(--accent, #2b88d8); }
        .preset-swatch {
            width: 26px; height: 26px; border-radius: 50%;
            background: linear-gradient(135deg, var(--a), var(--b));
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.15);
        }
        .design-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr); gap: 24px; align-items: start; }
        .dgroup { margin-bottom: 22px; }
        .dgroup h4 { margin: 0 0 10px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #666); }
        .dlabel { display: block; margin-bottom: 12px; font-size: 13px; color: var(--text, #333); }
        .dlabel input[type="color"] { display: block; width: 100%; height: 38px; padding: 2px; margin-top: 4px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: none; cursor: pointer; }
        .dlabel input[type="range"] { display: block; width: 100%; margin-top: 6px; }
        .dlabel .slot-input, .dlabel input[type="text"] { margin-top: 4px; }
        .drow { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .contrast-note { font-size: 12.5px; margin-top: -4px; }
        .contrast-note.warn { color: var(--warning-text, #92400e); }
        .contrast-note.ok   { color: var(--text-muted, #666); }
        .design-preview { position: sticky; top: 16px; }
        .preview-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; font-size: 13px; color: var(--text-muted, #666); }
        /* 🔑 THE PREVIEW IS RENDERED AT A REAL DESKTOP SIZE AND THEN SCALED DOWN.

           Left to fill the panel the iframe was about 640px wide, so the page
           inside laid itself out for a tablet: the sign-in card filled the frame
           and you scrolled to see any of it. A preview that reports a width
           nobody uses is a preview of the wrong thing.

           So the frame is fixed at 1280x800 — a laptop — and a CSS transform
           shrinks the whole thing to fit whatever room the panel has. The page
           inside still believes it is 1280 wide, which is the point.

           ⚠️ `transform` does not affect layout, so the wrapper's height has to
           be set to the SCALED height in JS or it reserves the full 800px and
           leaves a gap underneath. */
        .preview-frame {
            position: relative;
            width: 100%;
            overflow: hidden;
            border: 1px solid var(--border, #ddd);
            border-radius: 10px;
            background: var(--surface-2, #f4f5f7);
        }
        #ln_preview {
            width: 1280px;
            height: 800px;
            border: 0;
            transform-origin: top left;
            display: block;
        }
        .preview-note { margin-top: 8px; font-size: 12.5px; color: var(--text-muted, #666); }
        [data-hidden="1"] { display: none !important; }

        @media (max-width: 900px) {
            .design-grid { grid-template-columns: 1fr; }
            .design-preview { position: static; }
        }
        .logo-row {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-preview {
            width: 140px;
            height: 80px;
            border: 1px dashed var(--border, #ccc);
            border-radius: 6px;
            background: var(--surface-2, #fafafa);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .logo-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .logo-preview .no-logo {
            font-size: 11px;
            color: var(--text-faint, #aaa);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .logo-controls {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .logo-controls .file-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-controls input[type="file"] {
            font-size: 12px;
            color: var(--text, #333);
        }

        .logo-hint {
            font-size: 12px;
            color: var(--text-dim, #888);
            line-height: 1.5;
        }

        /* Slot grid */
        .slot-grid {
            display: grid;
            grid-template-columns: 80px 1fr 1fr 1fr;
            gap: 10px 12px;
            align-items: center;
        }

        .slot-grid .row-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text, #444);
            text-align: right;
            padding-right: 4px;
        }

        .slot-grid .col-head {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
        }

        .slot-input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
            box-sizing: border-box;
            background: var(--surface, #fff);
            color: var(--text, #333);
        }

        .slot-input:focus { outline: none; border-color: #06b6d4; }

        .info-note {
            background: #f5f7fa;
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 6px;
            padding: 14px 16px;
            font-size: 12px;
            color: var(--text-muted, #666);
            line-height: 1.6;
            margin-top: 16px;
        }

        .info-note strong { color: var(--text, #333); }

        .info-note code {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 11px;
            color: #06b6d4;
            font-family: 'Consolas', 'Monaco', monospace;
        }

        .save-area {
            margin-top: 30px;
            display: flex;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-primary {
            background: var(--sys-accent, #546e7a);
            color: var(--sys-on-accent, #fff);
        }

        .btn-primary:hover { background: #455a64; }

        .btn-secondary {
            background: var(--surface, #fff);
            color: var(--text-muted, #555);
            border: 1px solid var(--border, #ddd);
        }

        .btn-secondary:hover { background: #f5f7fa; }
        .btn-link {
            background: none;
            color: #c62828;
            padding: 4px 6px;
            font-size: 12px;
        }
        .btn-link:hover { text-decoration: underline; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ---- Dark mode overrides (pale washes / hovers that would glow) ---- */
        [data-theme-mode="dark"] .info-note { background: #22293a; }
        [data-theme-mode="dark"] .btn-primary:hover { background: #b0bec5; }
        [data-theme-mode="dark"] .btn-secondary:hover { background: var(--surface-hover, #2a3140); }
        [data-theme-mode="dark"] .btn-link { color: #ef5350; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="branding">
    <?php include '../includes/header.php'; ?>

    <div class="branding-container">
        <h1 class="page-title">Branding</h1>
        <p class="page-subtitle">Set the organisation logo and default header/footer text used on diagrams and exported documents</p>

        <form id="brandingForm" enctype="multipart/form-data">
            <!-- Logo -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.branding.logo_heading')); ?></h3>
                <p class="card-desc"><?php echo t('system.branding.logo_desc', ['code' => '<code>{{logo}}</code>']); ?></p>
                <div class="logo-row">
                    <div class="logo-preview" id="logoPreview">
                        <span class="no-logo"><?php echo htmlspecialchars(t('system.branding.no_logo')); ?></span>
                    </div>
                    <div class="logo-controls">
                        <div class="file-row">
                            <!-- SVG deliberately absent: the server stopped accepting it in the
                                 security round (an SVG is XML that can carry <script>, and the
                                 logo is served from our own origin — see save_branding.php).
                                 The picker was still offering it, so choosing one got you a
                                 server-side error instead of a greyed-out file. -->
                            <input type="file" id="logoFile" name="logo" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                            <button type="button" class="btn btn-link" id="removeLogoBtn" style="display:none;"><?php echo htmlspecialchars(t('system.branding.remove')); ?></button>
                        </div>
                        <div class="logo-hint"><?php echo htmlspecialchars(t('system.branding.logo_hint')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Landing page (discussion #63) -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.branding.landing_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.branding.landing_desc')); ?></p>
                <select id="landingPage" class="slot-input" style="max-width:420px;">
                    <option value="analyst"><?php echo htmlspecialchars(t('system.branding.landing_analyst')); ?></option>
                    <option value="portal"><?php echo htmlspecialchars(t('system.branding.landing_portal')); ?></option>
                </select>
                <div class="info-note" style="margin-top:12px;">
                    <?php echo htmlspecialchars(t('system.branding.landing_note')); ?>
                </div>
            </div>


            <!-- ============================================================
                 LOGIN SCREEN DESIGNER (#1421)

                 Every control here is a CHOICE, never a piece of syntax: an
                 enum, a colour picker, a slider, or plain text. The list of
                 controls and their permitted values comes from
                 includes/branding.php, which is also what validates the save
                 and what renders the page — so a control cannot exist here
                 without the server knowing about it.
                 ============================================================ -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.branding.login_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.branding.login_desc')); ?></p>

                <!-- Presets. The quickest way from "eighteen empty controls"
                     to something that looks deliberate. -->
                <!-- WHICH SCREEN. One set of controls, three sets of values —
                     you edit one screen at a time and the preview follows. -->
                <div class="scope-row" role="tablist">
                    <?php foreach (['login', 'portal', 'home'] as $sc): ?>
                        <button type="button" class="scope<?php echo $sc === 'login' ? ' active' : ''; ?>" data-scope="<?php echo $sc; ?>" role="tab">
                            <?php echo htmlspecialchars(t('system.branding.scope_' . $sc)); ?>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" class="btn btn-link" id="ln_copy" style="margin-left:auto;">
                        <?php echo htmlspecialchars(t('system.branding.login_copy')); ?>
                    </button>
                </div>
                <p class="card-desc" id="ln_scope_desc"></p>

                <div class="preset-row">
                    <?php foreach (brandingLoginPresets() as $id => $preset): ?>
                        <button type="button" class="preset" data-preset="<?php echo htmlspecialchars($id); ?>"
                                style="--a: <?php echo htmlspecialchars($preset['bg_from']); ?>; --b: <?php echo htmlspecialchars($preset['bg_to']); ?>;">
                            <span class="preset-swatch"></span>
                            <span class="preset-name"><?php echo htmlspecialchars($preset['label']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="design-grid">
                    <div class="design-controls">

                        <div class="dgroup">
                            <h4><?php echo htmlspecialchars(t('system.branding.login_group_background')); ?></h4>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_bg_style')); ?>
                                <select id="ln_bg_style" class="slot-input">
                                    <!-- 🔴 "theme" must be here or setting it in JS
                                         silently does nothing: assigning a value a
                                         <select> has no option for leaves the old one
                                         selected. The landing page defaults to theme,
                                         so without this option it saved a gradient it
                                         had never been given — overriding the very
                                         dark-mode background that default exists to
                                         protect. -->
                                    <option value="theme"><?php echo htmlspecialchars(t('system.branding.login_bg_theme')); ?></option>
                                    <option value="gradient"><?php echo htmlspecialchars(t('system.branding.login_bg_gradient')); ?></option>
                                    <option value="solid"><?php echo htmlspecialchars(t('system.branding.login_bg_solid')); ?></option>
                                    <option value="image"><?php echo htmlspecialchars(t('system.branding.login_bg_image')); ?></option>
                                </select>
                            </label>
                            <div class="drow">
                                <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_colour_from')); ?>
                                    <input type="color" id="ln_bg_from">
                                </label>
                                <label class="dlabel" data-when="gradient"><?php echo htmlspecialchars(t('system.branding.login_colour_to')); ?>
                                    <input type="color" id="ln_bg_to">
                                </label>
                            </div>
                            <label class="dlabel" data-when="gradient"><?php echo htmlspecialchars(t('system.branding.login_direction')); ?>
                                <select id="ln_bg_direction" class="slot-input">
                                    <option value="diagonal"><?php echo htmlspecialchars(t('system.branding.login_dir_diagonal')); ?></option>
                                    <option value="diagonal-up"><?php echo htmlspecialchars(t('system.branding.login_dir_diagonal_up')); ?></option>
                                    <option value="down"><?php echo htmlspecialchars(t('system.branding.login_dir_down')); ?></option>
                                    <option value="right"><?php echo htmlspecialchars(t('system.branding.login_dir_right')); ?></option>
                                    <option value="radial"><?php echo htmlspecialchars(t('system.branding.login_dir_radial')); ?></option>
                                </select>
                            </label>
                            <div data-when="image">
                                <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_bg_upload')); ?>
                                    <input type="file" id="ln_bg_file" name="login_bg" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                                </label>
                                <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_dim')); ?> <output id="ln_dim_out"></output>
                                    <input type="range" id="ln_bg_dim" min="0" max="80" step="5">
                                </label>
                            </div>
                        </div>

                        <div class="dgroup">
                            <h4><?php echo htmlspecialchars(t('system.branding.login_group_layout')); ?></h4>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_form_position')); ?>
                                <select id="ln_form_position" class="slot-input">
                                    <option value="left"><?php echo htmlspecialchars(t('system.branding.login_pos_left')); ?></option>
                                    <option value="centre"><?php echo htmlspecialchars(t('system.branding.login_pos_centre')); ?></option>
                                    <option value="right"><?php echo htmlspecialchars(t('system.branding.login_pos_right')); ?></option>
                                </select>
                            </label>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_card_style')); ?>
                                <select id="ln_card_style" class="slot-input">
                                    <option value="solid"><?php echo htmlspecialchars(t('system.branding.login_card_solid')); ?></option>
                                    <option value="glass"><?php echo htmlspecialchars(t('system.branding.login_card_glass')); ?></option>
                                    <option value="flat"><?php echo htmlspecialchars(t('system.branding.login_card_flat')); ?></option>
                                </select>
                            </label>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_logo_size')); ?> <output id="ln_logo_out"></output>
                                <input type="range" id="ln_logo_size" min="40" max="400" step="10">
                            </label>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_logo_height')); ?> <output id="ln_logo_h_out"></output>
                                <input type="range" id="ln_logo_height" min="0" max="400" step="10">
                                <span class="logo-hint"><?php echo htmlspecialchars(t('system.branding.login_logo_height_hint')); ?></span>
                            </label>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_logo_position')); ?>
                                <select id="ln_logo_position" class="slot-input">
                                    <option value="above"><?php echo htmlspecialchars(t('system.branding.login_logo_above')); ?></option>
                                    <option value="hidden"><?php echo htmlspecialchars(t('system.branding.login_logo_hidden')); ?></option>
                                </select>
                            </label>
                        </div>

                        <div class="dgroup">
                            <h4><?php echo htmlspecialchars(t('system.branding.login_group_words')); ?></h4>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_headline')); ?>
                                <input type="text" id="ln_heading" class="slot-input" maxlength="80"
                                       placeholder="<?php echo htmlspecialchars(t('system.branding.login_headline_ph')); ?>">
                            </label>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_subheading')); ?>
                                <input type="text" id="ln_subheading" class="slot-input" maxlength="160"
                                       placeholder="<?php echo htmlspecialchars(t('system.branding.login_subheading_ph')); ?>">
                            </label>
                        </div>

                        <div class="dgroup">
                            <h4><?php echo htmlspecialchars(t('system.branding.login_group_banner')); ?></h4>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_banner_position')); ?>
                                <select id="ln_banner_position" class="slot-input">
                                    <option value="off"><?php echo htmlspecialchars(t('system.branding.login_banner_off')); ?></option>
                                    <option value="top"><?php echo htmlspecialchars(t('system.branding.login_banner_top')); ?></option>
                                    <option value="bottom"><?php echo htmlspecialchars(t('system.branding.login_banner_bottom')); ?></option>
                                </select>
                            </label>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_banner_text')); ?>
                                <input type="text" id="ln_banner_text" class="slot-input" maxlength="160"
                                       placeholder="<?php echo htmlspecialchars(t('system.branding.login_banner_ph')); ?>">
                            </label>
                            <div class="drow">
                                <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_banner_bg')); ?>
                                    <input type="color" id="ln_banner_bg">
                                </label>
                                <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_banner_fg')); ?>
                                    <input type="color" id="ln_banner_fg">
                                </label>
                            </div>
                            <div class="contrast-note" id="bannerContrast"></div>
                        </div>

                        <div class="dgroup">
                            <h4><?php echo htmlspecialchars(t('system.branding.login_group_footer')); ?></h4>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_footer_text')); ?>
                                <input type="text" id="ln_footer_text" class="slot-input" maxlength="200"
                                       placeholder="<?php echo htmlspecialchars(t('system.branding.login_footer_ph')); ?>">
                            </label>
                            <label class="dlabel"><?php echo htmlspecialchars(t('system.branding.login_footer_fg')); ?>
                                <input type="color" id="ln_footer_fg">
                            </label>
                        </div>

                        <div class="dgroup">
                            <button type="button" class="btn btn-link" id="ln_reset"><?php echo htmlspecialchars(t('system.branding.login_reset')); ?></button>
                        </div>
                    </div>

                    <!-- The preview is the REAL login page in an iframe, driven
                         live over a same-origin BroadcastChannel. Not a mock-up:
                         a mock-up drifts from the page it claims to show, and
                         the first time it does, somebody ships a login screen
                         nobody has actually seen. -->
                    <div class="design-preview">
                        <div class="preview-head">
                            <span><?php echo htmlspecialchars(t('system.branding.login_preview')); ?></span>
                            <a href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>auth/login.php?preview=1" target="_blank" rel="noopener" class="btn btn-link" id="ln_open_tab">
                                <?php echo htmlspecialchars(t('system.branding.login_open_tab')); ?>
                            </a>
                        </div>
                        <div class="preview-frame" id="ln_frame">
                        <iframe id="ln_preview" src="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>auth/login.php?preview=1" title="<?php echo htmlspecialchars(t('system.branding.login_preview')); ?>"></iframe>
                        </div>
                        <p class="preview-note"><?php echo t('system.branding.login_safety', ['url' => '<code>?nobranding=1</code>']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Header slots -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.branding.header_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.branding.header_desc')); ?></p>
                <div class="slot-grid">
                    <div></div>
                    <div class="col-head"><?php echo htmlspecialchars(t('system.branding.col_left')); ?></div>
                    <div class="col-head"><?php echo htmlspecialchars(t('system.branding.col_centre')); ?></div>
                    <div class="col-head"><?php echo htmlspecialchars(t('system.branding.col_right')); ?></div>

                    <div class="row-label"><?php echo htmlspecialchars(t('system.branding.row_header')); ?></div>
                    <input type="text" class="slot-input" id="headerLeft" maxlength="200">
                    <input type="text" class="slot-input" id="headerCenter" maxlength="200">
                    <input type="text" class="slot-input" id="headerRight" maxlength="200">
                </div>
            </div>

            <!-- Footer slots -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.branding.footer_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.branding.footer_desc')); ?></p>
                <div class="slot-grid">
                    <div></div>
                    <div class="col-head"><?php echo htmlspecialchars(t('system.branding.col_left')); ?></div>
                    <div class="col-head"><?php echo htmlspecialchars(t('system.branding.col_centre')); ?></div>
                    <div class="col-head"><?php echo htmlspecialchars(t('system.branding.col_right')); ?></div>

                    <div class="row-label"><?php echo htmlspecialchars(t('system.branding.row_footer')); ?></div>
                    <input type="text" class="slot-input" id="footerLeft" maxlength="200">
                    <input type="text" class="slot-input" id="footerCenter" maxlength="200">
                    <input type="text" class="slot-input" id="footerRight" maxlength="200">
                </div>

                <div class="info-note">
                    <strong><?php echo htmlspecialchars(t('system.branding.tokens_heading')); ?></strong> — <?php echo htmlspecialchars(t('system.branding.tokens_intro')); ?><br>
                    <code>{{logo}}</code> <?php echo htmlspecialchars(t('system.branding.token_logo')); ?>
                    &nbsp;·&nbsp; <code>{{title}}</code> <?php echo htmlspecialchars(t('system.branding.token_title')); ?>
                    &nbsp;·&nbsp; <code>{{author}}</code> <?php echo htmlspecialchars(t('system.branding.token_author')); ?>
                    &nbsp;·&nbsp; <code>{{version}}</code> <?php echo htmlspecialchars(t('system.branding.token_version')); ?>
                    &nbsp;·&nbsp; <code>{{modified}}</code> <?php echo htmlspecialchars(t('system.branding.token_modified')); ?><br>
                    <?php echo htmlspecialchars(t('system.branding.tokens_example_prefix')); ?> <code>Author: {{author}}</code> <?php echo htmlspecialchars(t('system.branding.tokens_example_suffix')); ?> <em><?php echo htmlspecialchars(t('system.branding.tokens_example_render')); ?></em>.
                </div>
            </div>

            <div class="save-area">
                <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('system.branding.save')); ?></button>
                <button type="button" class="btn btn-secondary" id="resetBtn"><?php echo htmlspecialchars(t('system.branding.reset_defaults')); ?></button>
            </div>
        </form>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
    const API_BASE = '<?php echo $path_prefix; ?>api/system/';
    const PATH_PREFIX = '<?php echo $path_prefix; ?>';

    // Defaults match the get_branding.php fallback so the "Reset" button gives
    // the same values you'd see on a brand-new install.
    const DEFAULTS = {
        header_left:   '{{logo}}',
        header_center: '{{title}}',
        header_right:  '',
        footer_left:   'Author: {{author}}',
        footer_center: '{{version}}',
        footer_right:  'Modified: {{modified}}',
    };

    let currentLogoPath = null;
    let pendingRemoveLogo = false;

    async function loadBranding() {
        try {
            const resp = await fetch(API_BASE + 'get_branding.php');
            const data = await resp.json();
            if (!data.success) {
                showToast(window.t('system.branding.load_failed', { error: data.error }), 'error');
                return;
            }
            const b = data.branding;
            document.getElementById('headerLeft').value = b.header_left || '';
            document.getElementById('headerCenter').value = b.header_center || '';
            document.getElementById('headerRight').value = b.header_right || '';
            document.getElementById('footerLeft').value = b.footer_left || '';
            document.getElementById('footerCenter').value = b.footer_center || '';
            document.getElementById('footerRight').value = b.footer_right || '';
            // Sits outside `branding` — it is an install behaviour, not a slot (#63).
            document.getElementById('landingPage').value = data.landing_page || 'analyst';

            currentLogoPath = b.logo_path || null;
            renderLogoPreview();
        } catch (e) {
            showToast(window.t('system.branding.load_failed_generic'), 'error');
        }
    }

    function renderLogoPreview(localObjectUrl) {
        const preview = document.getElementById('logoPreview');
        const removeBtn = document.getElementById('removeLogoBtn');
        if (localObjectUrl) {
            preview.innerHTML = '<img src="' + localObjectUrl + '" alt="Logo preview">';
            removeBtn.style.display = 'inline-flex';
        } else if (currentLogoPath && !pendingRemoveLogo) {
            preview.innerHTML = '<img src="' + PATH_PREFIX + currentLogoPath + '" alt="Current logo">';
            removeBtn.style.display = 'inline-flex';
        } else {
            preview.innerHTML = '<span class="no-logo">No logo</span>';
            removeBtn.style.display = 'none';
        }
    }

    document.getElementById('logoFile').addEventListener('change', function(e) {
        const f = this.files[0];
        if (!f) return;
        if (f.size > 2 * 1024 * 1024) {
            showToast(window.t('system.branding.logo_too_large'), 'error');
            this.value = '';
            return;
        }
        pendingRemoveLogo = false;
        renderLogoPreview(URL.createObjectURL(f));
    });

    document.getElementById('removeLogoBtn').addEventListener('click', function() {
        // Clear any picked file AND mark the stored logo for deletion on save.
        document.getElementById('logoFile').value = '';
        pendingRemoveLogo = true;
        renderLogoPreview();
    });

    document.getElementById('resetBtn').addEventListener('click', function() {
        document.getElementById('headerLeft').value   = DEFAULTS.header_left;
        document.getElementById('headerCenter').value = DEFAULTS.header_center;
        document.getElementById('headerRight').value  = DEFAULTS.header_right;
        document.getElementById('footerLeft').value   = DEFAULTS.footer_left;
        document.getElementById('footerCenter').value = DEFAULTS.footer_center;
        document.getElementById('footerRight').value  = DEFAULTS.footer_right;
        showToast(window.t('system.branding.reset_hint'), 'info');
    });

        /* =====================================================================
           LOGIN SCREEN DESIGNER

           The controls, the preview and the save all read the same field list,
           which PHP prints from includes/branding.php — so the browser cannot
           know about a control the server does not.
           ===================================================================== */
        let   LN_FIELDS   = <?php echo json_encode(brandingLoginFields('login'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const LN_PRESETS  = <?php echo json_encode(brandingLoginPresets(), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        /* One field table per screen, and the values for all three, so switching
           between them needs no round trip and nothing is lost until you save. */
        const LN_SCOPES   = ['login', 'portal', 'home'];
        const LN_PAGES    = <?php echo json_encode(array_map(fn($x) => $x['page'], brandingScopes()), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const LN_FIELDSET = <?php echo json_encode(['login' => brandingLoginFields('login'), 'portal' => brandingLoginFields('portal'), 'home' => brandingLoginFields('home')], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const LN_ALL      = <?php echo json_encode(['login' => brandingLoginDesign(null, 'login'), 'portal' => brandingLoginDesign(null, 'portal'), 'home' => brandingLoginDesign(null, 'home')], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        let   LN_SCOPE    = 'login';
        const LN_SAVED    = LN_ALL[LN_SCOPE];
        const LN_LOGO     = <?php echo json_encode(brandingLogoUrl(), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        /* Same-origin only, which is what makes it safe to let the preview tab
           listen: no other site can post here. */
        const lnChannel = window.BroadcastChannel ? new BroadcastChannel('freeitsm-login-preview') : null;
        const lnEl = (f) => document.getElementById('ln_' + f);

        function lnRead() {
            const d = {};
            for (const f in LN_FIELDS) {
                const el = lnEl(f);
                d[f] = el ? el.value : LN_SAVED[f];
            }
            return d;
        }

        function lnWrite(d) {
            for (const f in LN_FIELDS) {
                const el = lnEl(f);
                if (el && d[f] !== undefined && d[f] !== null) el.value = d[f];
            }
            lnSync();
        }

        /* The CSS string is built the same way brandingLoginCss() builds it, from
           values that are enums, #rrggbb and clamped numbers. It is applied to a
           preview in this administrator's own browser and is never stored — what
           gets stored goes through the server's validator. */
        function lnCss(d) {
            const dirs = {
                'down':        `linear-gradient(180deg, ${d.bg_from}, ${d.bg_to})`,
                'right':       `linear-gradient(90deg, ${d.bg_from}, ${d.bg_to})`,
                'diagonal':    `linear-gradient(135deg, ${d.bg_from}, ${d.bg_to})`,
                'diagonal-up': `linear-gradient(45deg, ${d.bg_from}, ${d.bg_to})`,
                'radial':      `radial-gradient(circle at 30% 30%, ${d.bg_from}, ${d.bg_to})`
            };
            let bg = dirs[d.bg_direction] || dirs['diagonal'];
            if (d.bg_style === 'solid') bg = d.bg_from;
            return [
                `--login-bg: ${bg}`,
                `--login-accent: ${d.accent || '#2b88d8'}`,
                `--login-logo-size: ${parseInt(d.logo_size, 10) || 250}px`,
                // 0 means no limit — `none` is CSS's own word for it, and the
                // page reads this straight into a max-height. See
                // brandingLoginCss(); the two have to agree or it is not a preview.
                `--login-logo-height: ${parseInt(d.logo_height, 10) > 0 ? parseInt(d.logo_height, 10) + 'px' : 'none'}`,
                // Only over an image — see brandingLoginCss(); the preview has to
                // agree with the page or it is not a preview.
                `--login-dim: ${d.bg_style === 'image' ? (parseInt(d.bg_dim, 10) || 0) / 100 : 0}`,
                `--login-banner-bg: ${d.banner_bg}`,
                `--login-banner-fg: ${d.banner_fg}`,
                `--login-footer-fg: ${d.footer_fg}`
            ].join('; ') + ';';
        }

        function lnBroadcast() {
            const d = lnRead();
            const msg = {
                css: lnCss(d), formPos: d.form_position, card: d.card_style,
                logoPos: d.logo_position, logo: LN_LOGO,
                heading: d.heading, subheading: d.subheading,
                bannerText: d.banner_text, bannerAt: d.banner_position,
                footerText: d.footer_text
            };
            if (lnChannel) lnChannel.postMessage(msg);
            // The embedded preview is same-origin, so it can simply be told too —
            // and this covers a browser with no BroadcastChannel.
            const fr = document.getElementById('ln_preview');
            try { fr.contentWindow.postMessage({ __loginPreview: msg }, location.origin); } catch (e) {}
        }

        /* Show only the controls that apply to the chosen background, so the
           screen never asks about a gradient's second colour when a photograph
           is selected. */
        function lnSync() {
            const style = lnEl('bg_style').value;
            document.querySelectorAll('[data-when]').forEach(el => {
                el.setAttribute('data-hidden', el.getAttribute('data-when') === style ? '0' : '1');
            });
            const size = lnEl('logo_size');
            document.getElementById('ln_logo_out').textContent = size.value + 'px';
            // 0 reads as "no limit" rather than "0px", which would say the logo
            // is not drawn at all.
            const lh = parseInt(lnEl('logo_height').value, 10) || 0;
            document.getElementById('ln_logo_h_out').textContent =
                lh > 0 ? lh + 'px' : t('system.branding.login_logo_height_none');
            document.getElementById('ln_dim_out').textContent  = lnEl('bg_dim').value + '%';

            // Contrast is advice, not a gate — but silence here means somebody
            // ships a banner nobody can read.
            const ratio = lnContrast(lnEl('banner_bg').value, lnEl('banner_fg').value);
            const note  = document.getElementById('bannerContrast');
            const ok    = ratio >= 4.5;
            note.className = 'contrast-note ' + (ok ? 'ok' : 'warn');
            note.textContent = (ok ? '\u2713 ' : '\u26a0 ') +
                t('system.branding.login_contrast').replace('{ratio}', ratio.toFixed(1));

            lnBroadcast();
        }

        function lnContrast(a, b) {
            const lum = (hex) => {
                const v = [1, 3, 5].map(i => {
                    const c = parseInt(hex.substr(i, 2), 16) / 255;
                    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
                });
                return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
            };
            const x = lum(a), y = lum(b);
            return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
        }

        document.querySelectorAll('.preset').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = LN_PRESETS[btn.dataset.preset];
                if (!p) return;
                // A preset sets the LOOK and leaves the words alone: nobody wants
                // their carefully written welcome message replaced by a theme.
                const { label, ...values } = p;
                lnWrite({ ...lnRead(), ...values });
            });
        });

        document.getElementById('ln_reset').addEventListener('click', () => {
            const defaults = {};
            for (const f in LN_FIELDS) defaults[f] = LN_FIELDS[f].default;
            lnWrite(defaults);
        });

        for (const f in LN_FIELDS) {
            const el = lnEl(f);
            if (el) el.addEventListener('input', lnSync);
        }
        /* Fit the 1280x800 frame into whatever width the panel has. Recomputed
           on resize, because this panel is half of a two-column grid that
           becomes one column on a narrow screen.
           ⚠️ clientWidth, not getBoundingClientRect(): the wrapper is the
           element being scaled INTO, and mixing scaled rects with unscaled
           layout properties is how this codebase has produced four false
           measurements before now. */
        const LN_W = 1280, LN_H = 800;
        function lnFit() {
            const wrap = document.getElementById('ln_frame');
            const frame = document.getElementById('ln_preview');
            if (!wrap || !frame) return;
            const scale = wrap.clientWidth / LN_W;
            frame.style.transform = 'scale(' + scale + ')';
            wrap.style.height = (LN_H * scale) + 'px';
        }
        if (window.ResizeObserver) new ResizeObserver(lnFit).observe(document.getElementById('ln_frame'));
        window.addEventListener('resize', lnFit);
        lnFit();

        /* Switching screens keeps whatever you have typed on the one you are
           leaving — in memory, not saved — so flicking between the three to
           compare them does not throw work away. */
        let lnStarted = false;
        function lnSwitch(scope) {
            /* 🔴 NOT ON THE FIRST CALL. There is no outgoing screen to remember
               when the page opens, and the controls are still empty — capturing
               them here overwrote everything just loaded from the database with
               a blank form.

               It hid for hours because the first <option> used to be `gradient`,
               which is also the default, so the clobbered value happened to
               match. Adding a `theme` option in front of it made the same bug
               save `theme` for a screen whose default is a gradient. ⭐ A bug
               masked by a coincidence is still a bug, and it surfaces when
               something unrelated changes. */
            if (lnStarted) LN_ALL[LN_SCOPE] = lnRead();   // remember the outgoing one
            lnStarted = true;
            LN_SCOPE  = scope;
            LN_FIELDS = LN_FIELDSET[scope];

            document.querySelectorAll('.scope').forEach(b =>
                b.classList.toggle('active', b.dataset.scope === scope));
            document.getElementById('ln_scope_desc').textContent =
                t('system.branding.scope_' + scope + '_desc');

            // Hide the controls this screen has no use for.
            for (const f of Object.keys(LN_FIELDSET.login)) {
                const el = lnEl(f);
                if (!el) continue;
                const holder = el.closest('.dlabel') || el.parentElement;
                if (holder) holder.setAttribute('data-field-hidden', LN_FIELDS[f] ? '0' : '1');
            }

            const frame = document.getElementById('ln_preview');
            const base  = <?php echo json_encode(defined('BASE_URL') ? BASE_URL : '/'); ?>;
            frame.src = base + LN_PAGES[scope] + '?preview=1';
            document.getElementById('ln_open_tab').href = frame.src;

            lnWrite(LN_ALL[scope]);
        }

        document.querySelectorAll('.scope').forEach(b =>
            b.addEventListener('click', () => lnSwitch(b.dataset.scope)));

        /* "Copy from the analyst sign-in screen" — most installs want the three to
           match, and typing the same six colours three times is nobody's idea of
           a good afternoon. Only the fields the target screen actually has. */
        document.getElementById('ln_copy').addEventListener('click', () => {
            if (LN_SCOPE === 'login') return;
            const from = LN_ALL.login, into = { ...lnRead() };
            for (const f in LN_FIELDS) if (from[f] !== undefined) into[f] = from[f];
            lnWrite(into);
        });

        // The preview iframe has to have loaded before it can be told anything.
        document.getElementById('ln_preview').addEventListener('load', lnBroadcast);
        lnSwitch('login');

    document.getElementById('brandingForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;

        const fd = new FormData();
        // Every designer field, named exactly as the server's field table
        // names it, so save_branding.php can loop the same list.
        // Every screen, not just the one on display: switching tabs keeps edits
        // in memory, so Save has to persist all three or the other two are lost.
        LN_ALL[LN_SCOPE] = lnRead();
        for (const sc of LN_SCOPES) {
            for (const f in LN_FIELDSET[sc]) fd.append(sc + '_' + f, LN_ALL[sc][f]);
        }
        const lnBg = document.getElementById('ln_bg_file');
        if (lnBg && lnBg.files[0]) fd.append('login_bg', lnBg.files[0]);
        fd.append('header_left',   document.getElementById('headerLeft').value);
        fd.append('header_center', document.getElementById('headerCenter').value);
        fd.append('header_right',  document.getElementById('headerRight').value);
        fd.append('footer_left',   document.getElementById('footerLeft').value);
        fd.append('footer_center', document.getElementById('footerCenter').value);
        fd.append('footer_right',  document.getElementById('footerRight').value);
        fd.append('landing_page',  document.getElementById('landingPage').value);

        const logoInput = document.getElementById('logoFile');
        if (logoInput.files && logoInput.files[0]) {
            fd.append('logo', logoInput.files[0]);
        } else if (pendingRemoveLogo) {
            fd.append('remove_logo', '1');
        }

        try {
            const resp = await fetch(API_BASE + 'save_branding.php', {
                method: 'POST',
                body: fd
            });
            const data = await resp.json();
            if (data.success) {
                showToast(window.t('system.branding.saved'), 'success');
                // Re-fetch so the preview reflects whatever's actually on disk now
                pendingRemoveLogo = false;
                logoInput.value = '';
                await loadBranding();
            } else {
                showToast(window.t('system.branding.error', { error: data.error }), 'error');
            }
        } catch (err) {
            showToast(window.t('system.branding.save_failed'), 'error');
        }
        btn.disabled = false;
    });

    loadBranding();
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
