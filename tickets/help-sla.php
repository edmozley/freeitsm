<?php
/**
 * Tickets — SLA Management Help Page
 * Standalone deep-dive linked from the main tickets help page.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('tickets');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.help_sla.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=66">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--accent-accent);
            --accent-hover: var(--accent-accent-hover);
            --accent-soft:  var(--accent-accent-soft);
            --on-accent:    var(--accent-on-accent);
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="help-container">
    <!-- Left pane navigation -->
    <div class="help-sidebar">
        <a href="help.php" class="help-back"><?php echo t('tickets.help_sla.back_link'); ?></a>
        <h3><?php echo htmlspecialchars(t('tickets.help_sla.sidebar_title')); ?></h3>
        <a href="#overview" class="help-nav-link active" data-section="overview">
            <span class="help-nav-num">1</span>
            <?php echo t('tickets.help_sla.nav.overview'); ?>
        </a>
        <a href="#building-blocks" class="help-nav-link" data-section="building-blocks">
            <span class="help-nav-num">2</span>
            <?php echo t('tickets.help_sla.nav.building_blocks'); ?>
        </a>
        <a href="#behaviour-settings" class="help-nav-link" data-section="behaviour-settings">
            <span class="help-nav-num">3</span>
            <?php echo t('tickets.help_sla.nav.behaviour_settings'); ?>
        </a>
        <a href="#breach-notifications" class="help-nav-link" data-section="breach-notifications">
            <span class="help-nav-num">4</span>
            <?php echo t('tickets.help_sla.nav.breach_notifications'); ?>
        </a>
        <a href="#cron-setup" class="help-nav-link" data-section="cron-setup">
            <span class="help-nav-num">5</span>
            <?php echo t('tickets.help_sla.nav.cron_setup'); ?>
        </a>
        <a href="#worked-examples" class="help-nav-link" data-section="worked-examples">
            <span class="help-nav-num">6</span>
            <?php echo t('tickets.help_sla.nav.worked_examples'); ?>
        </a>
        <a href="#troubleshooting" class="help-nav-link" data-section="troubleshooting">
            <span class="help-nav-num">7</span>
            <?php echo t('tickets.help_sla.nav.troubleshooting'); ?>
        </a>
    </div>

    <!-- Main content -->
    <div class="help-main" id="helpMain">
        <div class="help-hero">
            <h2><?php echo t('tickets.help_sla.hero_title'); ?></h2>
            <p><?php echo t('tickets.help_sla.hero_sub'); ?></p>
        </div>

        <div class="help-content">

            <!-- 1. Overview -->
            <div class="help-section" id="overview">
                <div class="help-section-header">
                    <span class="help-section-num">1</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.overview.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.overview.sub'); ?></p>
                    </div>
                </div>
                <p><?php echo t('tickets.help_sla.overview.p1'); ?></p>

                <p><?php echo t('tickets.help_sla.overview.p2'); ?></p>

                <div class="help-list">
                    <div><?php echo t('tickets.help_sla.overview.choice_business'); ?></div>
                    <div><?php echo t('tickets.help_sla.overview.choice_pause'); ?></div>
                    <div><?php echo t('tickets.help_sla.overview.choice_compute'); ?></div>
                    <div><?php echo t('tickets.help_sla.overview.choice_cutoff'); ?></div>
                </div>

                <p class="help-note"><?php echo t('tickets.help_sla.overview.tip'); ?></p>
            </div>

            <!-- 2. Building blocks -->
            <div class="help-section" id="building-blocks">
                <div class="help-section-header">
                    <span class="help-section-num">2</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.blocks.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.blocks.sub'); ?></p>
                    </div>
                </div>

                <h4><?php echo t('tickets.help_sla.blocks.cal_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.blocks.cal_intro'); ?></p>
                <div class="help-list">
                    <div><?php echo t('tickets.help_sla.blocks.cal_tz'); ?></div>
                    <div><?php echo t('tickets.help_sla.blocks.cal_hours'); ?></div>
                    <div><?php echo t('tickets.help_sla.blocks.cal_holidays'); ?></div>
                    <div><?php echo t('tickets.help_sla.blocks.cal_default'); ?></div>
                </div>
                <p><?php echo t('tickets.help_sla.blocks.cal_outro'); ?></p>

                <h4><?php echo t('tickets.help_sla.blocks.prio_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.blocks.prio_intro'); ?></p>
                <div class="help-list">
                    <div><?php echo t('tickets.help_sla.blocks.prio_response'); ?></div>
                    <div><?php echo t('tickets.help_sla.blocks.prio_resolution'); ?></div>
                    <div><?php echo t('tickets.help_sla.blocks.prio_calendar'); ?></div>
                </div>
                <p><?php echo t('tickets.help_sla.blocks.prio_outro'); ?></p>

                <h4><?php echo t('tickets.help_sla.blocks.pause_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.blocks.pause_intro'); ?></p>
                <ul style="font-size:14px;color:var(--text-muted, #555);line-height:1.7;margin:8px 0 8px 24px;">
                    <li><?php echo t('tickets.help_sla.blocks.pause_awaiting'); ?></li>
                    <li><?php echo t('tickets.help_sla.blocks.pause_vendor'); ?></li>
                    <li><?php echo t('tickets.help_sla.blocks.pause_change'); ?></li>
                    <li><?php echo t('tickets.help_sla.blocks.pause_parts'); ?></li>
                </ul>
                <p class="help-note warn"><?php echo t('tickets.help_sla.blocks.pause_warn'); ?></p>

                <h4><?php echo t('tickets.help_sla.blocks.cutoff_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.blocks.cutoff_p1'); ?></p>
                <p><?php echo t('tickets.help_sla.blocks.cutoff_p2'); ?></p>
            </div>

            <!-- 3. Behaviour settings -->
            <div class="help-section" id="behaviour-settings">
                <div class="help-section-header">
                    <span class="help-section-num">3</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.behaviour.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.behaviour.sub'); ?></p>
                    </div>
                </div>

                <h4><?php echo t('tickets.help_sla.behaviour.prio_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.behaviour.prio_intro'); ?></p>
                <div class="help-list">
                    <div><?php echo t('tickets.help_sla.behaviour.prio_forward'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.prio_recompute'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.prio_reset'); ?></div>
                </div>

                <h4><?php echo t('tickets.help_sla.behaviour.reopen_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.behaviour.reopen_intro'); ?></p>
                <div class="help-list">
                    <div><?php echo t('tickets.help_sla.behaviour.reopen_reset'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.reopen_continue'); ?></div>
                </div>

                <h4><?php echo t('tickets.help_sla.behaviour.first_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.behaviour.first_intro'); ?></p>
                <div class="help-list">
                    <div><?php echo t('tickets.help_sla.behaviour.first_either'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.first_status'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.first_email'); ?></div>
                </div>

                <h4><?php echo t('tickets.help_sla.behaviour.warn_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.behaviour.warn_body'); ?></p>
            </div>

            <!-- 4. Breach notifications -->
            <div class="help-section" id="breach-notifications">
                <div class="help-section-header">
                    <span class="help-section-num">4</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.breach.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.breach.sub'); ?></p>
                    </div>
                </div>

                <p><?php echo t('tickets.help_sla.breach.intro'); ?></p>

                <h4><?php echo t('tickets.help_sla.breach.scope_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.breach.scope_body'); ?></p>

                <h4><?php echo t('tickets.help_sla.breach.trigger_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.breach.trigger_body'); ?></p>

                <h4><?php echo t('tickets.help_sla.breach.target_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.breach.target_body'); ?></p>

                <h4><?php echo t('tickets.help_sla.breach.recip_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.breach.recip_intro'); ?></p>
                <div class="help-list">
                    <div><?php echo t('tickets.help_sla.breach.recip_assignee'); ?></div>
                    <div><?php echo t('tickets.help_sla.breach.recip_teams'); ?></div>
                    <div><?php echo t('tickets.help_sla.breach.recip_specific'); ?></div>
                    <div><?php echo t('tickets.help_sla.breach.recip_custom'); ?></div>
                </div>

                <p class="help-note"><?php echo t('tickets.help_sla.breach.tip'); ?></p>

                <p class="help-note warn"><?php echo t('tickets.help_sla.breach.warn'); ?></p>
            </div>

            <!-- 5. Cron setup -->
            <div class="help-section" id="cron-setup">
                <div class="help-section-header">
                    <span class="help-section-num">5</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.cron.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.cron.sub'); ?></p>
                    </div>
                </div>

                <p><?php echo t('tickets.help_sla.cron.p1'); ?></p>

                <p><?php echo t('tickets.help_sla.cron.p2'); ?></p>

                <h4><?php echo t('tickets.help_sla.cron.cli_heading'); ?></h4>
                <div class="help-code">php c:\wamp64\www\freeitsm-app\cron\sla_breach_check.php</div>
                <p><?php echo t('tickets.help_sla.cron.cli_note'); ?></p>

                <h4><?php echo t('tickets.help_sla.cron.http_heading'); ?></h4>
                <div class="help-code">curl http://your-host/freeitsm-app/cron/sla_breach_check.php?token=&lt;TOKEN&gt;</div>
                <p><?php echo t('tickets.help_sla.cron.http_note'); ?></p>
                <div class="help-code">php scripts/cron_token.php sla</div>

                <h4><?php echo t('tickets.help_sla.cron.win_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.cron.win_intro'); ?></p>
                <div class="help-table">
                <table>
                    <tr><th style="width:35%;"><?php echo t('tickets.help_sla.cron.tbl_field'); ?></th><th><?php echo t('tickets.help_sla.cron.tbl_value'); ?></th></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r1_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r1_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r2_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r2_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r3_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r3_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r4_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r4_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r5_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r5_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r6_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r6_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r7_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r7_v'); ?></td></tr>
                </table>
                </div>

                <h4><?php echo t('tickets.help_sla.cron.linux_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.cron.linux_intro'); ?></p>
                <div class="help-code">*/5 * * * * /usr/bin/php /var/www/freeitsm-app/cron/sla_breach_check.php &gt;&gt; /var/log/freeitsm-sla-cron.log 2&gt;&amp;1</div>

                <h4><?php echo t('tickets.help_sla.cron.sec_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.cron.sec_intro'); ?></p>
                <div class="help-list">
                    <div><?php echo t('tickets.help_sla.cron.sec_token'); ?></div>
                    <div><?php echo t('tickets.help_sla.cron.sec_lockout'); ?></div>
                    <div><?php echo t('tickets.help_sla.cron.sec_interval'); ?></div>
                </div>

                <h4><?php echo t('tickets.help_sla.cron.log_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.cron.log_body'); ?></p>
            </div>

            <!-- 6. Worked examples -->
            <div class="help-section" id="worked-examples">
                <div class="help-section-header">
                    <span class="help-section-num">6</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.examples.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.examples.sub'); ?></p>
                    </div>
                </div>

                <h4><?php echo t('tickets.help_sla.examples.ex1_heading'); ?></h4>

                <div class="help-note">
                    <h5><?php echo t('tickets.help_sla.examples.ex1_title'); ?> <span class="help-pill ok"><?php echo t('tickets.help_sla.examples.ex1_tag'); ?></span></h5>
                    <p><?php echo t('tickets.help_sla.examples.ex1_setup'); ?></p>

                    <p><?php echo t('tickets.help_sla.examples.ex1_scenario'); ?></p>

                    <div class="help-timeline"><?php echo t('tickets.help_sla.examples.ex1_timeline'); ?></div>

                    <p><?php echo t('tickets.help_sla.examples.ex1_outro'); ?></p>
                </div>

                <h4><?php echo t('tickets.help_sla.examples.ex2_heading'); ?></h4>

                <div class="help-note">
                    <h5><?php echo t('tickets.help_sla.examples.ex2_title'); ?> <span class="help-pill warn"><?php echo t('tickets.help_sla.examples.ex2_tag'); ?></span></h5>
                    <p><?php echo t('tickets.help_sla.examples.ex2_setup'); ?></p>

                    <p><?php echo t('tickets.help_sla.examples.ex2_scenario'); ?></p>

                    <p><?php echo t('tickets.help_sla.examples.ex2_question'); ?></p>
                </div>

                <div class="help-card">
                    <span class="help-pill info"><?php echo t('tickets.help_sla.examples.optA_label'); ?></span>
                    <p><?php echo t('tickets.help_sla.examples.optA_p1'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optA_p2'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optA_p3'); ?></p>
                </div>

                <div class="help-card">
                    <span class="help-pill info"><?php echo t('tickets.help_sla.examples.optB_label'); ?></span>
                    <p><?php echo t('tickets.help_sla.examples.optB_p1'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optB_p2'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optB_p3'); ?></p>
                </div>

                <div class="help-card">
                    <span class="help-pill info"><?php echo t('tickets.help_sla.examples.optC_label'); ?></span>
                    <p><?php echo t('tickets.help_sla.examples.optC_p1'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optC_p2'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optC_p3'); ?></p>
                </div>

                <p class="help-note"><?php echo t('tickets.help_sla.examples.choose_tip'); ?></p>

                <h4><?php echo t('tickets.help_sla.examples.ex3_heading'); ?></h4>

                <div class="help-note">
                    <h5><?php echo t('tickets.help_sla.examples.ex3_title'); ?> <span class="help-pill"><?php echo t('tickets.help_sla.examples.ex3_tag'); ?></span></h5>
                    <p><?php echo t('tickets.help_sla.examples.ex3_setup'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.ex3_scenario'); ?></p>
                    <div class="help-timeline"><?php echo t('tickets.help_sla.examples.ex3_timeline'); ?></div>
                    <p><?php echo t('tickets.help_sla.examples.ex3_outro'); ?></p>
                </div>
            </div>

            <!-- 7. Troubleshooting -->
            <div class="help-section" id="troubleshooting">
                <div class="help-section-header">
                    <span class="help-section-num">7</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.trouble.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.trouble.sub'); ?></p>
                    </div>
                </div>

                <div class="help-table">
                <table>
                    <tr><th style="width:40%;"><?php echo t('tickets.help_sla.trouble.col_symptom'); ?></th><th><?php echo t('tickets.help_sla.trouble.col_cause'); ?></th></tr>
                    <tr><td><?php echo t('tickets.help_sla.trouble.r1_s'); ?></td><td><?php echo t('tickets.help_sla.trouble.r1_c'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.trouble.r2_s'); ?></td><td><?php echo t('tickets.help_sla.trouble.r2_c'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.trouble.r3_s'); ?></td><td><?php echo t('tickets.help_sla.trouble.r3_c'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.trouble.r4_s'); ?></td><td><?php echo t('tickets.help_sla.trouble.r4_c'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.trouble.r5_s'); ?></td><td><?php echo t('tickets.help_sla.trouble.r5_c'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.trouble.r6_s'); ?></td><td><?php echo t('tickets.help_sla.trouble.r6_c'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.trouble.r7_s'); ?></td><td><?php echo t('tickets.help_sla.trouble.r7_c'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.trouble.r8_s'); ?></td><td><?php echo t('tickets.help_sla.trouble.r8_c'); ?></td></tr>
                </table>
                </div>

                <p class="help-note"><?php echo t('tickets.help_sla.trouble.tip'); ?></p>
            </div>

        </div>
    </div>
</div>

<script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="../assets/js/i18n.js?v=2"></script>
<script>
    // Scroll-spy: highlight active sidebar entry as user scrolls
    const helpMain = document.getElementById('helpMain');
    const navLinks = document.querySelectorAll('.help-nav-link');
    const sections = Array.from(navLinks).map(l => document.getElementById(l.dataset.section)).filter(Boolean);

    function setActive(id) {
        navLinks.forEach(l => l.classList.toggle('active', l.dataset.section === id));
    }

    helpMain.addEventListener('scroll', () => {
        const scrollY = helpMain.scrollTop + 100;
        for (let i = sections.length - 1; i >= 0; i--) {
            if (sections[i].offsetTop <= scrollY) {
                setActive(sections[i].id);
                return;
            }
        }
    });

    // Smooth scroll on sidebar click
    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const target = document.getElementById(link.dataset.section);
            if (target) {
                helpMain.scrollTo({ top: target.offsetTop - 20, behavior: 'smooth' });
                setActive(link.dataset.section);
            }
        });
    });
</script>
</body>
</html>
