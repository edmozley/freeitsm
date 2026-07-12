<?php
/**
 * Forms Module Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}
requireModuleAccess('forms');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        /* Module accent (teal). */
        body { --accent: var(--forms-accent, #00897b); --accent-hover: var(--forms-accent-hover, #00695c); }

        .fm-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }

        /* Left sidebar navigation */
        .fm-help-sidebar {
            width: 260px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .fm-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .fm-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        .fm-help-nav-link:hover {
            background: var(--surface-hover, #f5f5f5);
            color: var(--text, #333);
        }

        .fm-help-nav-link.active {
            background: var(--forms-accent-soft, #e0f2f1);
            color: var(--forms-accent-hover, #004d40);
            font-weight: 600;
        }

        .fm-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--border-soft, #eee);
            color: var(--text-dim, #888);
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }

        .fm-help-nav-link.active .fm-help-nav-num {
            background: var(--forms-accent, #00897b);
            color: white;
        }

        /* Main content */
        .fm-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .fm-help-hero {
            background: linear-gradient(135deg, #00897b 0%, #00695c 50%, #004d40 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .fm-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .fm-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .fm-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .fm-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }

        .fm-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .fm-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .fm-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .fm-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: var(--text-muted, #666);
            line-height: 1.6;
        }

        .fm-help-section > p {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .fm-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--forms-accent-soft, #e0f2f1);
            color: var(--forms-accent-hover, #004d40);
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .fm-help-section-num.highlight {
            background: var(--forms-accent, #00897b);
            color: white;
        }

        /* Feature cards grid */
        .fm-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .fm-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, white);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .fm-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow, rgba(0,0,0,0.08));
        }

        .fm-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .fm-help-feature-icon.teal { background: #e0f2f1; color: #00897b; }
        .fm-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .fm-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .fm-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .fm-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: var(--text, #333);
        }

        .fm-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        /* Numbered steps */
        .fm-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .fm-help-step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--surface-2, #fafafa);
            font-size: 14px;
            color: var(--text-muted, #444);
            line-height: 1.5;
        }

        .fm-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--forms-accent, #00897b);
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .fm-help-section-highlight {
            background: var(--forms-accent-soft, #e0f2f1);
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid var(--forms-accent-soft, #80cbc4);
        }

        .fm-help-intro {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .fm-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .fm-help-fields div {
            padding: 8px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
        }

        /* Data cards */
        .fm-help-data-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .fm-help-data-card {
            padding: 12px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            border-left: 3px solid var(--forms-accent, #00897b);
        }

        .fm-help-data-card strong {
            display: block;
            font-size: 13px;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .fm-help-data-card span {
            font-size: 12px;
            color: var(--text-dim, #777);
            line-height: 1.4;
        }

        /* Flow diagram */
        .fm-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .fm-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .fm-help-flow-step.build { background: #e0f2f1; color: #004d40; }
        .fm-help-flow-step.fill { background: #e3f2fd; color: #1565c0; }
        .fm-help-flow-step.submit { background: #e8f5e9; color: #2e7d32; }
        .fm-help-flow-step.review { background: #fff3e0; color: #e65100; }

        .fm-help-flow-arrow {
            padding: 0 8px;
            color: var(--text-faint, #bbb);
            font-size: 18px;
        }

        /* Tip callout */
        .fm-help-tip {
            font-size: 13px !important;
            color: var(--forms-accent-hover, #004d40) !important;
            background: var(--forms-accent-soft, #e0f2f1);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--forms-accent, #00897b);
            margin-top: 10px;
        }

        /* Quick tips grid */
        .fm-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .fm-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }

        .fm-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .fm-help-tip-card strong {
            color: var(--text, #333);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .fm-help-sidebar { display: none; }
            .fm-help-content { padding: 10px 24px 40px; }
            .fm-help-hero { padding: 30px 24px; }
            .fm-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .fm-help-features-grid { grid-template-columns: 1fr; }
            .fm-help-data-grid { grid-template-columns: 1fr; }
            .fm-help-tips-grid { grid-template-columns: 1fr; }
        }

        /* Dark mode: darken the teal hero gradient (light mode untouched). */
        [data-theme-mode="dark"] .fm-help-hero {
            background: linear-gradient(135deg, #0f3b36 0%, #0a2a27 100%);
        }

        /* Dark mode: tone the highlighted section's bright teal top border. */
        [data-theme-mode="dark"] .fm-help-section-highlight {
            border-top-color: #1f5049;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="fm-help-container">
        <!-- Left pane navigation -->
        <div class="fm-help-sidebar">
            <h3><?php echo htmlspecialchars(t('forms.help.guide')); ?></h3>
            <a href="#overview" class="fm-help-nav-link active" data-section="overview">
                <span class="fm-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('forms.help.nav_overview')); ?>
            </a>
            <a href="#building-forms" class="fm-help-nav-link" data-section="building-forms">
                <span class="fm-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('forms.help.nav_building')); ?>
            </a>
            <a href="#filling-in" class="fm-help-nav-link" data-section="filling-in">
                <span class="fm-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('forms.help.nav_filling')); ?>
            </a>
            <a href="#submissions" class="fm-help-nav-link" data-section="submissions">
                <span class="fm-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('forms.help.nav_submissions')); ?>
            </a>
            <a href="#export" class="fm-help-nav-link" data-section="export">
                <span class="fm-help-nav-num">5</span>
                <?php echo htmlspecialchars(t('forms.help.nav_export')); ?>
            </a>
            <a href="#settings" class="fm-help-nav-link" data-section="settings">
                <span class="fm-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('forms.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="fm-help-nav-link" data-section="tips">
                <span class="fm-help-nav-num">7</span>
                <?php echo htmlspecialchars(t('forms.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="fm-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="fm-help-hero">
                <h2><?php echo htmlspecialchars(t('forms.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('forms.help.hero_sub')); ?></p>
            </div>

            <div class="fm-help-content">

                <!-- Section 1: Overview -->
                <div class="fm-help-section" id="overview">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('forms.help.overview_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('forms.help.overview_body')); ?></p>
                        </div>
                    </div>

                    <div class="fm-help-flow">
                        <div class="fm-help-flow-step build"><?php echo htmlspecialchars(t('forms.help.flow_build')); ?></div>
                        <div class="fm-help-flow-arrow">&rarr;</div>
                        <div class="fm-help-flow-step fill"><?php echo htmlspecialchars(t('forms.help.flow_fill')); ?></div>
                        <div class="fm-help-flow-arrow">&rarr;</div>
                        <div class="fm-help-flow-step submit"><?php echo htmlspecialchars(t('forms.help.flow_submit')); ?></div>
                        <div class="fm-help-flow-arrow">&rarr;</div>
                        <div class="fm-help-flow-step review"><?php echo htmlspecialchars(t('forms.help.flow_review')); ?></div>
                    </div>

                    <div class="fm-help-features-grid">
                        <div class="fm-help-feature-card">
                            <div class="fm-help-feature-icon teal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('forms.help.card_builder_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('forms.help.card_builder_body')); ?></p>
                        </div>
                        <div class="fm-help-feature-card">
                            <div class="fm-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('forms.help.card_fill_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('forms.help.card_fill_body')); ?></p>
                        </div>
                        <div class="fm-help-feature-card">
                            <div class="fm-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('forms.help.card_subs_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('forms.help.card_subs_body')); ?></p>
                        </div>
                        <div class="fm-help-feature-card">
                            <div class="fm-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('forms.help.card_export_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('forms.help.card_export_body')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Building Forms (highlighted) -->
                <div class="fm-help-section fm-help-section-highlight" id="building-forms">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num highlight">2</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.building_title')); ?></h3>
                    </div>
                    <p class="fm-help-intro"><?php echo htmlspecialchars(t('forms.help.building_intro')); ?></p>

                    <div class="fm-help-steps">
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">1</div>
                            <div>
                                <?php echo t('forms.help.building_step1'); ?>
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">2</div>
                            <div>
                                <?php echo t('forms.help.building_step2'); ?>
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">3</div>
                            <div>
                                <?php echo t('forms.help.building_step3'); ?>
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">4</div>
                            <div>
                                <?php echo t('forms.help.building_step4'); ?>
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">5</div>
                            <div>
                                <?php echo t('forms.help.building_step5'); ?>
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">6</div>
                            <div>
                                <?php echo t('forms.help.building_step6'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="fm-help-tip"><?php echo htmlspecialchars(t('forms.help.building_tip')); ?></p>
                </div>

                <!-- Section 3: Filling in Forms -->
                <div class="fm-help-section" id="filling-in">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.filling_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('forms.help.filling_body')); ?></p>

                    <div class="fm-help-data-grid">
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_logo_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_logo_body')); ?></span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_text_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_text_body')); ?></span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_textarea_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_textarea_body')); ?></span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_checkbox_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_checkbox_body')); ?></span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_dropdown_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_dropdown_body')); ?></span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_required_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_required_body')); ?></span>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('forms.help.filling_validate')); ?></p>

                    <p class="fm-help-tip"><?php echo htmlspecialchars(t('forms.help.filling_tip')); ?></p>
                </div>

                <!-- Section 4: Submissions -->
                <div class="fm-help-section" id="submissions">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.subs_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('forms.help.subs_body')); ?></p>

                    <div class="fm-help-steps">
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">1</div>
                            <div>
                                <?php echo t('forms.help.subs_step1'); ?>
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">2</div>
                            <div>
                                <?php echo t('forms.help.subs_step2'); ?>
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">3</div>
                            <div>
                                <?php echo t('forms.help.subs_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="fm-help-tip"><?php echo htmlspecialchars(t('forms.help.subs_tip')); ?></p>
                </div>

                <!-- Section 5: Export (highlighted) -->
                <div class="fm-help-section fm-help-section-highlight" id="export">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num highlight">5</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.export_title')); ?></h3>
                    </div>
                    <p class="fm-help-intro"><?php echo htmlspecialchars(t('forms.help.export_intro')); ?></p>

                    <div class="fm-help-fields">
                        <div><?php echo t('forms.help.export_f1'); ?></div>
                        <div><?php echo t('forms.help.export_f2'); ?></div>
                        <div><?php echo t('forms.help.export_f3'); ?></div>
                        <div><?php echo t('forms.help.export_f4'); ?></div>
                    </div>

                    <p class="fm-help-tip"><?php echo t('forms.help.export_tip'); ?></p>
                </div>

                <!-- Section 6: Settings -->
                <div class="fm-help-section" id="settings">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.settings_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('forms.help.settings_body')); ?></p>

                    <div class="fm-help-steps">
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">1</div>
                            <div>
                                <?php echo t('forms.help.settings_step1'); ?>
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">2</div>
                            <div>
                                <?php echo t('forms.help.settings_step2'); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('forms.help.settings_options')); ?></p>

                    <div class="fm-help-data-grid">
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.settings_left_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.settings_left_body')); ?></span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.settings_center_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.settings_center_body')); ?></span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.settings_right_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.settings_right_body')); ?></span>
                        </div>
                    </div>

                    <p class="fm-help-tip"><?php echo htmlspecialchars(t('forms.help.settings_tip')); ?></p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="fm-help-section" id="tips">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.tips_title')); ?></h3>
                    </div>
                    <div class="fm-help-tips-grid">
                        <div class="fm-help-tip-card">
                            <div class="fm-help-tip-icon">&#128221;</div>
                            <div><strong><?php echo htmlspecialchars(t('forms.help.tip1_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.tip1_body')); ?></div>
                        </div>
                        <div class="fm-help-tip-card">
                            <div class="fm-help-tip-icon">&#9989;</div>
                            <div><strong><?php echo htmlspecialchars(t('forms.help.tip2_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.tip2_body')); ?></div>
                        </div>
                        <div class="fm-help-tip-card">
                            <div class="fm-help-tip-icon">&#128203;</div>
                            <div><strong><?php echo htmlspecialchars(t('forms.help.tip3_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.tip3_body')); ?></div>
                        </div>
                        <div class="fm-help-tip-card">
                            <div class="fm-help-tip-icon">&#128202;</div>
                            <div><strong><?php echo htmlspecialchars(t('forms.help.tip4_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.tip4_body')); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.fm-help-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const id = link.dataset.section;
            const el = document.getElementById(id);
            if (el) sections.push({ id, el });
        });

        helpMain.addEventListener('scroll', function() {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;

            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) {
                    current = s.id;
                }
            }

            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Scroll within the help container, not the page
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const el = document.getElementById(this.dataset.section);
                if (el) {
                    const containerTop = helpMain.getBoundingClientRect().top;
                    const elTop = el.getBoundingClientRect().top;
                    helpMain.scrollTo({ top: helpMain.scrollTop + (elTop - containerTop) - 20, behavior: 'smooth' });
                }
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>
