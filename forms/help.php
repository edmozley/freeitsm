<?php
/**
 * Forms Module Help Guide - Full page with left pane navigation
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
requireModuleAccess('forms');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--forms-accent);
            --accent-hover: var(--forms-accent-hover);
            --accent-soft:  var(--forms-accent-soft);
            --on-accent:    var(--forms-on-accent);
        }
    </style>
    <!-- Mobile layer. Linked AFTER this page's inline <style> on purpose: the
         mobile rules must win on equal specificity, and a link placed above it
         would silently lose to the desktop block below (the load-order trap). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=127">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('forms.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('forms.help.nav_overview')); ?>
            </a>
            <a href="#building-forms" class="help-nav-link" data-section="building-forms">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('forms.help.nav_building')); ?>
            </a>
            <a href="#filling-in" class="help-nav-link" data-section="filling-in">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('forms.help.nav_filling')); ?>
            </a>
            <a href="#submissions" class="help-nav-link" data-section="submissions">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('forms.help.nav_submissions')); ?>
            </a>
            <a href="#export" class="help-nav-link" data-section="export">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('forms.help.nav_export')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('forms.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('forms.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('forms.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('forms.help.hero_sub')); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('forms.help.overview_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('forms.help.overview_body')); ?></p>
                        </div>
                    </div>

                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('forms.help.flow_build')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('forms.help.flow_fill')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('forms.help.flow_submit')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('forms.help.flow_review')); ?></div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('forms.help.card_builder_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('forms.help.card_builder_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('forms.help.card_fill_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('forms.help.card_fill_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('forms.help.card_subs_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('forms.help.card_subs_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('forms.help.card_export_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('forms.help.card_export_body')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Building Forms (highlighted) -->
                <div class="help-section" id="building-forms">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.building_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('forms.help.building_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('forms.help.building_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('forms.help.building_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('forms.help.building_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('forms.help.building_step4'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <?php echo t('forms.help.building_step5'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">6</div>
                            <div>
                                <?php echo t('forms.help.building_step6'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('forms.help.building_tip')); ?></p>

                    <!-- Lookup fields. Given their own block rather than another
                         bullet in the type list, because the portal tickbox is a
                         decision about who can see your records — not a formatting
                         choice, and not one to bury. -->
                    <p><strong><?php echo htmlspecialchars(t('forms.help.lookup_title')); ?></strong>
                       &mdash; <?php echo htmlspecialchars(t('forms.help.lookup_body')); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.lookup_asset_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.lookup_asset_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.lookup_cmdb_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.lookup_cmdb_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.lookup_user_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.lookup_user_body')); ?></span>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('forms.help.lookup_stored')); ?></p>

                    <div class="help-card row">
                        <div class="help-card-icon">&#128274;</div>
                        <div><strong><?php echo htmlspecialchars(t('forms.help.lookup_portal_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.lookup_portal')); ?></div>
                    </div>
                </div>

                <!-- Section 3: Filling in Forms -->
                <div class="help-section" id="filling-in">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.filling_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('forms.help.filling_body')); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_logo_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_logo_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_text_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_text_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_textarea_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_textarea_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_checkbox_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_checkbox_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_dropdown_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_dropdown_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.filling_required_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.filling_required_body')); ?></span>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('forms.help.filling_validate')); ?></p>

                    <p class="help-note"><?php echo htmlspecialchars(t('forms.help.filling_tip')); ?></p>
                </div>

                <!-- Section 4: Submissions -->
                <div class="help-section" id="submissions">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.subs_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('forms.help.subs_body')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('forms.help.subs_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('forms.help.subs_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('forms.help.subs_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('forms.help.subs_tip')); ?></p>
                </div>

                <!-- Section 5: Export (highlighted) -->
                <div class="help-section" id="export">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.export_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('forms.help.export_intro')); ?></p>

                    <div class="help-list">
                        <div><?php echo t('forms.help.export_f1'); ?></div>
                        <div><?php echo t('forms.help.export_f2'); ?></div>
                        <div><?php echo t('forms.help.export_f3'); ?></div>
                        <div><?php echo t('forms.help.export_f4'); ?></div>
                    </div>

                    <p class="help-note"><?php echo t('forms.help.export_tip'); ?></p>
                </div>

                <!-- Section 6: Settings -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.settings_title')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('forms.help.settings_body')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('forms.help.settings_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('forms.help.settings_step2'); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('forms.help.settings_options')); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.settings_left_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.settings_left_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.settings_center_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.settings_center_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('forms.help.settings_right_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('forms.help.settings_right_body')); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('forms.help.settings_tip')); ?></p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('forms.help.tips_title')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128221;</div>
                            <div><strong><?php echo htmlspecialchars(t('forms.help.tip1_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.tip1_body')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9989;</div>
                            <div><strong><?php echo htmlspecialchars(t('forms.help.tip2_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.tip2_body')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128203;</div>
                            <div><strong><?php echo htmlspecialchars(t('forms.help.tip3_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.tip3_body')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128202;</div>
                            <div><strong><?php echo htmlspecialchars(t('forms.help.tip4_title')); ?></strong><br><?php echo htmlspecialchars(t('forms.help.tip4_body')); ?></div>
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
    <!-- Mobile layer. Adds the views hamburger and the module drawer on a phone.
         Loaded last so it can wrap the page's own globals rather than edit them. -->
    <script src="../assets/js/mobile.js?v=50"></script>
</body>
</html>
