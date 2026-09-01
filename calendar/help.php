<?php
/**
 * Calendar Help Guide - Full page with left pane navigation
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
requireModuleAccess('calendar');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'calendar'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('calendar.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--cal-accent);
            --accent-hover: var(--cal-accent-hover);
            --accent-soft:  var(--cal-accent-soft);
            --on-accent:    var(--cal-on-accent);
        }
    </style>
    <!-- Mobile: help.css already reflows the guide's body at 900px, but the
         page had no app shell — the header's view links spilled off the right
         instead of becoming a drawer. LAYER 16h covers the rest. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=127">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('calendar.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_overview')); ?>
            </a>
            <a href="#views" class="help-nav-link" data-section="views">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_views')); ?>
            </a>
            <a href="#creating-events" class="help-nav-link" data-section="creating-events">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_creating')); ?>
            </a>
            <a href="#categories" class="help-nav-link" data-section="categories">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_categories')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('calendar.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('calendar.help.hero_title')); ?></h2>
                <p><?php echo t('calendar.help.hero_sub'); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('calendar.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('calendar.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('calendar.help.feature_tracking_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('calendar.help.feature_tracking_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('calendar.help.feature_views_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('calendar.help.feature_views_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path>
                                    <line x1="4" y1="22" x2="4" y2="15"></line>
                                </svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('calendar.help.feature_categories_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('calendar.help.feature_categories_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('calendar.help.feature_scheduling_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('calendar.help.feature_scheduling_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Calendar Views -->
                <div class="help-section" id="views">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.views_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('calendar.help.views_intro')); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('calendar.help.views_month_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('calendar.help.views_month_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('calendar.help.views_week_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('calendar.help.views_week_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('calendar.help.views_day_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('calendar.help.views_day_desc')); ?></span>
                        </div>
                    </div>

                    <p><?php echo t('calendar.help.views_nav'); ?></p>

                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('calendar.help.views_flow_today')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('calendar.help.views_flow_nav')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('calendar.help.views_flow_choose')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('calendar.help.views_flow_click')); ?></div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('calendar.help.views_tip')); ?></p>
                </div>

                <!-- Section 3: Creating Events (highlighted) -->
                <div class="help-section" id="creating-events">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.creating_heading')); ?></h3>
                    </div>
                    <p><?php echo t('calendar.help.creating_intro'); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('calendar.help.creating_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('calendar.help.creating_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('calendar.help.creating_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('calendar.help.creating_step4'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <?php echo t('calendar.help.creating_step5'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">6</div>
                            <div>
                                <?php echo t('calendar.help.creating_step6'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('calendar.help.creating_tip'); ?></p>
                </div>

                <!-- Section 4: Event Categories -->
                <div class="help-section" id="categories">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.categories_heading')); ?></h3>
                    </div>
                    <p><?php echo t('calendar.help.categories_intro'); ?></p>

                    <div class="help-list">
                        <div><?php echo t('calendar.help.categories_certificates'); ?></div>
                        <div><?php echo t('calendar.help.categories_contracts'); ?></div>
                        <div><?php echo t('calendar.help.categories_maintenance'); ?></div>
                        <div><?php echo t('calendar.help.categories_meetings'); ?></div>
                        <div><?php echo t('calendar.help.categories_custom'); ?></div>
                    </div>

                    <p><?php echo htmlspecialchars(t('calendar.help.categories_filtering')); ?></p>

                    <p class="help-note"><?php echo htmlspecialchars(t('calendar.help.categories_tip')); ?></p>
                </div>

                <!-- Section 5: Settings (highlighted) -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.settings_heading')); ?></h3>
                    </div>
                    <p><?php echo t('calendar.help.settings_intro'); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('calendar.help.settings_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('calendar.help.settings_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('calendar.help.settings_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('calendar.help.settings_step4'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('calendar.help.settings_tip'); ?></p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('calendar.help.tips_heading')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128197;</div>
                            <div><strong><?php echo htmlspecialchars(t('calendar.help.tips_maintenance_title')); ?></strong><br><?php echo htmlspecialchars(t('calendar.help.tips_maintenance_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128274;</div>
                            <div><strong><?php echo htmlspecialchars(t('calendar.help.tips_certificates_title')); ?></strong><br><?php echo htmlspecialchars(t('calendar.help.tips_certificates_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128203;</div>
                            <div><strong><?php echo htmlspecialchars(t('calendar.help.tips_contracts_title')); ?></strong><br><?php echo htmlspecialchars(t('calendar.help.tips_contracts_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128269;</div>
                            <div><strong><?php echo htmlspecialchars(t('calendar.help.tips_filters_title')); ?></strong><br><?php echo htmlspecialchars(t('calendar.help.tips_filters_desc')); ?></div>
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
