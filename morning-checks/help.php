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
    header('Location: ../auth/login.php');
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
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('morning-checks.help.hero_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--mc-accent);
            --accent-hover: var(--mc-accent-hover);
            --accent-soft:  var(--mc-accent-soft);
            --on-accent:    var(--mc-on-accent);
        }

        /* Module-specific: the trend chart preview. The bar colours ARE the
           data here — red/amber/green counts — so they keep their meaning,
           taken from the theme's semantic tokens so they hold in dark mode. */
        .mc-help-chart-preview {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 14px;
        }
        .mc-help-chart-bars { display: flex; align-items: flex-end; gap: 6px; height: 100px; padding: 0 10px; }
        .mc-help-chart-bar-group { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .mc-help-chart-bar { width: 100%; border-radius: 3px 3px 0 0; min-height: 4px; }
        .mc-help-chart-bar.green { background: var(--success-accent, #28a745); }
        .mc-help-chart-bar.amber { background: var(--warning-text, #ffc107); }
        .mc-help-chart-bar.red   { background: var(--danger-accent, #dc3545); }
        .mc-help-chart-label { font-size: 10px; color: var(--text-faint, #999); text-align: center; }
        .mc-help-chart-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 12px;
            font-size: 12px;
            color: var(--text-muted, #666);
        }
        .mc-help-chart-legend span { display: flex; align-items: center; gap: 6px; }
        .mc-help-chart-legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }

        /* The three cards that explain what green, amber and red mean. The
           colour is the subject of the card, so it stays. */
        .help-card.status-green { border-left: 3px solid var(--success-accent, #28a745); }
        .help-card.status-amber { border-left: 3px solid var(--warning-text, #ffc107); }
        .help-card.status-red   { border-left: 3px solid var(--danger-accent, #dc3545); }
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=130">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('morning-checks.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_overview')); ?>
            </a>
            <a href="#daily-checks" class="help-nav-link" data-section="daily-checks">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_daily_checks')); ?>
            </a>
            <a href="#groups" class="help-nav-link" data-section="groups">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_groups')); ?>
            </a>
            <a href="#trend-chart" class="help-nav-link" data-section="trend-chart">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_trend_chart')); ?>
            </a>
            <a href="#pdf-export" class="help-nav-link" data-section="pdf-export">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_pdf_export')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('morning-checks.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('morning-checks.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('morning-checks.help.hero_subtitle')); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('morning-checks.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('morning-checks.help.feature_checklist_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.feature_checklist_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('morning-checks.help.feature_trend_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.feature_trend_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('morning-checks.help.feature_pdf_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.feature_pdf_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.26.604.852.997 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09c-.658.003-1.25.396-1.51 1z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('morning-checks.help.feature_config_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('morning-checks.help.feature_config_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Performing Daily Checks -->
                <div class="help-section" id="daily-checks">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.daily_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('morning-checks.help.daily_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step1_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step1_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step2_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step2_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step3_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step3_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step4_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step4_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_step5_strong')); ?></strong> <?php echo t('morning-checks.help.daily_step5_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 18px;"><?php echo htmlspecialchars(t('morning-checks.help.daily_states_intro')); ?></p>

                    <div class="help-cards">
                        <div class="help-card status-green">
                            <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_green_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('morning-checks.help.daily_green_desc')); ?></span>
                        </div>
                        <div class="help-card status-amber">
                            <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_amber_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('morning-checks.help.daily_amber_desc')); ?></span>
                        </div>
                        <div class="help-card status-red">
                            <strong><?php echo htmlspecialchars(t('morning-checks.help.daily_red_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('morning-checks.help.daily_red_desc')); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('morning-checks.help.daily_tip')); ?></p>
                </div>

                <!-- Section 3: Groups, routing and who does what (discussion #64) -->
                <div class="help-section" id="groups">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.groups_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('morning-checks.help.groups_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.groups_step1_strong')); ?></strong> <?php echo t('morning-checks.help.groups_step1_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.groups_step2_strong')); ?></strong> <?php echo t('morning-checks.help.groups_step2_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.groups_step3_strong')); ?></strong> <?php echo t('morning-checks.help.groups_step3_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.groups_step4_strong')); ?></strong> <?php echo t('morning-checks.help.groups_step4_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('morning-checks.help.groups_tip')); ?></p>
                </div>

                <!-- Section 4: The Trend Chart (highlighted) -->
                <div class="help-section" id="trend-chart">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.trend_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('morning-checks.help.trend_intro')); ?></p>

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

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.trend_step1_strong')); ?></strong> <?php echo t('morning-checks.help.trend_step1_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.trend_step2_strong')); ?></strong> <?php echo t('morning-checks.help.trend_step2_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.trend_step3_strong')); ?></strong> <?php echo t('morning-checks.help.trend_step3_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('morning-checks.help.trend_tip')); ?></p>
                </div>

                <!-- Section 5: PDF Export -->
                <div class="help-section" id="pdf-export">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.pdf_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('morning-checks.help.pdf_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_step1_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_step1_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_step2_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_step2_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('morning-checks.help.pdf_includes')); ?></p>

                    <div class="help-list">
                        <div><strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_field_logo_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_field_logo_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_field_date_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_field_date_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('morning-checks.help.pdf_field_table_strong')); ?></strong> <?php echo t('morning-checks.help.pdf_field_table_text'); ?></div>
                    </div>

                    <p class="help-note"><?php echo t('morning-checks.help.pdf_tip'); ?></p>
                </div>

                <!-- Section 6: Settings (highlighted) -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.settings_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('morning-checks.help.settings_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step1_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step1_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step2_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step2_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step3_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step3_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step4_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step4_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('morning-checks.help.settings_step5_strong')); ?></strong> <?php echo t('morning-checks.help.settings_step5_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('morning-checks.help.settings_tip')); ?></p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('morning-checks.help.tips_heading')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#9200;</div>
                            <div><strong><?php echo htmlspecialchars(t('morning-checks.help.tip_consistent_title')); ?></strong><br><?php echo htmlspecialchars(t('morning-checks.help.tip_consistent_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#128221;</div>
                            <div><strong><?php echo htmlspecialchars(t('morning-checks.help.tip_notes_title')); ?></strong><br><?php echo htmlspecialchars(t('morning-checks.help.tip_notes_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#128257;</div>
                            <div><strong><?php echo htmlspecialchars(t('morning-checks.help.tip_handover_title')); ?></strong><br><?php echo htmlspecialchars(t('morning-checks.help.tip_handover_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#128200;</div>
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
        const navLinks = document.querySelectorAll('.help-nav-link');
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
    <script src="../assets/js/mobile.js?v=53"></script>
</body>
</html>
