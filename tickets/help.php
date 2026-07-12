<?php
/**
 * Tickets Module Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/functions.php';
require_once '../includes/tenancy.php';
require_once '../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}
requireModuleAccess('tickets');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'tickets'];

// The companies / email-routing section only makes sense once the install serves
// more than one company — keep it invisible on a single-company install, exactly
// like the rest of multi-tenancy (isMultiTenant gate).
$showTenancyHelp = false;
try {
    $conn = connectToDatabase();
    $showTenancyHelp = isMultiTenant($conn);
} catch (Exception $e) {
    $showTenancyHelp = false;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .tk-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }

        /* Left sidebar navigation */
        .tk-help-sidebar {
            width: 260px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .tk-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

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

        .tk-help-nav-link:hover {
            background: var(--surface-hover, #f5f5f5);
            color: var(--text, #333);
        }

        .tk-help-nav-link.active {
            background: var(--accent-soft, #e3f2fd);
            color: var(--accent-hover, #005a9e);
            font-weight: 600;
        }

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

        .tk-help-nav-link.active .tk-help-nav-num {
            background: var(--accent, #0078d4);
            color: var(--on-accent, white);
        }


        /* Main content */
        .tk-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .tk-help-hero {
            background: linear-gradient(135deg, #0078d4 0%, #005a9e 50%, #003d6b 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        /* Darken the hero in dark mode so it recedes instead of glowing bright blue. */
        [data-theme-mode="dark"] .tk-help-hero {
            background: linear-gradient(135deg, #1f3f63 0%, #15304c 50%, #0c2031 100%);
        }

        .tk-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .tk-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .tk-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .tk-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }

        .tk-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .tk-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .tk-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .tk-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: var(--text-muted, #666);
            line-height: 1.6;
        }

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

        .tk-help-section-num.highlight {
            background: var(--accent, #0078d4);
            color: var(--on-accent, white);
        }

        /* Feature cards grid */
        .tk-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .tk-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, white);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .tk-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow, rgba(0,0,0,0.08));
        }

        .tk-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .tk-help-feature-icon.blue { background: #e3f2fd; color: #0078d4; }
        .tk-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .tk-help-feature-icon.orange { background: #fff3e0; color: #e65100; }
        .tk-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }
        .tk-help-feature-icon.teal { background: #e0f2f1; color: #00695c; }
        .tk-help-feature-icon.red { background: #fce4ec; color: #c62828; }

        .tk-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: var(--text, #333);
        }

        .tk-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        /* Numbered steps */
        .tk-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .tk-help-step-item {
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

        .tk-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--accent, #0078d4);
            color: var(--on-accent, white);
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .tk-help-section-highlight {
            background: var(--accent-soft, #e3f2fd);
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid var(--accent, #90caf9);
        }

        .tk-help-intro {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .tk-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .tk-help-fields div {
            padding: 8px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
        }

        /* Data cards grid */
        .tk-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .tk-help-data-card {
            padding: 12px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            border-left: 3px solid var(--accent, #0078d4);
        }

        .tk-help-data-card strong {
            display: block;
            font-size: 13px;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .tk-help-data-card span {
            font-size: 12px;
            color: var(--text-dim, #777);
            line-height: 1.4;
        }

        /* Flow diagram */
        .tk-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .tk-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .tk-help-flow-step.inbox { background: #e3f2fd; color: #0078d4; }
        .tk-help-flow-step.action { background: #fff3e0; color: #e65100; }
        .tk-help-flow-step.resolve { background: #e8f5e9; color: #2e7d32; }
        .tk-help-flow-step.closed { background: #f3e5f5; color: #7b1fa2; }

        .tk-help-flow-arrow {
            padding: 0 8px;
            color: var(--text-faint, #bbb);
            font-size: 18px;
        }

        /* Tip callout */
        .tk-help-tip {
            font-size: 13px !important;
            color: var(--accent-hover, #005a9e) !important;
            background: var(--accent-soft, #e3f2fd);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--accent, #0078d4);
            margin-top: 10px;
        }

        /* Quick tips grid */
        .tk-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .tk-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }

        .tk-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .tk-help-tip-card strong {
            color: var(--text, #333);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .tk-help-sidebar { display: none; }
            .tk-help-content { padding: 10px 24px 40px; }
            .tk-help-hero { padding: 30px 24px; }
            .tk-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .tk-help-features-grid { grid-template-columns: 1fr; }
            .tk-help-data-grid { grid-template-columns: 1fr; }
            .tk-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="tk-help-container">
        <!-- Left pane navigation -->
        <div class="tk-help-sidebar">
            <h3><?php echo htmlspecialchars(t('tickets.help.sidebar_title')); ?></h3>
            <a href="#overview" class="tk-help-nav-link active" data-section="overview">
                <span class="tk-help-nav-num">1</span>
                <?php echo t('tickets.help.nav.overview'); ?>
            </a>
            <a href="#inbox" class="tk-help-nav-link" data-section="inbox">
                <span class="tk-help-nav-num">2</span>
                <?php echo t('tickets.help.nav.inbox'); ?>
            </a>
            <a href="#working-with-tickets" class="tk-help-nav-link" data-section="working-with-tickets">
                <span class="tk-help-nav-num">3</span>
                <?php echo t('tickets.help.nav.working_with_tickets'); ?>
            </a>
            <a href="#comments-attachments" class="tk-help-nav-link" data-section="comments-attachments">
                <span class="tk-help-nav-num">4</span>
                <?php echo t('tickets.help.nav.comments_attachments'); ?>
            </a>
            <a href="#ai-tools" class="tk-help-nav-link" data-section="ai-tools">
                <span class="tk-help-nav-num">5</span>
                <?php echo t('tickets.help.nav.ai_tools'); ?>
            </a>
            <a href="#csat" class="tk-help-nav-link" data-section="csat">
                <span class="tk-help-nav-num">6</span>
                <?php echo t('tickets.help.nav.csat'); ?>
            </a>
            <a href="#user-management" class="tk-help-nav-link" data-section="user-management">
                <span class="tk-help-nav-num">7</span>
                <?php echo t('tickets.help.nav.user_management'); ?>
            </a>
            <a href="#dashboard" class="tk-help-nav-link" data-section="dashboard">
                <span class="tk-help-nav-num">8</span>
                <?php echo t('tickets.help.nav.dashboard'); ?>
            </a>
            <a href="#calendar-rota" class="tk-help-nav-link" data-section="calendar-rota">
                <span class="tk-help-nav-num">9</span>
                <?php echo t('tickets.help.nav.calendar_rota'); ?>
            </a>
            <a href="#settings" class="tk-help-nav-link" data-section="settings">
                <span class="tk-help-nav-num">10</span>
                <?php echo t('tickets.help.nav.settings'); ?>
            </a>
            <a href="#tips" class="tk-help-nav-link" data-section="tips">
                <span class="tk-help-nav-num">11</span>
                <?php echo t('tickets.help.nav.tips'); ?>
            </a>
            <?php if ($showTenancyHelp): ?>
            <a href="#companies" class="tk-help-nav-link" data-section="companies">
                <span class="tk-help-nav-num">12</span>
                <?php echo t('tickets.help.nav.companies'); ?>
            </a>
            <?php endif; ?>
            <a href="#whatsapp" class="tk-help-nav-link" data-section="whatsapp">
                <span class="tk-help-nav-num"><?php echo $showTenancyHelp ? 13 : 12; ?></span>
                WhatsApp channel
            </a>
        </div>

        <!-- Main content area -->
        <div class="tk-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="tk-help-hero">
                <h2><?php echo t('tickets.help.hero_title'); ?></h2>
                <p><?php echo t('tickets.help.hero_sub'); ?></p>
            </div>

            <div class="tk-help-content">

                <!-- Section 1: Overview -->
                <div class="tk-help-section" id="overview">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">1</span>
                        <div>
                            <h3><?php echo t('tickets.help.overview.heading'); ?></h3>
                            <p><?php echo t('tickets.help.overview.intro'); ?></p>
                        </div>
                    </div>
                    <div class="tk-help-features-grid">
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4><?php echo t('tickets.help.overview.card_inbox_title'); ?></h4>
                            <p><?php echo t('tickets.help.overview.card_inbox_body'); ?></p>
                        </div>
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                            </div>
                            <h4><?php echo t('tickets.help.overview.card_dashboard_title'); ?></h4>
                            <p><?php echo t('tickets.help.overview.card_dashboard_body'); ?></p>
                        </div>
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4><?php echo t('tickets.help.overview.card_calendar_title'); ?></h4>
                            <p><?php echo t('tickets.help.overview.card_calendar_body'); ?></p>
                        </div>
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </div>
                            <h4><?php echo t('tickets.help.overview.card_rota_title'); ?></h4>
                            <p><?php echo t('tickets.help.overview.card_rota_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: The Inbox -->
                <div class="tk-help-section" id="inbox">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">2</span>
                        <div>
                            <h3><?php echo t('tickets.help.inbox.heading'); ?></h3>
                            <p><?php echo t('tickets.help.inbox.intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tickets.help.inbox.p_folders'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.inbox.field_my'); ?></div>
                        <div><?php echo t('tickets.help.inbox.field_unassigned'); ?></div>
                        <div><?php echo t('tickets.help.inbox.field_all_open'); ?></div>
                        <div><?php echo t('tickets.help.inbox.field_closed'); ?></div>
                        <div><?php echo t('tickets.help.inbox.field_dept'); ?></div>
                    </div>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.inbox.switch_heading'); ?></p>
                    <p><?php echo t('tickets.help.inbox.switch_body'); ?></p>

                    <p><?php echo t('tickets.help.inbox.p_actions'); ?></p>
                    <p class="tk-help-tip"><?php echo t('tickets.help.inbox.tip'); ?></p>
                </div>

                <!-- Section 3: Working with Tickets (highlighted) -->
                <div class="tk-help-section tk-help-section-highlight" id="working-with-tickets">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num highlight">3</span>
                        <h3><?php echo t('tickets.help.working.heading'); ?></h3>
                    </div>
                    <p class="tk-help-intro"><?php echo t('tickets.help.working.intro'); ?></p>

                    <p><?php echo t('tickets.help.working.creating_heading'); ?></p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.working.step1'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.working.step2'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.working.step3'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">4</div>
                            <div>
                                <?php echo t('tickets.help.working.step4'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">5</div>
                            <div>
                                <?php echo t('tickets.help.working.step5'); ?>
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.working.editing_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.editing_body'); ?></p>
                    <div class="tk-help-data-grid">
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.working.card_status_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_status_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.working.card_priority_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_priority_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.working.card_category_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_category_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.working.card_analyst_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_analyst_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.working.card_enduser_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_enduser_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.working.card_dept_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_dept_body'); ?></span>
                        </div>
                    </div>

                    <div class="tk-help-flow">
                        <div class="tk-help-flow-step inbox"><?php echo t('tickets.help.working.flow_new'); ?></div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step action"><?php echo t('tickets.help.working.flow_in_progress'); ?></div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step resolve"><?php echo t('tickets.help.working.flow_resolved'); ?></div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step closed"><?php echo t('tickets.help.working.flow_closed'); ?></div>
                    </div>

                    <p style="margin-top: 24px;"><?php echo t('tickets.help.working.triage_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.triage_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.working.triage_dept'); ?></div>
                        <div><?php echo t('tickets.help.working.triage_analyst'); ?></div>
                        <div><?php echo t('tickets.help.working.triage_spring'); ?></div>
                    </div>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.working.fullscreen_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.fullscreen_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.working.fullscreen_max'); ?></div>
                        <div><?php echo t('tickets.help.working.fullscreen_dbl'); ?></div>
                        <div><?php echo t('tickets.help.working.fullscreen_sticks'); ?></div>
                    </div>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.working.rightclick_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.rightclick_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.working.rightclick_status'); ?></div>
                        <div><?php echo t('tickets.help.working.rightclick_priority'); ?></div>
                        <div><?php echo t('tickets.help.working.rightclick_assign'); ?></div>
                        <div><?php echo t('tickets.help.working.rightclick_cmdb'); ?></div>
                        <div><?php echo t('tickets.help.working.rightclick_time'); ?></div>
                    </div>
                    <p class="tk-help-tip"><?php echo t('tickets.help.working.rightclick_tip'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.working.time_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.time_body'); ?></p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.working.time_step1'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.working.time_step2'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.working.time_step3'); ?>
                            </div>
                        </div>
                    </div>
                    <p class="tk-help-tip"><?php echo t('tickets.help.working.time_tip1'); ?></p>

                    <p class="tk-help-tip"><?php echo t('tickets.help.working.time_tip2'); ?></p>
                </div>

                <!-- Section 4: Comments & Attachments -->
                <div class="tk-help-section" id="comments-attachments">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">4</span>
                        <div>
                            <h3><?php echo t('tickets.help.comments.heading'); ?></h3>
                            <p><?php echo t('tickets.help.comments.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.comments.adding_heading'); ?></p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.comments.step1'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.comments.step2'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.comments.step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.comments.files_heading'); ?></p>
                    <p><?php echo t('tickets.help.comments.files_body'); ?></p>

                    <p><?php echo t('tickets.help.comments.audit_heading'); ?></p>
                    <p><?php echo t('tickets.help.comments.audit_body'); ?></p>

                    <p class="tk-help-tip"><?php echo t('tickets.help.comments.tip'); ?></p>
                </div>

                <!-- Section 5: AI tools -->
                <div class="tk-help-section" id="ai-tools">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">5</span>
                        <div>
                            <h3><?php echo t('tickets.help.ai.heading'); ?></h3>
                            <p><?php echo t('tickets.help.ai.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.ai.cleanup_heading'); ?></p>
                    <p><?php echo t('tickets.help.ai.cleanup_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.ai.cleanup_streams'); ?></div>
                        <div><?php echo t('tickets.help.ai.cleanup_undo'); ?></div>
                        <div><?php echo t('tickets.help.ai.cleanup_tone'); ?></div>
                        <div><?php echo t('tickets.help.ai.cleanup_key'); ?></div>
                    </div>
                    <p class="tk-help-tip"><?php echo t('tickets.help.ai.cleanup_tip'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.ai.ask_heading'); ?></p>
                    <p><?php echo t('tickets.help.ai.ask_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.ai.ask_context'); ?></div>
                        <div><?php echo t('tickets.help.ai.ask_linked'); ?></div>
                        <div><?php echo t('tickets.help.ai.ask_shared'); ?></div>
                    </div>
                </div>

                <!-- Section 6: CSAT surveys -->
                <div class="tk-help-section" id="csat">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">6</span>
                        <div>
                            <h3><?php echo t('tickets.help.csat.heading'); ?></h3>
                            <p><?php echo t('tickets.help.csat.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.csat.modes_heading'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.csat.mode_auto'); ?></div>
                        <div><?php echo t('tickets.help.csat.mode_manual'); ?></div>
                        <div><?php echo t('tickets.help.csat.mode_off'); ?></div>
                    </div>
                    <p><?php echo t('tickets.help.csat.modes_choose'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.csat.template_heading'); ?></p>
                    <p><?php echo t('tickets.help.csat.template_body'); ?></p>
                    <p class="tk-help-tip"><?php echo t('tickets.help.csat.template_tip'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.csat.survey_heading'); ?></p>
                    <p><?php echo t('tickets.help.csat.survey_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.csat.survey_stars'); ?></div>
                        <div><?php echo t('tickets.help.csat.survey_emojis'); ?></div>
                    </div>
                    <p><?php echo t('tickets.help.csat.survey_store'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.csat.analytics_heading'); ?></p>
                    <p><?php echo t('tickets.help.csat.analytics_body'); ?></p>
                    <div class="tk-help-data-grid">
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.csat.card_kpi_title'); ?></strong>
                            <span><?php echo t('tickets.help.csat.card_kpi_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.csat.card_dist_title'); ?></strong>
                            <span><?php echo t('tickets.help.csat.card_dist_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.csat.card_leader_title'); ?></strong>
                            <span><?php echo t('tickets.help.csat.card_leader_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.csat.card_recent_title'); ?></strong>
                            <span><?php echo t('tickets.help.csat.card_recent_body'); ?></span>
                        </div>
                    </div>
                    <p class="tk-help-tip"><?php echo t('tickets.help.csat.analytics_tip'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.csat.one_heading'); ?></p>
                    <p><?php echo t('tickets.help.csat.one_body'); ?></p>
                </div>

                <!-- Section 7: User management -->
                <div class="tk-help-section" id="user-management">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">7</span>
                        <div>
                            <h3><?php echo t('tickets.help.users.heading'); ?></h3>
                            <p><?php echo t('tickets.help.users.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.users.p_intro'); ?></p>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.users.add_heading'); ?></p>
                    <p><?php echo t('tickets.help.users.add_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.users.add_email'); ?></div>
                        <div><?php echo t('tickets.help.users.add_names'); ?></div>
                        <div><?php echo t('tickets.help.users.add_password'); ?></div>
                    </div>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.users.edit_heading'); ?></p>
                    <p><?php echo t('tickets.help.users.edit_body'); ?></p>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.users.delete_heading'); ?></p>
                    <p><?php echo t('tickets.help.users.delete_body'); ?></p>
                    <p class="tk-help-tip"><?php echo t('tickets.help.users.tip'); ?></p>
                </div>

                <!-- Section 8: Dashboard -->
                <div class="tk-help-section" id="dashboard">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">8</span>
                        <div>
                            <h3><?php echo t('tickets.help.dash.heading'); ?></h3>
                            <p><?php echo t('tickets.help.dash.intro'); ?></p>
                        </div>
                    </div>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.dash.step1'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.dash.step2'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.dash.step3'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">4</div>
                            <div>
                                <?php echo t('tickets.help.dash.step4'); ?>
                            </div>
                        </div>
                    </div>
                    <p><?php echo t('tickets.help.dash.examples'); ?></p>
                    <p class="tk-help-tip"><?php echo t('tickets.help.dash.tip'); ?></p>
                </div>

                <!-- Section 9: Calendar & Rota (highlighted) -->
                <div class="tk-help-section tk-help-section-highlight" id="calendar-rota">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num highlight">9</span>
                        <h3><?php echo t('tickets.help.cal_rota.heading'); ?></h3>
                    </div>
                    <p class="tk-help-intro"><?php echo t('tickets.help.cal_rota.intro'); ?></p>

                    <p><?php echo t('tickets.help.cal_rota.cal_heading'); ?></p>
                    <p><?php echo t('tickets.help.cal_rota.cal_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.cal_rota.cal_month'); ?></div>
                        <div><?php echo t('tickets.help.cal_rota.cal_week'); ?></div>
                        <div><?php echo t('tickets.help.cal_rota.cal_day'); ?></div>
                    </div>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.cal_rota.rota_heading'); ?></p>
                    <p><?php echo t('tickets.help.cal_rota.rota_body'); ?></p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.cal_rota.rota_step1'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.cal_rota.rota_step2'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.cal_rota.rota_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="tk-help-tip"><?php echo t('tickets.help.cal_rota.tip'); ?></p>
                </div>

                <!-- Section 10: Settings -->
                <div class="tk-help-section" id="settings">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">10</span>
                        <div>
                            <h3><?php echo t('tickets.help.settings.heading'); ?></h3>
                            <p><?php echo t('tickets.help.settings.intro'); ?></p>
                        </div>
                    </div>

                    <!-- Prominent SLA Management callout -->
                    <a href="help-sla.php" style="display:flex;align-items:center;gap:18px;padding:20px 24px;margin-bottom:24px;background:linear-gradient(135deg, #0078d4 0%, #005a9e 100%);color:white;border-radius:12px;text-decoration:none;box-shadow:0 4px 12px rgba(0,120,212,0.25);transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(0,120,212,0.35)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(0,120,212,0.25)';">
                        <div style="flex-shrink:0;width:56px;height:56px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:18px;font-weight:700;margin-bottom:4px;"><?php echo t('tickets.help.settings.sla_callout_title'); ?></div>
                            <div style="font-size:13px;opacity:0.9;line-height:1.5;"><?php echo t('tickets.help.settings.sla_callout_body'); ?></div>
                        </div>
                        <div style="flex-shrink:0;font-size:24px;opacity:0.7;">&rarr;</div>
                    </a>

                    <!-- Prominent Mailbox Authentication callout -->
                    <a href="help-mailbox-auth.php" style="display:flex;align-items:center;gap:18px;padding:20px 24px;margin-bottom:24px;background:linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);color:white;border-radius:12px;text-decoration:none;box-shadow:0 4px 12px rgba(79,70,229,0.25);transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(79,70,229,0.35)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(79,70,229,0.25)';">
                        <div style="flex-shrink:0;width:56px;height:56px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:18px;font-weight:700;margin-bottom:4px;">Mailbox Authentication — Admin Guide</div>
                            <div style="font-size:13px;opacity:0.9;line-height:1.5;">Connect a Microsoft 365 or Google mailbox: delegated vs app-only, the "reading from the right inbox" safeguards, email aliases, OAuth scopes &amp; Azure setup, and troubleshooting.</div>
                        </div>
                        <div style="flex-shrink:0;font-size:24px;opacity:0.7;">&rarr;</div>
                    </a>

                    <div class="tk-help-data-grid">
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_dept_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_dept_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_types_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_types_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_priorities_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_priorities_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_statuses_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_statuses_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_categories_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_categories_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_sla_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_sla_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_email_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_email_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_custom_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_custom_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_templates_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_templates_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_cleanup_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_cleanup_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.settings.card_csat_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_csat_body'); ?></span>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.settings.email_heading'); ?></p>
                    <p><?php echo t('tickets.help.settings.email_body'); ?></p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.settings.email_step1'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.settings.email_step2'); ?>
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.settings.email_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="tk-help-tip"><?php echo t('tickets.help.settings.tip'); ?></p>
                </div>

                <!-- Section 11: Quick Tips -->
                <div class="tk-help-section" id="tips">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">11</span>
                        <h3><?php echo t('tickets.help.tips.heading'); ?></h3>
                    </div>
                    <div class="tk-help-tips-grid">
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128269;</div>
                            <div><strong><?php echo t('tickets.help.tips.search_title'); ?></strong><br><?php echo t('tickets.help.tips.search_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#9200;</div>
                            <div><strong><?php echo t('tickets.help.tips.sla_title'); ?></strong><br><?php echo t('tickets.help.tips.sla_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128233;</div>
                            <div><strong><?php echo t('tickets.help.tips.reply_title'); ?></strong><br><?php echo t('tickets.help.tips.reply_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128203;</div>
                            <div><strong><?php echo t('tickets.help.tips.trail_title'); ?></strong><br><?php echo t('tickets.help.tips.trail_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128200;</div>
                            <div><strong><?php echo t('tickets.help.tips.dash_title'); ?></strong><br><?php echo t('tickets.help.tips.dash_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128197;</div>
                            <div><strong><?php echo t('tickets.help.tips.rota_title'); ?></strong><br><?php echo t('tickets.help.tips.rota_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#129032;</div>
                            <div><strong><?php echo t('tickets.help.tips.drag_title'); ?></strong><br><?php echo t('tickets.help.tips.drag_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128471;</div>
                            <div><strong><?php echo t('tickets.help.tips.dbl_title'); ?></strong><br><?php echo t('tickets.help.tips.dbl_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128499;</div>
                            <div><strong><?php echo t('tickets.help.tips.rightclick_title'); ?></strong><br><?php echo t('tickets.help.tips.rightclick_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#10024;</div>
                            <div><strong><?php echo t('tickets.help.tips.ai_title'); ?></strong><br><?php echo t('tickets.help.tips.ai_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#11088;</div>
                            <div><strong><?php echo t('tickets.help.tips.feedback_title'); ?></strong><br><?php echo t('tickets.help.tips.feedback_body'); ?></div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128100;</div>
                            <div><strong><?php echo t('tickets.help.tips.precreate_title'); ?></strong><br><?php echo t('tickets.help.tips.precreate_body'); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($showTenancyHelp): ?>
                <!-- Section 12: Companies & email routing (multi-tenancy) -->
                <div class="tk-help-section" id="companies">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">12</span>
                        <div>
                            <h3><?php echo t('tickets.help.companies.heading'); ?></h3>
                            <p><?php echo t('tickets.help.companies.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.companies.switcher_body'); ?></p>

                    <!-- Two kinds of mailbox -->
                    <p style="margin-top: 8px;"><?php echo t('tickets.help.companies.mailboxes_heading'); ?></p>
                    <div class="tk-help-features-grid">
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            </div>
                            <h4><?php echo t('tickets.help.companies.card_pinned_title'); ?></h4>
                            <p><?php echo t('tickets.help.companies.card_pinned_body'); ?></p>
                        </div>
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon teal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4><?php echo t('tickets.help.companies.card_shared_title'); ?></h4>
                            <p><?php echo t('tickets.help.companies.card_shared_body'); ?></p>
                        </div>
                    </div>

                    <!-- How a shared mailbox decides which company -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.routing_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.routing_body'); ?></p>
                    <div class="tk-help-flow">
                        <div class="tk-help-flow-step inbox"><?php echo t('tickets.help.companies.flow_reply'); ?></div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step action"><?php echo t('tickets.help.companies.flow_sender'); ?></div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step resolve"><?php echo t('tickets.help.companies.flow_domain'); ?></div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step closed"><?php echo t('tickets.help.companies.flow_triage'); ?></div>
                    </div>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.companies.rule_reply'); ?></div>
                        <div><?php echo t('tickets.help.companies.rule_sender'); ?></div>
                        <div><?php echo t('tickets.help.companies.rule_domain'); ?></div>
                        <div><?php echo t('tickets.help.companies.rule_triage'); ?></div>
                    </div>

                    <!-- Domains & specific senders -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.keys_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.keys_body'); ?></p>
                    <div class="tk-help-data-grid">
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.companies.card_domains_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.card_domains_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.companies.card_senders_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.card_senders_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.companies.card_public_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.card_public_body'); ?></span>
                        </div>
                    </div>

                    <!-- Triage queue -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.triage_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.triage_body'); ?></p>
                    <div class="tk-help-fields">
                        <div><?php echo t('tickets.help.companies.triage_create'); ?></div>
                        <div><?php echo t('tickets.help.companies.triage_assign'); ?></div>
                        <div><?php echo t('tickets.help.companies.triage_sweep'); ?></div>
                    </div>
                    <p class="tk-help-tip"><?php echo t('tickets.help.companies.triage_tip'); ?></p>

                    <!-- Routing test -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.test_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.test_body'); ?></p>

                    <!-- Data separation -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.privacy_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.privacy_body'); ?></p>

                    <!-- Per-company settings -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.settings_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.settings_body'); ?></p>
                    <div class="tk-help-data-grid">
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.companies.settings_custom_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.settings_custom_body'); ?></span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong><?php echo t('tickets.help.companies.settings_global_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.settings_global_body'); ?></span>
                        </div>
                    </div>

                    <p class="tk-help-tip"><?php echo t('tickets.help.companies.tip'); ?></p>
                </div>
                <?php endif; ?>

                <!-- WhatsApp channel -->
                <div class="tk-help-section" id="whatsapp">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num"><?php echo $showTenancyHelp ? 13 : 12; ?></span>
                        <div>
                            <h3>WhatsApp channel</h3>
                            <p>Let customers chat with an analyst over WhatsApp — each message becomes a ticket, just like email.</p>
                        </div>
                    </div>
                    <p>
                        Add a channel under <strong>Settings &rarr; Messaging</strong> (provider = Twilio or Meta, plus the
                        WhatsApp number and credentials). Each channel shows a <strong>webhook URL</strong> — paste it into your
                        provider so inbound messages reach this install.
                    </p>
                    <p>
                        An inbound message opens a new ticket (tagged with the <strong>WhatsApp</strong> origin) or threads into
                        the customer's open one. Reply from the <strong>inline composer</strong> in the reading pane — the
                        <strong>Suggest</strong> button drafts a reply with AI and <strong>Summarise</strong> writes a summary
                        into the ticket notes. WhatsApp only allows free-text replies within <strong>24 hours</strong> of the
                        customer's last message; after that the composer offers a <strong>template picker</strong> instead &mdash;
                        pick a pre-approved template (set up under Settings &rarr; Messaging), fill its blanks, and send to
                        re-open the conversation.
                    </p>
                    <p class="tk-help-tip">
                        Testing on a laptop? Providers can only reach a public address, so run a tunnel
                        (e.g. <code>ngrok http 80</code>) and use the HTTPS URL it gives you as the webhook host. See the
                        <a href="https://github.com/edmozley/freeitsm/wiki/WhatsApp" target="_blank" rel="noopener">WhatsApp wiki page</a> for a full walkthrough.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.tk-help-nav-link');
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
