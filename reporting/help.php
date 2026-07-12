<?php
/**
 * Reporting Help Guide - Full page with left pane navigation
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
requireModuleAccess('reporting');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('reporting.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        /* Module accent (rust-orange). */
        body { --accent: var(--rep-accent, #ca5010); --accent-hover: var(--rep-accent-hover, #a5410a); }

        .rp-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }

        /* Left sidebar navigation */
        .rp-help-sidebar {
            width: 260px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .rp-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .rp-help-nav-link {
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

        .rp-help-nav-link:hover {
            background: var(--surface-hover, #f5f5f5);
            color: var(--text, #333);
        }

        .rp-help-nav-link.active {
            background: var(--rep-accent-soft, #fbe9e7);
            color: var(--rep-accent-hover, #a5410a);
            font-weight: 600;
        }

        .rp-help-nav-num {
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

        .rp-help-nav-link.active .rp-help-nav-num {
            background: var(--rep-accent-hover, #a5410a);
            color: white;
        }

        /* Main content */
        .rp-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .rp-help-hero {
            background: linear-gradient(135deg, #ca5010 0%, #a5410a 50%, #7a2e06 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .rp-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .rp-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .rp-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .rp-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }

        .rp-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .rp-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .rp-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .rp-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: var(--text-muted, #666);
            line-height: 1.6;
        }

        .rp-help-section > p {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .rp-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--rep-accent-soft, #fbe9e7);
            color: var(--rep-accent-hover, #a5410a);
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .rp-help-section-num.highlight {
            background: var(--rep-accent-hover, #a5410a);
            color: white;
        }

        /* Feature cards grid */
        .rp-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .rp-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, white);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .rp-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow, rgba(0,0,0,0.08));
        }

        .rp-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .rp-help-feature-icon.rust { background: #fbe9e7; color: #ca5010; }
        .rp-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .rp-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .rp-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }

        .rp-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: var(--text, #333);
        }

        .rp-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        /* Numbered steps */
        .rp-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .rp-help-step-item {
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

        .rp-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--rep-accent, #ca5010);
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .rp-help-section-highlight {
            background: var(--rep-accent-soft, #fbe9e7);
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid var(--rep-accent-soft, #ffab91);
        }

        .rp-help-intro {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .rp-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .rp-help-fields div {
            padding: 8px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
        }

        /* Data cards */
        .rp-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .rp-help-data-card {
            padding: 12px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            border-left: 3px solid var(--rep-accent, #ca5010);
        }

        .rp-help-data-card strong {
            display: block;
            font-size: 13px;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .rp-help-data-card span {
            font-size: 12px;
            color: var(--text-dim, #777);
            line-height: 1.4;
        }

        /* Metric cards for understanding data */
        .rp-help-metric-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 14px 0;
        }

        .rp-help-metric-card {
            padding: 16px;
            background: var(--surface, white);
            border-radius: 8px;
            border: 1px solid var(--border, #e0e0e0);
        }

        .rp-help-metric-card h4 {
            margin: 0 0 6px;
            font-size: 14px;
            color: var(--text, #333);
        }

        .rp-help-metric-card p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        /* Tip callout */
        .rp-help-tip {
            font-size: 13px !important;
            color: var(--rep-accent-hover, #a5410a) !important;
            background: var(--rep-accent-soft, #fbe9e7);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--rep-accent, #ca5010);
            margin-top: 10px;
        }

        /* Quick tips grid */
        .rp-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .rp-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }

        .rp-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .rp-help-tip-card strong {
            color: var(--text, #333);
        }

        /* Log type badges */
        .rp-help-log-types {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 14px 0;
        }

        .rp-help-log-type {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            background: var(--surface, white);
            border-radius: 8px;
            border: 1px solid var(--border, #e0e0e0);
        }

        .rp-help-log-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .rp-help-log-badge.login { background: #e3f2fd; color: #1565c0; }
        .rp-help-log-badge.email { background: #fbe9e7; color: #ca5010; }
        .rp-help-log-badge.system { background: #e8f5e9; color: #2e7d32; }
        .rp-help-log-badge.audit { background: #f3e5f5; color: #7b1fa2; }

        .rp-help-log-type div {
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }

        .rp-help-log-type strong {
            color: var(--text, #333);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .rp-help-sidebar { display: none; }
            .rp-help-content { padding: 10px 24px 40px; }
            .rp-help-hero { padding: 30px 24px; }
            .rp-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .rp-help-features-grid { grid-template-columns: 1fr; }
            .rp-help-data-grid { grid-template-columns: 1fr; }
            .rp-help-metric-grid { grid-template-columns: 1fr; }
            .rp-help-tips-grid { grid-template-columns: 1fr; }
        }

        /* Dark mode: darken the rust hero gradient (light mode untouched). */
        [data-theme-mode="dark"] .rp-help-hero {
            background: linear-gradient(135deg, #7a3008 0%, #5a2406 50%, #3f1904 100%);
        }

        /* Dark mode: tone the highlight band's bright accent top border to dark rust. */
        [data-theme-mode="dark"] .rp-help-section-highlight {
            border-top-color: #4a2410;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="rp-help-container">
        <!-- Left pane navigation -->
        <div class="rp-help-sidebar">
            <h3><?php echo htmlspecialchars(t('reporting.help.guide')); ?></h3>
            <a href="#overview" class="rp-help-nav-link active" data-section="overview">
                <span class="rp-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_overview')); ?>
            </a>
            <a href="#ticket-reports" class="rp-help-nav-link" data-section="ticket-reports">
                <span class="rp-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_ticket_reports')); ?>
            </a>
            <a href="#system-logs" class="rp-help-nav-link" data-section="system-logs">
                <span class="rp-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_system_logs')); ?>
            </a>
            <a href="#understanding-data" class="rp-help-nav-link" data-section="understanding-data">
                <span class="rp-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_understanding_data')); ?>
            </a>
            <a href="#settings-filters" class="rp-help-nav-link" data-section="settings-filters">
                <span class="rp-help-nav-num">5</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_settings_filters')); ?>
            </a>
            <a href="#tips" class="rp-help-nav-link" data-section="tips">
                <span class="rp-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="rp-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="rp-help-hero">
                <h2><?php echo htmlspecialchars(t('reporting.help.hero_heading')); ?></h2>
                <p><?php echo htmlspecialchars(t('reporting.help.hero_sub')); ?></p>
            </div>

            <div class="rp-help-content">

                <!-- Section 1: Overview -->
                <div class="rp-help-section" id="overview">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('reporting.help.s1_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_intro')); ?></p>
                        </div>
                    </div>
                    <div class="rp-help-features-grid">
                        <div class="rp-help-feature-card">
                            <div class="rp-help-feature-icon rust">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('reporting.help.s1_card1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_card1_body')); ?></p>
                        </div>
                        <div class="rp-help-feature-card">
                            <div class="rp-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('reporting.help.s1_card2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_card2_body')); ?></p>
                        </div>
                        <div class="rp-help-feature-card">
                            <div class="rp-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('reporting.help.s1_card3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_card3_body')); ?></p>
                        </div>
                        <div class="rp-help-feature-card">
                            <div class="rp-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('reporting.help.s1_card4_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_card4_body')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Ticket Reports -->
                <div class="rp-help-section" id="ticket-reports">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s2_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('reporting.help.s2_intro')); ?></p>

                    <div class="rp-help-data-grid">
                        <div class="rp-help-data-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card1_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card1_body')); ?></span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card2_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card2_body')); ?></span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card3_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card3_body')); ?></span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card4_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card4_body')); ?></span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card5_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card5_body')); ?></span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card6_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card6_body')); ?></span>
                        </div>
                    </div>

                    <p class="rp-help-tip"><?php echo htmlspecialchars(t('reporting.help.s2_tip')); ?></p>
                </div>

                <!-- Section 3: System Logs -->
                <div class="rp-help-section" id="system-logs">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s3_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('reporting.help.s3_intro')); ?></p>

                    <div class="rp-help-log-types">
                        <div class="rp-help-log-type">
                            <span class="rp-help-log-badge login"><?php echo htmlspecialchars(t('reporting.help.s3_badge_login')); ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_login_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_login_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-log-type">
                            <span class="rp-help-log-badge email"><?php echo htmlspecialchars(t('reporting.help.s3_badge_email')); ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_email_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_email_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-log-type">
                            <span class="rp-help-log-badge system"><?php echo htmlspecialchars(t('reporting.help.s3_badge_system')); ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_system_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_system_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-log-type">
                            <span class="rp-help-log-badge audit"><?php echo htmlspecialchars(t('reporting.help.s3_badge_audit')); ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_audit_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_audit_body')); ?>
                            </div>
                        </div>
                    </div>

                    <div class="rp-help-steps">
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_step1_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_step1_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_step2_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_step2_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_step3_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_step3_body')); ?>
                            </div>
                        </div>
                    </div>

                    <p class="rp-help-tip"><?php echo htmlspecialchars(t('reporting.help.s3_tip')); ?></p>
                </div>

                <!-- Section 4: Understanding the Data (highlighted) -->
                <div class="rp-help-section rp-help-section-highlight" id="understanding-data">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num highlight">4</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s4_heading')); ?></h3>
                    </div>
                    <p class="rp-help-intro"><?php echo htmlspecialchars(t('reporting.help.s4_intro')); ?></p>

                    <div class="rp-help-metric-grid">
                        <div class="rp-help-metric-card">
                            <h4><?php echo htmlspecialchars(t('reporting.help.s4_metric1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s4_metric1_body')); ?></p>
                        </div>
                        <div class="rp-help-metric-card">
                            <h4><?php echo htmlspecialchars(t('reporting.help.s4_metric2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s4_metric2_body')); ?></p>
                        </div>
                        <div class="rp-help-metric-card">
                            <h4><?php echo htmlspecialchars(t('reporting.help.s4_metric3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s4_metric3_body')); ?></p>
                        </div>
                        <div class="rp-help-metric-card">
                            <h4><?php echo htmlspecialchars(t('reporting.help.s4_metric4_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s4_metric4_body')); ?></p>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('reporting.help.s4_combine')); ?></p>

                    <p class="rp-help-tip"><?php echo htmlspecialchars(t('reporting.help.s4_tip')); ?></p>
                </div>

                <!-- Section 5: Settings & Filters -->
                <div class="rp-help-section" id="settings-filters">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s5_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('reporting.help.s5_intro')); ?></p>

                    <div class="rp-help-steps">
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step1_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step1_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step2_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step2_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step3_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step3_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step4_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step4_body')); ?>
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">5</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step5_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step5_body')); ?>
                            </div>
                        </div>
                    </div>

                    <p class="rp-help-tip"><?php echo htmlspecialchars(t('reporting.help.s5_tip')); ?></p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="rp-help-section" id="tips">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s6_heading')); ?></h3>
                    </div>
                    <div class="rp-help-tips-grid">
                        <div class="rp-help-tip-card">
                            <div class="rp-help-tip-icon">&#128202;</div>
                            <div><strong><?php echo htmlspecialchars(t('reporting.help.s6_tip1_title')); ?></strong><br><?php echo htmlspecialchars(t('reporting.help.s6_tip1_body')); ?></div>
                        </div>
                        <div class="rp-help-tip-card">
                            <div class="rp-help-tip-icon">&#128269;</div>
                            <div><strong><?php echo htmlspecialchars(t('reporting.help.s6_tip2_title')); ?></strong><br><?php echo htmlspecialchars(t('reporting.help.s6_tip2_body')); ?></div>
                        </div>
                        <div class="rp-help-tip-card">
                            <div class="rp-help-tip-icon">&#128200;</div>
                            <div><strong><?php echo htmlspecialchars(t('reporting.help.s6_tip3_title')); ?></strong><br><?php echo htmlspecialchars(t('reporting.help.s6_tip3_body')); ?></div>
                        </div>
                        <div class="rp-help-tip-card">
                            <div class="rp-help-tip-icon">&#128274;</div>
                            <div><strong><?php echo htmlspecialchars(t('reporting.help.s6_tip4_title')); ?></strong><br><?php echo htmlspecialchars(t('reporting.help.s6_tip4_body')); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.rp-help-nav-link');
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
