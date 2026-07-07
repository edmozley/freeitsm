<?php
/**
 * Tickets — SLA Management Help Page
 * Standalone deep-dive linked from the main tickets help page.
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
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.help_sla.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=15">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .tk-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--surface-2, #f5f5f5);
        }
        .tk-help-sidebar {
            width: 280px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
        }
        .tk-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .tk-help-back-link {
            font-size: 12px;
            color: var(--accent, #0078d4);
            text-decoration: none;
            margin-bottom: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tk-help-back-link:hover { text-decoration: underline; }

        .tk-help-nav-link {
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
        .tk-help-nav-link:hover { background: var(--surface-2, #f5f5f5); color: var(--text, #333); }
        .tk-help-nav-link.active { background: var(--accent-soft, #e3f2fd); color: var(--accent-hover, #005a9e); font-weight: 600; }
        .tk-help-nav-num {
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
        .tk-help-nav-link.active .tk-help-nav-num { background: var(--accent, #0078d4); color: var(--on-accent, white); }

        .tk-help-main { flex: 1; overflow-y: auto; }

        .tk-help-hero {
            background: linear-gradient(135deg, var(--accent, #0078d4) 0%, var(--accent-hover, #005a9e) 50%, #003d6b 100%);
            color: var(--on-accent, white);
            padding: 40px 48px 36px;
            text-align: center;
        }
        [data-theme-mode="dark"] .tk-help-hero {
            background: linear-gradient(135deg, #1f3f63 0%, #15304c 50%, #0c2031 100%);
        }
        .tk-help-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .tk-help-hero p { margin: 0; font-size: 15px; opacity: 0.85; }

        .tk-help-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        .tk-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }
        .tk-help-section:last-child { border-bottom: none; padding-bottom: 0; }
        .tk-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }
        .tk-help-section-header h3 { margin: 0; font-size: 18px; color: var(--text, #333); }
        .tk-help-section-header p { margin: 6px 0 0; font-size: 14px; color: var(--text-muted, #666); line-height: 1.6; }
        .tk-help-section > p {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin: 0 0 14px;
        }
        .tk-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--accent-soft, #e3f2fd);
            color: var(--accent-hover, #005a9e);
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .tk-help-section h4 {
            font-size: 15px;
            color: var(--text, #333);
            margin: 22px 0 10px;
        }
        .tk-help-section h5 {
            font-size: 14px;
            color: var(--text-muted, #444);
            margin: 16px 0 8px;
        }

        .tk-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }
        .tk-help-fields div {
            padding: 10px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }
        .tk-help-fields div strong { color: var(--text, #333); }

        .tk-help-tip {
            font-size: 13px !important;
            color: var(--accent-hover, #005a9e) !important;
            background: var(--accent-soft, #e3f2fd);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--accent, #0078d4);
            margin: 14px 0;
        }
        .tk-help-warn {
            font-size: 13px;
            color: var(--warning-text, #92400e);
            background: var(--warning-bg, #fef3c7);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--warning-border, #f59e0b);
            margin: 14px 0;
            line-height: 1.5;
        }

        .tk-help-example {
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 10px;
            background: var(--surface, white);
            padding: 20px 22px;
            margin: 16px 0;
        }
        .tk-help-example h5 {
            margin: 0 0 10px;
            font-size: 15px;
            color: var(--accent, #0078d4);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tk-help-example .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            background: var(--accent-soft, #e3f2fd);
            color: var(--accent-hover, #005a9e);
        }
        .tk-help-example .tag.green { background: #e8f5e9; color: #2e7d32; }
        .tk-help-example .tag.amber { background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); }
        .tk-help-example .tag.red   { background: #fee2e2; color: #991b1b; }
        .tk-help-example p {
            font-size: 13.5px;
            color: var(--text-muted, #555);
            line-height: 1.65;
            margin: 8px 0;
        }
        .tk-help-example .timeline {
            background: var(--surface-2, #fafafa);
            border-left: 3px solid #94a3b8;
            padding: 12px 14px;
            border-radius: 6px;
            margin: 12px 0;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            font-family: ui-monospace, "Cascadia Mono", "Source Code Pro", Menlo, Consolas, monospace;
        }
        .tk-help-example .timeline strong { color: var(--accent, #0078d4); }

        .tk-help-option-card {
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 14px 16px;
            margin: 10px 0;
            background: var(--surface-2, #fafafa);
        }
        .tk-help-option-card .label {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .tk-help-option-card .label.customer { background: #dcfce7; color: #166534; }
        .tk-help-option-card .label.analyst  { background: #fff3e0; color: #e65100; }
        .tk-help-option-card .label.overlap  { background: #f3e5f5; color: #7b1fa2; }
        .tk-help-option-card strong { color: var(--text, #333); }
        .tk-help-option-card p {
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.55;
            margin: 6px 0 0;
        }

        .tk-help-code {
            font-family: ui-monospace, "Cascadia Mono", "Source Code Pro", Menlo, Consolas, monospace;
            background: var(--surface-2, #f5f5f5);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12.5px;
            color: var(--text, #333);
        }

        .tk-help-code-block {
            font-family: ui-monospace, "Cascadia Mono", "Source Code Pro", Menlo, Consolas, monospace;
            background: #1e293b;
            color: #e2e8f0;
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 12.5px;
            line-height: 1.55;
            overflow-x: auto;
            margin: 12px 0;
            white-space: pre;
        }

        table.tk-help-table {
            width: 100%;
            border-collapse: collapse;
            margin: 14px 0;
            font-size: 13px;
        }
        table.tk-help-table th {
            text-align: left;
            background: var(--surface-2, #f5f5f5);
            color: var(--text-muted, #444);
            padding: 10px 12px;
            border-bottom: 2px solid var(--border, #e0e0e0);
            font-weight: 600;
        }
        table.tk-help-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-soft, #eee);
            color: var(--text-muted, #555);
            vertical-align: top;
            line-height: 1.5;
        }

        @media (max-width: 900px) {
            .tk-help-sidebar { display: none; }
            .tk-help-content { padding: 10px 24px 40px; }
            .tk-help-hero { padding: 30px 24px; }
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="tk-help-container">
    <!-- Left pane navigation -->
    <div class="tk-help-sidebar">
        <a href="help.php" class="tk-help-back-link"><?php echo t('tickets.help_sla.back_link'); ?></a>
        <h3><?php echo htmlspecialchars(t('tickets.help_sla.sidebar_title')); ?></h3>
        <a href="#overview" class="tk-help-nav-link active" data-section="overview">
            <span class="tk-help-nav-num">1</span>
            <?php echo t('tickets.help_sla.nav.overview'); ?>
        </a>
        <a href="#building-blocks" class="tk-help-nav-link" data-section="building-blocks">
            <span class="tk-help-nav-num">2</span>
            <?php echo t('tickets.help_sla.nav.building_blocks'); ?>
        </a>
        <a href="#behaviour-settings" class="tk-help-nav-link" data-section="behaviour-settings">
            <span class="tk-help-nav-num">3</span>
            <?php echo t('tickets.help_sla.nav.behaviour_settings'); ?>
        </a>
        <a href="#breach-notifications" class="tk-help-nav-link" data-section="breach-notifications">
            <span class="tk-help-nav-num">4</span>
            <?php echo t('tickets.help_sla.nav.breach_notifications'); ?>
        </a>
        <a href="#cron-setup" class="tk-help-nav-link" data-section="cron-setup">
            <span class="tk-help-nav-num">5</span>
            <?php echo t('tickets.help_sla.nav.cron_setup'); ?>
        </a>
        <a href="#worked-examples" class="tk-help-nav-link" data-section="worked-examples">
            <span class="tk-help-nav-num">6</span>
            <?php echo t('tickets.help_sla.nav.worked_examples'); ?>
        </a>
        <a href="#troubleshooting" class="tk-help-nav-link" data-section="troubleshooting">
            <span class="tk-help-nav-num">7</span>
            <?php echo t('tickets.help_sla.nav.troubleshooting'); ?>
        </a>
    </div>

    <!-- Main content -->
    <div class="tk-help-main" id="helpMain">
        <div class="tk-help-hero">
            <h2><?php echo t('tickets.help_sla.hero_title'); ?></h2>
            <p><?php echo t('tickets.help_sla.hero_sub'); ?></p>
        </div>

        <div class="tk-help-content">

            <!-- 1. Overview -->
            <div class="tk-help-section" id="overview">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">1</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.overview.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.overview.sub'); ?></p>
                    </div>
                </div>
                <p><?php echo t('tickets.help_sla.overview.p1'); ?></p>

                <p><?php echo t('tickets.help_sla.overview.p2'); ?></p>

                <div class="tk-help-fields">
                    <div><?php echo t('tickets.help_sla.overview.choice_business'); ?></div>
                    <div><?php echo t('tickets.help_sla.overview.choice_pause'); ?></div>
                    <div><?php echo t('tickets.help_sla.overview.choice_compute'); ?></div>
                    <div><?php echo t('tickets.help_sla.overview.choice_cutoff'); ?></div>
                </div>

                <p class="tk-help-tip"><?php echo t('tickets.help_sla.overview.tip'); ?></p>
            </div>

            <!-- 2. Building blocks -->
            <div class="tk-help-section" id="building-blocks">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">2</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.blocks.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.blocks.sub'); ?></p>
                    </div>
                </div>

                <h4><?php echo t('tickets.help_sla.blocks.cal_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.blocks.cal_intro'); ?></p>
                <div class="tk-help-fields">
                    <div><?php echo t('tickets.help_sla.blocks.cal_tz'); ?></div>
                    <div><?php echo t('tickets.help_sla.blocks.cal_hours'); ?></div>
                    <div><?php echo t('tickets.help_sla.blocks.cal_holidays'); ?></div>
                    <div><?php echo t('tickets.help_sla.blocks.cal_default'); ?></div>
                </div>
                <p><?php echo t('tickets.help_sla.blocks.cal_outro'); ?></p>

                <h4><?php echo t('tickets.help_sla.blocks.prio_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.blocks.prio_intro'); ?></p>
                <div class="tk-help-fields">
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
                <p class="tk-help-warn"><?php echo t('tickets.help_sla.blocks.pause_warn'); ?></p>

                <h4><?php echo t('tickets.help_sla.blocks.cutoff_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.blocks.cutoff_p1'); ?></p>
                <p><?php echo t('tickets.help_sla.blocks.cutoff_p2'); ?></p>
            </div>

            <!-- 3. Behaviour settings -->
            <div class="tk-help-section" id="behaviour-settings">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">3</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.behaviour.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.behaviour.sub'); ?></p>
                    </div>
                </div>

                <h4><?php echo t('tickets.help_sla.behaviour.prio_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.behaviour.prio_intro'); ?></p>
                <div class="tk-help-fields">
                    <div><?php echo t('tickets.help_sla.behaviour.prio_forward'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.prio_recompute'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.prio_reset'); ?></div>
                </div>

                <h4><?php echo t('tickets.help_sla.behaviour.reopen_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.behaviour.reopen_intro'); ?></p>
                <div class="tk-help-fields">
                    <div><?php echo t('tickets.help_sla.behaviour.reopen_reset'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.reopen_continue'); ?></div>
                </div>

                <h4><?php echo t('tickets.help_sla.behaviour.first_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.behaviour.first_intro'); ?></p>
                <div class="tk-help-fields">
                    <div><?php echo t('tickets.help_sla.behaviour.first_either'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.first_status'); ?></div>
                    <div><?php echo t('tickets.help_sla.behaviour.first_email'); ?></div>
                </div>

                <h4><?php echo t('tickets.help_sla.behaviour.warn_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.behaviour.warn_body'); ?></p>
            </div>

            <!-- 4. Breach notifications -->
            <div class="tk-help-section" id="breach-notifications">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">4</span>
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
                <div class="tk-help-fields">
                    <div><?php echo t('tickets.help_sla.breach.recip_assignee'); ?></div>
                    <div><?php echo t('tickets.help_sla.breach.recip_teams'); ?></div>
                    <div><?php echo t('tickets.help_sla.breach.recip_specific'); ?></div>
                    <div><?php echo t('tickets.help_sla.breach.recip_custom'); ?></div>
                </div>

                <p class="tk-help-tip"><?php echo t('tickets.help_sla.breach.tip'); ?></p>

                <p class="tk-help-warn"><?php echo t('tickets.help_sla.breach.warn'); ?></p>
            </div>

            <!-- 5. Cron setup -->
            <div class="tk-help-section" id="cron-setup">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">5</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.cron.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.cron.sub'); ?></p>
                    </div>
                </div>

                <p><?php echo t('tickets.help_sla.cron.p1'); ?></p>

                <p><?php echo t('tickets.help_sla.cron.p2'); ?></p>

                <h4><?php echo t('tickets.help_sla.cron.cli_heading'); ?></h4>
                <div class="tk-help-code-block">php c:\wamp64\www\freeitsm-app\cron\sla_breach_check.php</div>
                <p><?php echo t('tickets.help_sla.cron.cli_note'); ?></p>

                <h4><?php echo t('tickets.help_sla.cron.http_heading'); ?></h4>
                <div class="tk-help-code-block">curl http://your-host/freeitsm-app/cron/sla_breach_check.php?token=&lt;TOKEN&gt;</div>
                <p><?php echo t('tickets.help_sla.cron.http_note'); ?></p>
                <div class="tk-help-code-block">SELECT setting_value FROM system_settings WHERE setting_key = 'sla_cron_token';</div>

                <h4><?php echo t('tickets.help_sla.cron.win_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.cron.win_intro'); ?></p>
                <table class="tk-help-table">
                    <tr><th style="width:35%;"><?php echo t('tickets.help_sla.cron.tbl_field'); ?></th><th><?php echo t('tickets.help_sla.cron.tbl_value'); ?></th></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r1_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r1_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r2_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r2_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r3_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r3_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r4_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r4_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r5_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r5_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r6_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r6_v'); ?></td></tr>
                    <tr><td><?php echo t('tickets.help_sla.cron.win_r7_k'); ?></td><td><?php echo t('tickets.help_sla.cron.win_r7_v'); ?></td></tr>
                </table>

                <h4><?php echo t('tickets.help_sla.cron.linux_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.cron.linux_intro'); ?></p>
                <div class="tk-help-code-block">*/5 * * * * /usr/bin/php /var/www/freeitsm-app/cron/sla_breach_check.php &gt;&gt; /var/log/freeitsm-sla-cron.log 2&gt;&amp;1</div>

                <h4><?php echo t('tickets.help_sla.cron.sec_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.cron.sec_intro'); ?></p>
                <div class="tk-help-fields">
                    <div><?php echo t('tickets.help_sla.cron.sec_token'); ?></div>
                    <div><?php echo t('tickets.help_sla.cron.sec_lockout'); ?></div>
                    <div><?php echo t('tickets.help_sla.cron.sec_interval'); ?></div>
                </div>

                <h4><?php echo t('tickets.help_sla.cron.log_heading'); ?></h4>
                <p><?php echo t('tickets.help_sla.cron.log_body'); ?></p>
            </div>

            <!-- 6. Worked examples -->
            <div class="tk-help-section" id="worked-examples">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">6</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.examples.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.examples.sub'); ?></p>
                    </div>
                </div>

                <h4><?php echo t('tickets.help_sla.examples.ex1_heading'); ?></h4>

                <div class="tk-help-example">
                    <h5><?php echo t('tickets.help_sla.examples.ex1_title'); ?> <span class="tag green"><?php echo t('tickets.help_sla.examples.ex1_tag'); ?></span></h5>
                    <p><?php echo t('tickets.help_sla.examples.ex1_setup'); ?></p>

                    <p><?php echo t('tickets.help_sla.examples.ex1_scenario'); ?></p>

                    <div class="timeline"><?php echo t('tickets.help_sla.examples.ex1_timeline'); ?></div>

                    <p><?php echo t('tickets.help_sla.examples.ex1_outro'); ?></p>
                </div>

                <h4><?php echo t('tickets.help_sla.examples.ex2_heading'); ?></h4>

                <div class="tk-help-example">
                    <h5><?php echo t('tickets.help_sla.examples.ex2_title'); ?> <span class="tag amber"><?php echo t('tickets.help_sla.examples.ex2_tag'); ?></span></h5>
                    <p><?php echo t('tickets.help_sla.examples.ex2_setup'); ?></p>

                    <p><?php echo t('tickets.help_sla.examples.ex2_scenario'); ?></p>

                    <p><?php echo t('tickets.help_sla.examples.ex2_question'); ?></p>
                </div>

                <div class="tk-help-option-card">
                    <span class="label customer"><?php echo t('tickets.help_sla.examples.optA_label'); ?></span>
                    <p><?php echo t('tickets.help_sla.examples.optA_p1'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optA_p2'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optA_p3'); ?></p>
                </div>

                <div class="tk-help-option-card">
                    <span class="label analyst"><?php echo t('tickets.help_sla.examples.optB_label'); ?></span>
                    <p><?php echo t('tickets.help_sla.examples.optB_p1'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optB_p2'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optB_p3'); ?></p>
                </div>

                <div class="tk-help-option-card">
                    <span class="label overlap"><?php echo t('tickets.help_sla.examples.optC_label'); ?></span>
                    <p><?php echo t('tickets.help_sla.examples.optC_p1'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optC_p2'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.optC_p3'); ?></p>
                </div>

                <p class="tk-help-tip"><?php echo t('tickets.help_sla.examples.choose_tip'); ?></p>

                <h4><?php echo t('tickets.help_sla.examples.ex3_heading'); ?></h4>

                <div class="tk-help-example">
                    <h5><?php echo t('tickets.help_sla.examples.ex3_title'); ?> <span class="tag"><?php echo t('tickets.help_sla.examples.ex3_tag'); ?></span></h5>
                    <p><?php echo t('tickets.help_sla.examples.ex3_setup'); ?></p>
                    <p><?php echo t('tickets.help_sla.examples.ex3_scenario'); ?></p>
                    <div class="timeline"><?php echo t('tickets.help_sla.examples.ex3_timeline'); ?></div>
                    <p><?php echo t('tickets.help_sla.examples.ex3_outro'); ?></p>
                </div>
            </div>

            <!-- 7. Troubleshooting -->
            <div class="tk-help-section" id="troubleshooting">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">7</span>
                    <div>
                        <h3><?php echo t('tickets.help_sla.trouble.heading'); ?></h3>
                        <p><?php echo t('tickets.help_sla.trouble.sub'); ?></p>
                    </div>
                </div>

                <table class="tk-help-table">
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

                <p class="tk-help-tip"><?php echo t('tickets.help_sla.trouble.tip'); ?></p>
            </div>

        </div>
    </div>
</div>

<script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="../assets/js/i18n.js?v=2"></script>
<script>
    // Scroll-spy: highlight active sidebar entry as user scrolls
    const helpMain = document.getElementById('helpMain');
    const navLinks = document.querySelectorAll('.tk-help-nav-link');
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
