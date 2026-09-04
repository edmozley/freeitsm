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
    header('Location: ../auth/login.php');
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
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('software.help.page_title')); ?></title>
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
            --accent:       var(--sw-accent);
            --accent-hover: var(--sw-accent-hover);
            --accent-soft:  var(--sw-accent-soft);
            --on-accent:    var(--sw-on-accent);
        }
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=132">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('software.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('software.help.nav_overview')); ?>
            </a>
            <a href="#inventory" class="help-nav-link" data-section="inventory">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('software.help.nav_inventory')); ?>
            </a>
            <a href="#dashboard" class="help-nav-link" data-section="dashboard">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('software.help.nav_dashboard')); ?>
            </a>
            <a href="#licences" class="help-nav-link" data-section="licences">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('software.help.nav_licences')); ?>
            </a>
            <a href="#data-collection" class="help-nav-link" data-section="data-collection">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('software.help.nav_collection')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('software.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('software.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('software.help.hero_heading')); ?></h2>
                <p><?php echo htmlspecialchars(t('software.help.hero_sub')); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('software.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('software.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('software.help.card_inventory_h')); ?></h4>
                            <p><?php echo htmlspecialchars(t('software.help.card_inventory_p')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('software.help.card_dashboard_h')); ?></h4>
                            <p><?php echo htmlspecialchars(t('software.help.card_dashboard_p')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('software.help.card_licences_h')); ?></h4>
                            <p><?php echo htmlspecialchars(t('software.help.card_licences_p')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('software.help.card_search_h')); ?></h4>
                            <p><?php echo htmlspecialchars(t('software.help.card_search_p')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Software Inventory -->
                <div class="help-section" id="inventory">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('software.help.inventory_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('software.help.inventory_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s1_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s1_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s2_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s2_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s3_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s3_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s4_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s4_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.inventory_s5_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.inventory_s5_t')); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('software.help.inventory_tip')); ?></p>
                </div>

                <!-- Section 3: Dashboard -->
                <div class="help-section" id="dashboard">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('software.help.dashboard_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('software.help.dashboard_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.dashboard_s1_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.dashboard_s1_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.dashboard_s2_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.dashboard_s2_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.dashboard_s3_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.dashboard_s3_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.dashboard_s4_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.dashboard_s4_t')); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('software.help.dashboard_types_intro')); ?></p>
                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('software.help.dashboard_type1_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.dashboard_type1_p')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('software.help.dashboard_type2_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.dashboard_type2_p')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('software.help.dashboard_type3_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.dashboard_type3_p')); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('software.help.dashboard_tip')); ?></p>
                </div>

                <!-- Section 4: Licence Management -->
                <div class="help-section" id="licences">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('software.help.licences_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('software.help.licences_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.licences_s1_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_s1_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.licences_s2_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_s2_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.licences_s3_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_s3_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.licences_s4_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_s4_t')); ?>
                            </div>
                        </div>
                    </div>

                    <div class="help-list">
                        <div><strong><?php echo htmlspecialchars(t('software.help.licences_field_compliant_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_field_compliant_t')); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('software.help.licences_field_approaching_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_field_approaching_t')); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('software.help.licences_field_over_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.licences_field_over_t')); ?></div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('software.help.licences_tip')); ?></p>
                </div>

                <!-- Section 5: How Data Gets Collected (highlighted) -->
                <div class="help-section" id="data-collection">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('software.help.collection_heading')); ?></h3>
                    </div>
                    <p><?php echo t('software.help.collection_intro', ['script' => '<strong>Invoke-AssetInventory.ps1</strong>']); ?></p>

                    <p><?php echo htmlspecialchars(t('software.help.collection_p2')); ?></p>

                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('software.help.flow_script')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('software.help.flow_api')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('software.help.flow_db')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('software.help.flow_ui')); ?></div>
                    </div>

                    <p><?php echo htmlspecialchars(t('software.help.collection_fields_intro')); ?></p>
                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('software.help.collection_field1_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.collection_field1_p')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('software.help.collection_field2_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.collection_field2_p')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('software.help.collection_field3_h')); ?></strong>
                            <span><?php echo htmlspecialchars(t('software.help.collection_field3_p')); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('software.help.collection_tip_before')); ?><a href="../asset-management/help.php" style="color: var(--sw-accent-hover, #283593); font-weight: 600;"><?php echo htmlspecialchars(t('software.help.collection_tip_link')); ?></a><?php echo htmlspecialchars(t('software.help.collection_tip_after')); ?></p>
                </div>

                <!-- Section 6: Settings -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('software.help.settings_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('software.help.settings_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.settings_s1_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.settings_s1_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.settings_s2_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.settings_s2_t')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('software.help.settings_s3_b')); ?></strong> &mdash; <?php echo htmlspecialchars(t('software.help.settings_s3_t')); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('software.help.settings_tip')); ?></p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('software.help.tips_heading')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128269;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip1_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip1_t')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128200;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip2_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip2_t')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128274;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip3_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip3_t')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128203;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip4_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip4_t')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128187;</div>
                            <div><strong><?php echo htmlspecialchars(t('software.help.tip5_b')); ?></strong><br><?php echo htmlspecialchars(t('software.help.tip5_t')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9889;</div>
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
    <script src="../assets/js/mobile.js?v=55"></script>
</body>
</html>
