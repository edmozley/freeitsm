<?php
/**
 * Watchtower Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
require_once '../includes/theme.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('watchtower');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'watchtower'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('watchtower.help.page_title')); ?></title>
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
            --accent:       var(--wt-accent);
            --accent-hover: var(--wt-accent-hover);
            --accent-soft:  var(--wt-accent-soft);
            --on-accent:    var(--wt-on-accent);
        }

        /* Module-specific: a drawing of a real Watchtower module card. It is a
           picture of the product, so it keeps the product's own colours. */
        .wt-help-card-diagram {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 14px;
            max-width: 360px;
        }
        .wt-help-card-diagram-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px 10px;
            border-bottom: 1px solid var(--border-soft, #f1f5f9);
        }
        .wt-help-card-diagram-left { display: flex; align-items: center; gap: 8px; }
        .wt-help-card-diagram-icon { width: 28px; height: 28px; border-radius: 6px; background: var(--accent); }
        .wt-help-card-diagram-name { font-size: 13px; font-weight: 600; color: var(--text, #334155); }
        .wt-help-card-diagram-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--success-accent, #22c55e);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
        }
        .wt-help-card-diagram-body { padding: 10px 14px 14px; }
        .wt-help-card-diagram-metrics { display: flex; gap: 16px; margin-bottom: 8px; }
        .wt-help-card-diagram-metric { text-align: center; }
        .wt-help-card-diagram-metric-value { font-size: 18px; font-weight: 700; color: var(--text, #334155); }
        .wt-help-card-diagram-metric-label { font-size: 10px; color: var(--text-dim, #94a3b8); text-transform: uppercase; }
        .wt-help-card-diagram-attention {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            background: var(--success-bg, #f0fdf4);
            color: var(--success-text, #166534);
        }
        .wt-help-card-diagram-attention-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--success-accent, #22c55e); }

        /* The worked examples under each status card. */
        .wt-help-status-examples { font-size: 12px; color: var(--text-dim, #888); font-style: italic; margin-top: 6px; }
        .wt-help-module-triggers { font-size: 12px; color: var(--text-dim, #888); margin-top: 6px; }

        /* The green/amber/red dots carry the meaning the section is about, so
           they keep their colour — from the theme's semantic tokens. */
        .help-dot { width: 14px; height: 14px; }
        .help-dot.green { background: var(--success-accent, #22c55e); }
        .help-dot.amber { background: var(--warning-text, #f59e0b); }
        .help-dot.red   { background: var(--danger-accent, #ef4444); }
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=130">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('watchtower.help.sidebar_label')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_overview')); ?>
            </a>
            <a href="#dashboard-layout" class="help-nav-link" data-section="dashboard-layout">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_layout')); ?>
            </a>
            <a href="#whose-work" class="help-nav-link" data-section="whose-work">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_whose')); ?>
            </a>
            <a href="#status-dots" class="help-nav-link" data-section="status-dots">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_dots')); ?>
            </a>
            <a href="#module-cards" class="help-nav-link" data-section="module-cards">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_cards')); ?>
            </a>
            <a href="#auto-refresh" class="help-nav-link" data-section="auto-refresh">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_refresh')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_tips')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">8</span>
                <?php echo htmlspecialchars(t('watchtower.help.nav_settings')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('watchtower.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('watchtower.help.hero_subtitle')); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('watchtower.help.s1_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('watchtower.help.s1_feat1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_feat1_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('watchtower.help.s1_feat2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_feat2_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('watchtower.help.s1_feat3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_feat3_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('watchtower.help.s1_feat4_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('watchtower.help.s1_feat4_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: The Dashboard Layout -->
                <div class="help-section" id="dashboard-layout">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
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

                    <div class="help-list">
                        <div><?php echo t('watchtower.help.s2_field_icon'); ?></div>
                        <div><?php echo t('watchtower.help.s2_field_name'); ?></div>
                        <div><?php echo t('watchtower.help.s2_field_dot'); ?></div>
                        <div><?php echo t('watchtower.help.s2_field_metrics'); ?></div>
                        <div><?php echo t('watchtower.help.s2_field_attention'); ?></div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('watchtower.help.s2_tip')); ?></p>
                </div>

                <!-- Section 3: Understanding Status Dots (highlighted) -->
                <div class="help-section" id="whose-work">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s_whose_title')); ?></h3>
                    </div>
                    <p><?php echo t('watchtower.help.s_whose_p1'); ?></p>

                    <div class="help-list">
                        <div><?php echo t('watchtower.help.s_whose_mine'); ?></div>
                        <div><?php echo t('watchtower.help.s_whose_team'); ?></div>
                        <div><?php echo t('watchtower.help.s_whose_all'); ?></div>
                    </div>

                    <p style="margin-top:14px;"><?php echo t('watchtower.help.s_whose_narrows'); ?></p>

                    <p class="help-note"><?php echo t('watchtower.help.s_whose_impersonal'); ?></p>

                    <p class="help-note warn"><?php echo t('watchtower.help.s_whose_unassigned'); ?></p>

                    <p><?php echo t('watchtower.help.s_whose_checks'); ?></p>

                    <p class="help-note"><?php echo t('watchtower.help.s_whose_setting'); ?></p>
                </div>

                <div class="help-section" id="status-dots">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s3_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('watchtower.help.s3_intro')); ?></p>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-dot green"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('watchtower.help.s3_green_label')); ?></strong>
                                <span><?php echo htmlspecialchars(t('watchtower.help.s3_green_desc')); ?></span>
                                <div class="wt-help-status-examples"><?php echo t('watchtower.help.s3_green_examples'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-dot amber"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('watchtower.help.s3_amber_label')); ?></strong>
                                <span><?php echo htmlspecialchars(t('watchtower.help.s3_amber_desc')); ?></span>
                                <div class="wt-help-status-examples"><?php echo t('watchtower.help.s3_amber_examples'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-dot red"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('watchtower.help.s3_red_label')); ?></strong>
                                <span><?php echo htmlspecialchars(t('watchtower.help.s3_red_desc')); ?></span>
                                <div class="wt-help-status-examples"><?php echo t('watchtower.help.s3_red_examples'); ?></div>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('watchtower.help.s3_tip')); ?></p>
                </div>

                <!-- Section 4: Module Cards Explained -->
                <div class="help-section" id="module-cards">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s4_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('watchtower.help.s4_intro')); ?></p>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon" style="background:#00acc1;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_mc_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_mc_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_mc_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon" style="background:#0078d4;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_tk_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_tk_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_tk_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon" style="background:#00897b;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_ch_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_ch_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_ch_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon" style="background:#ef6c00;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_cal_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_cal_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_cal_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon" style="background:#10b981;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_ss_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_ss_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_ss_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon" style="background:#f59e0b;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="12" y1="9" x2="8" y2="9"></line></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_ct_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_ct_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_ct_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon" style="background:#8764b8;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars(t('watchtower.help.s4_kb_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('watchtower.help.s4_kb_desc')); ?></p>
                                <div class="wt-help-module-triggers"><?php echo t('watchtower.help.s4_kb_triggers'); ?></div>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon" style="background:#107c10;">
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
                <div class="help-section" id="auto-refresh">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s5_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('watchtower.help.s5_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('watchtower.help.s5_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('watchtower.help.s5_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('watchtower.help.s5_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('watchtower.help.s5_tip')); ?></p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s6_title')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#9728;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip1_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s6_tip1_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128308;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip2_title')); ?></strong><br><?php echo t('watchtower.help.s6_tip2_desc'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128279;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip3_title')); ?></strong><br><?php echo t('watchtower.help.s6_tip3_desc'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128260;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip4_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s6_tip4_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128101;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip5_title')); ?></strong><br><?php echo t('watchtower.help.s6_tip5_desc'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9989;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s6_tip6_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s6_tip6_desc')); ?></div>
                        </div>
                    </div>
                </div>

                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <h3><?php echo htmlspecialchars(t('watchtower.help.s7_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('watchtower.help.s7_intro')); ?></p>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128203;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s7_cards_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s7_cards_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128290;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s7_counts_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s7_counts_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9749;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s7_mc_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s7_mc_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128721;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s7_red_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s7_red_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9203;</div>
                            <div><strong><?php echo htmlspecialchars(t('watchtower.help.s7_paused_title')); ?></strong><br><?php echo htmlspecialchars(t('watchtower.help.s7_paused_desc')); ?></div>
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
