<?php
/**
 * Calendar Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'calendar'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('calendar.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=11">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=37">
    <style>
        .cal-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }

        /* Left sidebar navigation */
        .cal-help-sidebar {
            width: 260px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .cal-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .cal-help-nav-link {
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

        .cal-help-nav-link:hover {
            background: var(--surface-hover, #f5f5f5);
            color: var(--text, #333);
        }

        .cal-help-nav-link.active {
            background: var(--cal-accent-soft, #fff3e0);
            color: var(--cal-accent-hover, #e65100);
            font-weight: 600;
        }

        .cal-help-nav-num {
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

        .cal-help-nav-link.active .cal-help-nav-num {
            background: var(--cal-accent-hover, #e65100);
            color: var(--cal-on-accent, white);
        }


        /* Main content */
        .cal-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .cal-help-hero {
            background: linear-gradient(135deg, #ef6c00 0%, #e65100 50%, #bf360c 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        /* Darken the hero in dark mode so it recedes instead of glowing bright orange. */
        [data-theme-mode="dark"] .cal-help-hero {
            background: linear-gradient(135deg, #3a2410 0%, #2e1c0b 50%, #221408 100%);
        }

        .cal-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .cal-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .cal-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .cal-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }

        .cal-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .cal-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .cal-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .cal-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: var(--text-muted, #666);
            line-height: 1.6;
        }

        .cal-help-section > p {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .cal-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--cal-accent-soft, #fff3e0);
            color: var(--cal-accent-hover, #e65100);
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .cal-help-section-num.highlight {
            background: var(--cal-accent, #ef6c00);
            color: var(--cal-on-accent, white);
        }

        /* Feature cards grid */
        .cal-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .cal-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, white);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .cal-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow, rgba(0,0,0,0.08));
        }

        .cal-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .cal-help-feature-icon.orange { background: #fff3e0; color: #ef6c00; }
        .cal-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .cal-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .cal-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }

        .cal-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: var(--text, #333);
        }

        .cal-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        /* Numbered steps */
        .cal-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .cal-help-step-item {
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

        .cal-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--cal-accent, #ef6c00);
            color: var(--cal-on-accent, white);
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .cal-help-section-highlight {
            background: var(--cal-accent-soft, #fff3e0);
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid var(--cal-accent, #ffcc80);
        }

        .cal-help-intro {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .cal-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .cal-help-fields div {
            padding: 8px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
        }

        /* Data cards grid */
        .cal-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .cal-help-data-card {
            padding: 12px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            border-left: 3px solid var(--cal-accent, #ef6c00);
        }

        .cal-help-data-card strong {
            display: block;
            font-size: 13px;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .cal-help-data-card span {
            font-size: 12px;
            color: var(--text-dim, #777);
            line-height: 1.4;
        }

        /* Flow diagram */
        .cal-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .cal-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .cal-help-flow-step.calendar { background: #fff3e0; color: #e65100; }
        .cal-help-flow-step.view { background: #e3f2fd; color: #1565c0; }
        .cal-help-flow-step.category { background: #e8f5e9; color: #2e7d32; }
        .cal-help-flow-step.action { background: #f3e5f5; color: #7b1fa2; }

        .cal-help-flow-arrow {
            padding: 0 8px;
            color: var(--text-faint, #bbb);
            font-size: 18px;
        }

        /* Tip callout */
        .cal-help-tip {
            font-size: 13px !important;
            color: var(--cal-accent-hover, #e65100) !important;
            background: var(--cal-accent-soft, #fff3e0);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--cal-accent, #ef6c00);
            margin-top: 10px;
        }

        /* Quick tips grid */
        .cal-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .cal-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }

        .cal-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .cal-help-tip-card strong {
            color: var(--text, #333);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .cal-help-sidebar { display: none; }
            .cal-help-content { padding: 10px 24px 40px; }
            .cal-help-hero { padding: 30px 24px; }
            .cal-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .cal-help-features-grid { grid-template-columns: 1fr; }
            .cal-help-data-grid { grid-template-columns: 1fr; }
            .cal-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="cal-help-container">
        <!-- Left pane navigation -->
        <div class="cal-help-sidebar">
            <h3><?php echo htmlspecialchars(t('calendar.help.guide')); ?></h3>
            <a href="#overview" class="cal-help-nav-link active" data-section="overview">
                <span class="cal-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_overview')); ?>
            </a>
            <a href="#views" class="cal-help-nav-link" data-section="views">
                <span class="cal-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_views')); ?>
            </a>
            <a href="#creating-events" class="cal-help-nav-link" data-section="creating-events">
                <span class="cal-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_creating')); ?>
            </a>
            <a href="#categories" class="cal-help-nav-link" data-section="categories">
                <span class="cal-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_categories')); ?>
            </a>
            <a href="#settings" class="cal-help-nav-link" data-section="settings">
                <span class="cal-help-nav-num">5</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="cal-help-nav-link" data-section="tips">
                <span class="cal-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="cal-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="cal-help-hero">
                <h2><?php echo htmlspecialchars(t('calendar.help.hero_title')); ?></h2>
                <p><?php echo t('calendar.help.hero_sub'); ?></p>
            </div>

            <div class="cal-help-content">

                <!-- Section 1: Overview -->
                <div class="cal-help-section" id="overview">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('calendar.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('calendar.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="cal-help-features-grid">
                        <div class="cal-help-feature-card">
                            <div class="cal-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('calendar.help.feature_tracking_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('calendar.help.feature_tracking_desc')); ?></p>
                        </div>
                        <div class="cal-help-feature-card">
                            <div class="cal-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('calendar.help.feature_views_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('calendar.help.feature_views_desc')); ?></p>
                        </div>
                        <div class="cal-help-feature-card">
                            <div class="cal-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path>
                                    <line x1="4" y1="22" x2="4" y2="15"></line>
                                </svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('calendar.help.feature_categories_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('calendar.help.feature_categories_desc')); ?></p>
                        </div>
                        <div class="cal-help-feature-card">
                            <div class="cal-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('calendar.help.feature_scheduling_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('calendar.help.feature_scheduling_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Calendar Views -->
                <div class="cal-help-section" id="views">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.views_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('calendar.help.views_intro')); ?></p>

                    <div class="cal-help-data-grid">
                        <div class="cal-help-data-card">
                            <strong><?php echo htmlspecialchars(t('calendar.help.views_month_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('calendar.help.views_month_desc')); ?></span>
                        </div>
                        <div class="cal-help-data-card">
                            <strong><?php echo htmlspecialchars(t('calendar.help.views_week_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('calendar.help.views_week_desc')); ?></span>
                        </div>
                        <div class="cal-help-data-card">
                            <strong><?php echo htmlspecialchars(t('calendar.help.views_day_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('calendar.help.views_day_desc')); ?></span>
                        </div>
                    </div>

                    <p><?php echo t('calendar.help.views_nav'); ?></p>

                    <div class="cal-help-flow">
                        <div class="cal-help-flow-step calendar"><?php echo htmlspecialchars(t('calendar.help.views_flow_today')); ?></div>
                        <div class="cal-help-flow-arrow">&rarr;</div>
                        <div class="cal-help-flow-step view"><?php echo htmlspecialchars(t('calendar.help.views_flow_nav')); ?></div>
                        <div class="cal-help-flow-arrow">&rarr;</div>
                        <div class="cal-help-flow-step category"><?php echo htmlspecialchars(t('calendar.help.views_flow_choose')); ?></div>
                        <div class="cal-help-flow-arrow">&rarr;</div>
                        <div class="cal-help-flow-step action"><?php echo htmlspecialchars(t('calendar.help.views_flow_click')); ?></div>
                    </div>

                    <p class="cal-help-tip"><?php echo htmlspecialchars(t('calendar.help.views_tip')); ?></p>
                </div>

                <!-- Section 3: Creating Events (highlighted) -->
                <div class="cal-help-section cal-help-section-highlight" id="creating-events">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num highlight">3</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.creating_heading')); ?></h3>
                    </div>
                    <p class="cal-help-intro"><?php echo t('calendar.help.creating_intro'); ?></p>

                    <div class="cal-help-steps">
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">1</div>
                            <div>
                                <?php echo t('calendar.help.creating_step1'); ?>
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">2</div>
                            <div>
                                <?php echo t('calendar.help.creating_step2'); ?>
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">3</div>
                            <div>
                                <?php echo t('calendar.help.creating_step3'); ?>
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">4</div>
                            <div>
                                <?php echo t('calendar.help.creating_step4'); ?>
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">5</div>
                            <div>
                                <?php echo t('calendar.help.creating_step5'); ?>
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">6</div>
                            <div>
                                <?php echo t('calendar.help.creating_step6'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="cal-help-tip"><?php echo t('calendar.help.creating_tip'); ?></p>
                </div>

                <!-- Section 4: Event Categories -->
                <div class="cal-help-section" id="categories">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.categories_heading')); ?></h3>
                    </div>
                    <p><?php echo t('calendar.help.categories_intro'); ?></p>

                    <div class="cal-help-fields">
                        <div><?php echo t('calendar.help.categories_certificates'); ?></div>
                        <div><?php echo t('calendar.help.categories_contracts'); ?></div>
                        <div><?php echo t('calendar.help.categories_maintenance'); ?></div>
                        <div><?php echo t('calendar.help.categories_meetings'); ?></div>
                        <div><?php echo t('calendar.help.categories_custom'); ?></div>
                    </div>

                    <p><?php echo htmlspecialchars(t('calendar.help.categories_filtering')); ?></p>

                    <p class="cal-help-tip"><?php echo htmlspecialchars(t('calendar.help.categories_tip')); ?></p>
                </div>

                <!-- Section 5: Settings (highlighted) -->
                <div class="cal-help-section cal-help-section-highlight" id="settings">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num highlight">5</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.settings_heading')); ?></h3>
                    </div>
                    <p class="cal-help-intro"><?php echo t('calendar.help.settings_intro'); ?></p>

                    <div class="cal-help-steps">
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">1</div>
                            <div>
                                <?php echo t('calendar.help.settings_step1'); ?>
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">2</div>
                            <div>
                                <?php echo t('calendar.help.settings_step2'); ?>
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">3</div>
                            <div>
                                <?php echo t('calendar.help.settings_step3'); ?>
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">4</div>
                            <div>
                                <?php echo t('calendar.help.settings_step4'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="cal-help-tip"><?php echo t('calendar.help.settings_tip'); ?></p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="cal-help-section" id="tips">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.tips_heading')); ?></h3>
                    </div>
                    <div class="cal-help-tips-grid">
                        <div class="cal-help-tip-card">
                            <div class="cal-help-tip-icon">&#128197;</div>
                            <div><strong><?php echo htmlspecialchars(t('calendar.help.tips_maintenance_title')); ?></strong><br><?php echo htmlspecialchars(t('calendar.help.tips_maintenance_desc')); ?></div>
                        </div>
                        <div class="cal-help-tip-card">
                            <div class="cal-help-tip-icon">&#128274;</div>
                            <div><strong><?php echo htmlspecialchars(t('calendar.help.tips_certificates_title')); ?></strong><br><?php echo htmlspecialchars(t('calendar.help.tips_certificates_desc')); ?></div>
                        </div>
                        <div class="cal-help-tip-card">
                            <div class="cal-help-tip-icon">&#128203;</div>
                            <div><strong><?php echo htmlspecialchars(t('calendar.help.tips_contracts_title')); ?></strong><br><?php echo htmlspecialchars(t('calendar.help.tips_contracts_desc')); ?></div>
                        </div>
                        <div class="cal-help-tip-card">
                            <div class="cal-help-tip-icon">&#128269;</div>
                            <div><strong><?php echo htmlspecialchars(t('calendar.help.tips_filters_title')); ?></strong><br><?php echo htmlspecialchars(t('calendar.help.tips_filters_desc')); ?></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.cal-help-nav-link');
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
