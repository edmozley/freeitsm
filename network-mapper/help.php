<?php
/**
 * Network Mapper Module Help Guide — full page with left pane navigation.
 *
 * Mirrors the process-mapper/help.php structure (sidebar + hero + numbered
 * sections + scroll-spy). Cyan branding to match the module palette.
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

requireModuleAccess('network-mapper');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'network-mapper'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('network-mapper.help.browser_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--nm-accent);
            --accent-hover: var(--nm-accent-hover);
            --accent-soft:  var(--nm-accent-soft);
            --on-accent:    var(--nm-on-accent);
        }
    </style>
    <!-- Mobile layer: after this page's own <style> (Techniques §9).
         This is also what makes the guide READABLE on a phone at all —
         help.css's own @media block hands the scroll to the document, and
         inbox.css clips <body>, so nothing scrolled. LAYER 16h gives
         `.help-container` the scroller role back (§28). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=133">
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="network-mapper" data-mobile-page="nm-help">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Sidebar nav -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('network-mapper.help.sidebar_title')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_overview')); ?>
            </a>
            <a href="#creating" class="help-nav-link" data-section="creating">
                <span class="help-nav-num">2</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_creating')); ?>
            </a>
            <a href="#placing" class="help-nav-link" data-section="placing">
                <span class="help-nav-num">3</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_placing')); ?>
            </a>
            <a href="#connectors" class="help-nav-link" data-section="connectors">
                <span class="help-nav-num">4</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_connectors')); ?>
            </a>
            <a href="#related" class="help-nav-link" data-section="related">
                <span class="help-nav-num">5</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_related')); ?>
            </a>
            <a href="#planned" class="help-nav-link" data-section="planned">
                <span class="help-nav-num">6</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_planned')); ?>
            </a>
            <a href="#paper" class="help-nav-link" data-section="paper">
                <span class="help-nav-num">7</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_paper')); ?>
            </a>
            <a href="#branding" class="help-nav-link" data-section="branding">
                <span class="help-nav-num">8</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_branding')); ?>
            </a>
            <a href="#versioning" class="help-nav-link" data-section="versioning">
                <span class="help-nav-num">9</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_versioning')); ?>
            </a>
            <a href="#saving" class="help-nav-link" data-section="saving">
                <span class="help-nav-num">10</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_saving')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">11</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content -->
        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('network-mapper.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('network-mapper.help.hero_subtitle')); ?></p>
            </div>

            <div class="help-content">

                <!-- 1. Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.overview_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.overview_body'); ?></p>
                        </div>
                    </div>

                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('network-mapper.help.flow_create')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('network-mapper.help.flow_drag')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('network-mapper.help.flow_connect')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('network-mapper.help.flow_save')); ?></div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="6" height="6"/><rect x="14" y="14" width="6" height="6"/><line x1="10" y1="7" x2="14" y2="14"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.feat_bound_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.feat_bound_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="12" r="3"/><circle cx="18" cy="12" r="3"/><line x1="9" y1="12" x2="15" y2="12"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.feat_prov_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.feat_prov_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7L9 18l-5-5"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.feat_autosave_title')); ?></h4>
                            <p><?php echo t('network-mapper.help.feat_autosave_body', ['ctrl' => '<span class="help-kbd">Ctrl</span>', 's' => '<span class="help-kbd">S</span>']); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.feat_history_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.feat_history_body')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 2. Creating -->
                <div class="help-section" id="creating">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.creating_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.creating_body'); ?></p>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('network-mapper.help.creating_tip'); ?></p>
                </div>

                <!-- 3. Placing nodes -->
                <div class="help-section" id="placing">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.placing_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.placing_body')); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step"><span class="help-step-num">1</span><div><?php echo htmlspecialchars(t('network-mapper.help.placing_step1')); ?></div></div>
                        <div class="help-step"><span class="help-step-num">2</span><div><?php echo htmlspecialchars(t('network-mapper.help.placing_step2')); ?></div></div>
                        <div class="help-step"><span class="help-step-num">3</span><div><?php echo htmlspecialchars(t('network-mapper.help.placing_step3')); ?></div></div>
                        <div class="help-step"><span class="help-step-num">4</span><div><?php echo t('network-mapper.help.placing_step4', ['del' => '<span class="help-kbd">Delete</span>']); ?></div></div>
                    </div>

                    <p class="help-note"><?php echo t('network-mapper.help.placing_tip1'); ?></p>
                    <p class="help-note"><?php echo t('network-mapper.help.placing_tip2'); ?></p>
                </div>

                <!-- 4. Connectors -->
                <div class="help-section" id="connectors">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.connectors_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.connectors_body')); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step"><span class="help-step-num">1</span><div><?php echo t('network-mapper.help.connectors_step1'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">2</span><div><?php echo t('network-mapper.help.connectors_step2'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">3</span><div><?php echo t('network-mapper.help.connectors_step3'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">4</span><div><?php echo t('network-mapper.help.connectors_step4', ['del' => '<span class="help-kbd">Delete</span>']); ?></div></div>
                    </div>

                    <p class="help-note"><?php echo t('network-mapper.help.connectors_tip'); ?></p>
                </div>

                <!-- 5. Add related objects -->
                <div class="help-section" id="related">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.related_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.related_body'); ?></p>
                        </div>
                    </div>

                    <div class="help-cards" style="margin-top: 14px;">
                        <div class="help-card">
                            <div class="help-card-icon">&rarr;</div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.related_out_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.related_out_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">&larr;</div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.related_in_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.related_in_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">&loz;</div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.related_ref_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.related_ref_body')); ?></p>
                        </div>
                    </div>

                    <p style="margin-top: 14px;"><?php echo t('network-mapper.help.related_commit'); ?></p>
                    <p class="help-note"><?php echo t('network-mapper.help.related_tip1'); ?></p>
                    <p class="help-note"><?php echo t('network-mapper.help.related_tip2'); ?></p>
                </div>

                <!-- 6. Planned objects -->
                <div class="help-section" id="planned">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.planned_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.planned_body_before')); ?><span class="help-pill info"><?php echo htmlspecialchars(t('network-mapper.help.planned_pill')); ?></span><?php echo htmlspecialchars(t('network-mapper.help.planned_body_after')); ?></p>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('network-mapper.help.planned_tip'); ?></p>
                </div>

                <!-- 7. Page size guide -->
                <div class="help-section" id="paper">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.paper_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.paper_body'); ?></p>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('network-mapper.help.paper_tip1'); ?></p>
                    <p class="help-note"><?php echo t('network-mapper.help.paper_tip2'); ?></p>
                </div>

                <!-- 8. Header &amp; footer -->
                <div class="help-section" id="branding">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.branding_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.branding_body')); ?></p>
                        </div>
                    </div>
                    <div class="help-steps">
                        <div class="help-step"><span class="help-step-num">1</span><div><?php echo t('network-mapper.help.branding_step1'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">2</span><div><?php echo t('network-mapper.help.branding_step2'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">3</span><div><?php echo t('network-mapper.help.branding_step3'); ?></div></div>
                    </div>
                    <p class="help-note"><?php echo t('network-mapper.help.branding_tip1'); ?></p>
                    <p class="help-note"><?php echo t('network-mapper.help.branding_tip2'); ?></p>
                    <p class="help-note"><?php echo t('network-mapper.help.branding_tip3'); ?></p>
                </div>

                <!-- 9. Versioning -->
                <div class="help-section" id="versioning">
                    <div class="help-section-header">
                        <span class="help-section-num">9</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.versioning_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.versioning_body_before')); ?><span class="help-pill ok"><?php echo htmlspecialchars(t('network-mapper.help.versioning_pill_current')); ?></span><?php echo htmlspecialchars(t('network-mapper.help.versioning_body_mid')); ?><span class="help-pill"><?php echo htmlspecialchars(t('network-mapper.help.versioning_pill_readonly')); ?></span><?php echo htmlspecialchars(t('network-mapper.help.versioning_body_after')); ?></p>
                        </div>
                    </div>
                    <div class="help-steps">
                        <div class="help-step"><span class="help-step-num">1</span><div><?php echo htmlspecialchars(t('network-mapper.help.versioning_step1')); ?></div></div>
                        <div class="help-step"><span class="help-step-num">2</span><div><?php echo t('network-mapper.help.versioning_step2'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">3</span><div><?php echo htmlspecialchars(t('network-mapper.help.versioning_step3')); ?></div></div>
                    </div>
                    <p class="help-note warn"><?php echo t('network-mapper.help.versioning_warn'); ?></p>
                </div>

                <!-- 10. Saving -->
                <div class="help-section" id="saving">
                    <div class="help-section-header">
                        <span class="help-section-num">10</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.saving_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.saving_body', ['ctrl' => '<span class="help-kbd">Ctrl</span>', 's' => '<span class="help-kbd">S</span>']); ?></p>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('network-mapper.help.saving_tip'); ?></p>
                    <p class="help-note warn"><?php echo t('network-mapper.help.saving_warn'); ?></p>
                </div>

                <!-- 11. Quick tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">11</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.tips_title')); ?></h3>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row"><span class="help-card-icon">&#8984;</span><div><?php echo t('network-mapper.help.tip_ctrls'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x2316;</span><div><?php echo t('network-mapper.help.tip_esc'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x2715;</span><div><?php echo htmlspecialchars(t('network-mapper.help.tip_deselect')); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x21BB;</span><div><?php echo htmlspecialchars(t('network-mapper.help.tip_track')); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x2713;</span><div><?php echo htmlspecialchars(t('network-mapper.help.tip_dedupe')); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x21AA;</span><div><?php echo htmlspecialchars(t('network-mapper.help.tip_cmdblink')); ?></div></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as the user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.help-nav-link');
        const sections = [];
        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el: el });
        });
        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0] && sections[0].id;
            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => link.classList.toggle('active', link.dataset.section === current));
        });
        // Smooth-scroll within the help container, not the page
        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
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
