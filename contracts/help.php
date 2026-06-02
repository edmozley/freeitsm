<?php
/**
 * Contracts Module Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once __DIR__ . '/../includes/i18n.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .ct-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .ct-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .ct-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .ct-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        .ct-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .ct-help-nav-link.active {
            background: #fffbeb;
            color: #92400e;
            font-weight: 600;
        }

        .ct-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #eee;
            color: #888;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }

        .ct-help-nav-link.active .ct-help-nav-num {
            background: #f59e0b;
            color: white;
        }

        /* Main content */
        .ct-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .ct-help-hero {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .ct-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .ct-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .ct-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .ct-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .ct-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .ct-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .ct-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .ct-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .ct-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .ct-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #fffbeb;
            color: #92400e;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .ct-help-section-num.highlight {
            background: #f59e0b;
            color: white;
        }

        /* Feature cards grid */
        .ct-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .ct-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .ct-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .ct-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .ct-help-feature-icon.amber { background: #fffbeb; color: #f59e0b; }
        .ct-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .ct-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .ct-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }

        .ct-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .ct-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .ct-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .ct-help-step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: #fafafa;
            font-size: 14px;
            color: #444;
            line-height: 1.5;
        }

        .ct-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #f59e0b;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .ct-help-section-highlight {
            background: #fffbeb;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #fcd34d;
        }

        .ct-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .ct-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .ct-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Data cards grid */
        .ct-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .ct-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #f59e0b;
        }

        .ct-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .ct-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Tab cards for contract terms */
        .ct-help-tabs-demo {
            display: flex;
            gap: 0;
            margin: 14px 0 0;
            border-bottom: 2px solid #eee;
        }

        .ct-help-tab-demo {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 500;
            color: #888;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }

        .ct-help-tab-demo.active {
            color: #f59e0b;
            border-bottom-color: #f59e0b;
        }

        .ct-help-tab-body {
            padding: 16px;
            background: #fafafa;
            border-radius: 0 0 8px 8px;
            border: 1px solid #eee;
            border-top: none;
            font-size: 13px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 14px;
        }

        /* Tip callout */
        .ct-help-tip {
            font-size: 13px !important;
            color: #92400e !important;
            background: #fffbeb;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #f59e0b;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .ct-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .ct-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .ct-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .ct-help-tip-card strong {
            color: #333;
        }

        /* Settings config cards */
        .ct-help-config-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 14px 0;
        }

        .ct-help-config-card {
            padding: 16px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .ct-help-config-card h4 {
            margin: 0 0 6px;
            font-size: 14px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ct-help-config-card h4 .config-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .ct-help-config-card h4 .config-dot.amber { background: #f59e0b; }
        .ct-help-config-card h4 .config-dot.blue { background: #1565c0; }
        .ct-help-config-card h4 .config-dot.green { background: #2e7d32; }
        .ct-help-config-card h4 .config-dot.purple { background: #7b1fa2; }
        .ct-help-config-card h4 .config-dot.red { background: #c62828; }

        .ct-help-config-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .ct-help-sidebar { display: none; }
            .ct-help-content { padding: 10px 24px 40px; }
            .ct-help-hero { padding: 30px 24px; }
            .ct-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .ct-help-features-grid { grid-template-columns: 1fr; }
            .ct-help-data-grid { grid-template-columns: 1fr; }
            .ct-help-tips-grid { grid-template-columns: 1fr; }
            .ct-help-config-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="ct-help-container">
        <!-- Left pane navigation -->
        <div class="ct-help-sidebar">
            <h3><?php echo htmlspecialchars(t('contracts.help.guide')); ?></h3>
            <a href="#overview" class="ct-help-nav-link active" data-section="overview">
                <span class="ct-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_overview')); ?>
            </a>
            <a href="#managing-contracts" class="ct-help-nav-link" data-section="managing-contracts">
                <span class="ct-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_managing')); ?>
            </a>
            <a href="#contract-terms" class="ct-help-nav-link" data-section="contract-terms">
                <span class="ct-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_terms')); ?>
            </a>
            <a href="#suppliers" class="ct-help-nav-link" data-section="suppliers">
                <span class="ct-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_suppliers')); ?>
            </a>
            <a href="#contacts" class="ct-help-nav-link" data-section="contacts">
                <span class="ct-help-nav-num">5</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_contacts')); ?>
            </a>
            <a href="#settings" class="ct-help-nav-link" data-section="settings">
                <span class="ct-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="ct-help-nav-link" data-section="tips">
                <span class="ct-help-nav-num">7</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="ct-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="ct-help-hero">
                <h2><?php echo htmlspecialchars(t('contracts.help.hero_title')); ?></h2>
                <p><?php echo t('contracts.help.hero_subtitle'); ?></p>
            </div>

            <div class="ct-help-content">

                <!-- Section 1: Overview -->
                <div class="ct-help-section" id="overview">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('contracts.help.nav_overview')); ?></h3>
                            <p><?php echo t('contracts.help.overview_intro'); ?></p>
                        </div>
                    </div>
                    <div class="ct-help-features-grid">
                        <div class="ct-help-feature-card">
                            <div class="ct-help-feature-icon amber">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('contracts.nav.contracts')); ?></h4>
                            <p><?php echo t('contracts.help.feature_contracts'); ?></p>
                        </div>
                        <div class="ct-help-feature-card">
                            <div class="ct-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('contracts.nav.suppliers')); ?></h4>
                            <p><?php echo t('contracts.help.feature_suppliers'); ?></p>
                        </div>
                        <div class="ct-help-feature-card">
                            <div class="ct-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('contracts.nav.contacts')); ?></h4>
                            <p><?php echo t('contracts.help.feature_contacts'); ?></p>
                        </div>
                        <div class="ct-help-feature-card">
                            <div class="ct-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('contracts.nav.settings')); ?></h4>
                            <p><?php echo t('contracts.help.feature_settings'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Managing Contracts -->
                <div class="ct-help-section" id="managing-contracts">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_managing')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.managing_intro'); ?></p>
                    <div class="ct-help-steps">
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">1</div>
                            <div><?php echo t('contracts.help.managing_step1'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">2</div>
                            <div><?php echo t('contracts.help.managing_step2'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">3</div>
                            <div><?php echo t('contracts.help.managing_step3'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">4</div>
                            <div><?php echo t('contracts.help.managing_step4'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">5</div>
                            <div><?php echo t('contracts.help.managing_step5'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">6</div>
                            <div><?php echo t('contracts.help.managing_step6'); ?></div>
                        </div>
                    </div>
                    <p><?php echo t('contracts.help.managing_dashboard'); ?></p>
                    <p class="ct-help-tip"><?php echo t('contracts.help.managing_tip'); ?></p>
                </div>

                <!-- Section 3: Contract Terms (highlighted) -->
                <div class="ct-help-section ct-help-section-highlight" id="contract-terms">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num highlight">3</span>
                        <h3><?php echo t('contracts.help.terms_title'); ?></h3>
                    </div>
                    <p class="ct-help-intro"><?php echo t('contracts.help.terms_intro'); ?></p>

                    <div class="ct-help-tabs-demo">
                        <div class="ct-help-tab-demo active"><?php echo htmlspecialchars(t('contracts.help.terms_demo_sla')); ?></div>
                        <div class="ct-help-tab-demo"><?php echo htmlspecialchars(t('contracts.help.terms_demo_kpis')); ?></div>
                        <div class="ct-help-tab-demo"><?php echo htmlspecialchars(t('contracts.help.terms_demo_special')); ?></div>
                        <div class="ct-help-tab-demo"><?php echo htmlspecialchars(t('contracts.help.terms_demo_obligations')); ?></div>
                    </div>
                    <div class="ct-help-tab-body">
                        <?php echo t('contracts.help.terms_demo_body'); ?>
                    </div>

                    <div class="ct-help-steps">
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">1</div>
                            <div><?php echo t('contracts.help.terms_step1'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">2</div>
                            <div><?php echo t('contracts.help.terms_step2'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">3</div>
                            <div><?php echo t('contracts.help.terms_step3'); ?></div>
                        </div>
                    </div>

                    <p class="ct-help-tip"><?php echo t('contracts.help.terms_tip'); ?></p>
                </div>

                <!-- Section 4: Suppliers -->
                <div class="ct-help-section" id="suppliers">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_suppliers')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.suppliers_intro'); ?></p>

                    <div class="ct-help-data-grid">
                        <div class="ct-help-data-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_legal_name')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_legal_name_desc')); ?></span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_trading_name')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_trading_name_desc')); ?></span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_reg_number')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_reg_number_desc')); ?></span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_address')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_address_desc')); ?></span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_type')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_type_desc')); ?></span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong><?php echo htmlspecialchars(t('contracts.detail.status')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_status_desc')); ?></span>
                        </div>
                    </div>

                    <div class="ct-help-steps">
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">1</div>
                            <div><?php echo t('contracts.help.suppliers_step1'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">2</div>
                            <div><?php echo t('contracts.help.suppliers_step2'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">3</div>
                            <div><?php echo t('contracts.help.suppliers_step3'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">4</div>
                            <div><?php echo t('contracts.help.suppliers_step4'); ?></div>
                        </div>
                    </div>

                    <p class="ct-help-tip"><?php echo t('contracts.help.suppliers_tip'); ?></p>
                </div>

                <!-- Section 5: Contacts -->
                <div class="ct-help-section" id="contacts">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_contacts')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.contacts_intro'); ?></p>

                    <div class="ct-help-fields">
                        <div><?php echo t('contracts.help.contacts_field_name'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_job'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_email'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_mobile'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_supplier'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_status'); ?></div>
                    </div>

                    <div class="ct-help-steps">
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">1</div>
                            <div><?php echo t('contracts.help.contacts_step1'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">2</div>
                            <div><?php echo t('contracts.help.contacts_step2'); ?></div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">3</div>
                            <div><?php echo t('contracts.help.contacts_step3'); ?></div>
                        </div>
                    </div>

                    <p class="ct-help-tip"><?php echo t('contracts.help.contacts_tip'); ?></p>
                </div>

                <!-- Section 6: Settings -->
                <div class="ct-help-section" id="settings">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_settings')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.settings_intro'); ?></p>

                    <div class="ct-help-config-grid">
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot amber"></span> <?php echo htmlspecialchars(t('contracts.help.settings_supplier_types')); ?></h4>
                            <p><?php echo t('contracts.help.settings_supplier_types_desc'); ?></p>
                        </div>
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot green"></span> <?php echo htmlspecialchars(t('contracts.help.settings_supplier_statuses')); ?></h4>
                            <p><?php echo t('contracts.help.settings_supplier_statuses_desc'); ?></p>
                        </div>
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot blue"></span> <?php echo htmlspecialchars(t('contracts.help.settings_contract_statuses')); ?></h4>
                            <p><?php echo t('contracts.help.settings_contract_statuses_desc'); ?></p>
                        </div>
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot purple"></span> <?php echo htmlspecialchars(t('contracts.help.settings_payment_schedules')); ?></h4>
                            <p><?php echo t('contracts.help.settings_payment_schedules_desc'); ?></p>
                        </div>
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot red"></span> <?php echo htmlspecialchars(t('contracts.help.settings_term_tabs')); ?></h4>
                            <p><?php echo t('contracts.help.settings_term_tabs_desc'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('contracts.help.settings_list_desc'); ?></p>

                    <p class="ct-help-tip"><?php echo t('contracts.help.settings_tip'); ?></p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="ct-help-section" id="tips">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_tips')); ?></h3>
                    </div>
                    <div class="ct-help-tips-grid">
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128197;</div>
                            <div><?php echo t('contracts.help.tip_review_dates'); ?></div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128200;</div>
                            <div><?php echo t('contracts.help.tip_track_money'); ?></div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128101;</div>
                            <div><?php echo t('contracts.help.tip_relationships'); ?></div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128196;</div>
                            <div><?php echo t('contracts.help.tip_upload'); ?></div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128295;</div>
                            <div><?php echo t('contracts.help.tip_term_tabs'); ?></div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#9889;</div>
                            <div><?php echo t('contracts.help.tip_statuses'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.ct-help-nav-link');
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