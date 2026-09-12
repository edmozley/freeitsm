<?php
/**
 * Reporting Help Guide - Full page with left pane navigation
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
requireModuleAccess('reporting');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('reporting.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=70">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--rep-accent);
            --accent-hover: var(--rep-accent-hover);
            --accent-soft:  var(--rep-accent-soft);
            --on-accent:    var(--rep-on-accent);
        }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> block, or a rule at
         equal specificity loses on document order (Techniques §9). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=139">
</head>
<body data-mobile-module="reporting" data-mobile-page="rep-help">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('reporting.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_overview')); ?>
            </a>
            <a href="#ticket-reports" class="help-nav-link" data-section="ticket-reports">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_ticket_reports')); ?>
            </a>
            <a href="#system-logs" class="help-nav-link" data-section="system-logs">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_system_logs')); ?>
            </a>
            <a href="#understanding-data" class="help-nav-link" data-section="understanding-data">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_understanding_data')); ?>
            </a>
            <a href="#settings-filters" class="help-nav-link" data-section="settings-filters">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_settings_filters')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('reporting.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('reporting.help.hero_heading')); ?></h2>
                <p><?php echo htmlspecialchars(t('reporting.help.hero_sub')); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('reporting.help.s1_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('reporting.help.s1_card1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_card1_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('reporting.help.s1_card2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_card2_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('reporting.help.s1_card3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_card3_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('reporting.help.s1_card4_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s1_card4_body')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Ticket Reports -->
                <div class="help-section" id="ticket-reports">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s2_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('reporting.help.s2_intro')); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card1_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card1_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card2_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card2_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card3_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card3_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card4_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card4_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card5_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card5_body')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('reporting.help.s2_card6_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('reporting.help.s2_card6_body')); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('reporting.help.s2_tip')); ?></p>
                </div>

                <!-- Section 3: System Logs -->
                <div class="help-section" id="system-logs">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s3_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('reporting.help.s3_intro')); ?></p>

                    <div class="help-defs">
                        <div class="help-def">
                            <span class="help-def-term"><?php echo htmlspecialchars(t('reporting.help.s3_badge_login')); ?></span>
                            <div class="help-def-desc">
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_login_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_login_body')); ?>
                            </div>
                        </div>
                        <div class="help-def">
                            <span class="help-def-term"><?php echo htmlspecialchars(t('reporting.help.s3_badge_email')); ?></span>
                            <div class="help-def-desc">
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_email_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_email_body')); ?>
                            </div>
                        </div>
                        <div class="help-def">
                            <span class="help-def-term"><?php echo htmlspecialchars(t('reporting.help.s3_badge_system')); ?></span>
                            <div class="help-def-desc">
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_system_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_system_body')); ?>
                            </div>
                        </div>
                        <div class="help-def">
                            <span class="help-def-term"><?php echo htmlspecialchars(t('reporting.help.s3_badge_audit')); ?></span>
                            <div class="help-def-desc">
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_audit_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_audit_body')); ?>
                            </div>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_step1_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_step1_body')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_step2_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_step2_body')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s3_step3_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s3_step3_body')); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('reporting.help.s3_tip')); ?></p>
                </div>

                <!-- Section 4: Understanding the Data (highlighted) -->
                <div class="help-section" id="understanding-data">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s4_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('reporting.help.s4_intro')); ?></p>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('reporting.help.s4_metric1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s4_metric1_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('reporting.help.s4_metric2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s4_metric2_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('reporting.help.s4_metric3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s4_metric3_body')); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('reporting.help.s4_metric4_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('reporting.help.s4_metric4_body')); ?></p>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('reporting.help.s4_combine')); ?></p>

                    <p class="help-note"><?php echo htmlspecialchars(t('reporting.help.s4_tip')); ?></p>
                </div>

                <!-- Section 5: Settings & Filters -->
                <div class="help-section" id="settings-filters">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s5_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('reporting.help.s5_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step1_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step1_body')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step2_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step2_body')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step3_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step3_body')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step4_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step4_body')); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('reporting.help.s5_step5_title')); ?></strong> &mdash; <?php echo htmlspecialchars(t('reporting.help.s5_step5_body')); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo htmlspecialchars(t('reporting.help.s5_tip')); ?></p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('reporting.help.s6_heading')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128202;</div>
                            <div><strong><?php echo htmlspecialchars(t('reporting.help.s6_tip1_title')); ?></strong><br><?php echo htmlspecialchars(t('reporting.help.s6_tip1_body')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128269;</div>
                            <div><strong><?php echo htmlspecialchars(t('reporting.help.s6_tip2_title')); ?></strong><br><?php echo htmlspecialchars(t('reporting.help.s6_tip2_body')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128200;</div>
                            <div><strong><?php echo htmlspecialchars(t('reporting.help.s6_tip3_title')); ?></strong><br><?php echo htmlspecialchars(t('reporting.help.s6_tip3_body')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128274;</div>
                            <div><strong><?php echo htmlspecialchars(t('reporting.help.s6_tip4_title')); ?></strong><br><?php echo htmlspecialchars(t('reporting.help.s6_tip4_body')); ?></div>
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
    <script src="../assets/js/mobile.js?v=57"></script>
</body>
</html>
