<?php
/**
 * Process Mapper Module Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

requireModuleAccess('process-mapper');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'process-mapper'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('process-mapper.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        .pm-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .pm-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .pm-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .pm-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        .pm-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .pm-help-nav-link.active {
            background: #eef2ff;
            color: #3730a3;
            font-weight: 600;
        }

        .pm-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #eee;
            color: #888;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }

        .pm-help-nav-link.active .pm-help-nav-num {
            background: #6366f1;
            color: white;
        }


        /* Main content */
        .pm-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .pm-help-hero {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 50%, #4338ca 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .pm-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .pm-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .pm-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .pm-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .pm-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .pm-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .pm-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .pm-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .pm-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .pm-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #eef2ff;
            color: #3730a3;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .pm-help-section-num.highlight {
            background: #6366f1;
            color: white;
        }

        /* Feature cards grid */
        .pm-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .pm-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .pm-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .pm-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .pm-help-feature-icon.indigo { background: #eef2ff; color: #4f46e5; }
        .pm-help-feature-icon.blue   { background: #e3f2fd; color: #1565c0; }
        .pm-help-feature-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .pm-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .pm-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .pm-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .pm-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .pm-help-step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: #fafafa;
            font-size: 14px;
            color: #444;
            line-height: 1.5;
        }

        .pm-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #6366f1;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .pm-help-section-highlight {
            background: #eef2ff;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #c7d2fe;
        }

        .pm-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .pm-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .pm-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Data cards */
        .pm-help-data-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .pm-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #6366f1;
        }

        .pm-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .pm-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Shape preview swatches */
        .pm-help-shape {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            margin-right: 8px;
            color: #4f46e5;
            vertical-align: middle;
        }

        /* Flow diagram */
        .pm-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .pm-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .pm-help-flow-step.create { background: #eef2ff; color: #3730a3; }
        .pm-help-flow-step.draw   { background: #e3f2fd; color: #1565c0; }
        .pm-help-flow-step.connect{ background: #e8f5e9; color: #2e7d32; }
        .pm-help-flow-step.save   { background: #fff3e0; color: #e65100; }

        .pm-help-flow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Tip callout */
        .pm-help-tip {
            font-size: 13px !important;
            color: #3730a3 !important;
            background: #eef2ff;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #6366f1;
            margin-top: 10px;
        }

        /* Keyboard shortcut chip */
        .pm-help-kbd {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            background: white;
            border: 1px solid #cbd5e1;
            box-shadow: 0 1px 0 rgba(0,0,0,0.04);
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 11.5px;
            color: #334155;
        }

        /* Quick tips grid */
        .pm-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .pm-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .pm-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .pm-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .pm-help-sidebar { display: none; }
            .pm-help-content { padding: 10px 24px 40px; }
            .pm-help-hero { padding: 30px 24px; }
            .pm-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .pm-help-features-grid { grid-template-columns: 1fr; }
            .pm-help-data-grid { grid-template-columns: 1fr; }
            .pm-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="pm-help-container">
        <!-- Left pane navigation -->
        <div class="pm-help-sidebar">
            <h3><?php echo htmlspecialchars(t('process-mapper.help.guide')); ?></h3>
            <a href="#overview" class="pm-help-nav-link active" data-section="overview">
                <span class="pm-help-nav-num">1</span>
                <?php echo t('process-mapper.help.nav_overview'); ?>
            </a>
            <a href="#creating" class="pm-help-nav-link" data-section="creating">
                <span class="pm-help-nav-num">2</span>
                <?php echo t('process-mapper.help.nav_creating'); ?>
            </a>
            <a href="#step-types" class="pm-help-nav-link" data-section="step-types">
                <span class="pm-help-nav-num">3</span>
                <?php echo t('process-mapper.help.nav_step_types'); ?>
            </a>
            <a href="#connectors" class="pm-help-nav-link" data-section="connectors">
                <span class="pm-help-nav-num">4</span>
                <?php echo t('process-mapper.help.nav_connectors'); ?>
            </a>
            <a href="#arranging" class="pm-help-nav-link" data-section="arranging">
                <span class="pm-help-nav-num">5</span>
                <?php echo t('process-mapper.help.nav_arranging'); ?>
            </a>
            <a href="#saving" class="pm-help-nav-link" data-section="saving">
                <span class="pm-help-nav-num">6</span>
                <?php echo t('process-mapper.help.nav_saving'); ?>
            </a>
            <a href="#export" class="pm-help-nav-link" data-section="export">
                <span class="pm-help-nav-num">7</span>
                <?php echo t('process-mapper.help.nav_export'); ?>
            </a>
            <a href="#tips" class="pm-help-nav-link" data-section="tips">
                <span class="pm-help-nav-num">8</span>
                <?php echo t('process-mapper.help.nav_tips'); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="pm-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="pm-help-hero">
                <h2><?php echo htmlspecialchars(t('process-mapper.help.hero_title')); ?></h2>
                <p><?php echo t('process-mapper.help.hero_subtitle'); ?></p>
            </div>

            <div class="pm-help-content">

                <!-- Section 1: Overview -->
                <div class="pm-help-section" id="overview">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('process-mapper.help.overview_heading')); ?></h3>
                            <p><?php echo t('process-mapper.help.overview_intro'); ?></p>
                        </div>
                    </div>

                    <div class="pm-help-flow">
                        <div class="pm-help-flow-step create"><?php echo t('process-mapper.help.overview_flow_create'); ?></div>
                        <div class="pm-help-flow-arrow">&rarr;</div>
                        <div class="pm-help-flow-step draw"><?php echo t('process-mapper.help.overview_flow_draw'); ?></div>
                        <div class="pm-help-flow-arrow">&rarr;</div>
                        <div class="pm-help-flow-step connect"><?php echo t('process-mapper.help.overview_flow_connect'); ?></div>
                        <div class="pm-help-flow-arrow">&rarr;</div>
                        <div class="pm-help-flow-step save"><?php echo t('process-mapper.help.overview_flow_save'); ?></div>
                    </div>

                    <div class="pm-help-features-grid">
                        <div class="pm-help-feature-card">
                            <div class="pm-help-feature-icon indigo">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1" fill="currentColor"/><circle cx="14" cy="8" r="1" fill="currentColor"/><circle cx="20" cy="8" r="1" fill="currentColor"/><circle cx="8" cy="14" r="1" fill="currentColor"/><circle cx="14" cy="14" r="1" fill="currentColor"/><circle cx="20" cy="14" r="1" fill="currentColor"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('process-mapper.help.overview_card1_title')); ?></h4>
                            <p><?php echo t('process-mapper.help.overview_card1_desc'); ?></p>
                        </div>
                        <div class="pm-help-feature-card">
                            <div class="pm-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="6" height="12" rx="1"/><polygon points="12,4 18,12 12,20 6,12" transform="translate(2 0)"/><ellipse cx="20" cy="12" rx="3" ry="2"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('process-mapper.help.overview_card2_title')); ?></h4>
                            <p><?php echo t('process-mapper.help.overview_card2_desc'); ?></p>
                        </div>
                        <div class="pm-help-feature-card">
                            <div class="pm-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="17" x2="15" y2="5"/><polyline points="10,5 15,5 15,10"/><circle cx="3" cy="17" r="2"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('process-mapper.help.overview_card3_title')); ?></h4>
                            <p><?php echo t('process-mapper.help.overview_card3_desc'); ?></p>
                        </div>
                        <div class="pm-help-feature-card">
                            <div class="pm-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                            </div>
                            <h4><?php echo t('process-mapper.help.overview_card4_title'); ?></h4>
                            <p><?php echo t('process-mapper.help.overview_card4_desc'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Creating a process (highlighted) -->
                <div class="pm-help-section pm-help-section-highlight" id="creating">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num highlight">2</span>
                        <h3><?php echo htmlspecialchars(t('process-mapper.help.creating_heading')); ?></h3>
                    </div>
                    <p class="pm-help-intro"><?php echo t('process-mapper.help.creating_intro'); ?></p>

                    <div class="pm-help-steps">
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">1</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step1'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">2</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step2'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">3</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step3'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">4</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step4'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">5</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step5'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="pm-help-tip"><?php echo t('process-mapper.help.creating_tip'); ?></p>
                </div>

                <!-- Section 3: Step types -->
                <div class="pm-help-section" id="step-types">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('process-mapper.help.step_types_heading')); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.step_types_intro'); ?></p>

                    <div class="pm-help-data-grid">
                        <div class="pm-help-data-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><rect x="1" y="3" width="16" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg><?php echo htmlspecialchars(t('process-mapper.help.step_types_process_name')); ?></strong>
                            <span><?php echo t('process-mapper.help.step_types_process_desc'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><polygon points="9,1 17,9 9,17 1,9" fill="none" stroke="currentColor" stroke-width="1.5"/></svg><?php echo htmlspecialchars(t('process-mapper.help.step_types_decision_name')); ?></strong>
                            <span><?php echo t('process-mapper.help.step_types_decision_desc'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><ellipse cx="9" cy="9" rx="8" ry="5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg><?php echo htmlspecialchars(t('process-mapper.help.step_types_terminal_name')); ?></strong>
                            <span><?php echo t('process-mapper.help.step_types_terminal_desc'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><path d="M2 2h14v12c-2.3 1.3-4.7 1.3-7 0s-4.7-1.3-7 0V2z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg><?php echo htmlspecialchars(t('process-mapper.help.step_types_document_name')); ?></strong>
                            <span><?php echo t('process-mapper.help.step_types_document_desc'); ?></span>
                        </div>
                    </div>

                    <h4 style="margin: 22px 0 8px; font-size: 15px; color: #333;"><?php echo htmlspecialchars(t('process-mapper.help.step_types_custom_heading')); ?></h4>
                    <p><?php echo t('process-mapper.help.step_types_custom_body'); ?></p>

                    <p class="pm-help-tip"><?php echo t('process-mapper.help.step_types_tip'); ?></p>
                </div>

                <!-- Section 4: Drawing connectors + the right-click menu -->
                <div class="pm-help-section" id="connectors">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">4</span>
                        <h3><?php echo t('process-mapper.help.connectors_heading'); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.connectors_intro'); ?></p>

                    <h4 style="margin: 22px 0 8px; font-size: 15px; color: #333;"><?php echo htmlspecialchars(t('process-mapper.help.connectors_drawing_heading')); ?></h4>
                    <div class="pm-help-steps">
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">1</div>
                            <div>
                                <?php echo t('process-mapper.help.connectors_draw_step1'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">2</div>
                            <div>
                                <?php echo t('process-mapper.help.connectors_draw_step2'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">3</div>
                            <div>
                                <?php echo t('process-mapper.help.connectors_draw_step3'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">4</div>
                            <div>
                                <?php echo t('process-mapper.help.connectors_draw_step4'); ?>
                            </div>
                        </div>
                    </div>

                    <h4 style="margin: 26px 0 8px; font-size: 15px; color: #333;"><?php echo htmlspecialchars(t('process-mapper.help.connectors_menu_heading')); ?></h4>
                    <p><?php echo t('process-mapper.help.connectors_menu_intro'); ?></p>
                    <div class="pm-help-data-grid">
                        <div class="pm-help-data-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card1_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card1_body'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card2_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card2_body'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card3_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card3_body'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card4_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card4_body'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card5_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card5_body'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card6_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card6_body'); ?></span>
                        </div>
                    </div>

                    <p class="pm-help-tip"><?php echo t('process-mapper.help.connectors_tip'); ?></p>
                </div>

                <!-- Section 5: Arranging & editing (highlighted) -->
                <div class="pm-help-section pm-help-section-highlight" id="arranging">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num highlight">5</span>
                        <h3><?php echo t('process-mapper.help.arranging_heading'); ?></h3>
                    </div>
                    <p class="pm-help-intro"><?php echo t('process-mapper.help.arranging_intro'); ?></p>

                    <div class="pm-help-steps">
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">1</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step1'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">2</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step2'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">3</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step3'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">4</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step4'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">5</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step5'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="pm-help-tip"><?php echo t('process-mapper.help.arranging_tip'); ?></p>
                </div>

                <!-- Section 6: Saving & loading -->
                <div class="pm-help-section" id="saving">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">6</span>
                        <h3><?php echo t('process-mapper.help.saving_heading'); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.saving_intro'); ?></p>

                    <div class="pm-help-steps">
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">1</div>
                            <div>
                                <?php echo t('process-mapper.help.saving_step1'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">2</div>
                            <div>
                                <?php echo t('process-mapper.help.saving_step2'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">3</div>
                            <div>
                                <?php echo t('process-mapper.help.saving_step3'); ?>
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">4</div>
                            <div>
                                <?php echo t('process-mapper.help.saving_step4'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="pm-help-tip"><?php echo t('process-mapper.help.saving_tip'); ?></p>
                </div>

                <!-- Section 7: Exporting -->
                <div class="pm-help-section pm-help-section-highlight" id="export">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num highlight">7</span>
                        <h3><?php echo htmlspecialchars(t('process-mapper.help.export_heading')); ?></h3>
                    </div>
                    <p class="pm-help-intro"><?php echo t('process-mapper.help.export_intro'); ?></p>

                    <div class="pm-help-data-grid">
                        <div class="pm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('process-mapper.help.export_png_title')); ?></strong>
                            <span><?php echo t('process-mapper.help.export_png_body'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('process-mapper.help.export_pdf_title')); ?></strong>
                            <span><?php echo t('process-mapper.help.export_pdf_body'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('process-mapper.help.export_mermaid_title')); ?></strong>
                            <span><?php echo t('process-mapper.help.export_mermaid_body'); ?></span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><?php echo htmlspecialchars(t('process-mapper.help.export_capture_title')); ?></strong>
                            <span><?php echo t('process-mapper.help.export_capture_body'); ?></span>
                        </div>
                    </div>

                    <p class="pm-help-tip"><?php echo t('process-mapper.help.export_tip'); ?></p>
                </div>

                <!-- Section 8: Quick Tips -->
                <div class="pm-help-section" id="tips">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">8</span>
                        <h3><?php echo htmlspecialchars(t('process-mapper.help.tips_heading')); ?></h3>
                    </div>
                    <div class="pm-help-tips-grid">
                        <div class="pm-help-tip-card">
                            <div class="pm-help-tip-icon">&#128200;</div>
                            <div><?php echo t('process-mapper.help.tip1'); ?></div>
                        </div>
                        <div class="pm-help-tip-card">
                            <div class="pm-help-tip-icon">&#127919;</div>
                            <div><?php echo t('process-mapper.help.tip2'); ?></div>
                        </div>
                        <div class="pm-help-tip-card">
                            <div class="pm-help-tip-icon">&#128229;</div>
                            <div><?php echo t('process-mapper.help.tip3'); ?></div>
                        </div>
                        <div class="pm-help-tip-card">
                            <div class="pm-help-tip-icon">&#9997;</div>
                            <div><?php echo t('process-mapper.help.tip4'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.pm-help-nav-link');
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
