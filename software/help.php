<?php
/**
 * Software Help Guide - Full page with left pane navigation
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
requireModuleAccess('software');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'software'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('software.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=19">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        /* Module accent (indigo). */
        body { --accent: var(--sw-accent, #5c6bc0); --accent-hover: var(--sw-accent-hover, #3f51b5); }

        .sw-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }

        /* Left sidebar navigation */
        .sw-help-sidebar {
            width: 260px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .sw-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .sw-help-nav-link {
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

        .sw-help-nav-link:hover {
            background: var(--surface-hover, #f5f5f5);
            color: var(--text, #333);
        }

        .sw-help-nav-link.active {
            background: var(--sw-accent-soft, #e8eaf6);
            color: var(--sw-accent-hover, #283593);
            font-weight: 600;
        }

        .sw-help-nav-num {
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

        .sw-help-nav-link.active .sw-help-nav-num {
            background: var(--sw-accent-hover, #283593);
            color: white;
        }

        /* Main content */
        .sw-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .sw-help-hero {
            background: linear-gradient(135deg, #5c6bc0 0%, #3f51b5 50%, #283593 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .sw-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .sw-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .sw-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .sw-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }

        .sw-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .sw-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .sw-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .sw-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: var(--text-muted, #666);
            line-height: 1.6;
        }

        .sw-help-section > p {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .sw-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--sw-accent-soft, #e8eaf6);
            color: var(--sw-accent-hover, #283593);
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sw-help-section-num.highlight {
            background: var(--sw-accent-hover, #3f51b5);
            color: white;
        }

        /* Feature cards grid */
        .sw-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .sw-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, white);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .sw-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow, rgba(0,0,0,0.08));
        }

        .sw-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .sw-help-feature-icon.indigo { background: #e8eaf6; color: #5c6bc0; }
        .sw-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .sw-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .sw-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .sw-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: var(--text, #333);
        }

        .sw-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        /* Numbered steps */
        .sw-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .sw-help-step-item {
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

        .sw-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--sw-accent, #5c6bc0);
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .sw-help-section-highlight {
            background: var(--sw-accent-soft, #e8eaf6);
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #9fa8da;   /* dark override below tones this */
        }

        .sw-help-intro {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .sw-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .sw-help-fields div {
            padding: 8px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
        }

        /* Flow diagram */
        .sw-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .sw-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .sw-help-flow-step.script { background: #e8eaf6; color: #283593; }
        .sw-help-flow-step.api { background: #fff3e0; color: #e65100; }
        .sw-help-flow-step.db { background: #e8f5e9; color: #2e7d32; }
        .sw-help-flow-step.ui { background: #e3f2fd; color: #1565c0; }

        .sw-help-flow-arrow {
            padding: 0 8px;
            color: var(--text-faint, #bbb);
            font-size: 18px;
        }

        /* Tip callout */
        .sw-help-tip {
            font-size: 13px !important;
            color: var(--sw-accent-hover, #283593) !important;
            background: var(--sw-accent-soft, #e8eaf6);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--sw-accent, #5c6bc0);
            margin-top: 10px;
        }

        /* Data cards */
        .sw-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .sw-help-data-card {
            padding: 12px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            border-left: 3px solid var(--sw-accent, #5c6bc0);
        }

        .sw-help-data-card strong {
            display: block;
            font-size: 13px;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .sw-help-data-card span {
            font-size: 12px;
            color: var(--text-muted, #777);
            line-height: 1.4;
        }

        /* Quick tips grid */
        .sw-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .sw-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }

        .sw-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .sw-help-tip-card strong {
            color: var(--text, #333);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .sw-help-sidebar { display: none; }
            .sw-help-content { padding: 10px 24px 40px; }
            .sw-help-hero { padding: 30px 24px; }
            .sw-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .sw-help-features-grid { grid-template-columns: 1fr; }
            .sw-help-data-grid { grid-template-columns: 1fr; }
            .sw-help-tips-grid { grid-template-columns: 1fr; }
        }

        /* Dark mode: darken the indigo hero gradient (light mode untouched). */
        [data-theme-mode="dark"] .sw-help-hero {
            background: linear-gradient(135deg, #2a3060 0%, #1e2347 100%);
        }

        /* Dark mode: tone the highlight band's bright indigo top border. */
        [data-theme-mode="dark"] .sw-help-section-highlight {
            border-top-color: #363e6b;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="sw-help-container">
        <!-- Left pane navigation -->
        <div class="sw-help-sidebar">
            <h3><?php echo htmlspecialchars(t('software.help.guide')); ?></h3>
            <a href="#overview" class="sw-help-nav-link active" data-section="overview">
                <span class="sw-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('software.help.nav_overview')); ?>
            </a>
            <a href="#inventory" class="sw-help-nav-link" data-section="inventory">
                <span class="sw-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('software.help.nav_inventory')); ?>
            </a>
            <a href="#dashboard" class="sw-help-nav-link" data-section="dashboard">
                <span class="sw-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('software.help.nav_dashboard')); ?>
            </a>
            <a href="#licences" class="sw-help-nav-link" data-section="licences">
                <span class="sw-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('software.help.nav_licences')); ?>
            </a>
            <a href="#data-collection" class="sw-help-nav-link" data-section="data-collection">
                <span class="sw-help-nav-num">5</span>
                <?php echo htmlspecialchars(t('software.help.nav_collection')); ?>
            </a>
            <a href="#settings" class="sw-help-nav-link" data-section="settings">
                <span class="sw-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('software.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="sw-help-nav-link" data-section="tips">
                <span class="sw-help-nav-num">7</span>
                <?php echo htmlspecialchars(t('software.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="sw-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="sw-help-hero">
                <h2><?php echo htmlspecialchars(t('software.help.hero_heading')); ?></h2>
                <p><?php echo htmlspecialchars(t('software.help.hero_sub')); ?></p>
            </div>

            <div class="sw-help-content">

                <!-- Section 1: Overview -->
                <div class="sw-help-section" id="overview">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('software.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('software.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="sw-help-features-grid">
                        <div class="sw-help-feature-card">
                            <div class="sw-help-feature-icon indigo">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('software.help.card_inventory_h')); ?></h4>
                            <p><?php echo htmlspecialchars(t('software.help.card_inventory_p')); ?></p>
                        </div>
                        <div class="sw-help-feature-card">
                            <div class="sw-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('software.help.card_dashboard_h')); ?></h4>
                            <p><?php echo htmlspecialchars(t('software.help.card_dashboard_p')); ?></p>
                        </div>
                        <div class="sw-help-feature-card">
                            <div class="sw-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('software.help.card_licences_h')); ?></h4>
                            <p><?php echo htmlspecialchars(t('software.help.card_licences_p')); ?></p>
                        </div>
                        <div class="sw-help-feature-card">
                            <div class="sw-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('software.help.card_search_h')); ?></h4>
                            <p><?php echo htmlspecialchars(t('software.help.card_search_p')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Software Inventory -->
                <div class="sw-help-section" id="inventory">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('software.help.inventory_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('software.help.inventory_intro')); ?></p>

                    <div class="sw-help-steps">
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s1_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s1_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s2_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s2_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s3_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s3_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s4_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s4_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">5</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s5_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s5_t')); ?>
                            </div>
                        </div>
                    </div>

                    <p class="sw-help-tip"><?php echo htmlspecialchars(t('software.help.inventory_tip')); ?></p>
                </div>

                <!-- Section 3: Dashboard -->
                <div class="sw-help-section" id="dashboard">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('software.help.dashboard_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('software.help.dashboard_intro')); ?></p>

                    <div class="sw-help-steps">
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.dashboard_s1_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.dashboard_s1_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.dashboard_s2_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.dashboard_s2_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.dashboard_s3_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.dashboard_s3_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.dashboard_s4_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.dashboard_s4_t')); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('software.help.dashboard_types_intro')); ?></p>
                    <div class="sw-help-data-grid">
                        <div class="sw-help-data-card">
                            <strong><?php echo htmlspecialchars(t('software.help.dashboard_type1_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.dashboard_type1_p')); ?></span>
                        </div>
                        <div class="sw-help-data-card">
                            <strong><?php echo htmlspecialchars(t('software.help.dashboard_type2_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.dashboard_type2_p')); ?></span>
                        </div>
                        <div class="sw-help-data-card">
                            <strong><?php echo htmlspecialchars(t('software.help.dashboard_type3_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.dashboard_type3_p')); ?></span>
                        </div>
                    </div>

                    <p class="sw-help-tip"><?php echo htmlspecialchars(t('software.help.dashboard_tip')); ?></p>
                </div>

                <!-- Section 4: Licence Management -->
                <div class="sw-help-section" id="licences">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('software.help.licences_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('software.help.licences_intro')); ?></p>

                    <div class="sw-help-steps">
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.licences_s1_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_s1_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.licences_s2_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_s2_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.licences_s3_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_s3_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.licences_s4_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_s4_t')); ?>
                            </div>
                        </div>
                    </div>

                    <div class="sw-help-fields">
                        <div><strong><?php echo htmlspecialchars(t('software.help.licences_field_compliant_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_field_compliant_t')); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('software.help.licences_field_approaching_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_field_approaching_t')); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('software.help.licences_field_over_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_field_over_t')); ?></div>
                    </div>

                    <p class="sw-help-tip"><?php echo htmlspecialchars(t('software.help.licences_tip')); ?></p>
                </div>

                <!-- Section 5: How Data Gets Collected (highlighted) -->
                <div class="sw-help-section sw-help-section-highlight" id="data-collection">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num highlight">5</span>
                        <h3><?php echo htmlspecialchars(t('software.help.collection_heading')); ?></h3>
                    </div>
                    <p class="sw-help-intro"><?php echo t('software.help.collection_intro', ['script' => '<strong>Invoke-AssetInventory.ps1</strong>']); ?></p>

                    <p><?php echo htmlspecialchars(t('software.help.collection_p2')); ?></p>

                    <div class="sw-help-flow">
                        <div class="sw-help-flow-step script"><?php echo htmlspecialchars(t('software.help.flow_script')); ?></div>
                        <div class="sw-help-flow-arrow">&rarr;</div>
                        <div class="sw-help-flow-step api"><?php echo htmlspecialchars(t('software.help.flow_api')); ?></div>
                        <div class="sw-help-flow-arrow">&rarr;</div>
                        <div class="sw-help-flow-step db"><?php echo htmlspecialchars(t('software.help.flow_db')); ?></div>
                        <div class="sw-help-flow-arrow">&rarr;</div>
                        <div class="sw-help-flow-step ui"><?php echo htmlspecialchars(t('software.help.flow_ui')); ?></div>
                    </div>

                    <p><?php echo htmlspecialchars(t('software.help.collection_fields_intro')); ?></p>
                    <div class="sw-help-data-grid">
                        <div class="sw-help-data-card">
                            <strong><?php echo htmlspecialchars(t('software.help.collection_field1_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.collection_field1_p')); ?></span>
                        </div>
                        <div class="sw-help-data-card">
                            <strong><?php echo htmlspecialchars(t('software.help.collection_field2_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.collection_field2_p')); ?></span>
                        </div>
                        <div class="sw-help-data-card">
                            <strong><?php echo htmlspecialchars(t('software.help.collection_field3_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.collection_field3_p')); ?></span>
                        </div>
                    </div>

                    <p class="sw-help-tip"><?php echo htmlspecialchars(t('software.help.collection_tip_before')); ?><a href="../asset-management/help.php" style="color: var(--sw-accent-hover, #283593); font-weight: 600;"><?php echo htmlspecialchars(t('software.help.collection_tip_link')); ?></a><?php echo htmlspecialchars(t('software.help.collection_tip_after')); ?></p>
                </div>

                <!-- Section 6: Settings -->
                <div class="sw-help-section" id="settings">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('software.help.settings_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('software.help.settings_intro')); ?></p>

                    <div class="sw-help-steps">
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.settings_s1_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.settings_s1_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.settings_s2_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.settings_s2_t')); ?>
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.settings_s3_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.settings_s3_t')); ?>
                            </div>
                        </div>
                    </div>

                    <p class="sw-help-tip"><?php echo htmlspecialchars(t('software.help.settings_tip')); ?></p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="sw-help-section" id="tips">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('software.help.tips_heading')); ?></h3>
                    </div>
                    <div class="sw-help-tips-grid">
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128269;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip1_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip1_t')); ?></div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128200;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip2_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip2_t')); ?></div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128274;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip3_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip3_t')); ?></div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128203;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip4_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip4_t')); ?></div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128187;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip5_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip5_t')); ?></div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#9889;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip6_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip6_t')); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.sw-help-nav-link');
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
