<?php
/**
 * Forms Settings - Configure forms module settings
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/ai_settings_panel.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.settings.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/ai-settings.js"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=16">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
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
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="layout" onclick="switchTab('layout')"><?php echo htmlspecialchars(t('forms.settings.tab_layout')); ?></button>
            <button class="tab" data-tab="ai" onclick="switchTab('ai')"><?php echo htmlspecialchars(t('forms.settings.tab_ai')); ?></button>
        </div>

        <!-- Layout Tab -->
        <div class="tab-content active" id="layout-tab">
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
                <img id="logoPreview" src="../../assets/images/CompanyLogo.png" alt="<?php echo htmlspecialchars(t('forms.settings.logo_alt')); ?>" class="align-center">
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" onclick="saveSettings()"><?php echo htmlspecialchars(t('forms.settings.save')); ?></button>
            </div>
        </div>

        <!-- AI Tab — per-module billing. Provider, model, key + test
             connection. Saved settings drive api/forms/ai_generate.php. -->
        <div class="tab-content" id="ai-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('forms.settings.ai_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 24px; max-width: 720px;">
                <?php echo htmlspecialchars(t('forms.settings.ai_intro')); ?>
            </p>

            <?php renderAiSettingsPanel('forms_ai'); ?>
        </div>
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
</body>
</html>
