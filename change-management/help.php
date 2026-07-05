<?php
/**
 * Change Management Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'change-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('change-management.page.help')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=13">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/change-management.css?v=6">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="cm-help-container">
        <!-- Left pane navigation -->
        <div class="cm-help-sidebar">
            <h3><?php echo htmlspecialchars(t('change-management.help.guide')); ?></h3>
            <a href="#what-is-a-change" class="cm-help-nav-link active" data-section="what-is-a-change">
                <span class="cm-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('change-management.help.nav_what')); ?>
            </a>
            <a href="#change-types" class="cm-help-nav-link" data-section="change-types">
                <span class="cm-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('change-management.help.nav_types')); ?>
            </a>
            <a href="#lifecycle" class="cm-help-nav-link" data-section="lifecycle">
                <span class="cm-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('change-management.help.nav_lifecycle')); ?>
            </a>
            <a href="#recording" class="cm-help-nav-link" data-section="recording">
                <span class="cm-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('change-management.help.nav_recording')); ?>
            </a>
            <a href="#cab" class="cm-help-nav-link cab" data-section="cab">
                <span class="cm-help-nav-num cab">5</span>
                <?php echo htmlspecialchars(t('change-management.help.nav_cab')); ?>
            </a>
            <a href="#risk" class="cm-help-nav-link" data-section="risk">
                <span class="cm-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('change-management.help.nav_risk')); ?>
            </a>
            <a href="#pir" class="cm-help-nav-link" data-section="pir">
                <span class="cm-help-nav-num">7</span>
                <?php echo htmlspecialchars(t('change-management.help.nav_pir')); ?>
            </a>
            <a href="#tips" class="cm-help-nav-link" data-section="tips">
                <span class="cm-help-nav-num">8</span>
                <?php echo htmlspecialchars(t('change-management.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="cm-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="cm-help-hero">
                <h2><?php echo htmlspecialchars(t('change-management.help.hero_heading')); ?></h2>
                <p><?php echo htmlspecialchars(t('change-management.help.hero_intro')); ?></p>
            </div>

            <div class="cm-help-content">

                <!-- Section 1: What is a Change? -->
                <div class="cm-help-section" id="what-is-a-change">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('change-management.help.what_heading')); ?></h3>
                            <p><?php echo t('change-management.help.what_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Change Types -->
                <div class="cm-help-section" id="change-types">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('change-management.help.types_heading')); ?></h3>
                    </div>
                    <div class="cm-help-types-grid">
                        <div class="cm-help-type-card standard">
                            <div class="cm-help-type-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('change-management.help.type_standard')); ?></h4>
                            <p><?php echo t('change-management.help.type_standard_desc'); ?></p>
                        </div>
                        <div class="cm-help-type-card normal">
                            <div class="cm-help-type-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('change-management.help.type_normal')); ?></h4>
                            <p><?php echo t('change-management.help.type_normal_desc'); ?></p>
                        </div>
                        <div class="cm-help-type-card emergency">
                            <div class="cm-help-type-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('change-management.help.type_emergency')); ?></h4>
                            <p><?php echo t('change-management.help.type_emergency_desc'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 3: The Change Lifecycle -->
                <div class="cm-help-section" id="lifecycle">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('change-management.help.lifecycle_heading')); ?></h3>
                    </div>
                    <div class="cm-help-lifecycle">
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge draft"><?php echo htmlspecialchars(t('change-management.help.lc_draft')); ?></div>
                            <div class="cm-help-step-desc">
                                <strong><?php echo t('change-management.help.lc_draft_title'); ?></strong>
                                <span><?php echo htmlspecialchars(t('change-management.help.lc_draft_desc')); ?></span>
                            </div>
                        </div>
                        <div class="cm-help-lifecycle-arrow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        </div>
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge pending-approval"><?php echo htmlspecialchars(t('change-management.help.lc_pending')); ?></div>
                            <div class="cm-help-step-desc">
                                <strong><?php echo htmlspecialchars(t('change-management.help.lc_pending_title')); ?></strong>
                                <span><?php echo htmlspecialchars(t('change-management.help.lc_pending_desc')); ?></span>
                            </div>
                        </div>
                        <div class="cm-help-lifecycle-arrow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        </div>
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge approved"><?php echo htmlspecialchars(t('change-management.help.lc_approved')); ?></div>
                            <div class="cm-help-step-desc">
                                <strong><?php echo htmlspecialchars(t('change-management.help.lc_approved_title')); ?></strong>
                                <span><?php echo htmlspecialchars(t('change-management.help.lc_approved_desc')); ?></span>
                            </div>
                        </div>
                        <div class="cm-help-lifecycle-arrow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        </div>
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge in-progress"><?php echo htmlspecialchars(t('change-management.help.lc_inprogress')); ?></div>
                            <div class="cm-help-step-desc">
                                <strong><?php echo htmlspecialchars(t('change-management.help.lc_inprogress_title')); ?></strong>
                                <span><?php echo htmlspecialchars(t('change-management.help.lc_inprogress_desc')); ?></span>
                            </div>
                        </div>
                        <div class="cm-help-lifecycle-arrow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        </div>
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge completed"><?php echo htmlspecialchars(t('change-management.help.lc_completed')); ?></div>
                            <div class="cm-help-step-desc">
                                <strong><?php echo htmlspecialchars(t('change-management.help.lc_completed_title')); ?></strong>
                                <span><?php echo htmlspecialchars(t('change-management.help.lc_completed_desc')); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="cm-help-lifecycle-alt">
                        <span><?php echo t('change-management.help.lc_alt', [
                            'failed' => '<span class="cm-help-step-badge-inline failed">' . htmlspecialchars(t('change-management.help.lc_failed')) . '</span>',
                            'cancelled' => '<span class="cm-help-step-badge-inline cancelled">' . htmlspecialchars(t('change-management.help.lc_cancelled')) . '</span>',
                        ]); ?></span>
                    </div>
                </div>

                <!-- Section 4: Recording a Change -->
                <div class="cm-help-section" id="recording">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">4</span>
                        <h3><?php echo t('change-management.help.recording_heading'); ?></h3>
                    </div>
                    <div class="cm-help-steps">
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">1</div>
                            <div>
                                <?php echo t('change-management.help.rec_1'); ?>
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">2</div>
                            <div>
                                <?php echo t('change-management.help.rec_2'); ?>
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">3</div>
                            <div>
                                <?php echo t('change-management.help.rec_3'); ?>
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">4</div>
                            <div>
                                <?php echo t('change-management.help.rec_4'); ?>
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">5</div>
                            <div>
                                <?php echo t('change-management.help.rec_5'); ?>
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">6</div>
                            <div>
                                <?php echo t('change-management.help.rec_6'); ?>
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">7</div>
                            <div>
                                <?php echo t('change-management.help.rec_7'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 5: CAB -->
                <div class="cm-help-section cm-help-section-highlight" id="cab">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num cab">5</span>
                        <h3><?php echo t('change-management.help.cab_heading'); ?></h3>
                    </div>
                    <p class="cm-help-intro"><?php echo t('change-management.help.cab_intro'); ?></p>

                    <div class="cm-help-cab-flow">
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon setup">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('change-management.help.cab_setup_heading')); ?></h4>
                            <p><?php echo t('change-management.help.cab_setup_desc'); ?></p>
                        </div>
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon choose">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('change-management.help.cab_choose_heading')); ?></h4>
                            <div class="cm-help-approval-types">
                                <div class="cm-help-approval-type">
                                    <span class="cm-help-approval-label all"><?php echo htmlspecialchars(t('change-management.help.cab_all_label')); ?></span>
                                    <span><?php echo t('change-management.help.cab_all_desc'); ?></span>
                                </div>
                                <div class="cm-help-approval-type">
                                    <span class="cm-help-approval-label majority"><?php echo htmlspecialchars(t('change-management.help.cab_majority_label')); ?></span>
                                    <span><?php echo htmlspecialchars(t('change-management.help.cab_majority_desc')); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon members">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('change-management.help.cab_members_heading')); ?></h4>
                            <p><?php echo t('change-management.help.cab_members_desc'); ?></p>
                        </div>
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon vote">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('change-management.help.cab_vote_heading')); ?></h4>
                            <p><?php echo t('change-management.help.cab_vote_desc'); ?></p>
                            <div class="cm-help-vote-options">
                                <span class="cm-help-vote approve"><?php echo htmlspecialchars(t('change-management.help.cab_approve')); ?></span>
                                <span class="cm-help-vote reject"><?php echo htmlspecialchars(t('change-management.help.cab_reject')); ?></span>
                                <span class="cm-help-vote abstain"><?php echo htmlspecialchars(t('change-management.help.cab_abstain')); ?></span>
                            </div>
                            <p><?php echo htmlspecialchars(t('change-management.help.cab_vote_comment')); ?></p>
                        </div>
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon auto">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 17 18 12 13 7"></polyline><polyline points="6 17 11 12 6 7"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('change-management.help.cab_auto_heading')); ?></h4>
                            <div class="cm-help-auto-rules">
                                <div class="cm-help-auto-rule approve">
                                    <span class="cm-help-auto-arrow">&#10003;</span>
                                    <div>
                                        <strong><?php echo htmlspecialchars(t('change-management.help.cab_auto_met')); ?></strong>
                                        <span><?php echo t('change-management.help.cab_auto_met_desc'); ?></span>
                                    </div>
                                </div>
                                <div class="cm-help-auto-rule reject">
                                    <span class="cm-help-auto-arrow">&#10007;</span>
                                    <div>
                                        <strong><?php echo htmlspecialchars(t('change-management.help.cab_auto_reject')); ?></strong>
                                        <span><?php echo t('change-management.help.cab_auto_reject_desc'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 6: Risk Matrix -->
                <div class="cm-help-section" id="risk">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('change-management.help.risk_heading')); ?></h3>
                    </div>
                    <p><?php echo t('change-management.help.risk_intro'); ?></p>
                    <div class="cm-help-risk-scale">
                        <div class="cm-help-risk-level low"><span>1&ndash;4</span> <?php echo htmlspecialchars(t('change-management.help.risk_low')); ?></div>
                        <div class="cm-help-risk-level medium"><span>5&ndash;9</span> <?php echo htmlspecialchars(t('change-management.help.risk_medium')); ?></div>
                        <div class="cm-help-risk-level high"><span>10&ndash;15</span> <?php echo htmlspecialchars(t('change-management.help.risk_high')); ?></div>
                        <div class="cm-help-risk-level very-high"><span>16&ndash;20</span> <?php echo htmlspecialchars(t('change-management.help.risk_very_high')); ?></div>
                        <div class="cm-help-risk-level critical"><span>21&ndash;25</span> <?php echo htmlspecialchars(t('change-management.help.risk_critical')); ?></div>
                    </div>
                    <p class="cm-help-tip"><?php echo htmlspecialchars(t('change-management.help.risk_tip')); ?></p>
                </div>

                <!-- Section 7: PIR -->
                <div class="cm-help-section" id="pir">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('change-management.help.pir_heading')); ?></h3>
                    </div>
                    <p><?php echo t('change-management.help.pir_intro'); ?></p>
                    <div class="cm-help-pir-fields">
                        <div><?php echo t('change-management.help.pir_successful'); ?></div>
                        <div><?php echo t('change-management.help.pir_actual'); ?></div>
                        <div><?php echo t('change-management.help.pir_lessons'); ?></div>
                        <div><?php echo t('change-management.help.pir_followup'); ?></div>
                    </div>
                    <p class="cm-help-tip"><?php echo htmlspecialchars(t('change-management.help.pir_tip')); ?></p>
                </div>

                <!-- Section 8: Quick tips -->
                <div class="cm-help-section" id="tips">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">8</span>
                        <h3><?php echo htmlspecialchars(t('change-management.help.tips_heading')); ?></h3>
                    </div>
                    <div class="cm-help-tips-grid">
                        <div class="cm-help-tip-card">
                            <div class="cm-help-tip-icon">&#128197;</div>
                            <div><strong><?php echo htmlspecialchars(t('change-management.help.tip_calendar')); ?></strong><br><?php echo htmlspecialchars(t('change-management.help.tip_calendar_desc')); ?></div>
                        </div>
                        <div class="cm-help-tip-card">
                            <div class="cm-help-tip-icon">&#128172;</div>
                            <div><strong><?php echo htmlspecialchars(t('change-management.help.tip_comments')); ?></strong><br><?php echo htmlspecialchars(t('change-management.help.tip_comments_desc')); ?></div>
                        </div>
                        <div class="cm-help-tip-card">
                            <div class="cm-help-tip-icon">&#128206;</div>
                            <div><strong><?php echo htmlspecialchars(t('change-management.help.tip_attachments')); ?></strong><br><?php echo htmlspecialchars(t('change-management.help.tip_attachments_desc')); ?></div>
                        </div>
                        <div class="cm-help-tip-card">
                            <div class="cm-help-tip-icon">&#128269;</div>
                            <div><strong><?php echo htmlspecialchars(t('change-management.help.tip_audit')); ?></strong><br><?php echo htmlspecialchars(t('change-management.help.tip_audit_desc')); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.cm-help-nav-link');
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
                // offset by hero height + some padding
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
