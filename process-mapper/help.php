<?php
/**
 * Process Mapper Module Help Guide - Full page with left pane navigation
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

requireModuleAccess('process-mapper');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'process-mapper'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active('process-mapper')); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode('process-mapper')); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('process-mapper.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--pmap-accent);
            --accent-hover: var(--pmap-accent-hover);
            --accent-soft:  var(--pmap-accent-soft);
            --on-accent:    var(--pmap-on-accent);
        }

        /* Module-specific: the little step-shape glyphs drawn beside each step
           type name (rectangle, diamond, ellipse, document). */
        .pm-help-shape {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            margin-right: 8px;
            vertical-align: middle;
            color: var(--accent);
        }
    </style>
    <!-- Mobile layer: linked AFTER this page's own CSS so its @media rules win on ties. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=130">
</head>
<body data-mobile-module="process-mapper">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('process-mapper.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo t('process-mapper.help.nav_overview'); ?>
            </a>
            <a href="#creating" class="help-nav-link" data-section="creating">
                <span class="help-nav-num">2</span>
                <?php echo t('process-mapper.help.nav_creating'); ?>
            </a>
            <a href="#step-types" class="help-nav-link" data-section="step-types">
                <span class="help-nav-num">3</span>
                <?php echo t('process-mapper.help.nav_step_types'); ?>
            </a>
            <a href="#connectors" class="help-nav-link" data-section="connectors">
                <span class="help-nav-num">4</span>
                <?php echo t('process-mapper.help.nav_connectors'); ?>
            </a>
            <a href="#arranging" class="help-nav-link" data-section="arranging">
                <span class="help-nav-num">5</span>
                <?php echo t('process-mapper.help.nav_arranging'); ?>
            </a>
            <a href="#saving" class="help-nav-link" data-section="saving">
                <span class="help-nav-num">6</span>
                <?php echo t('process-mapper.help.nav_saving'); ?>
            </a>
            <a href="#export" class="help-nav-link" data-section="export">
                <span class="help-nav-num">7</span>
                <?php echo t('process-mapper.help.nav_export'); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">8</span>
                <?php echo t('process-mapper.help.nav_tips'); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('process-mapper.help.hero_title')); ?></h2>
                <p><?php echo t('process-mapper.help.hero_subtitle'); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('process-mapper.help.overview_heading')); ?></h3>
                            <p><?php echo t('process-mapper.help.overview_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo t('process-mapper.help.overview_flow_create'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('process-mapper.help.overview_flow_draw'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('process-mapper.help.overview_flow_connect'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('process-mapper.help.overview_flow_save'); ?></div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1" fill="currentColor"/><circle cx="14" cy="8" r="1" fill="currentColor"/><circle cx="20" cy="8" r="1" fill="currentColor"/><circle cx="8" cy="14" r="1" fill="currentColor"/><circle cx="14" cy="14" r="1" fill="currentColor"/><circle cx="20" cy="14" r="1" fill="currentColor"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('process-mapper.help.overview_card1_title')); ?></h4>
                            <p><?php echo t('process-mapper.help.overview_card1_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="6" height="12" rx="1"/><polygon points="12,4 18,12 12,20 6,12" transform="translate(2 0)"/><ellipse cx="20" cy="12" rx="3" ry="2"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('process-mapper.help.overview_card2_title')); ?></h4>
                            <p><?php echo t('process-mapper.help.overview_card2_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="17" x2="15" y2="5"/><polyline points="10,5 15,5 15,10"/><circle cx="3" cy="17" r="2"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('process-mapper.help.overview_card3_title')); ?></h4>
                            <p><?php echo t('process-mapper.help.overview_card3_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                            </div>
                            <h4><?php echo t('process-mapper.help.overview_card4_title'); ?></h4>
                            <p><?php echo t('process-mapper.help.overview_card4_desc'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Creating a process (highlighted) -->
                <div class="help-section" id="creating">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('process-mapper.help.creating_heading')); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.creating_intro'); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step4'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <?php echo t('process-mapper.help.creating_step5'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('process-mapper.help.creating_tip'); ?></p>
                </div>

                <!-- Section 3: Step types -->
                <div class="help-section" id="step-types">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('process-mapper.help.step_types_heading')); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.step_types_intro'); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><rect x="1" y="3" width="16" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg><?php echo htmlspecialchars(t('process-mapper.help.step_types_process_name')); ?></strong>
                            <span><?php echo t('process-mapper.help.step_types_process_desc'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><polygon points="9,1 17,9 9,17 1,9" fill="none" stroke="currentColor" stroke-width="1.5"/></svg><?php echo htmlspecialchars(t('process-mapper.help.step_types_decision_name')); ?></strong>
                            <span><?php echo t('process-mapper.help.step_types_decision_desc'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><ellipse cx="9" cy="9" rx="8" ry="5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg><?php echo htmlspecialchars(t('process-mapper.help.step_types_terminal_name')); ?></strong>
                            <span><?php echo t('process-mapper.help.step_types_terminal_desc'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><path d="M2 2h14v12c-2.3 1.3-4.7 1.3-7 0s-4.7-1.3-7 0V2z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg><?php echo htmlspecialchars(t('process-mapper.help.step_types_document_name')); ?></strong>
                            <span><?php echo t('process-mapper.help.step_types_document_desc'); ?></span>
                        </div>
                    </div>

                    <h4 style="margin: 22px 0 8px; font-size: 15px; color: var(--text, #333);"><?php echo htmlspecialchars(t('process-mapper.help.step_types_custom_heading')); ?></h4>
                    <p><?php echo t('process-mapper.help.step_types_custom_body'); ?></p>

                    <p class="help-note"><?php echo t('process-mapper.help.step_types_tip'); ?></p>
                </div>

                <!-- Section 4: Drawing connectors + the right-click menu -->
                <div class="help-section" id="connectors">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo t('process-mapper.help.connectors_heading'); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.connectors_intro'); ?></p>

                    <h4 style="margin: 22px 0 8px; font-size: 15px; color: var(--text, #333);"><?php echo htmlspecialchars(t('process-mapper.help.connectors_drawing_heading')); ?></h4>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('process-mapper.help.connectors_draw_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('process-mapper.help.connectors_draw_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('process-mapper.help.connectors_draw_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('process-mapper.help.connectors_draw_step4'); ?>
                            </div>
                        </div>
                    </div>

                    <h4 style="margin: 26px 0 8px; font-size: 15px; color: var(--text, #333);"><?php echo htmlspecialchars(t('process-mapper.help.connectors_menu_heading')); ?></h4>
                    <p><?php echo t('process-mapper.help.connectors_menu_intro'); ?></p>
                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card1_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card1_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card2_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card2_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card3_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card3_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card4_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card4_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card5_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card5_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('process-mapper.help.connectors_menu_card6_title'); ?></strong>
                            <span><?php echo t('process-mapper.help.connectors_menu_card6_body'); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('process-mapper.help.connectors_tip'); ?></p>
                </div>

                <!-- Section 5: Arranging & editing (highlighted) -->
                <div class="help-section" id="arranging">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo t('process-mapper.help.arranging_heading'); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.arranging_intro'); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step4'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <?php echo t('process-mapper.help.arranging_step5'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('process-mapper.help.arranging_tip'); ?></p>
                </div>

                <!-- Section 6: Saving & loading -->
                <div class="help-section" id="saving">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo t('process-mapper.help.saving_heading'); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.saving_intro'); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('process-mapper.help.saving_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('process-mapper.help.saving_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('process-mapper.help.saving_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('process-mapper.help.saving_step4'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('process-mapper.help.saving_tip'); ?></p>
                </div>

                <!-- Section 7: Exporting -->
                <div class="help-section" id="export">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('process-mapper.help.export_heading')); ?></h3>
                    </div>
                    <p><?php echo t('process-mapper.help.export_intro'); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('process-mapper.help.export_png_title')); ?></strong>
                            <span><?php echo t('process-mapper.help.export_png_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('process-mapper.help.export_pdf_title')); ?></strong>
                            <span><?php echo t('process-mapper.help.export_pdf_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('process-mapper.help.export_mermaid_title')); ?></strong>
                            <span><?php echo t('process-mapper.help.export_mermaid_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('process-mapper.help.export_capture_title')); ?></strong>
                            <span><?php echo t('process-mapper.help.export_capture_body'); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('process-mapper.help.export_tip'); ?></p>
                </div>

                <!-- Section 8: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <h3><?php echo htmlspecialchars(t('process-mapper.help.tips_heading')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128200;</div>
                            <div><?php echo t('process-mapper.help.tip1'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#127919;</div>
                            <div><?php echo t('process-mapper.help.tip2'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128229;</div>
                            <div><?php echo t('process-mapper.help.tip3'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9997;</div>
                            <div><?php echo t('process-mapper.help.tip4'); ?></div>
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
    <script src="../assets/js/mobile.js?v=53"></script>
</body>
</html>
