<?php
/**
 * Service Status Help Guide - Full page with left pane navigation
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
requireModuleAccess('service-status');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'service-status'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('service-status.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--ss-accent);
            --accent-hover: var(--ss-accent-hover);
            --accent-soft:  var(--ss-accent-soft);
            --on-accent:    var(--ss-on-accent);
        }

        /* Module-specific: the status dots carry meaning, so they keep their
           own colours — taken from the theme's semantic tokens so they hold up
           in dark mode too. */
        .help-dot { width: 14px; height: 14px; }
        .help-dot.operational  { background: var(--success-accent, #10b981); }
        .help-dot.degraded     { background: var(--warning-text, #f59e0b); }
        .help-dot.maintenance  { background: var(--accent); }
        .help-dot.major-outage { background: var(--danger-accent, #ef4444); }
    </style>
    <!-- Mobile: LAYER 16h — the guide body is already responsive; this adds the app shell. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=127">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('service-status.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('service-status.help.nav_overview')); ?>
            </a>
            <a href="#status-dashboard" class="help-nav-link" data-section="status-dashboard">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('service-status.help.nav_dashboard')); ?>
            </a>
            <a href="#managing-services" class="help-nav-link" data-section="managing-services">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('service-status.help.nav_services')); ?>
            </a>
            <a href="#incident-history" class="help-nav-link" data-section="incident-history">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('service-status.help.nav_history')); ?>
            </a>
            <a href="#uptime" class="help-nav-link" data-section="uptime">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('service-status.help.nav_uptime')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('service-status.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('service-status.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('service-status.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('service-status.help.hero_sub')); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('service-status.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('service-status.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('service-status.help.feature_dashboard_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('service-status.help.feature_dashboard_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('service-status.help.feature_incident_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('service-status.help.feature_incident_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('service-status.help.feature_management_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('service-status.help.feature_management_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('service-status.help.feature_comms_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('service-status.help.feature_comms_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: The Status Dashboard -->
                <div class="help-section" id="status-dashboard">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('service-status.help.dashboard_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('service-status.help.dashboard_p1')); ?></p>
                    <p><?php echo t('service-status.help.dashboard_p2_html'); ?></p>

                    <p style="margin-top: 18px; margin-bottom: 10px; font-weight: 600; color: var(--text, #333);"><?php echo htmlspecialchars(t('service-status.help.status_levels')); ?></p>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-dot operational"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('service-status.help.level_operational_name')); ?></strong>
                                <span><?php echo htmlspecialchars(t('service-status.help.level_operational_desc')); ?></span>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-dot degraded"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('service-status.help.level_degraded_name')); ?></strong>
                                <span><?php echo htmlspecialchars(t('service-status.help.level_degraded_desc')); ?></span>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-dot maintenance"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('service-status.help.level_maintenance_name')); ?></strong>
                                <span><?php echo htmlspecialchars(t('service-status.help.level_maintenance_desc')); ?></span>
                            </div>
                        </div>
                        <div class="help-card">
                            <div class="help-dot major-outage"></div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('service-status.help.level_outage_name')); ?></strong>
                                <span><?php echo htmlspecialchars(t('service-status.help.level_outage_desc')); ?></span>
                            </div>
                        </div>
                    </div>
                    <p class="help-note"><?php echo htmlspecialchars(t('service-status.help.dashboard_tip')); ?></p>
                </div>

                <!-- Section 3: Managing Services (highlighted) -->
                <div class="help-section" id="managing-services">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo t('service-status.help.services_heading_html'); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('service-status.help.services_intro')); ?></p>

                    <p style="font-weight: 600; color: var(--text, #333); margin-bottom: 10px;"><?php echo htmlspecialchars(t('service-status.help.add_incident_heading')); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('service-status.help.add_incident_step1_html'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('service-status.help.add_incident_step2_html'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('service-status.help.add_incident_step3_html'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('service-status.help.add_incident_step4_html'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <?php echo t('service-status.help.add_incident_step5_html'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">6</div>
                            <div>
                                <?php echo t('service-status.help.add_incident_step6_html'); ?>
                            </div>
                        </div>
                    </div>

                    <p style="font-weight: 600; color: var(--text, #333); margin: 20px 0 10px;"><?php echo htmlspecialchars(t('service-status.help.workflow_heading')); ?></p>
                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('service-status.help.workflow_investigating')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('service-status.help.workflow_identified')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('service-status.help.workflow_monitoring')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('service-status.help.workflow_resolved')); ?></div>
                    </div>
                    <p style="font-size: 13px; color: var(--text-muted, #555); text-align: center; margin-top: 4px;"><?php echo t('service-status.help.workflow_note_html'); ?></p>

                    <p class="help-note"><?php echo htmlspecialchars(t('service-status.help.services_tip')); ?></p>
                </div>

                <!-- Section 4: Incident History -->
                <div class="help-section" id="incident-history">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('service-status.help.history_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('service-status.help.history_p1')); ?></p>
                    <div class="help-list">
                        <div><?php echo t('service-status.help.history_field_title_html'); ?></div>
                        <div><?php echo t('service-status.help.history_field_status_html'); ?></div>
                        <div><?php echo t('service-status.help.history_field_affected_html'); ?></div>
                        <div><?php echo t('service-status.help.history_field_updated_html'); ?></div>
                        <div><?php echo t('service-status.help.history_field_actions_html'); ?></div>
                    </div>
                    <p><?php echo htmlspecialchars(t('service-status.help.history_p2')); ?></p>
                    <h4><?php echo htmlspecialchars(t('service-status.help.actions_heading')); ?></h4>
                    <p><?php echo t('service-status.help.actions_p1'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('service-status.help.actions_edit'); ?></div>
                        <div><?php echo t('service-status.help.actions_resolve'); ?></div>
                        <div><?php echo t('service-status.help.actions_delete'); ?></div>
                    </div>
                    <p><?php echo t('service-status.help.actions_updates'); ?></p>
                    <p class="help-note"><?php echo t('service-status.help.actions_tip'); ?></p>

                    <h4><?php echo htmlspecialchars(t('service-status.help.vis_heading')); ?></h4>
                    <p><?php echo t('service-status.help.vis_p1'); ?></p>
                    <p><?php echo t('service-status.help.vis_p2'); ?></p>
                    <p><?php echo t('service-status.help.vis_p3'); ?></p>
                    <p class="help-note warn"><?php echo t('service-status.help.vis_note'); ?></p>

                    <h4><?php echo htmlspecialchars(t('service-status.help.corr_heading')); ?></h4>
                    <p><?php echo t('service-status.help.corr_p1'); ?></p>
                    <p><?php echo t('service-status.help.corr_p2'); ?></p>
                    <p><?php echo t('service-status.help.corr_p3'); ?></p>
                    <p class="help-note"><?php echo t('service-status.help.corr_note'); ?></p>

                    <p class="help-note"><?php echo htmlspecialchars(t('service-status.help.history_tip')); ?></p>
                </div>

                <!-- Section 5: Uptime and per-service history (discussion #59) -->
                <div class="help-section" id="uptime">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('service-status.help.uptime_heading')); ?></h3>
                    </div>
                    <p><?php echo t('service-status.help.uptime_p1_html'); ?></p>
                    <p><?php echo t('service-status.help.uptime_p2_html'); ?></p>

                    <h4><?php echo htmlspecialchars(t('service-status.help.uptime_record_heading')); ?></h4>
                    <p><?php echo t('service-status.help.uptime_record_p_html'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('service-status.help.uptime_step1_html'); ?></div>
                        <div><?php echo t('service-status.help.uptime_step2_html'); ?></div>
                        <div><?php echo t('service-status.help.uptime_step3_html'); ?></div>
                    </div>
                    <p class="help-note"><?php echo t('service-status.help.uptime_resolve_note_html'); ?></p>

                    <h4><?php echo htmlspecialchars(t('service-status.help.uptime_counts_heading')); ?></h4>
                    <p><?php echo t('service-status.help.uptime_counts_p_html'); ?></p>

                    <h4><?php echo htmlspecialchars(t('service-status.help.uptime_reading_heading')); ?></h4>
                    <div class="help-list">
                        <div><?php echo t('service-status.help.uptime_read_green_html'); ?></div>
                        <div><?php echo t('service-status.help.uptime_read_red_html'); ?></div>
                        <div><?php echo t('service-status.help.uptime_read_grey_html'); ?></div>
                    </div>
                    <p class="help-note"><?php echo t('service-status.help.uptime_tip_html'); ?></p>
                </div>

                <!-- Section 6: Settings -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('service-status.help.settings_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('service-status.help.settings_p1')); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('service-status.help.settings_step1_html'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('service-status.help.settings_step2_html'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('service-status.help.settings_step3_html'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('service-status.help.settings_step4_html'); ?>
                            </div>
                        </div>
                    </div>
                    <p class="help-note"><?php echo htmlspecialchars(t('service-status.help.settings_tip')); ?></p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('service-status.help.tips_heading')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128226;</div>
                            <div><strong><?php echo htmlspecialchars(t('service-status.help.tip_communicate_title')); ?></strong><br><?php echo htmlspecialchars(t('service-status.help.tip_communicate_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128260;</div>
                            <div><strong><?php echo htmlspecialchars(t('service-status.help.tip_update_title')); ?></strong><br><?php echo t('service-status.help.tip_update_desc'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128200;</div>
                            <div><strong><?php echo htmlspecialchars(t('service-status.help.tip_review_title')); ?></strong><br><?php echo htmlspecialchars(t('service-status.help.tip_review_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128736;</div>
                            <div><strong><?php echo htmlspecialchars(t('service-status.help.tip_maintenance_title')); ?></strong><br><?php echo htmlspecialchars(t('service-status.help.tip_maintenance_desc')); ?></div>
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
    <script src="../assets/js/mobile.js?v=50"></script>
</body>
</html>
