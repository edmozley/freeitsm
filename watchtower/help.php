<?php
/**
 * Watchtower Help Guide - Full page with left pane navigation
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
requireModuleAccess('watchtower');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'watchtower'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('watchtower.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        .wt-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .wt-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .wt-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .wt-help-nav-link {
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

        .wt-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .wt-help-nav-link.active {
            background: #e2e8f0;
            color: #1e293b;
            font-weight: 600;
        }

        .wt-help-nav-num {
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

        .wt-help-nav-link.active .wt-help-nav-num {
            background: #1e293b;
            color: white;
        }


        /* Main content */
        .wt-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .wt-help-hero {
            background: linear-gradient(135deg, #1e293b 0%, #152238 50%, #0f172a 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .wt-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .wt-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .wt-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .wt-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .wt-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .wt-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .wt-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .wt-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .wt-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .wt-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #1e293b;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .wt-help-section-num.highlight {
            background: #1e293b;
            color: white;
        }

        /* Feature cards grid */
        .wt-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .wt-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .wt-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .wt-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .wt-help-feature-icon.slate { background: #e2e8f0; color: #475569; }
        .wt-help-feature-icon.blue { background: #dbeafe; color: #2563eb; }
        .wt-help-feature-icon.emerald { background: #d1fae5; color: #059669; }
        .wt-help-feature-icon.amber { background: #fef3c7; color: #d97706; }

        .wt-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .wt-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Status dot cards */
        .wt-help-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin: 14px 0;
        }

        .wt-help-status-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border-radius: 8px;
            background: white;
            border: 1px solid #e0e0e0;
        }

        .wt-help-status-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .wt-help-status-dot.green { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.2); }
        .wt-help-status-dot.amber { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,0.2); }
        .wt-help-status-dot.red { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.2); }

        .wt-help-status-card strong {
            display: block;
            font-size: 14px;
            color: #333;
            margin-bottom: 4px;
        }

        .wt-help-status-card span {
            font-size: 12.5px;
            color: #666;
            line-height: 1.4;
        }

        .wt-help-status-examples {
            margin-top: 6px;
            font-size: 12px;
            color: #888;
            line-height: 1.5;
        }

        /* Numbered steps */
        .wt-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .wt-help-step-item {
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

        .wt-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #1e293b;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .wt-help-section-highlight {
            background: #f1f5f9;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #94a3b8;
        }

        .wt-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .wt-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .wt-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Module card descriptions */
        .wt-help-module-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 14px 0;
        }

        .wt-help-module-card {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px 16px;
            border-radius: 8px;
            background: white;
            border: 1px solid #e0e0e0;
        }

        .wt-help-module-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .wt-help-module-icon svg {
            width: 18px;
            height: 18px;
            stroke: #fff;
            stroke-width: 2;
            fill: none;
        }

        .wt-help-module-card h4 {
            margin: 0 0 4px;
            font-size: 14px;
            color: #333;
        }

        .wt-help-module-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        .wt-help-module-card .wt-help-module-triggers {
            margin-top: 4px;
            font-size: 11.5px;
            color: #94a3b8;
        }

        /* Tip callout */
        .wt-help-tip {
            font-size: 13px !important;
            color: #1e293b !important;
            background: #f1f5f9;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #334155;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .wt-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .wt-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .wt-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .wt-help-tip-card strong {
            color: #333;
        }

        /* Card structure illustration */
        .wt-help-card-diagram {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin: 14px 0;
            max-width: 360px;
        }

        .wt-help-card-diagram-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px 10px;
            border-bottom: 1px solid #f1f5f9;
        }

        .wt-help-card-diagram-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .wt-help-card-diagram-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: #64748b;
        }

        .wt-help-card-diagram-name {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }

        .wt-help-card-diagram-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 0 3px rgba(34,197,94,0.2);
        }

        .wt-help-card-diagram-body {
            padding: 10px 14px 14px;
        }

        .wt-help-card-diagram-metrics {
            display: flex;
            gap: 16px;
            margin-bottom: 8px;
        }

        .wt-help-card-diagram-metric {
            text-align: center;
        }

        .wt-help-card-diagram-metric-value {
            font-size: 18px;
            font-weight: 700;
            color: #334155;
        }

        .wt-help-card-diagram-metric-label {
            font-size: 10px;
            color: #94a3b8;
            text-transform: uppercase;
        }

        .wt-help-card-diagram-attention {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            background: #f0fdf4;
            border-radius: 4px;
            font-size: 11px;
            color: #166534;
        }

        .wt-help-card-diagram-attention-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #22c55e;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .wt-help-sidebar { display: none; }
            .wt-help-content { padding: 10px 24px 40px; }
            .wt-help-hero { padding: 30px 24px; }
            .wt-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .wt-help-features-grid { grid-template-columns: 1fr; }
            .wt-help-status-grid { grid-template-columns: 1fr; }
            .wt-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wt-help-container">
        <!-- Left pane navigation -->
        <div class="wt-help-sidebar">
            <h3><?php echo htmlspecialchars(t('watchtower.help.sidebar_label')); ?></h3>
            <a href="#overview" class="wt-help-nav-link active" data-section="overview">
                <span class="wt-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_overview')); ?>
            </a>
            <a href="#dashboard-layout" class="wt-help-nav-link" data-section="dashboard-layout">
                <span class="wt-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_layout')); ?>
            </a>
            <a href="#status-dots" class="wt-help-nav-link" data-section="status-dots">
                <span class="wt-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_dots')); ?>
            </a>
            <a href="#module-cards" class="wt-help-nav-link" data-section="module-cards">
                <span class="wt-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_cards')); ?>
            </a>
            <a href="#auto-refresh" class="wt-help-nav-link" data-section="auto-refresh">
                <span class="wt-help-nav-num">5</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_refresh')); ?>
            </a>
            <a href="#tips" class="wt-help-nav-link" data-section="tips">
                <span class="wt-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="wt-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="wt-help-hero">
                <h2><?php echo htmlspecialchars(t('watchtower.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('watchtower.help.hero_subtitle')); ?></p>
            </div>

            <div class="wt-help-content">

                <!-- Section 1: Overview -->
                <div class="wt-help-section" id="overview">
                    <div class="wt-help-section-header">
                        <span class="wt-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('watchtower.help.s1_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_intro')); ?></p>
                        </div>
                    </div>
                    <div class="wt-help-features-grid">
                        <div class="wt-help-feature-card">
                            <div class="wt-help-feature-icon slate">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('watchtower.help.s1_feat1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_feat1_desc')); ?></p>
                        </div>
                        <div class="wt-help-feature-card">
                            <div class="wt-help-feature-icon emerald">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('watchtower.help.s1_feat2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_feat2_desc')); ?></p>
                        </div>
                        <div class="wt-help-feature-card">
                            <div class="wt-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('watchtower.help.s1_feat3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_feat3_desc')); ?></p>
                        </div>
                        <div class="wt-help-feature-card">
                            <div class="wt-help-feature-icon amber">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('watchtower.help.s1_feat4_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_feat4_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: The Dashboard Layout -->
                <div class="wt-help-section" id="dashboard-layout">
                    <div class="wt-help-section-header">
                        <span class="wt-help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s2_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('watchtower.help.s2_p1')); ?></p>
                    <p><?php echo htmlspecialchars(t('watchtower.help.s2_p2')); ?></p>

                    <div class="wt-help-card-diagram">
                        <div class="wt-help-card-diagram-header">
                            <div class="wt-help-card-diagram-left">
                                <div class="wt-help-card-diagram-icon"></div>
                                <div class="wt-help-card-diagram-name"><?php echo htmlspecialchars(t('watchtower.help.s2_diagram_name')); ?></div>
                            </div>
                            <div class="wt-help-card-diagram-dot"></div>
                        </div>
                        <div class="wt-help-card-diagram-body">
                            <div class="wt-help-card-diagram-metrics">
                                <div class="wt-help-card-diagram-metric">
                                    <div class="wt-help-card-diagram-metric-value">12</div>
                                    <div class="wt-help-card-diagram-metric-label"><?php echo htmlspecialchars(t('watchtower.help.s2_diagram_open')); ?></div>
                                </div>
                                <div class="wt-help-card-diagram-metric">
                                    <div class="wt-help-card-diagram-metric-value">5</div>
                                    <div class="wt-help-card-diagram-metric-label"><?php echo htmlspecialchars(t('watchtower.help.s2_diagram_active')); ?></div>
                                </div>
                                <div class="wt-help-card-diagram-metric">
                                    <div class="wt-help-card-diagram-metric-value">2</div>
                                    <div class="wt-help-card-diagram-metric-label"><?php echo htmlspecialchars(t('watchtower.help.s2_diagram_hold')); ?></div>
                                </div>
                            </div>
                            <div class="wt-help-card-diagram-attention">
                                <div class="wt-help-card-diagram-attention-dot"></div>
                                <?php echo htmlspecialchars(t('watchtower.help.s2_diagram_clear')); ?>
                            </div>
                        </div>
                    </div>

                    <div class="wt-help-fields">
                        <div><?php echo t('watchtower.help.s2_field_icon'); ?></div>
                        <div><?php echo t('watchtower.help.s2_field_name'); ?></div>
                        <div><?php echo t('watchtower.help.s2_field_dot'); ?></div>
                        <div><?php echo t('watchtower.help.s2_field_metrics'); ?></div>
                        <div><?php echo t('watchtower.help.s2_field_attention'); ?></div>
                    </div>

                    <p class="wt-help-tip"><?php echo htmlspecialchars(t('watchtower.help.s2_tip')); ?></p>
                </div>

                <!-- Section 3: Understanding Status Dots (highlighted) -->
                <div class="wt-help-section wt-help-section-highlight" id="status-dots">
                    <div class="wt-help-section-header">
                        <span class="wt-help-section-num highlight">3</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s3_title')); ?></h3>
                    </div>
                    <p class="wt-help-intro"><?php echo htmlspecialchars(t('watchtower.help.s3_intro')); ?></p>

                    <div class="wt-help-status-grid">
                        <div class="wt-help-status-card">
                            <div class="wt-help-status-dot green"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('watchtower.help.s3_green_label')); ?></strong>
                                <span><?php echo htmlspecialchars(t('watchtower.help.s3_green_desc')); ?></span>
                                <div class="wt-help-status-examples"><?php echo t('watchtower.help.s3_green_examples'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-status-card">
                            <div class="wt-help-status-dot amber"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('watchtower.help.s3_amber_label')); ?></strong>
                                <span><?php echo htmlspecialchars(t('watchtower.help.s3_amber_desc')); ?></span>
                                <div class="wt-help-status-examples"><?php echo t('watchtower.help.s3_amber_examples'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-status-card">
                            <div class="wt-help-status-dot red"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('watchtower.help.s3_red_label')); ?></strong>
                                <span><?php echo htmlspecialchars(t('watchtower.help.s3_red_desc')); ?></span>
                                <div class="wt-help-status-examples"><?php echo t('watchtower.help.s3_red_examples'); ?></div>
                            </div>
                        </div>
                    </div>

                    <p class="wt-help-tip"><?php echo htmlspecialchars(t('watchtower.help.s3_tip')); ?></p>
                </div>

                <!-- Section 4: Module Cards Explained -->
                <div class="wt-help-section" id="module-cards">
                    <div class="wt-help-section-header">
                        <span class="wt-help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s4_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('watchtower.help.s4_intro')); ?></p>

                    <div class="wt-help-module-grid">
                        <div class="wt-help-module-card">
                            <div class="wt-help-module-icon" style="background:#00acc1;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_mc_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_mc_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_mc_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-module-card">
                            <div class="wt-help-module-icon" style="background:#0078d4;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_tk_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_tk_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_tk_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-module-card">
                            <div class="wt-help-module-icon" style="background:#00897b;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_ch_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_ch_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_ch_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-module-card">
                            <div class="wt-help-module-icon" style="background:#ef6c00;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_cal_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_cal_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_cal_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-module-card">
                            <div class="wt-help-module-icon" style="background:#10b981;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_ss_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_ss_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_ss_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-module-card">
                            <div class="wt-help-module-icon" style="background:#f59e0b;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="12" y1="9" x2="8" y2="9"></line></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_ct_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_ct_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_ct_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-module-card">
                            <div class="wt-help-module-icon" style="background:#8764b8;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_kb_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_kb_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_kb_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="wt-help-module-card">
                            <div class="wt-help-module-icon" style="background:#107c10;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_as_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_as_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_as_triggers'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 5: Auto-Refresh and Manual Refresh -->
                <div class="wt-help-section" id="auto-refresh">
                    <div class="wt-help-section-header">
                        <span class="wt-help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s5_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('watchtower.help.s5_intro')); ?></p>

                    <div class="wt-help-steps">
                        <div class="wt-help-step-item">
                            <div class="wt-help-step-num">1</div>
                            <div>
                                <?php echo t('watchtower.help.s5_step1'); ?>
                            </div>
                        </div>
                        <div class="wt-help-step-item">
                            <div class="wt-help-step-num">2</div>
                            <div>
                                <?php echo t('watchtower.help.s5_step2'); ?>
                            </div>
                        </div>
                        <div class="wt-help-step-item">
                            <div class="wt-help-step-num">3</div>
                            <div>
                                <?php echo t('watchtower.help.s5_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="wt-help-tip"><?php echo htmlspecialchars(t('watchtower.help.s5_tip')); ?></p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="wt-help-section" id="tips">
                    <div class="wt-help-section-header">
                        <span class="wt-help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s6_title')); ?></h3>
                    </div>
                    <div class="wt-help-tips-grid">
                        <div class="wt-help-tip-card">
                            <div class="wt-help-tip-icon">&#9728;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip1_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s6_tip1_desc')); ?></div>
                        </div>
                        <div class="wt-help-tip-card">
                            <div class="wt-help-tip-icon">&#128308;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip2_title')); ?></strong><br><?php echo t('watchtower.help.s6_tip2_desc'); ?></div>
                        </div>
                        <div class="wt-help-tip-card">
                            <div class="wt-help-tip-icon">&#128279;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip3_title')); ?></strong><br><?php echo t('watchtower.help.s6_tip3_desc'); ?></div>
                        </div>
                        <div class="wt-help-tip-card">
                            <div class="wt-help-tip-icon">&#128260;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip4_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s6_tip4_desc')); ?></div>
                        </div>
                        <div class="wt-help-tip-card">
                            <div class="wt-help-tip-icon">&#128101;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip5_title')); ?></strong><br><?php echo t('watchtower.help.s6_tip5_desc'); ?></div>
                        </div>
                        <div class="wt-help-tip-card">
                            <div class="wt-help-tip-icon">&#9989;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip6_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s6_tip6_desc')); ?></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.wt-help-nav-link');
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
