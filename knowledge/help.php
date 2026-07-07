<?php
/**
 * Knowledge Base Help Guide - Full page with left pane navigation
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
$translationNamespaces = ['common', 'knowledge'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('knowledge.browser_title.help')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=15">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        .kb-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }

        /* Left sidebar navigation */
        .kb-help-sidebar {
            width: 260px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .kb-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .kb-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        .kb-help-nav-link:hover {
            background: var(--surface-hover, #f5f5f5);
            color: var(--text, #333);
        }

        .kb-help-nav-link.active {
            background: var(--kb-accent-soft, #f3e5f5);
            color: var(--kb-accent-hover, #6b4fa2);
            font-weight: 600;
        }

        .kb-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--border-soft, #eee);
            color: var(--text-dim, #888);
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }

        .kb-help-nav-link.active .kb-help-nav-num {
            background: var(--kb-accent-hover, #6b4fa2);
            color: var(--kb-on-accent, white);
        }

        /* Main content */
        .kb-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .kb-help-hero {
            background: linear-gradient(135deg, #8764b8 0%, #6b4fa2 50%, #4a3570 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        /* Darken the hero in dark mode so it recedes instead of glowing bright purple. */
        [data-theme-mode="dark"] .kb-help-hero {
            background: linear-gradient(135deg, #3a2d52 0%, #2e2342 50%, #1f1730 100%);
        }

        .kb-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .kb-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .kb-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .kb-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }

        .kb-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .kb-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .kb-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .kb-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: var(--text-muted, #666);
            line-height: 1.6;
        }

        .kb-help-section > p {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .kb-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--kb-accent-soft, #f3e5f5);
            color: var(--kb-accent-hover, #6b4fa2);
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .kb-help-section-num.highlight {
            background: var(--kb-accent-hover, #6b4fa2);
            color: var(--kb-on-accent, white);
        }

        /* Feature cards grid */
        .kb-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .kb-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, white);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .kb-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow, rgba(0,0,0,0.08));
        }

        .kb-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .kb-help-feature-icon.purple { background: #f3e5f5; color: #8764b8; }
        .kb-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .kb-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .kb-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .kb-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: var(--text, #333);
        }

        .kb-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        /* Numbered steps */
        .kb-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .kb-help-step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--surface-2, #fafafa);
            font-size: 14px;
            color: var(--text-muted, #444);
            line-height: 1.5;
        }

        .kb-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--kb-accent, #8764b8);
            color: var(--kb-on-accent, white);
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .kb-help-section-highlight {
            background: var(--kb-accent-soft, #f3e5f5);
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid var(--kb-accent, #ce93d8);
        }

        .kb-help-intro {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Review workflow flow */
        .kb-help-workflow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 20px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .kb-help-workflow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .kb-help-workflow-step.draft { background: #e3f2fd; color: #1565c0; }
        .kb-help-workflow-step.review { background: #fff3e0; color: #e65100; }
        .kb-help-workflow-step.approved { background: #e8f5e9; color: #2e7d32; }
        .kb-help-workflow-step.published { background: #f3e5f5; color: #6b4fa2; }

        .kb-help-workflow-arrow {
            padding: 0 8px;
            color: var(--text-faint, #bbb);
            font-size: 18px;
        }

        /* Review status badges */
        .kb-help-status-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .kb-help-status-card {
            padding: 14px 16px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            border-left: 3px solid var(--kb-accent, #8764b8);
        }

        .kb-help-status-card strong {
            display: block;
            font-size: 13px;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .kb-help-status-card span {
            font-size: 12.5px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }

        .kb-help-status-card.pending { border-left-color: #e65100; }
        .kb-help-status-card.approved { border-left-color: #2e7d32; }
        .kb-help-status-card.rejected { border-left-color: #c62828; }

        /* AI chat preview */
        .kb-help-ai-demo {
            background: var(--surface, white);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 10px;
            padding: 20px;
            margin: 16px 0;
        }

        .kb-help-ai-msg {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
        }

        .kb-help-ai-msg:last-child {
            margin-bottom: 0;
        }

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
        }

        .kb-help-ai-avatar.user { background: var(--kb-accent-soft, #f3e5f5); color: var(--kb-accent-hover, #6b4fa2); }
        .kb-help-ai-avatar.ai { background: #e3f2fd; color: #1565c0; }

        .kb-help-ai-bubble {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            color: var(--text-muted, #444);
            max-width: 80%;
        }

        .kb-help-ai-bubble.user { background: var(--kb-accent-soft, #f3e5f5); }
        .kb-help-ai-bubble.ai { background: var(--surface-hover, #f5f5f5); }

        /* Info fields list */
        .kb-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .kb-help-fields div {
            padding: 8px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
        }

        /* Settings options grid */
        .kb-help-settings-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .kb-help-setting-card {
            padding: 14px 16px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            border-left: 3px solid var(--kb-accent, #8764b8);
        }

        .kb-help-setting-card strong {
            display: block;
            font-size: 13px;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .kb-help-setting-card span {
            font-size: 12px;
            color: var(--text-dim, #777);
            line-height: 1.4;
        }

        /* Tip callout */
        .kb-help-tip {
            font-size: 13px !important;
            color: var(--kb-accent-hover, #6b4fa2) !important;
            background: var(--kb-accent-soft, #f3e5f5);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--kb-accent, #8764b8);
            margin-top: 10px;
        }

        /* Quick tips grid */
        .kb-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .kb-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }

        .kb-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .kb-help-tip-card strong {
            color: var(--text, #333);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .kb-help-sidebar { display: none; }
            .kb-help-content { padding: 10px 24px 40px; }
            .kb-help-hero { padding: 30px 24px; }
            .kb-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .kb-help-features-grid { grid-template-columns: 1fr; }
            .kb-help-status-grid { grid-template-columns: 1fr; }
            .kb-help-settings-grid { grid-template-columns: 1fr; }
            .kb-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="kb-help-container">
        <!-- Left pane navigation -->
        <div class="kb-help-sidebar">
            <h3><?php echo htmlspecialchars(t('knowledge.help.guide')); ?></h3>
            <a href="#overview" class="kb-help-nav-link active" data-section="overview">
                <span class="kb-help-nav-num">1</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_overview')); ?>
            </a>
            <a href="#writing-articles" class="kb-help-nav-link" data-section="writing-articles">
                <span class="kb-help-nav-num">2</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_writing')); ?>
            </a>
            <a href="#review-workflow" class="kb-help-nav-link" data-section="review-workflow">
                <span class="kb-help-nav-num">3</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_review')); ?>
            </a>
            <a href="#ask-ai" class="kb-help-nav-link" data-section="ask-ai">
                <span class="kb-help-nav-num">4</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_ask_ai')); ?>
            </a>
            <a href="#search-navigation" class="kb-help-nav-link" data-section="search-navigation">
                <span class="kb-help-nav-num">5</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_search')); ?>
            </a>
            <a href="#sharing-export" class="kb-help-nav-link" data-section="sharing-export">
                <span class="kb-help-nav-num">6</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_sharing')); ?>
            </a>
            <a href="#tips" class="kb-help-nav-link" data-section="tips">
                <span class="kb-help-nav-num">7</span>
                <?php echo htmlspecialchars(t('knowledge.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="kb-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="kb-help-hero">
                <h2><?php echo htmlspecialchars(t('knowledge.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('knowledge.help.hero_subtitle')); ?></p>
            </div>

            <div class="kb-help-content">

                <!-- Section 1: Overview -->
                <div class="kb-help-section" id="overview">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('knowledge.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="kb-help-features-grid">
                        <div class="kb-help-feature-card">
                            <div class="kb-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('knowledge.help.overview_card1_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_card1_desc')); ?></p>
                        </div>
                        <div class="kb-help-feature-card">
                            <div class="kb-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('knowledge.help.overview_card2_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_card2_desc')); ?></p>
                        </div>
                        <div class="kb-help-feature-card">
                            <div class="kb-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('knowledge.help.overview_card3_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_card3_desc')); ?></p>
                        </div>
                        <div class="kb-help-feature-card">
                            <div class="kb-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('knowledge.help.overview_card4_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('knowledge.help.overview_card4_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Writing Articles -->
                <div class="kb-help-section" id="writing-articles">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.writing_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('knowledge.help.writing_intro')); ?></p>
                    <div class="kb-help-steps">
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">1</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step1'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">2</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step2'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">3</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step3'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">4</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step4'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">5</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step5'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">6</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step6'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">7</div>
                            <div>
                                <?php echo t('knowledge.help.writing_step7'); ?>
                            </div>
                        </div>
                    </div>
                    <p class="kb-help-tip"><?php echo htmlspecialchars(t('knowledge.help.writing_tip')); ?></p>
                </div>

                <!-- Section 3: Review Workflow (highlighted) -->
                <div class="kb-help-section kb-help-section-highlight" id="review-workflow">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num highlight">3</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.review_heading')); ?></h3>
                    </div>
                    <p class="kb-help-intro"><?php echo htmlspecialchars(t('knowledge.help.review_intro')); ?></p>

                    <div class="kb-help-workflow">
                        <div class="kb-help-workflow-step draft"><?php echo htmlspecialchars(t('knowledge.help.review_flow_draft')); ?></div>
                        <div class="kb-help-workflow-arrow">&rarr;</div>
                        <div class="kb-help-workflow-step review"><?php echo htmlspecialchars(t('knowledge.help.review_flow_pending')); ?></div>
                        <div class="kb-help-workflow-arrow">&rarr;</div>
                        <div class="kb-help-workflow-step approved"><?php echo htmlspecialchars(t('knowledge.help.review_flow_approved')); ?></div>
                        <div class="kb-help-workflow-arrow">&rarr;</div>
                        <div class="kb-help-workflow-step published"><?php echo htmlspecialchars(t('knowledge.help.review_flow_published')); ?></div>
                    </div>

                    <div class="kb-help-steps" style="margin-left: 0;">
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">1</div>
                            <div>
                                <?php echo t('knowledge.help.review_step1'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">2</div>
                            <div>
                                <?php echo t('knowledge.help.review_step2'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">3</div>
                            <div>
                                <?php echo t('knowledge.help.review_step3'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">4</div>
                            <div>
                                <?php echo t('knowledge.help.review_step4'); ?>
                            </div>
                        </div>
                    </div>

                    <div class="kb-help-status-grid" style="margin-top: 20px;">
                        <div class="kb-help-status-card pending">
                            <strong><?php echo htmlspecialchars(t('knowledge.help.review_status_pending_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('knowledge.help.review_status_pending_desc')); ?></span>
                        </div>
                        <div class="kb-help-status-card approved">
                            <strong><?php echo htmlspecialchars(t('knowledge.help.review_status_approved_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('knowledge.help.review_status_approved_desc')); ?></span>
                        </div>
                        <div class="kb-help-status-card rejected">
                            <strong><?php echo htmlspecialchars(t('knowledge.help.review_status_changes_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('knowledge.help.review_status_changes_desc')); ?></span>
                        </div>
                        <div class="kb-help-status-card">
                            <strong><?php echo htmlspecialchars(t('knowledge.help.review_status_scheduled_title')); ?></strong>
                            <span><?php echo htmlspecialchars(t('knowledge.help.review_status_scheduled_desc')); ?></span>
                        </div>
                    </div>

                    <p class="kb-help-tip"><?php echo t('knowledge.help.review_tip'); ?></p>
                </div>

                <!-- Section 4: Ask AI -->
                <div class="kb-help-section" id="ask-ai">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">4</span>
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

                    <div class="kb-help-steps">
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">1</div>
                            <div>
                                <?php echo t('knowledge.help.ai_step1'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">2</div>
                            <div>
                                <?php echo t('knowledge.help.ai_step2'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">3</div>
                            <div>
                                <?php echo t('knowledge.help.ai_step3'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">4</div>
                            <div>
                                <?php echo t('knowledge.help.ai_step4'); ?>
                            </div>
                        </div>
                    </div>
                    <p class="kb-help-tip"><?php echo htmlspecialchars(t('knowledge.help.ai_tip')); ?></p>
                </div>

                <!-- Section 5: Search & Navigation -->
                <div class="kb-help-section" id="search-navigation">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.search_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('knowledge.help.search_intro')); ?></p>

                    <div class="kb-help-fields">
                        <div><?php echo t('knowledge.help.search_field1'); ?></div>
                        <div><?php echo t('knowledge.help.search_field2'); ?></div>
                        <div><?php echo t('knowledge.help.search_field3'); ?></div>
                        <div><?php echo t('knowledge.help.search_field4'); ?></div>
                        <div><?php echo t('knowledge.help.search_field5'); ?></div>
                    </div>
                    <p class="kb-help-tip"><?php echo htmlspecialchars(t('knowledge.help.search_tip')); ?></p>
                </div>

                <!-- Section 6: Sharing & Export (highlighted) -->
                <div class="kb-help-section kb-help-section-highlight" id="sharing-export">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num highlight">6</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.sharing_heading')); ?></h3>
                    </div>
                    <p class="kb-help-intro"><?php echo htmlspecialchars(t('knowledge.help.sharing_intro')); ?></p>

                    <div class="kb-help-steps" style="margin-left: 0;">
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">1</div>
                            <div>
                                <?php echo t('knowledge.help.sharing_step1'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">2</div>
                            <div>
                                <?php echo t('knowledge.help.sharing_step2'); ?>
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">3</div>
                            <div>
                                <?php echo t('knowledge.help.sharing_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo t('knowledge.help.sharing_note'); ?></p>

                    <p class="kb-help-tip"><?php echo htmlspecialchars(t('knowledge.help.sharing_tip')); ?></p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="kb-help-section" id="tips">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('knowledge.help.tips_heading')); ?></h3>
                    </div>
                    <div class="kb-help-tips-grid">
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#128221;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip1_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip1_desc')); ?></div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#128197;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip2_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip2_desc')); ?></div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#128278;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip3_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip3_desc')); ?></div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#127991;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip4_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip4_desc')); ?></div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#128172;</div>
                            <div><strong><?php echo htmlspecialchars(t('knowledge.help.tip5_title')); ?></strong><br><?php echo htmlspecialchars(t('knowledge.help.tip5_desc')); ?></div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#9851;</div>
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
        const navLinks = document.querySelectorAll('.kb-help-nav-link');
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
</body>
</html>
