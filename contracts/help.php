<?php
/**
 * Contracts Module Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('contracts');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.help.page_title')); ?></title>
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
            --accent:       var(--con-accent);
            --accent-hover: var(--con-accent-hover);
            --accent-soft:  var(--con-accent-soft);
            --on-accent:    var(--con-on-accent);
        }

        /* Module-specific: a mock-up of the contract terms tab strip. Nothing
           else in the app draws one, so it stays here rather than in help.css. */
        .ct-help-tabs-demo {
            display: flex;
            margin-bottom: 0;
            border-bottom: 2px solid var(--border-soft, #eee);
        }
        .ct-help-tab-demo {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-dim, #888);
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        .ct-help-tab-demo.active { color: var(--accent); border-bottom-color: var(--accent); }
        .ct-help-tab-body {
            padding: 16px;
            margin-bottom: 14px;
            background: var(--surface-2, #fafafa);
            border: 1px solid var(--border-soft, #eee);
            border-top: none;
            border-radius: 0 0 8px 8px;
            font-size: 13px;
            color: var(--text-muted, #666);
            line-height: 1.6;
        }
    </style>
    <!-- Mobile layer: linked AFTER this page's own <style> so its @media rules win on ties. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=128">
</head>
<body data-mobile-module="contracts">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('contracts.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_overview')); ?>
            </a>
            <a href="#managing-contracts" class="help-nav-link" data-section="managing-contracts">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_managing')); ?>
            </a>
            <a href="#contract-terms" class="help-nav-link" data-section="contract-terms">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_terms')); ?>
            </a>
            <a href="#equipment" class="help-nav-link" data-section="equipment">
                <span class="help-nav-num">4</span> <?php echo htmlspecialchars(t('contracts.help.nav_equipment')); ?>
            </a>
            <a href="#suppliers" class="help-nav-link" data-section="suppliers">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_suppliers')); ?>
            </a>
            <a href="#contacts" class="help-nav-link" data-section="contacts">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_contacts')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">8</span>
                <?php echo htmlspecialchars(t('contracts.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('contracts.help.hero_title')); ?></h2>
                <p><?php echo t('contracts.help.hero_subtitle'); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('contracts.help.nav_overview')); ?></h3>
                            <p><?php echo t('contracts.help.overview_intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('contracts.nav.contracts')); ?></h4>
                            <p><?php echo t('contracts.help.feature_contracts'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('contracts.nav.suppliers')); ?></h4>
                            <p><?php echo t('contracts.help.feature_suppliers'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('contracts.nav.contacts')); ?></h4>
                            <p><?php echo t('contracts.help.feature_contacts'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('contracts.nav.settings')); ?></h4>
                            <p><?php echo t('contracts.help.feature_settings'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Managing Contracts -->
                <div class="help-section" id="managing-contracts">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_managing')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.managing_intro'); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><?php echo t('contracts.help.managing_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><?php echo t('contracts.help.managing_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div><?php echo t('contracts.help.managing_step3'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div><?php echo t('contracts.help.managing_step4'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div><?php echo t('contracts.help.managing_step5'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">6</div>
                            <div><?php echo t('contracts.help.managing_step6'); ?></div>
                        </div>
                    </div>
                    <p><?php echo t('contracts.help.managing_dashboard'); ?></p>
                    <p class="help-note"><?php echo t('contracts.help.managing_tip'); ?></p>
                </div>

                <!-- Section 3: Contract Terms (highlighted) -->
                <div class="help-section" id="contract-terms">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo t('contracts.help.terms_title'); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.terms_intro'); ?></p>

                    <div class="ct-help-tabs-demo">
                        <div class="ct-help-tab-demo active"><?php echo htmlspecialchars(t('contracts.help.terms_demo_sla')); ?></div>
                        <div class="ct-help-tab-demo"><?php echo htmlspecialchars(t('contracts.help.terms_demo_kpis')); ?></div>
                        <div class="ct-help-tab-demo"><?php echo htmlspecialchars(t('contracts.help.terms_demo_special')); ?></div>
                        <div class="ct-help-tab-demo"><?php echo htmlspecialchars(t('contracts.help.terms_demo_obligations')); ?></div>
                    </div>
                    <div class="ct-help-tab-body">
                        <?php echo t('contracts.help.terms_demo_body'); ?>
                    </div>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><?php echo t('contracts.help.terms_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><?php echo t('contracts.help.terms_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div><?php echo t('contracts.help.terms_step3'); ?></div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('contracts.help.terms_tip'); ?></p>
                </div>

                <!-- Section 4: Suppliers -->
                <div class="help-section" id="equipment">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_equipment')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.equipment_intro'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.help.equipment_adding_heading')); ?></h4>
                    <p><?php echo t('contracts.help.equipment_adding_body'); ?></p>
                    <p><?php echo t('contracts.help.equipment_both_ways'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.help.equipment_reference_heading')); ?></h4>
                    <p><?php echo t('contracts.help.equipment_reference_body'); ?></p>

                    <p><?php echo t('contracts.help.equipment_preview_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.help.equipment_report_heading')); ?></h4>
                    <p><?php echo t('contracts.help.equipment_report_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.help.equipment_asset_heading')); ?></h4>
                    <p><?php echo t('contracts.help.equipment_asset_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.help.equipment_removing_heading')); ?></h4>
                    <p><?php echo t('contracts.help.equipment_removing_body'); ?></p>

                    <p class="help-note"><?php echo t('contracts.help.equipment_renewals_note'); ?></p>
                    <p class="help-note"><?php echo t('contracts.help.equipment_tenancy_note'); ?></p>
                </div>

<div class="help-section" id="suppliers">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_suppliers')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.suppliers_intro'); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_legal_name')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_legal_name_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_trading_name')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_trading_name_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_reg_number')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_reg_number_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_address')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_address_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('contracts.help.suppliers_type')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_type_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('contracts.detail.status')); ?></strong>
                            <span><?php echo htmlspecialchars(t('contracts.help.suppliers_status_desc')); ?></span>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><?php echo t('contracts.help.suppliers_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><?php echo t('contracts.help.suppliers_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div><?php echo t('contracts.help.suppliers_step3'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div><?php echo t('contracts.help.suppliers_step4'); ?></div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('contracts.help.suppliers_tip'); ?></p>
                </div>

                <!-- Section 5: Contacts -->
                <div class="help-section" id="contacts">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_contacts')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.contacts_intro'); ?></p>

                    <div class="help-list">
                        <div><?php echo t('contracts.help.contacts_field_name'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_job'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_email'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_mobile'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_supplier'); ?></div>
                        <div><?php echo t('contracts.help.contacts_field_status'); ?></div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><?php echo t('contracts.help.contacts_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><?php echo t('contracts.help.contacts_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div><?php echo t('contracts.help.contacts_step3'); ?></div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('contracts.help.contacts_tip'); ?></p>
                </div>

                <!-- Section 6: Settings -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_settings')); ?></h3>
                    </div>
                    <p><?php echo t('contracts.help.settings_intro'); ?></p>

                    <div class="help-cards">
                        <div class="help-card">
                            <h4><span class="help-dot"></span> <?php echo htmlspecialchars(t('contracts.help.settings_supplier_types')); ?></h4>
                            <p><?php echo t('contracts.help.settings_supplier_types_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><span class="help-dot"></span> <?php echo htmlspecialchars(t('contracts.help.settings_supplier_statuses')); ?></h4>
                            <p><?php echo t('contracts.help.settings_supplier_statuses_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><span class="help-dot"></span> <?php echo htmlspecialchars(t('contracts.help.settings_contract_statuses')); ?></h4>
                            <p><?php echo t('contracts.help.settings_contract_statuses_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><span class="help-dot"></span> <?php echo htmlspecialchars(t('contracts.help.settings_payment_schedules')); ?></h4>
                            <p><?php echo t('contracts.help.settings_payment_schedules_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><span class="help-dot"></span> <?php echo htmlspecialchars(t('contracts.help.settings_term_tabs')); ?></h4>
                            <p><?php echo t('contracts.help.settings_term_tabs_desc'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('contracts.help.settings_list_desc'); ?></p>

                    <p class="help-note"><?php echo t('contracts.help.settings_tip'); ?></p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <h3><?php echo htmlspecialchars(t('contracts.help.nav_tips')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128197;</div>
                            <div><?php echo t('contracts.help.tip_review_dates'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128200;</div>
                            <div><?php echo t('contracts.help.tip_track_money'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128101;</div>
                            <div><?php echo t('contracts.help.tip_relationships'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128196;</div>
                            <div><?php echo t('contracts.help.tip_upload'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128295;</div>
                            <div><?php echo t('contracts.help.tip_term_tabs'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9889;</div>
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
    <script src="../assets/js/mobile.js?v=51"></script>
</body>
