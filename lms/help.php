<?php
/**
 * LMS Module Help Guide — full page with left pane navigation.
 *
 * Mirrors the network-mapper/help.php and process-mapper/help.php structure
 * (sidebar + hero + numbered sections + scroll-spy). Blue branding to match
 * the LMS module palette.
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
require_once '../includes/theme.php';
I18n::initFromSession();
Tz::init();
require_once '../includes/functions.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

requireModuleAccess('lms');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('lms.help.page_title')); ?></title>
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
            --accent:       var(--lms-accent);
            --accent-hover: var(--lms-accent-hover);
            --accent-soft:  var(--lms-accent-soft);
            --on-accent:    var(--lms-on-accent);
        }
    </style>
    <!-- Mobile layer: linked AFTER this page's own CSS so its @media rules win on ties. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="lms">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Sidebar nav -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('lms.help.nav_label')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span> <?php echo htmlspecialchars(t('lms.help.nav_overview')); ?>
            </a>
            <a href="#authoring" class="help-nav-link" data-section="authoring">
                <span class="help-nav-num">2</span> <?php echo htmlspecialchars(t('lms.help.nav_authoring')); ?>
            </a>
            <a href="#uploading" class="help-nav-link" data-section="uploading">
                <span class="help-nav-num">3</span> <?php echo htmlspecialchars(t('lms.help.nav_uploading')); ?>
            </a>
            <a href="#groups" class="help-nav-link" data-section="groups">
                <span class="help-nav-num">4</span> <?php echo htmlspecialchars(t('lms.help.nav_groups')); ?>
            </a>
            <a href="#assigning" class="help-nav-link" data-section="assigning">
                <span class="help-nav-num">5</span> <?php echo htmlspecialchars(t('lms.help.nav_assigning')); ?>
            </a>
            <a href="#launching" class="help-nav-link" data-section="launching">
                <span class="help-nav-num">6</span> <?php echo htmlspecialchars(t('lms.help.nav_launching')); ?>
            </a>
            <a href="#progress" class="help-nav-link" data-section="progress">
                <span class="help-nav-num">7</span> <?php echo htmlspecialchars(t('lms.help.nav_progress')); ?>
            </a>
            <a href="#learner-data" class="help-nav-link" data-section="learner-data">
                <span class="help-nav-num">8</span> <?php echo htmlspecialchars(t('lms.help.nav_learner_data')); ?>
            </a>
            <a href="#scorm" class="help-nav-link" data-section="scorm">
                <span class="help-nav-num">9</span> <?php echo htmlspecialchars(t('lms.help.nav_scorm')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">10</span> <?php echo htmlspecialchars(t('lms.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content -->
        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('lms.help.hero_title')); ?></h2>
                <p><?php echo t('lms.help.hero_sub'); ?></p>
            </div>

            <div class="help-content">

                <!-- 1. Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.overview_heading')); ?></h3>
                            <p><?php echo t('lms.help.overview_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('lms.help.flow_upload')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('lms.help.flow_groups')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('lms.help.flow_assign')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('lms.help.flow_track')); ?></div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.overview_card1_title')); ?></h4>
                            <p><?php echo t('lms.help.overview_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.overview_card2_title')); ?></h4>
                            <p><?php echo t('lms.help.overview_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7L9 18l-5-5"/></svg>
                            </div>
                            <h4><?php echo t('lms.help.overview_card3_title'); ?></h4>
                            <p><?php echo t('lms.help.overview_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.overview_card4_title')); ?></h4>
                            <p><?php echo t('lms.help.overview_card4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 2. Writing your own course -->
                <div class="help-section" id="authoring">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.authoring_heading')); ?></h3>
                            <p><?php echo t('lms.help.authoring_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step"><span class="help-step-num">1</span><div><?php echo t('lms.help.authoring_step1'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">2</span><div><?php echo t('lms.help.authoring_step2'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">3</span><div><?php echo t('lms.help.authoring_step3'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">4</span><div><?php echo t('lms.help.authoring_step4'); ?></div></div>
                    </div>

                    <p style="margin-top: 18px;"><?php echo t('lms.help.authoring_quiz'); ?></p>
                    <p><?php echo t('lms.help.authoring_passmark'); ?></p>

                    <div class="help-cards" style="margin-top: 16px;">
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('lms.help.authoring_ai1_title')); ?></h4>
                            <p><?php echo t('lms.help.authoring_ai1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('lms.help.authoring_ai2_title')); ?></h4>
                            <p><?php echo t('lms.help.authoring_ai2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('lms.help.authoring_ai3_title')); ?></h4>
                            <p><?php echo t('lms.help.authoring_ai3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo htmlspecialchars(t('lms.help.authoring_ai4_title')); ?></h4>
                            <p><?php echo t('lms.help.authoring_ai4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 3. Uploading a course -->
                <div class="help-section" id="uploading">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.uploading_heading')); ?></h3>
                            <p><?php echo t('lms.help.uploading_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step"><span class="help-step-num">1</span><div><?php echo t('lms.help.uploading_step1'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">2</span><div><?php echo t('lms.help.uploading_step2'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">3</span><div><?php echo t('lms.help.uploading_step3'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">4</span><div><?php echo t('lms.help.uploading_step4'); ?></div></div>
                    </div>

                    <p class="help-note"><?php echo t('lms.help.uploading_tip1'); ?></p>
                    <p class="help-note"><?php echo t('lms.help.uploading_tip2'); ?></p>
                    <p class="help-note warn"><?php echo t('lms.help.uploading_warn'); ?></p>
                </div>

                <!-- 3. Learning groups -->
                <div class="help-section" id="groups">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.groups_heading')); ?></h3>
                            <p><?php echo t('lms.help.groups_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step"><span class="help-step-num">1</span><div><?php echo t('lms.help.groups_step1'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">2</span><div><?php echo t('lms.help.groups_step2'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">3</span><div><?php echo t('lms.help.groups_step3'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">4</span><div><?php echo t('lms.help.groups_step4'); ?></div></div>
                    </div>

                    <p class="help-note"><?php echo t('lms.help.groups_tip'); ?></p>
                </div>

                <!-- 4. Assigning courses -->
                <div class="help-section" id="assigning">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.assigning_heading')); ?></h3>
                            <p><?php echo t('lms.help.assigning_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step"><span class="help-step-num">1</span><div><?php echo t('lms.help.assigning_step1'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">2</span><div><?php echo t('lms.help.assigning_step2'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">3</span><div><?php echo t('lms.help.assigning_step3'); ?></div></div>
                        <div class="help-step"><span class="help-step-num">4</span><div><?php echo t('lms.help.assigning_step4'); ?></div></div>
                    </div>

                    <p class="help-note"><?php echo t('lms.help.assigning_tip'); ?></p>
                    <p class="help-note warn"><?php echo t('lms.help.assigning_warn'); ?></p>
                </div>

                <!-- 5. Launching a course -->
                <div class="help-section" id="launching">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.launching_heading')); ?></h3>
                            <p><?php echo t('lms.help.launching_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-cards" style="margin-top: 14px;">
                        <div class="help-card">
                            <div class="help-card-icon">&#x21BB;</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.launching_card1_title')); ?></h4>
                            <p><?php echo t('lms.help.launching_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">&#x1F4BE;</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.launching_card2_title')); ?></h4>
                            <p><?php echo t('lms.help.launching_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">&#x2713;</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.launching_card3_title')); ?></h4>
                            <p><?php echo t('lms.help.launching_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">&#x1F4CA;</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.launching_card4_title')); ?></h4>
                            <p><?php echo t('lms.help.launching_card4_body'); ?></p>
                        </div>
                    </div>

                    <p style="margin-top: 14px;"><?php echo t('lms.help.launching_attempts'); ?></p>
                    <p class="help-note"><?php echo t('lms.help.launching_tip'); ?></p>
                </div>

                <!-- 6. Tracking progress -->
                <div class="help-section" id="progress">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.progress_heading')); ?></h3>
                            <p><?php echo t('lms.help.progress_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('lms.help.progress_status_intro')); ?></p>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon" style="background: #f5f5f5; color: #666;">&#x25CB;</div>
                            <h4><span class="help-pill"><?php echo htmlspecialchars(t('lms.help.progress_card1_title')); ?></span></h4>
                            <p><?php echo t('lms.help.progress_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">&#x25D0;</div>
                            <h4><span class="help-pill warn"><?php echo htmlspecialchars(t('lms.help.progress_card2_title')); ?></span></h4>
                            <p><?php echo t('lms.help.progress_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">&#x2714;</div>
                            <h4><span class="help-pill info"><?php echo htmlspecialchars(t('lms.help.progress_card3_title')); ?></span></h4>
                            <p><?php echo t('lms.help.progress_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">&#x2605;</div>
                            <h4><span class="help-pill ok"><?php echo htmlspecialchars(t('lms.help.progress_card4_title_passed')); ?></span> / <span class="help-pill bad"><?php echo htmlspecialchars(t('lms.help.progress_card4_title_failed')); ?></span></h4>
                            <p><?php echo t('lms.help.progress_card4_body'); ?></p>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('lms.help.progress_tip'); ?></p>
                </div>

                <!-- 7. Learner data drill-down -->
                <div class="help-section" id="learner-data">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.learner_heading')); ?></h3>
                            <p><?php echo t('lms.help.learner_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('lms.help.learner_groups_into')); ?></p>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.learner_card1_title')); ?></h4>
                            <p><?php echo t('lms.help.learner_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.learner_card2_title')); ?></h4>
                            <p><?php echo t('lms.help.learner_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.learner_card3_title')); ?></h4>
                            <p><?php echo t('lms.help.learner_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.learner_card4_title')); ?></h4>
                            <p><?php echo t('lms.help.learner_card4_body'); ?></p>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('lms.help.learner_tip'); ?></p>
                </div>

                <!-- 8. SCORM support -->
                <div class="help-section" id="scorm">
                    <div class="help-section-header">
                        <span class="help-section-num">9</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.scorm_heading')); ?></h3>
                            <p><?php echo t('lms.help.scorm_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">1.1</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.scorm_card1_title')); ?></h4>
                            <p><?php echo t('lms.help.scorm_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">1.2</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.scorm_card2_title')); ?></h4>
                            <p><?php echo t('lms.help.scorm_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">2004</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.scorm_card3_title')); ?></h4>
                            <p><?php echo t('lms.help.scorm_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.scorm_card4_title')); ?></h4>
                            <p><?php echo t('lms.help.scorm_card4_body'); ?></p>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('lms.help.scorm_tip'); ?></p>
                </div>

                <!-- 9. Quick tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">10</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.tips_heading')); ?></h3>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row"><span class="help-card-icon">&#x1F4E6;</span><div><?php echo t('lms.help.tip1'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x1F465;</span><div><?php echo t('lms.help.tip2'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x1F4CB;</span><div><?php echo t('lms.help.tip3'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x23F2;</span><div><?php echo t('lms.help.tip4'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x1F4D6;</span><div><?php echo t('lms.help.tip5'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x1F441;</span><div><?php echo t('lms.help.tip6'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x1F501;</span><div><?php echo t('lms.help.tip7'); ?></div></div>
                        <div class="help-card row"><span class="help-card-icon">&#x1F3CC;</span><div><?php echo t('lms.help.tip8'); ?></div></div>
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
