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
    header('Location: ../login.php');
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('lms.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        /* Pin the module accent for shared components */
        body { --accent: var(--lms-accent, #2563eb); }

        .lh-container { display: flex; height: calc(100vh - 48px); background: var(--app-bg, #f5f5f5); }

        /* ---- Sidebar nav ---- */
        .lh-sidebar {
            width: 260px;
            background: var(--surface, #fff);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }
        .lh-sidebar h3 {
            font-size: 12px; font-weight: 600;
            color: var(--text-dim, #888); text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .lh-nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 13px; color: var(--text-muted, #555);
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .lh-nav-link:hover { background: var(--surface-hover, #f5f5f5); color: var(--text, #333); }
        .lh-nav-link.active { background: #dbeafe; color: #1e40af; font-weight: 600; }
        .lh-nav-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px;
            border-radius: 50%;
            background: var(--surface-3, #f5f5f5); color: var(--text-dim, #888);
            font-size: 11px; font-weight: 700;
        }
        .lh-nav-link.active .lh-nav-num { background: var(--lms-accent, #2563eb); color: white; }

        /* ---- Main content ---- */
        .lh-main { flex: 1; overflow-y: auto; }
        .lh-hero {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 50%, #1e3a8a 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        .lh-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .lh-hero p  { margin: 0; font-size: 15px; opacity: 0.9; }
        .lh-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        /* ---- Sections ---- */
        .lh-section { padding: 28px 0; border-bottom: 1px solid var(--border-soft, #eee); scroll-margin-top: 20px; }
        .lh-section:last-child { border-bottom: 0; padding-bottom: 0; }
        .lh-section-header {
            display: flex; align-items: flex-start; gap: 14px;
            margin-bottom: 16px;
        }
        .lh-section-header h3 { margin: 0; font-size: 18px; color: var(--text, #333); }
        .lh-section-header p  { margin: 6px 0 0; font-size: 14px; color: var(--text-muted, #666); line-height: 1.6; }
        .lh-section > p {
            font-size: 14px; color: var(--text-muted, #555); line-height: 1.7;
            margin: 0 0 14px;
        }
        .lh-section-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px;
            border-radius: 50%;
            background: #dbeafe; color: #1e40af;
            font-weight: 700; font-size: 14px;
            flex-shrink: 0;
        }
        .lh-section-num.highlight { background: var(--lms-accent, #2563eb); color: white; }

        /* ---- Feature card grid ---- */
        .lh-features-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .lh-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, #fff);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .lh-feature-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px var(--shadow, rgba(0,0,0,0.08)); }
        .lh-feature-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
        }
        .lh-feature-icon.blue   { background: #dbeafe; color: #1e40af; }
        .lh-feature-icon.indigo { background: #e0e7ff; color: #4338ca; }
        .lh-feature-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .lh-feature-icon.amber  { background: #fff7ed; color: #c2410c; }
        .lh-feature-card h4 { margin: 0 0 6px; font-size: 15px; color: var(--text, #333); }
        .lh-feature-card p  { margin: 0; font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; }

        /* ---- Numbered steps ---- */
        .lh-steps { display: flex; flex-direction: column; gap: 12px; margin-left: 46px; }
        .lh-step-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 10px 14px; border-radius: 8px;
            background: var(--surface-2, #fafafa);
            font-size: 14px; color: var(--text, #444); line-height: 1.5;
        }
        .lh-step-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--lms-accent, #2563eb); color: white;
            font-weight: 700; font-size: 13px;
            flex-shrink: 0;
        }

        /* ---- Highlighted section ---- */
        .lh-section-highlight {
            background: #dbeafe;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #93c5fd;
        }

        /* ---- Flow row ---- */
        .lh-flow {
            display: flex; align-items: center; gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }
        .lh-flow-step {
            display: flex; align-items: center; justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px; font-weight: 600;
            text-align: center;
        }
        .lh-flow-step.s1 { background: #dbeafe; color: #1e40af; }
        .lh-flow-step.s2 { background: #e0e7ff; color: #4338ca; }
        .lh-flow-step.s3 { background: #e8f5e9; color: #2e7d32; }
        .lh-flow-step.s4 { background: #fff3e0; color: #c2410c; }
        .lh-flow-arrow { padding: 0 8px; color: var(--text-faint, #bbb); font-size: 18px; }

        /* ---- Callouts ---- */
        .lh-tip {
            font-size: 13px !important;
            color: #1e40af !important;
            background: #dbeafe;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--lms-accent, #2563eb);
            margin-top: 10px;
        }
        .lh-warn {
            font-size: 13px !important;
            color: #9a3412 !important;
            background: #fff7ed;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #f97316;
            margin-top: 10px;
        }

        /* ---- Keyboard chip ---- */
        .lh-kbd {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            background: var(--surface, #fff);
            border: 1px solid var(--border, #cbd5e1);
            box-shadow: 0 1px 0 var(--shadow, rgba(0,0,0,0.04));
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 11.5px;
            color: var(--text, #334155);
        }

        /* ---- Tips grid ---- */
        .lh-tips-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .lh-tip-card {
            display: flex; gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }
        .lh-tip-icon { font-size: 24px; flex-shrink: 0; line-height: 1; }
        .lh-tip-card strong { color: var(--text, #333); }

        /* ---- Status pills used in copy ---- */
        .lh-pill {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            vertical-align: middle;
            text-transform: uppercase;
        }
        .lh-pill.not-started { background: #f5f5f5; color: #666; border: 1px solid #e0e0e0; }
        .lh-pill.incomplete  { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .lh-pill.completed   { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .lh-pill.passed      { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .lh-pill.failed      { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .lh-pill.overdue     { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }

        /* ---- Responsive ---- */
        @media (max-width: 900px) {
            .lh-sidebar { display: none; }
            .lh-content { padding: 10px 24px 40px; }
            .lh-hero { padding: 30px 24px; }
            .lh-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }
        @media (max-width: 700px) {
            .lh-features-grid { grid-template-columns: 1fr; }
            .lh-tips-grid { grid-template-columns: 1fr; }
        }

        /* ---- Dark mode: pale washes that would otherwise glow ---- */
        [data-theme-mode="dark"] .lh-hero { filter: brightness(0.82); }
        [data-theme-mode="dark"] .lh-nav-link.active { background: #1b2a45; color: #93c5fd; }
        [data-theme-mode="dark"] .lh-section-num { background: #1b2a45; color: #93c5fd; }
        [data-theme-mode="dark"] .lh-section-highlight {
            background: #1b2a45;
            border-top-color: #2f4a76;
        }
        [data-theme-mode="dark"] .lh-tip {
            background: #1b2a45;
            color: #93c5fd !important;
        }
        [data-theme-mode="dark"] .lh-warn {
            background: #3a2412;
            color: #fdba74 !important;
            border-left-color: #b45309;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="lh-container">
        <!-- Sidebar nav -->
        <div class="lh-sidebar">
            <h3><?php echo htmlspecialchars(t('lms.help.nav_label')); ?></h3>
            <a href="#overview" class="lh-nav-link active" data-section="overview">
                <span class="lh-nav-num">1</span> <?php echo htmlspecialchars(t('lms.help.nav_overview')); ?>
            </a>
            <a href="#authoring" class="lh-nav-link" data-section="authoring">
                <span class="lh-nav-num">2</span> <?php echo htmlspecialchars(t('lms.help.nav_authoring')); ?>
            </a>
            <a href="#uploading" class="lh-nav-link" data-section="uploading">
                <span class="lh-nav-num">3</span> <?php echo htmlspecialchars(t('lms.help.nav_uploading')); ?>
            </a>
            <a href="#groups" class="lh-nav-link" data-section="groups">
                <span class="lh-nav-num">4</span> <?php echo htmlspecialchars(t('lms.help.nav_groups')); ?>
            </a>
            <a href="#assigning" class="lh-nav-link" data-section="assigning">
                <span class="lh-nav-num">5</span> <?php echo htmlspecialchars(t('lms.help.nav_assigning')); ?>
            </a>
            <a href="#launching" class="lh-nav-link" data-section="launching">
                <span class="lh-nav-num">6</span> <?php echo htmlspecialchars(t('lms.help.nav_launching')); ?>
            </a>
            <a href="#progress" class="lh-nav-link" data-section="progress">
                <span class="lh-nav-num">7</span> <?php echo htmlspecialchars(t('lms.help.nav_progress')); ?>
            </a>
            <a href="#learner-data" class="lh-nav-link" data-section="learner-data">
                <span class="lh-nav-num">8</span> <?php echo htmlspecialchars(t('lms.help.nav_learner_data')); ?>
            </a>
            <a href="#scorm" class="lh-nav-link" data-section="scorm">
                <span class="lh-nav-num">9</span> <?php echo htmlspecialchars(t('lms.help.nav_scorm')); ?>
            </a>
            <a href="#tips" class="lh-nav-link" data-section="tips">
                <span class="lh-nav-num">10</span> <?php echo htmlspecialchars(t('lms.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content -->
        <div class="lh-main" id="helpMain">
            <div class="lh-hero">
                <h2><?php echo htmlspecialchars(t('lms.help.hero_title')); ?></h2>
                <p><?php echo t('lms.help.hero_sub'); ?></p>
            </div>

            <div class="lh-content">

                <!-- 1. Overview -->
                <div class="lh-section" id="overview">
                    <div class="lh-section-header">
                        <span class="lh-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.overview_heading')); ?></h3>
                            <p><?php echo t('lms.help.overview_intro'); ?></p>
                        </div>
                    </div>

                    <div class="lh-flow">
                        <div class="lh-flow-step s1"><?php echo htmlspecialchars(t('lms.help.flow_upload')); ?></div>
                        <div class="lh-flow-arrow">&rarr;</div>
                        <div class="lh-flow-step s2"><?php echo htmlspecialchars(t('lms.help.flow_groups')); ?></div>
                        <div class="lh-flow-arrow">&rarr;</div>
                        <div class="lh-flow-step s3"><?php echo htmlspecialchars(t('lms.help.flow_assign')); ?></div>
                        <div class="lh-flow-arrow">&rarr;</div>
                        <div class="lh-flow-step s4"><?php echo htmlspecialchars(t('lms.help.flow_track')); ?></div>
                    </div>

                    <div class="lh-features-grid">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.overview_card1_title')); ?></h4>
                            <p><?php echo t('lms.help.overview_card1_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon indigo">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.overview_card2_title')); ?></h4>
                            <p><?php echo t('lms.help.overview_card2_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7L9 18l-5-5"/></svg>
                            </div>
                            <h4><?php echo t('lms.help.overview_card3_title'); ?></h4>
                            <p><?php echo t('lms.help.overview_card3_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.overview_card4_title')); ?></h4>
                            <p><?php echo t('lms.help.overview_card4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 2. Writing your own course -->
                <div class="lh-section" id="authoring">
                    <div class="lh-section-header">
                        <span class="lh-section-num highlight">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.authoring_heading')); ?></h3>
                            <p><?php echo t('lms.help.authoring_intro'); ?></p>
                        </div>
                    </div>

                    <div class="lh-steps">
                        <div class="lh-step-item"><span class="lh-step-num">1</span><div><?php echo t('lms.help.authoring_step1'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">2</span><div><?php echo t('lms.help.authoring_step2'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">3</span><div><?php echo t('lms.help.authoring_step3'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">4</span><div><?php echo t('lms.help.authoring_step4'); ?></div></div>
                    </div>

                    <p style="margin-top: 18px;"><?php echo t('lms.help.authoring_quiz'); ?></p>
                    <p><?php echo t('lms.help.authoring_passmark'); ?></p>

                    <div class="lh-features-grid" style="margin-top: 16px;">
                        <div class="lh-feature-card">
                            <h4><?php echo htmlspecialchars(t('lms.help.authoring_ai1_title')); ?></h4>
                            <p><?php echo t('lms.help.authoring_ai1_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <h4><?php echo htmlspecialchars(t('lms.help.authoring_ai2_title')); ?></h4>
                            <p><?php echo t('lms.help.authoring_ai2_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <h4><?php echo htmlspecialchars(t('lms.help.authoring_ai3_title')); ?></h4>
                            <p><?php echo t('lms.help.authoring_ai3_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <h4><?php echo htmlspecialchars(t('lms.help.authoring_ai4_title')); ?></h4>
                            <p><?php echo t('lms.help.authoring_ai4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 3. Uploading a course -->
                <div class="lh-section" id="uploading">
                    <div class="lh-section-header">
                        <span class="lh-section-num highlight">3</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.uploading_heading')); ?></h3>
                            <p><?php echo t('lms.help.uploading_intro'); ?></p>
                        </div>
                    </div>

                    <div class="lh-steps">
                        <div class="lh-step-item"><span class="lh-step-num">1</span><div><?php echo t('lms.help.uploading_step1'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">2</span><div><?php echo t('lms.help.uploading_step2'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">3</span><div><?php echo t('lms.help.uploading_step3'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">4</span><div><?php echo t('lms.help.uploading_step4'); ?></div></div>
                    </div>

                    <p class="lh-tip"><?php echo t('lms.help.uploading_tip1'); ?></p>
                    <p class="lh-tip"><?php echo t('lms.help.uploading_tip2'); ?></p>
                    <p class="lh-warn"><?php echo t('lms.help.uploading_warn'); ?></p>
                </div>

                <!-- 3. Learning groups -->
                <div class="lh-section" id="groups">
                    <div class="lh-section-header">
                        <span class="lh-section-num">4</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.groups_heading')); ?></h3>
                            <p><?php echo t('lms.help.groups_intro'); ?></p>
                        </div>
                    </div>

                    <div class="lh-steps">
                        <div class="lh-step-item"><span class="lh-step-num">1</span><div><?php echo t('lms.help.groups_step1'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">2</span><div><?php echo t('lms.help.groups_step2'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">3</span><div><?php echo t('lms.help.groups_step3'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">4</span><div><?php echo t('lms.help.groups_step4'); ?></div></div>
                    </div>

                    <p class="lh-tip"><?php echo t('lms.help.groups_tip'); ?></p>
                </div>

                <!-- 4. Assigning courses -->
                <div class="lh-section" id="assigning">
                    <div class="lh-section-header">
                        <span class="lh-section-num">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.assigning_heading')); ?></h3>
                            <p><?php echo t('lms.help.assigning_intro'); ?></p>
                        </div>
                    </div>

                    <div class="lh-steps">
                        <div class="lh-step-item"><span class="lh-step-num">1</span><div><?php echo t('lms.help.assigning_step1'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">2</span><div><?php echo t('lms.help.assigning_step2'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">3</span><div><?php echo t('lms.help.assigning_step3'); ?></div></div>
                        <div class="lh-step-item"><span class="lh-step-num">4</span><div><?php echo t('lms.help.assigning_step4'); ?></div></div>
                    </div>

                    <p class="lh-tip"><?php echo t('lms.help.assigning_tip'); ?></p>
                    <p class="lh-warn"><?php echo t('lms.help.assigning_warn'); ?></p>
                </div>

                <!-- 5. Launching a course -->
                <div class="lh-section lh-section-highlight" id="launching">
                    <div class="lh-section-header">
                        <span class="lh-section-num highlight">6</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.launching_heading')); ?></h3>
                            <p><?php echo t('lms.help.launching_intro'); ?></p>
                        </div>
                    </div>

                    <div class="lh-features-grid" style="margin-top: 14px;">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">&#x21BB;</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.launching_card1_title')); ?></h4>
                            <p><?php echo t('lms.help.launching_card1_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon indigo">&#x1F4BE;</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.launching_card2_title')); ?></h4>
                            <p><?php echo t('lms.help.launching_card2_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">&#x2713;</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.launching_card3_title')); ?></h4>
                            <p><?php echo t('lms.help.launching_card3_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">&#x1F4CA;</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.launching_card4_title')); ?></h4>
                            <p><?php echo t('lms.help.launching_card4_body'); ?></p>
                        </div>
                    </div>

                    <p style="margin-top: 14px;"><?php echo t('lms.help.launching_attempts'); ?></p>
                    <p class="lh-tip"><?php echo t('lms.help.launching_tip'); ?></p>
                </div>

                <!-- 6. Tracking progress -->
                <div class="lh-section" id="progress">
                    <div class="lh-section-header">
                        <span class="lh-section-num">7</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.progress_heading')); ?></h3>
                            <p><?php echo t('lms.help.progress_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('lms.help.progress_status_intro')); ?></p>

                    <div class="lh-features-grid">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon" style="background: #f5f5f5; color: #666;">&#x25CB;</div>
                            <h4><span class="lh-pill not-started"><?php echo htmlspecialchars(t('lms.help.progress_card1_title')); ?></span></h4>
                            <p><?php echo t('lms.help.progress_card1_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">&#x25D0;</div>
                            <h4><span class="lh-pill incomplete"><?php echo htmlspecialchars(t('lms.help.progress_card2_title')); ?></span></h4>
                            <p><?php echo t('lms.help.progress_card2_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">&#x2714;</div>
                            <h4><span class="lh-pill completed"><?php echo htmlspecialchars(t('lms.help.progress_card3_title')); ?></span></h4>
                            <p><?php echo t('lms.help.progress_card3_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">&#x2605;</div>
                            <h4><span class="lh-pill passed"><?php echo htmlspecialchars(t('lms.help.progress_card4_title_passed')); ?></span> / <span class="lh-pill failed"><?php echo htmlspecialchars(t('lms.help.progress_card4_title_failed')); ?></span></h4>
                            <p><?php echo t('lms.help.progress_card4_body'); ?></p>
                        </div>
                    </div>

                    <p class="lh-tip"><?php echo t('lms.help.progress_tip'); ?></p>
                </div>

                <!-- 7. Learner data drill-down -->
                <div class="lh-section" id="learner-data">
                    <div class="lh-section-header">
                        <span class="lh-section-num">8</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.learner_heading')); ?></h3>
                            <p><?php echo t('lms.help.learner_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo htmlspecialchars(t('lms.help.learner_groups_into')); ?></p>

                    <div class="lh-features-grid">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.learner_card1_title')); ?></h4>
                            <p><?php echo t('lms.help.learner_card1_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon indigo">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.learner_card2_title')); ?></h4>
                            <p><?php echo t('lms.help.learner_card2_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.learner_card3_title')); ?></h4>
                            <p><?php echo t('lms.help.learner_card3_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.learner_card4_title')); ?></h4>
                            <p><?php echo t('lms.help.learner_card4_body'); ?></p>
                        </div>
                    </div>

                    <p class="lh-tip"><?php echo t('lms.help.learner_tip'); ?></p>
                </div>

                <!-- 8. SCORM support -->
                <div class="lh-section" id="scorm">
                    <div class="lh-section-header">
                        <span class="lh-section-num">9</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.scorm_heading')); ?></h3>
                            <p><?php echo t('lms.help.scorm_intro'); ?></p>
                        </div>
                    </div>

                    <div class="lh-features-grid">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">1.1</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.scorm_card1_title')); ?></h4>
                            <p><?php echo t('lms.help.scorm_card1_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon indigo">1.2</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.scorm_card2_title')); ?></h4>
                            <p><?php echo t('lms.help.scorm_card2_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">2004</div>
                            <h4><?php echo htmlspecialchars(t('lms.help.scorm_card3_title')); ?></h4>
                            <p><?php echo t('lms.help.scorm_card3_body'); ?></p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('lms.help.scorm_card4_title')); ?></h4>
                            <p><?php echo t('lms.help.scorm_card4_body'); ?></p>
                        </div>
                    </div>

                    <p class="lh-tip"><?php echo t('lms.help.scorm_tip'); ?></p>
                </div>

                <!-- 9. Quick tips -->
                <div class="lh-section" id="tips">
                    <div class="lh-section-header">
                        <span class="lh-section-num">10</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('lms.help.tips_heading')); ?></h3>
                        </div>
                    </div>
                    <div class="lh-tips-grid">
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F4E6;</span><div><?php echo t('lms.help.tip1'); ?></div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F465;</span><div><?php echo t('lms.help.tip2'); ?></div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F4CB;</span><div><?php echo t('lms.help.tip3'); ?></div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x23F2;</span><div><?php echo t('lms.help.tip4'); ?></div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F4D6;</span><div><?php echo t('lms.help.tip5'); ?></div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F441;</span><div><?php echo t('lms.help.tip6'); ?></div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F501;</span><div><?php echo t('lms.help.tip7'); ?></div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F3CC;</span><div><?php echo t('lms.help.tip8'); ?></div></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as the user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.lh-nav-link');
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
</body>
</html>
