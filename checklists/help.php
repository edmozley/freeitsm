<?php
/**
 * Checklists & SOPs Help Guide - full page with left pane navigation.
 *
 * ⚠️ The module shipped in 2.0.0 with NO help page and no Help button, the only
 * one of 21 modules without either. Same house style as every other module's
 * guide (help.css, scroll-spy, numbered sections) - see the wiki page
 * "Help page house style".
 *
 * Accent: the module has no --chk-* tokens in theme.css; its header hardcodes
 * the teal gradient. Set here from the same two colours rather than inventing
 * tokens for one page.
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
requireModuleAccess('checklists');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'checklists'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars(t('checklists.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=78">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        body {
            --accent:       #0d9488;
            --accent-hover: #0f766e;
            --accent-soft:  #ccfbf1;
            --on-accent:    #ffffff;
        }
        [data-theme-mode="dark"] body { --accent-soft: #123b36; }
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=193">
</head>
<body data-mobile-page="checklists-help">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('checklists.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('checklists.help.nav_overview')); ?>
            </a>
            <a href="#building" class="help-nav-link" data-section="building">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('checklists.help.nav_building')); ?>
            </a>
            <a href="#steps" class="help-nav-link" data-section="steps">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('checklists.help.nav_steps')); ?>
            </a>
            <a href="#using" class="help-nav-link" data-section="using">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('checklists.help.nav_using')); ?>
            </a>
            <a href="#closing" class="help-nav-link" data-section="closing">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('checklists.help.nav_closing')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('checklists.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('checklists.help.nav_tips')); ?>
            </a>
        </div>

        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('checklists.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('checklists.help.hero_sub')); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('checklists.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('checklists.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('checklists.help.card_templates_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('checklists.help.card_templates_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('checklists.help.card_attach_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('checklists.help.card_attach_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('checklists.help.card_mandatory_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('checklists.help.card_mandatory_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('checklists.help.card_suggest_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('checklists.help.card_suggest_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Writing a procedure -->
                <div class="help-section" id="building">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('checklists.help.building_heading')); ?></h3>
                            <p><?php echo t('checklists.help.building_intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><?php echo t('checklists.help.building_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><?php echo t('checklists.help.building_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div><?php echo t('checklists.help.building_step3'); ?> <?php echo t('checklists.help.building_step3_tasks'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div><?php echo t('checklists.help.building_step4'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div><?php echo t('checklists.help.building_step5'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">6</div>
                            <div><?php echo t('checklists.help.building_step6'); ?></div>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('checklists.help.building_note'); ?></p>
                </div>

                <!-- Section 3: Steps -->
                <div class="help-section" id="steps">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('checklists.help.steps_heading')); ?></h3>
                            <p><?php echo t('checklists.help.steps_intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('checklists.help.steps_order_title')); ?></h4>
                            <p><?php echo t('checklists.help.steps_order_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('checklists.help.steps_role_title')); ?></h4>
                            <p><?php echo t('checklists.help.steps_role_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('checklists.help.steps_mand_title')); ?></h4>
                            <p><?php echo t('checklists.help.steps_mand_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('checklists.help.steps_input_title')); ?></h4>
                            <p><?php echo t('checklists.help.steps_input_desc'); ?></p>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('checklists.help.steps_delete'); ?></p>
                </div>

                <!-- Section 4: Using one on a ticket -->
                <div class="help-section" id="using">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('checklists.help.using_heading')); ?></h3>
                            <p><?php echo t('checklists.help.using_intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><?php echo t('checklists.help.using_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><?php echo t('checklists.help.using_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div><?php echo t('checklists.help.using_step3'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div><?php echo t('checklists.help.using_step4'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div><?php echo t('checklists.help.using_step5'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Section 5: Mandatory steps and closing -->
                <div class="help-section" id="closing">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('checklists.help.closing_heading')); ?></h3>
                            <p><?php echo t('checklists.help.closing_intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('checklists.help.closing_warn_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('checklists.help.closing_warn_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('checklists.help.closing_block_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('checklists.help.closing_block_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('checklists.help.closing_empty_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('checklists.help.closing_empty_desc')); ?></p>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('checklists.help.closing_enforced'); ?></p>
                </div>

                <!-- Section 6: Settings -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('checklists.help.settings_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('checklists.help.settings_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <h4><?php echo t('checklists.help.settings_mod_title'); ?></h4>
                            <p><?php echo t('checklists.help.settings_mod_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('checklists.help.settings_tick_title'); ?></h4>
                            <p><?php echo t('checklists.help.settings_tick_desc'); ?></p>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('checklists.help.settings_demo'); ?></p>
                </div>

                <!-- Section 7: Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('checklists.help.tips_heading')); ?></h3>
                        </div>
                    </div>
                    <div class="help-tips">
                        <div class="help-tip">
                            <div><strong><?php echo htmlspecialchars(t('checklists.help.tip_one_action_title')); ?></strong><br><?php echo htmlspecialchars(t('checklists.help.tip_one_action_body')); ?></div>
                        </div>
                        <div class="help-tip">
                            <div><strong><?php echo htmlspecialchars(t('checklists.help.tip_keywords_title')); ?></strong><br><?php echo htmlspecialchars(t('checklists.help.tip_keywords_body')); ?></div>
                        </div>
                        <div class="help-tip">
                            <div><strong><?php echo htmlspecialchars(t('checklists.help.tip_mandatory_title')); ?></strong><br><?php echo htmlspecialchars(t('checklists.help.tip_mandatory_body')); ?></div>
                        </div>
                        <div class="help-tip">
                            <div><strong><?php echo htmlspecialchars(t('checklists.help.tip_edit_title')); ?></strong><br><?php echo htmlspecialchars(t('checklists.help.tip_edit_body')); ?></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy, lifted from the other module guides so all 22 behave
        // identically: highlight the active section in the sidebar as the
        // help pane scrolls (the pane scrolls, not the page).
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
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

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
    <script src="../assets/js/mobile.js?v=78"></script>
</body>
</html>
