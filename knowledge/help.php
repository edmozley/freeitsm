<?php
/**
 * Knowledge Base Help Guide - Full page with left pane navigation
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

requireModuleAccess('knowledge');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'knowledge'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('knowledge.browser_title.help')); ?></title>
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
            --accent:       var(--kb-accent);
            --accent-hover: var(--kb-accent-hover);
            --accent-soft:  var(--kb-accent-soft);
            --on-accent:    var(--kb-on-accent);
        }

        /* Module-specific: a mock-up of the AI assistant conversation. It is a
           picture of the real thing, so it keeps its own shape. */
        .kb-help-ai-demo {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 14px;
        }
        .kb-help-ai-msg { display: flex; gap: 10px; margin-bottom: 14px; }
        .kb-help-ai-msg:last-child { margin-bottom: 0; }
        .kb-help-ai-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
            background: var(--accent-soft);
            color: var(--accent);
        }
        .kb-help-ai-avatar.ai { background: var(--accent); color: var(--on-accent, #fff); }
        .kb-help-ai-bubble {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--text-muted, #444);
            max-width: 80%;
            background: var(--accent-soft);
        }
        .kb-help-ai-bubble.ai { background: var(--surface-2, #fafafa); }
    </style>
    <!-- Mobile: the guide body is already responsive (help-page house style); LAYER 16h adds the app shell and the scroll container. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=127">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('knowledge.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_overview')); ?>
            </a>
            <a href="#writing-articles" class="help-nav-link" data-section="writing-articles">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_writing')); ?>
            </a>
            <a href="#review-workflow" class="help-nav-link" data-section="review-workflow">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_review')); ?>
            </a>
            <a href="#folders" class="help-nav-link" data-section="folders">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_folders')); ?>
            </a>
            <a href="#permissions" class="help-nav-link" data-section="permissions">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_permissions')); ?>
            </a>
            <a href="#ask-ai" class="help-nav-link" data-section="ask-ai">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_ask_ai')); ?>
            </a>
            <a href="#search-navigation" class="help-nav-link" data-section="search-navigation">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_search')); ?>
            </a>
            <a href="#sharing-export" class="help-nav-link" data-section="sharing-export">
                <span class="help-nav-num">8</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_sharing')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">9</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('knowledge.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('knowledge.help.hero_subtitle')); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('knowledge.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('knowledge.help.overview_card1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_card1_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('knowledge.help.overview_card2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_card2_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('knowledge.help.overview_card3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_card3_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('knowledge.help.overview_card4_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_card4_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Writing Articles -->
                <div class="help-section" id="writing-articles">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.writing_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('knowledge.help.writing_intro')); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step4'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step5'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">6</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step6'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">7</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step_visibility'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">8</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step7'); ?>
                            </div>
                        </div>
                    </div>
                    <p class="help-note"><?php echo htmlspecialchars(t('knowledge.help.writing_tip')); ?></p>
                    <p class="help-note"><?php echo t('knowledge.help.writing_visibility_callout'); ?></p>
                </div>

                <!-- Section 3: Review Workflow (highlighted) -->
                <div class="help-section" id="review-workflow">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.review_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('knowledge.help.review_intro')); ?></p>

                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('knowledge.help.review_flow_draft')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('knowledge.help.review_flow_pending')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('knowledge.help.review_flow_approved')); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo htmlspecialchars(t('knowledge.help.review_flow_published')); ?></div>
                    </div>

                    <div class="help-steps" style="margin-left: 0;">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('knowledge.help.review_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('knowledge.help.review_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('knowledge.help.review_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('knowledge.help.review_step4'); ?>
                            </div>
                        </div>
                    </div>

                    <div class="help-cards" style="margin-top: 20px;">
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('knowledge.help.review_status_pending_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('knowledge.help.review_status_pending_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('knowledge.help.review_status_approved_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('knowledge.help.review_status_approved_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('knowledge.help.review_status_changes_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('knowledge.help.review_status_changes_desc')); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo htmlspecialchars(t('knowledge.help.review_status_scheduled_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('knowledge.help.review_status_scheduled_desc')); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('knowledge.help.review_tip'); ?></p>
                </div>

                <!-- Section 4: Folders & views -->
                <div class="help-section" id="folders">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('knowledge.help.folders_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('knowledge.help.folders_intro')); ?></p>
                        </div>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.folders_make_heading')); ?></h4>
                        <div class="help-steps">
                            <div class="help-step">
                                <div class="help-step-num">1</div>
                                <div><?php echo t('knowledge.help.folders_step1'); ?></div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">2</div>
                                <div><?php echo t('knowledge.help.folders_step2'); ?></div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">3</div>
                                <div><?php echo t('knowledge.help.folders_step3'); ?></div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">4</div>
                                <div><?php echo t('knowledge.help.folders_step4'); ?></div>
                            </div>
                            <div class="help-step">
                                <div class="help-step-num">5</div>
                                <div><?php echo t('knowledge.help.folders_step5'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.folders_views_heading')); ?></h4>
                        <div class="help-cards">
                            <div class="help-card">
                                <strong><?php echo htmlspecialchars(t('knowledge.help.folders_view_list')); ?></strong>
                                <span><?php echo htmlspecialchars(t('knowledge.help.folders_view_list_desc')); ?></span>
                            </div>
                            <div class="help-card">
                                <strong><?php echo htmlspecialchars(t('knowledge.help.folders_view_cards')); ?></strong>
                                <span><?php echo htmlspecialchars(t('knowledge.help.folders_view_cards_desc')); ?></span>
                            </div>
                            <div class="help-card">
                                <strong><?php echo htmlspecialchars(t('knowledge.help.folders_view_tree')); ?></strong>
                                <span><?php echo htmlspecialchars(t('knowledge.help.folders_view_tree_desc')); ?></span>
                            </div>
                            <div class="help-card">
                                <strong><?php echo htmlspecialchars(t('knowledge.help.folders_view_details')); ?></strong>
                                <span><?php echo htmlspecialchars(t('knowledge.help.folders_view_details_desc')); ?></span>
                            </div>
                        </div>
                        <p class="help-note"><?php echo htmlspecialchars(t('knowledge.help.folders_views_note')); ?></p>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.folders_home_heading')); ?></h4>
                        <div class="help-list">
                            <div><?php echo t('knowledge.help.folders_home'); ?></div>
                            <div><?php echo t('knowledge.help.folders_breadcrumb'); ?></div>
                            <div><?php echo t('knowledge.help.folders_search'); ?></div>
                        </div>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.folders_select_heading')); ?></h4>
                        <p><?php echo htmlspecialchars(t('knowledge.help.folders_select_intro')); ?></p>
                        <div class="help-list">
                            <div><?php echo t('knowledge.help.folders_select1'); ?></div>
                            <div><?php echo t('knowledge.help.folders_select2'); ?></div>
                            <div><?php echo t('knowledge.help.folders_select3'); ?></div>
                            <div><?php echo t('knowledge.help.folders_select4'); ?></div>
                            <div><?php echo t('knowledge.help.folders_select5'); ?></div>
                            <div><?php echo t('knowledge.help.folders_select6'); ?></div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('knowledge.help.folders_tip'); ?></p>
                </div>

                <!-- Section 5: Who can see what -->
                <div class="help-section" id="permissions">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('knowledge.help.perms_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('knowledge.help.perms_intro')); ?></p>
                        </div>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.perms_two_heading')); ?></h4>
                        <div class="help-defs">
                        <div class="help-def">
                            <div class="help-def-term"><?php echo t('knowledge.help.perms_audience_title'); ?></div>
                            <div class="help-def-desc"><?php echo t('knowledge.help.perms_audience_desc'); ?></div>
                        </div>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo t('knowledge.help.perms_acl_title'); ?></div>
                            <div class="help-def-desc"><?php echo t('knowledge.help.perms_acl_desc'); ?></div>
                        </div>
                        </div>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.perms_mode_heading')); ?></h4>
                        <p><?php echo t('knowledge.help.perms_mode_intro'); ?></p>
                        <div class="help-cards">
                            <div class="help-card">
                                <strong><?php echo htmlspecialchars(t('knowledge.help.perms_open_title')); ?></strong>
                                <span><?php echo t('knowledge.help.perms_open_desc'); ?></span>
                            </div>
                            <div class="help-card">
                                <strong><?php echo htmlspecialchars(t('knowledge.help.perms_restricted_title')); ?></strong>
                                <span><?php echo t('knowledge.help.perms_restricted_desc'); ?></span>
                            </div>
                        </div>
                        <p><?php echo t('knowledge.help.perms_mode_why'); ?></p>
                        <p class="help-note warn"><?php echo t('knowledge.help.perms_mode_switch'); ?></p>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.perms_inherit_heading')); ?></h4>
                        <div class="help-list">
                            <div><?php echo t('knowledge.help.perms_inherit'); ?></div>
                            <div><?php echo t('knowledge.help.perms_inherit_view'); ?></div>
                            <div><?php echo t('knowledge.help.perms_inherit_own'); ?></div>
                        </div>
                        <p class="help-note"><?php echo t('knowledge.help.perms_exceptions'); ?></p>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.perms_model_heading')); ?></h4>
                        <p><?php echo t('knowledge.help.perms_model_intro'); ?></p>
                        <div class="help-cards">
                            <div class="help-card">
                                <strong><?php echo htmlspecialchars(t('knowledge.help.perms_containers_title')); ?></strong>
                                <span><?php echo htmlspecialchars(t('knowledge.help.perms_containers_desc')); ?></span>
                            </div>
                            <div class="help-card">
                                <strong><?php echo htmlspecialchars(t('knowledge.help.perms_filing_title')); ?></strong>
                                <span><?php echo htmlspecialchars(t('knowledge.help.perms_filing_desc')); ?></span>
                            </div>
                        </div>
                        <p class="help-note"><?php echo t('knowledge.help.perms_model_preview'); ?></p>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.perms_where_heading')); ?></h4>
                        <p><?php echo htmlspecialchars(t('knowledge.help.perms_where_intro')); ?></p>
                        <div class="help-list">
                            <div><?php echo t('knowledge.help.perms_where_analysts'); ?></div>
                            <div><?php echo t('knowledge.help.perms_where_portal'); ?></div>
                            <div><?php echo t('knowledge.help.perms_where_ai'); ?></div>
                            <div><?php echo t('knowledge.help.perms_where_chat'); ?></div>
                            <div><?php echo t('knowledge.help.perms_where_api'); ?></div>
                        </div>
                    </div>

                    <div class="help-subsection">
                        <h4><?php echo htmlspecialchars(t('knowledge.help.perms_admin_heading')); ?></h4>
                        <div class="help-list">
                            <div><?php echo t('knowledge.help.perms_admin'); ?></div>
                            <div><?php echo t('knowledge.help.perms_admin_audit'); ?></div>
                        </div>
                        <p class="help-note warn"><?php echo t('knowledge.help.perms_admin_tip'); ?></p>
                    </div>
                </div>

                <!-- Section 6: Ask AI -->
                <div class="help-section" id="ask-ai">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.ai_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('knowledge.help.ai_intro')); ?></p>

                    <div class="kb-help-ai-demo">
                        <div class="kb-help-ai-msg">
                            <div class="kb-help-ai-avatar user"><?php echo htmlspecialchars(t('knowledge.help.ai_demo_user_label')); ?></div>
                            <div class="kb-help-ai-bubble user"><?php echo htmlspecialchars(t('knowledge.help.ai_demo_user_msg')); ?></div>
                        </div>
                        <div class="kb-help-ai-msg">
                            <div class="kb-help-ai-avatar ai"><?php echo htmlspecialchars(t('knowledge.help.ai_demo_ai_label')); ?></div>
                            <div class="kb-help-ai-bubble ai"><?php echo t('knowledge.help.ai_demo_ai_msg'); ?></div>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('knowledge.help.ai_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('knowledge.help.ai_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('knowledge.help.ai_step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('knowledge.help.ai_step4'); ?>
                            </div>
                        </div>
                    </div>
                    <p class="help-note"><?php echo htmlspecialchars(t('knowledge.help.ai_tip')); ?></p>
                </div>

                <!-- Section 7: Search & Navigation -->
                <div class="help-section" id="search-navigation">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.search_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('knowledge.help.search_intro')); ?></p>

                    <div class="help-list">
                        <div><?php echo t('knowledge.help.search_field1'); ?></div>
                        <div><?php echo t('knowledge.help.search_field2'); ?></div>
                        <div><?php echo t('knowledge.help.search_field3'); ?></div>
                        <div><?php echo t('knowledge.help.search_field4'); ?></div>
                        <div><?php echo t('knowledge.help.search_field5'); ?></div>
                    </div>
                    <p class="help-note"><?php echo htmlspecialchars(t('knowledge.help.search_tip')); ?></p>
                </div>

                <!-- Section 8: Sharing & Export -->
                <div class="help-section" id="sharing-export">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.sharing_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('knowledge.help.sharing_intro')); ?></p>

                    <div class="help-steps" style="margin-left: 0;">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('knowledge.help.sharing_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('knowledge.help.sharing_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('knowledge.help.sharing_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo t('knowledge.help.sharing_note'); ?></p>

                    <p class="help-note"><?php echo htmlspecialchars(t('knowledge.help.sharing_tip')); ?></p>
                </div>

                <!-- Section 9: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">9</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.tips_heading')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#128221;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip1_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip1_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#128197;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip2_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip2_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#128278;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip3_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip3_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#127991;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip4_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip4_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#128172;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip5_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip5_desc')); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon plain">&#9851;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip6_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip6_desc')); ?></div>
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
