<?php
/**
 * CMDB Module Help Guide — full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'cmdb'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM - <?php echo htmlspecialchars(t('cmdb.help.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=14">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--cmdb-accent); }
        .cmdb-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }

        /* Left sidebar navigation */
        .cmdb-help-sidebar {
            width: 260px;
            background: var(--surface, #ffffff);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
        }
        .cmdb-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .cmdb-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .cmdb-help-nav-link:hover { background: #fdf2f8; color: var(--cmdb-accent, #be185d); }
        [data-theme-mode="dark"] .cmdb-help-nav-link:hover { background: var(--surface-hover, #fdf2f8); }
        .cmdb-help-nav-link.active { background: #fce7f3; color: var(--cmdb-accent, #be185d); font-weight: 600; }
        [data-theme-mode="dark"] .cmdb-help-nav-link.active { background: var(--cmdb-accent-soft, #fce7f3); }
        .cmdb-help-nav-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 24px; height: 24px;
            border-radius: 50%;
            background: var(--surface-3, #eee); color: var(--text-dim, #888);
            font-weight: 700; font-size: 11px;
            flex-shrink: 0;
        }
        .cmdb-help-nav-link.active .cmdb-help-nav-num { background: var(--cmdb-accent, #be185d); color: var(--cmdb-on-accent, #ffffff); }

        /* Main content area */
        .cmdb-help-main { flex: 1; overflow-y: auto; }
        .cmdb-help-hero {
            background: linear-gradient(135deg, #ec4899 0%, #be185d 50%, #9d174d 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        [data-theme-mode="dark"] .cmdb-help-hero {
            background: linear-gradient(135deg, #4a1330 0%, #3a0f26 50%, #2a0b1c 100%);
        }
        .cmdb-help-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .cmdb-help-hero p { margin: 0; font-size: 15px; opacity: 0.85; max-width: 720px; margin: 0 auto; }
        .cmdb-help-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        /* Sections */
        .cmdb-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }
        .cmdb-help-section:last-child { border-bottom: none; padding-bottom: 0; }
        .cmdb-help-section-header {
            display: flex; align-items: flex-start; gap: 14px;
            margin-bottom: 16px;
        }
        .cmdb-help-section-header h3 { margin: 0; font-size: 18px; color: var(--text, #333); }
        .cmdb-help-section-header p { margin: 6px 0 0; font-size: 14px; color: var(--text-muted, #666); line-height: 1.6; }
        .cmdb-help-section > p { font-size: 14px; color: var(--text-muted, #555); line-height: 1.7; margin: 0 0 14px; }
        .cmdb-help-section-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px;
            border-radius: 50%;
            background: #fce7f3; color: var(--cmdb-accent, #be185d);
            font-weight: 700; font-size: 14px;
            flex-shrink: 0;
        }
        [data-theme-mode="dark"] .cmdb-help-section-num { background: var(--cmdb-accent-soft, #fce7f3); }
        .cmdb-help-section-num.highlight { background: var(--cmdb-accent, #be185d); color: var(--cmdb-on-accent, #ffffff); }

        /* Highlighted section */
        .cmdb-help-section-highlight {
            background: #fdf2f8;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #fbcfe8;
        }
        [data-theme-mode="dark"] .cmdb-help-section-highlight {
            background: var(--surface-2, #fdf2f8);
            border-top-color: var(--border, #fbcfe8);
        }

        /* Feature cards grid */
        .cmdb-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-left: 46px;
        }
        .cmdb-help-feature-card {
            padding: 16px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, #ffffff);
        }
        .cmdb-help-feature-card h4 { margin: 0 0 6px; font-size: 14px; color: var(--text, #333); }
        .cmdb-help-feature-card p { margin: 0; font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; }
        .cmdb-help-feature-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 10px;
            background: #fce7f3; color: var(--cmdb-accent, #be185d);
        }
        [data-theme-mode="dark"] .cmdb-help-feature-icon { background: var(--cmdb-accent-soft, #fce7f3); }

        /* Concept callouts (Class / Object / Property / etc) */
        .concept-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 16px;
            padding: 12px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            border-left: 3px solid var(--cmdb-accent, #be185d);
            margin-bottom: 10px;
            margin-left: 46px;
            align-items: start;
        }
        .concept-name {
            font-weight: 700;
            color: var(--cmdb-accent, #be185d);
            font-size: 14px;
        }
        .concept-desc {
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.55;
        }
        .concept-desc em { color: var(--cmdb-accent, #be185d); font-style: normal; font-weight: 500; }

        /* Numbered steps */
        .cmdb-help-steps {
            display: flex; flex-direction: column; gap: 10px;
            margin-left: 46px;
        }
        .cmdb-help-step-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--surface-2, #fafafa);
            font-size: 14px; color: var(--text, #444); line-height: 1.5;
        }
        .cmdb-help-step-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 26px; height: 26px;
            border-radius: 50%;
            background: var(--cmdb-accent, #be185d); color: var(--cmdb-on-accent, #ffffff);
            font-weight: 700; font-size: 13px;
            flex-shrink: 0;
        }

        /* Tip callout */
        .cmdb-help-tip {
            font-size: 13px;
            color: var(--cmdb-accent-hover, #9d174d);
            background: #fdf2f8;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--cmdb-accent, #be185d);
            margin-top: 14px;
            margin-left: 46px;
            line-height: 1.55;
        }
        [data-theme-mode="dark"] .cmdb-help-tip { background: var(--surface-2, #fdf2f8); }
        .cmdb-help-tip strong { color: #831843; }
        [data-theme-mode="dark"] .cmdb-help-tip strong { color: var(--cmdb-accent, #831843); }

        /* The SQL hierarchy diagram */
        .hierarchy-diagram {
            font-family: 'Consolas', 'Monaco', monospace;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            padding: 18px 20px;
            margin: 14px 0 14px 46px;
            font-size: 13px;
            color: var(--text, #333);
            line-height: 1.8;
        }
        .hierarchy-diagram .node {
            display: inline-block;
            background: var(--surface, #ffffff);
            padding: 3px 10px;
            border-radius: 4px;
            border: 1px solid #fbcfe8;
            color: var(--cmdb-accent, #be185d);
            font-weight: 600;
        }
        [data-theme-mode="dark"] .hierarchy-diagram .node { border-color: var(--border, #fbcfe8); }
        .hierarchy-diagram .arrow { color: var(--text-faint, #d1d5db); }

        /* When to use which — three-column comparison */
        .when-table {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin: 16px 0 0 46px;
        }
        .when-card {
            background: var(--surface, #ffffff);
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            padding: 16px;
        }
        .when-card h4 {
            font-size: 13px;
            color: var(--cmdb-accent, #be185d);
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .when-card p { font-size: 12.5px; color: var(--text-muted, #555); margin: 0 0 6px; line-height: 1.5; }
        .when-card .ex { font-size: 12px; color: var(--text-dim, #888); font-style: italic; }

        /* Two-column tip pairs */
        .tips-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 14px 0 0 46px;
        }
        .tip-card {
            background: var(--surface, #ffffff);
            padding: 14px 16px;
            border-radius: 8px;
            border: 1px solid var(--border, #e0e0e0);
        }
        .tip-card h4 {
            font-size: 13px;
            color: var(--cmdb-accent, #be185d);
            margin: 0 0 6px;
        }
        .tip-card p {
            font-size: 12.5px;
            color: var(--text-muted, #555);
            margin: 0;
            line-height: 1.55;
        }

        kbd {
            display: inline-block;
            background: var(--surface, #ffffff);
            border: 1px solid var(--border, #d1d5db);
            border-bottom-width: 2px;
            border-radius: 3px;
            padding: 1px 5px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 11px;
            color: var(--text-muted, #4b5563);
        }

        @media (max-width: 900px) {
            .cmdb-help-features-grid,
            .when-table,
            .tips-grid { grid-template-columns: 1fr; }
            .concept-row { grid-template-columns: 1fr; }
            .concept-row { margin-left: 0; }
            .cmdb-help-steps { margin-left: 0; }
            .cmdb-help-tip { margin-left: 0; }
            .hierarchy-diagram { margin-left: 0; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="cmdb-help-container">
        <!-- Left pane navigation -->
        <div class="cmdb-help-sidebar">
            <h3><?php echo htmlspecialchars(t('cmdb.help.sidebar_label')); ?></h3>
            <a href="#overview" class="cmdb-help-nav-link active" data-section="overview">
                <span class="cmdb-help-nav-num">1</span> <?php echo t('cmdb.help.nav_overview'); ?>
            </a>
            <a href="#concepts" class="cmdb-help-nav-link" data-section="concepts">
                <span class="cmdb-help-nav-num">2</span> <?php echo t('cmdb.help.nav_concepts'); ?>
            </a>
            <a href="#classes" class="cmdb-help-nav-link" data-section="classes">
                <span class="cmdb-help-nav-num">3</span> <?php echo t('cmdb.help.nav_classes'); ?>
            </a>
            <a href="#ai-suggest" class="cmdb-help-nav-link" data-section="ai-suggest">
                <span class="cmdb-help-nav-num">4</span> <?php echo t('cmdb.help.nav_ai_suggest'); ?>
            </a>
            <a href="#objects" class="cmdb-help-nav-link" data-section="objects">
                <span class="cmdb-help-nav-num">5</span> <?php echo t('cmdb.help.nav_objects'); ?>
            </a>
            <a href="#hierarchy" class="cmdb-help-nav-link" data-section="hierarchy">
                <span class="cmdb-help-nav-num">6</span> <?php echo t('cmdb.help.nav_hierarchy'); ?>
            </a>
            <a href="#relationships" class="cmdb-help-nav-link" data-section="relationships">
                <span class="cmdb-help-nav-num">7</span> <?php echo t('cmdb.help.nav_relationships'); ?>
            </a>
            <a href="#when-to-use" class="cmdb-help-nav-link" data-section="when-to-use">
                <span class="cmdb-help-nav-num">8</span> <?php echo t('cmdb.help.nav_when_to_use'); ?>
            </a>
            <a href="#synthesis" class="cmdb-help-nav-link" data-section="synthesis">
                <span class="cmdb-help-nav-num">9</span> <?php echo t('cmdb.help.nav_synthesis'); ?>
            </a>
            <a href="#tickets" class="cmdb-help-nav-link" data-section="tickets">
                <span class="cmdb-help-nav-num">10</span> <?php echo t('cmdb.help.nav_tickets'); ?>
            </a>
            <a href="#settings" class="cmdb-help-nav-link" data-section="settings">
                <span class="cmdb-help-nav-num">11</span> <?php echo t('cmdb.help.nav_settings'); ?>
            </a>
            <a href="#tips" class="cmdb-help-nav-link" data-section="tips">
                <span class="cmdb-help-nav-num">12</span> <?php echo t('cmdb.help.nav_tips'); ?>
            </a>

        </div>

        <!-- Main content -->
        <div class="cmdb-help-main" id="helpMain">
            <div class="cmdb-help-hero">
                <h2><?php echo htmlspecialchars(t('cmdb.help.hero_title')); ?></h2>
                <p><?php echo t('cmdb.help.hero_intro'); ?></p>
            </div>

            <div class="cmdb-help-content">

                <!-- 1. Overview -->
                <div class="cmdb-help-section" id="overview">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('cmdb.help.overview_heading')); ?></h3>
                            <p><?php echo t('cmdb.help.overview_intro'); ?></p>
                        </div>
                    </div>

                    <div class="cmdb-help-features-grid">
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 22V8l10-6 10 6v14"></path><path d="M2 12h20"></path><path d="M2 17h20"></path><line x1="12" y1="2" x2="12" y2="22"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('cmdb.help.overview_card1_title')); ?></h4>
                            <p><?php echo t('cmdb.help.overview_card1_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('cmdb.help.overview_card2_title')); ?></h4>
                            <p><?php echo t('cmdb.help.overview_card2_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('cmdb.help.overview_card3_title')); ?></h4>
                            <p><?php echo t('cmdb.help.overview_card3_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('cmdb.help.overview_card4_title')); ?></h4>
                            <p><?php echo t('cmdb.help.overview_card4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 2. Core concepts -->
                <div class="cmdb-help-section cmdb-help-section-highlight" id="concepts">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num highlight">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('cmdb.help.concepts_heading')); ?></h3>
                            <p><?php echo t('cmdb.help.concepts_intro'); ?></p>
                        </div>
                    </div>

                    <div class="concept-row">
                        <div class="concept-name"><?php echo t('cmdb.help.concept_class_name'); ?></div>
                        <div class="concept-desc"><?php echo t('cmdb.help.concept_class_desc'); ?></div>
                    </div>
                    <div class="concept-row">
                        <div class="concept-name"><?php echo t('cmdb.help.concept_object_name'); ?></div>
                        <div class="concept-desc"><?php echo t('cmdb.help.concept_object_desc'); ?></div>
                    </div>
                    <div class="concept-row">
                        <div class="concept-name"><?php echo t('cmdb.help.concept_property_name'); ?></div>
                        <div class="concept-desc"><?php echo t('cmdb.help.concept_property_desc'); ?></div>
                    </div>
                    <div class="concept-row">
                        <div class="concept-name"><?php echo t('cmdb.help.concept_hierarchy_name'); ?></div>
                        <div class="concept-desc"><?php echo t('cmdb.help.concept_hierarchy_desc'); ?></div>
                    </div>
                    <div class="concept-row">
                        <div class="concept-name"><?php echo t('cmdb.help.concept_relationship_name'); ?></div>
                        <div class="concept-desc"><?php echo t('cmdb.help.concept_relationship_desc'); ?></div>
                    </div>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.concepts_tip'); ?>
                    </div>
                </div>

                <!-- 3. Classes & properties -->
                <div class="cmdb-help-section" id="classes">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">3</span>
                        <div>
                            <h3><?php echo t('cmdb.help.classes_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.classes_intro'); ?></p>
                        </div>
                    </div>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div><?php echo t('cmdb.help.classes_step1'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div><?php echo t('cmdb.help.classes_step2'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div><?php echo t('cmdb.help.classes_step3'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">4</span>
                            <div><?php echo t('cmdb.help.classes_step4'); ?></div>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.classes_tip1'); ?>
                    </div>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.classes_tip2'); ?>
                    </div>
                </div>

                <!-- 4. AI Suggest Properties -->
                <div class="cmdb-help-section cmdb-help-section-highlight" id="ai-suggest">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num highlight">4</span>
                        <div>
                            <h3><?php echo t('cmdb.help.ai_suggest_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.ai_suggest_intro'); ?></p>
                        </div>
                    </div>

                    <p style="margin-left: 46px;"><?php echo t('cmdb.help.ai_suggest_lead'); ?></p>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div><?php echo t('cmdb.help.ai_suggest_step1'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div><?php echo t('cmdb.help.ai_suggest_step2'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div><?php echo t('cmdb.help.ai_suggest_step3'); ?></div>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.ai_suggest_tip'); ?>
                    </div>
                </div>

                <!-- 5. Adding objects -->
                <div class="cmdb-help-section" id="objects">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">5</span>
                        <div>
                            <h3><?php echo t('cmdb.help.objects_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.objects_intro'); ?></p>
                        </div>
                    </div>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div><?php echo t('cmdb.help.objects_step1'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div><?php echo t('cmdb.help.objects_step2'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div><?php echo t('cmdb.help.objects_step3'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">4</span>
                            <div><?php echo t('cmdb.help.objects_step4'); ?></div>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.objects_tip'); ?>
                    </div>
                </div>

                <!-- 6. Hierarchy -->
                <div class="cmdb-help-section" id="hierarchy">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">6</span>
                        <div>
                            <h3><?php echo t('cmdb.help.hierarchy_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.hierarchy_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('cmdb.help.hierarchy_body'); ?></p>

                    <div class="hierarchy-diagram">
                        <span class="node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n1')); ?></span><br>
                        <span class="arrow">&#9492;&#9472;&#9472;</span> <span class="node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n2')); ?></span><br>
                        <span class="arrow">&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n3')); ?></span><br>
                        <span class="arrow">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n4')); ?></span><br>
                        <span class="arrow">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n5')); ?></span>
                    </div>

                    <p style="margin-left: 46px;"><?php echo t('cmdb.help.hierarchy_body2'); ?></p>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.hierarchy_tip'); ?>
                    </div>
                </div>

                <!-- 7. Relationships -->
                <div class="cmdb-help-section" id="relationships">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">7</span>
                        <div>
                            <h3><?php echo t('cmdb.help.relationships_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.relationships_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('cmdb.help.relationships_body'); ?></p>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div><?php echo t('cmdb.help.relationships_step1'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div><?php echo t('cmdb.help.relationships_step2'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div><?php echo t('cmdb.help.relationships_step3'); ?></div>
                        </div>
                    </div>

                    <p style="margin-left: 46px; margin-top: 14px;"><?php echo t('cmdb.help.relationships_body2'); ?></p>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.relationships_tip'); ?>
                    </div>
                </div>

                <!-- 8. When to use which -->
                <div class="cmdb-help-section cmdb-help-section-highlight" id="when-to-use">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num highlight">8</span>
                        <div>
                            <h3><?php echo t('cmdb.help.when_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.when_intro'); ?></p>
                        </div>
                    </div>

                    <div class="when-table">
                        <div class="when-card">
                            <h4><?php echo t('cmdb.help.when_card1_title'); ?></h4>
                            <p><?php echo t('cmdb.help.when_card1_body'); ?></p>
                            <p class="ex"><?php echo t('cmdb.help.when_card1_ex'); ?></p>
                        </div>
                        <div class="when-card">
                            <h4><?php echo t('cmdb.help.when_card2_title'); ?></h4>
                            <p><?php echo t('cmdb.help.when_card2_body'); ?></p>
                            <p class="ex"><?php echo t('cmdb.help.when_card2_ex'); ?></p>
                        </div>
                        <div class="when-card">
                            <h4><?php echo t('cmdb.help.when_card3_title'); ?></h4>
                            <p><?php echo t('cmdb.help.when_card3_body'); ?></p>
                            <p class="ex"><?php echo t('cmdb.help.when_card3_ex'); ?></p>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.when_tip'); ?>
                    </div>
                </div>

                <!-- 9. Synthesis layer -->
                <div class="cmdb-help-section" id="synthesis">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">9</span>
                        <div>
                            <h3><?php echo t('cmdb.help.synthesis_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.synthesis_intro'); ?></p>
                        </div>
                    </div>

                    <div class="cmdb-help-features-grid">
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                            </div>
                            <h4><?php echo t('cmdb.help.synthesis_card1_title'); ?></h4>
                            <p><?php echo t('cmdb.help.synthesis_card1_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo t('cmdb.help.synthesis_card2_title'); ?></h4>
                            <p><?php echo t('cmdb.help.synthesis_card2_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                            </div>
                            <h4><?php echo t('cmdb.help.synthesis_card3_title'); ?></h4>
                            <p><?php echo t('cmdb.help.synthesis_card3_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4><?php echo t('cmdb.help.synthesis_card4_title'); ?></h4>
                            <p><?php echo t('cmdb.help.synthesis_card4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 10. Linking tickets -->
                <div class="cmdb-help-section" id="tickets">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">10</span>
                        <div>
                            <h3><?php echo t('cmdb.help.tickets_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.tickets_intro'); ?></p>
                        </div>
                    </div>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div><?php echo t('cmdb.help.tickets_step1'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div><?php echo t('cmdb.help.tickets_step2'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div><?php echo t('cmdb.help.tickets_step3'); ?></div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">4</span>
                            <div><?php echo t('cmdb.help.tickets_step4'); ?></div>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        <?php echo t('cmdb.help.tickets_tip'); ?>
                    </div>
                </div>

                <!-- 11. Settings tour -->
                <div class="cmdb-help-section" id="settings">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">11</span>
                        <div>
                            <h3><?php echo t('cmdb.help.settings_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.settings_intro'); ?></p>
                        </div>
                    </div>

                    <div class="cmdb-help-features-grid">
                        <div class="cmdb-help-feature-card">
                            <h4><?php echo t('cmdb.help.settings_card1_title'); ?></h4>
                            <p><?php echo t('cmdb.help.settings_card1_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <h4><?php echo t('cmdb.help.settings_card2_title'); ?></h4>
                            <p><?php echo t('cmdb.help.settings_card2_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <h4><?php echo t('cmdb.help.settings_card3_title'); ?></h4>
                            <p><?php echo t('cmdb.help.settings_card3_body'); ?></p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <h4><?php echo t('cmdb.help.settings_card4_title'); ?></h4>
                            <p><?php echo t('cmdb.help.settings_card4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 12. Tips -->
                <div class="cmdb-help-section" id="tips">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">12</span>
                        <div>
                            <h3><?php echo t('cmdb.help.tips_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.tips_intro'); ?></p>
                        </div>
                    </div>

                    <div class="tips-grid">
                        <div class="tip-card">
                            <h4><?php echo t('cmdb.help.tips_card1_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card1_body'); ?></p>
                        </div>
                        <div class="tip-card">
                            <h4><?php echo t('cmdb.help.tips_card2_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card2_body'); ?></p>
                        </div>
                        <div class="tip-card">
                            <h4><?php echo t('cmdb.help.tips_card3_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card3_body'); ?></p>
                        </div>
                        <div class="tip-card">
                            <h4><?php echo t('cmdb.help.tips_card4_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card4_body'); ?></p>
                        </div>
                        <div class="tip-card">
                            <h4><?php echo t('cmdb.help.tips_card5_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card5_body'); ?></p>
                        </div>
                        <div class="tip-card">
                            <h4><?php echo t('cmdb.help.tips_card6_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card6_body'); ?></p>
                        </div>
                        <div class="tip-card">
                            <h4><?php echo t('cmdb.help.tips_card7_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card7_body'); ?></p>
                        </div>
                        <div class="tip-card">
                            <h4><?php echo t('cmdb.help.tips_card8_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card8_body'); ?></p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.cmdb-help-nav-link');
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
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Smooth-scroll within the help container, not the page
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
