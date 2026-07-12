<?php
/**
 * Morning Checks Help Guide - Full page with left pane navigation
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
requireModuleAccess('morning-checks');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'morning-checks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('morning-checks.help.hero_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        /* Module accent (cyan). */
        body { --accent: var(--mc-accent, #00acc1); --accent-hover: var(--mc-accent-hover, #00838f); }

        .mc-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }

        /* Left sidebar navigation */
        .mc-help-sidebar {
            width: 260px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .mc-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .mc-help-nav-link {
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

        .mc-help-nav-link:hover {
            background: var(--surface-hover, #f5f5f5);
            color: var(--text, #333);
        }

        .mc-help-nav-link.active {
            background: var(--mc-accent-soft, #e0f7fa);
            color: var(--mc-accent-hover, #00838f);
            font-weight: 600;
        }

        .mc-help-nav-num {
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

        .mc-help-nav-link.active .mc-help-nav-num {
            background: var(--mc-accent-hover, #00838f);
            color: white;
        }

        /* Main content */
        .mc-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .mc-help-hero {
            background: linear-gradient(135deg, #00acc1 0%, #00838f 50%, #005662 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .mc-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .mc-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .mc-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .mc-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }

        .mc-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .mc-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .mc-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .mc-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: var(--text-muted, #666);
            line-height: 1.6;
        }

        .mc-help-section > p {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .mc-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--mc-accent-soft, #e0f7fa);
            color: var(--mc-accent-hover, #00838f);
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .mc-help-section-num.highlight {
            background: var(--mc-accent-hover, #00838f);
            color: white;
        }

        /* Feature cards grid */
        .mc-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .mc-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, white);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .mc-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow, rgba(0,0,0,0.08));
        }

        .mc-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .mc-help-feature-icon.teal { background: #e0f7fa; color: #00acc1; }
        .mc-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .mc-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .mc-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .mc-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: var(--text, #333);
        }

        .mc-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        /* Numbered steps */
        .mc-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .mc-help-step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--surface-2, #fafafa);
            font-size: 14px;
            color: var(--text, #444);
            line-height: 1.5;
        }

        .mc-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--mc-accent, #00acc1);
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .mc-help-section-highlight {
            background: var(--mc-accent-soft, #e0f7fa);
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #80deea;
        }

        .mc-help-intro {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .mc-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .mc-help-fields div {
            padding: 8px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
        }

        /* Status indicator cards */
        .mc-help-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin: 14px 0;
        }

        .mc-help-status-card {
            padding: 16px;
            border-radius: 8px;
            border-left: 4px solid;
            background: var(--surface, white);
        }

        .mc-help-status-card.status-green { border-left-color: #28a745; }
        .mc-help-status-card.status-amber { border-left-color: #ffc107; }
        .mc-help-status-card.status-red { border-left-color: #dc3545; }

        .mc-help-status-card strong {
            display: block;
            font-size: 14px;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .mc-help-status-card span {
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.4;
        }

        /* Tip callout */
        .mc-help-tip {
            font-size: 13px !important;
            color: var(--mc-accent-hover, #00838f) !important;
            background: var(--mc-accent-soft, #e0f7fa);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--mc-accent, #00acc1);
            margin-top: 10px;
        }

        /* Quick tips grid */
        .mc-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .mc-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }

        .mc-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .mc-help-tip-card strong {
            color: var(--text, #333);
        }

        /* Chart preview illustration */
        .mc-help-chart-preview {
            background: var(--surface, white);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 10px;
            padding: 20px;
            margin: 14px 0;
        }

        .mc-help-chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            height: 100px;
            padding: 0 10px;
        }

        .mc-help-chart-bar-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .mc-help-chart-bar {
            width: 100%;
            border-radius: 3px 3px 0 0;
            min-height: 4px;
        }

        .mc-help-chart-bar.green { background: #28a745; }
        .mc-help-chart-bar.amber { background: #ffc107; }
        .mc-help-chart-bar.red { background: #dc3545; }

        .mc-help-chart-label {
            font-size: 10px;
            color: var(--text-faint, #999);
            text-align: center;
        }

        .mc-help-chart-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 12px;
            font-size: 12px;
            color: var(--text-muted, #666);
        }

        .mc-help-chart-legend span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .mc-help-chart-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .mc-help-sidebar { display: none; }
            .mc-help-content { padding: 10px 24px 40px; }
            .mc-help-hero { padding: 30px 24px; }
            .mc-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .mc-help-features-grid { grid-template-columns: 1fr; }
            .mc-help-status-grid { grid-template-columns: 1fr; }
            .mc-help-tips-grid { grid-template-columns: 1fr; }
        }

        /* Dark mode: darken the cyan hero gradient + tone the highlight band's
           bright top border (light mode is left untouched). */
        [data-theme-mode="dark"] .mc-help-hero {
            background: linear-gradient(135deg, #0b4b53 0%, #063b41 50%, #04292d 100%);
        }
        [data-theme-mode="dark"] .mc-help-section-highlight { border-top-color: #1f4a52; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="mc-help-container">
        <!-- Left pane navigation -->
        <div class="mc-help-sidebar">
            <h3><?php echo htmlspecialchars(t('morning-checks.help.guide')); ?></h3>
            <a href="#overview" class="mc-help-nav-link active" data-section="overview">
                <span class="mc-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_overview')); ?>
            </a>
            <a href="#daily-checks" class="mc-help-nav-link" data-section="daily-checks">
                <span class="mc-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_daily_checks')); ?>
            </a>
            <a href="#trend-chart" class="mc-help-nav-link" data-section="trend-chart">
                <span class="mc-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_trend_chart')); ?>
            </a>
            <a href="#pdf-export" class="mc-help-nav-link" data-section="pdf-export">
                <span class="mc-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_pdf_export')); ?>
            </a>
            <a href="#settings" class="mc-help-nav-link" data-section="settings">
                <span class="mc-help-nav-num">5</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="mc-help-nav-link" data-section="tips">
                <span class="mc-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="mc-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="mc-help-hero">
                <h2><?php echo htmlspecialchars(t('morning-checks.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('morning-checks.help.hero_subtitle')); ?></p>
            </div>

            <div class="mc-help-content">

                <!-- Section 1: Overview -->
                <div class="mc-help-section" id="overview">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('morning-checks.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="mc-help-features-grid">
                        <div class="mc-help-feature-card">
                            <div class="mc-help-feature-icon teal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('morning-checks.help.feature_checklist_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.feature_checklist_desc')); ?></p>
                        </div>
                        <div class="mc-help-feature-card">
                            <div class="mc-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('morning-checks.help.feature_trend_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.feature_trend_desc')); ?></p>
                        </div>
                        <div class="mc-help-feature-card">
                            <div class="mc-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('morning-checks.help.feature_pdf_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.feature_pdf_desc')); ?></p>
                        </div>
                        <div class="mc-help-feature-card">
                            <div class="mc-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.26.604.852.997 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09c-.658.003-1.25.396-1.51 1z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('morning-checks.help.feature_config_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.feature_config_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Performing Daily Checks -->
                <div class="mc-help-section" id="daily-checks">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.daily_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('morning-checks.help.daily_intro')); ?></p>

                    <div class="mc-help-steps">
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step1_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step1_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step2_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step2_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step3_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step3_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step4_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step4_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 18px;"><?php echo htmlspecialchars(t('morning-checks.help.daily_states_intro')); ?></p>

                    <div class="mc-help-status-grid">
                        <div class="mc-help-status-card status-green">
                            <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_green_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('morning-checks.help.daily_green_desc')); ?></span>
                        </div>
                        <div class="mc-help-status-card status-amber">
                            <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_amber_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('morning-checks.help.daily_amber_desc')); ?></span>
                        </div>
                        <div class="mc-help-status-card status-red">
                            <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_red_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('morning-checks.help.daily_red_desc')); ?></span>
                        </div>
                    </div>

                    <p class="mc-help-tip"><?php echo htmlspecialchars(t('morning-checks.help.daily_tip')); ?></p>
                </div>

                <!-- Section 3: The Trend Chart (highlighted) -->
                <div class="mc-help-section mc-help-section-highlight" id="trend-chart">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num highlight">3</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.trend_heading')); ?></h3>
                    </div>
                    <p class="mc-help-intro"><?php echo htmlspecialchars(t('morning-checks.help.trend_intro')); ?></p>

                    <div class="mc-help-chart-preview">
                        <div class="mc-help-chart-bars">
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar red" style="height: 8px;"></div>
                                <div class="mc-help-chart-bar amber" style="height: 12px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 60px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar amber" style="height: 6px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 74px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar green" style="height: 80px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar red" style="height: 16px;"></div>
                                <div class="mc-help-chart-bar amber" style="height: 10px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 54px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar amber" style="height: 8px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 72px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar green" style="height: 80px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar red" style="height: 6px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 74px;"></div>
                            </div>
                        </div>
                        <div class="mc-help-chart-legend">
                            <span><span class="mc-help-chart-legend-dot" style="background:#28a745;"></span> <?php echo htmlspecialchars(t('morning-checks.help.trend_legend_green')); ?></span>
                            <span><span class="mc-help-chart-legend-dot" style="background:#ffc107;"></span> <?php echo htmlspecialchars(t('morning-checks.help.trend_legend_amber')); ?></span>
                            <span><span class="mc-help-chart-legend-dot" style="background:#dc3545;"></span> <?php echo htmlspecialchars(t('morning-checks.help.trend_legend_red')); ?></span>
                        </div>
                    </div>

                    <div class="mc-help-steps">
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.trend_step1_strong')); ?></strong> <?php echo t('morning-checks.help.trend_step1_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.trend_step2_strong')); ?></strong> <?php echo t('morning-checks.help.trend_step2_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.trend_step3_strong')); ?></strong> <?php echo t('morning-checks.help.trend_step3_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="mc-help-tip"><?php echo htmlspecialchars(t('morning-checks.help.trend_tip')); ?></p>
                </div>

                <!-- Section 4: PDF Export -->
                <div class="mc-help-section" id="pdf-export">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.pdf_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('morning-checks.help.pdf_intro')); ?></p>

                    <div class="mc-help-steps">
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_step1_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_step1_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_step2_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_step2_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('morning-checks.help.pdf_includes')); ?></p>

                    <div class="mc-help-fields">
                        <div><strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_field_logo_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_field_logo_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_field_date_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_field_date_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_field_table_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_field_table_text'); ?></div>
                    </div>

                    <p class="mc-help-tip"><?php echo t('morning-checks.help.pdf_tip'); ?></p>
                </div>

                <!-- Section 5: Settings (highlighted) -->
                <div class="mc-help-section mc-help-section-highlight" id="settings">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num highlight">5</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.settings_heading')); ?></h3>
                    </div>
                    <p class="mc-help-intro"><?php echo htmlspecialchars(t('morning-checks.help.settings_intro')); ?></p>

                    <div class="mc-help-steps">
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step1_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step1_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step2_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step2_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step3_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step3_text'); ?>
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step4_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step4_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="mc-help-tip"><?php echo htmlspecialchars(t('morning-checks.help.settings_tip')); ?></p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="mc-help-section" id="tips">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.tips_heading')); ?></h3>
                    </div>
                    <div class="mc-help-tips-grid">
                        <div class="mc-help-tip-card">
                            <div class="mc-help-tip-icon">&#9200;</div>
                            <div><strong><?php echo htmlspecialchars(t('morning-checks.help.tip_consistent_title')); ?></strong><br><?php echo htmlspecialchars(t('morning-checks.help.tip_consistent_desc')); ?></div>
                        </div>
                        <div class="mc-help-tip-card">
                            <div class="mc-help-tip-icon">&#128221;</div>
                            <div><strong><?php echo htmlspecialchars(t('morning-checks.help.tip_notes_title')); ?></strong><br><?php echo htmlspecialchars(t('morning-checks.help.tip_notes_desc')); ?></div>
                        </div>
                        <div class="mc-help-tip-card">
                            <div class="mc-help-tip-icon">&#128257;</div>
                            <div><strong><?php echo htmlspecialchars(t('morning-checks.help.tip_handover_title')); ?></strong><br><?php echo htmlspecialchars(t('morning-checks.help.tip_handover_desc')); ?></div>
                        </div>
                        <div class="mc-help-tip-card">
                            <div class="mc-help-tip-icon">&#128200;</div>
                            <div><strong><?php echo htmlspecialchars(t('morning-checks.help.tip_review_title')); ?></strong><br><?php echo htmlspecialchars(t('morning-checks.help.tip_review_desc')); ?></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.mc-help-nav-link');
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
