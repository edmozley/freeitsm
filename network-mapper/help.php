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
    header('Location: ../login.php');
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('network-mapper.help.browser_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .nh-container { display: flex; height: calc(100vh - 48px); background: var(--app-bg, #f5f5f5); }

        /* ---- Sidebar nav ---- */
        .nh-sidebar {
            width: 260px;
            background: var(--surface, #fff);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }
        .nh-sidebar h3 {
            font-size: 12px; font-weight: 600;
            color: var(--text-dim, #888); text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .nh-nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 13px; color: var(--text-muted, #555);
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .nh-nav-link:hover { background: var(--surface-hover, #f5f5f5); color: var(--text, #333); }
        .nh-nav-link.active { background: #ecfeff; color: #0e7490; font-weight: 600; }
        .nh-nav-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px;
            border-radius: 50%;
            background: var(--surface-3, #f5f5f5); color: var(--text-dim, #888);
            font-size: 11px; font-weight: 700;
        }
        .nh-nav-link.active .nh-nav-num { background: var(--nm-accent, #06b6d4); color: white; }

        /* ---- Main content ---- */
        .nh-main { flex: 1; overflow-y: auto; }
        .nh-hero {
            background: linear-gradient(135deg, var(--nm-accent, #06b6d4) 0%, var(--nm-accent-hover, #0891b2) 50%, #0e7490 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        .nh-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .nh-hero p  { margin: 0; font-size: 15px; opacity: 0.9; }
        .nh-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        /* ---- Sections ---- */
        .nh-section { padding: 28px 0; border-bottom: 1px solid var(--border-soft, #eee); scroll-margin-top: 20px; }
        .nh-section:last-child { border-bottom: 0; padding-bottom: 0; }
        .nh-section-header {
            display: flex; align-items: flex-start; gap: 14px;
            margin-bottom: 16px;
        }
        .nh-section-header h3 { margin: 0; font-size: 18px; color: var(--text, #333); }
        .nh-section-header p  { margin: 6px 0 0; font-size: 14px; color: var(--text-muted, #666); line-height: 1.6; }
        .nh-section > p {
            font-size: 14px; color: var(--text-muted, #555); line-height: 1.7;
            margin: 0 0 14px;
        }
        .nh-section-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px;
            border-radius: 50%;
            background: #ecfeff; color: #0e7490;
            font-weight: 700; font-size: 14px;
            flex-shrink: 0;
        }
        .nh-section-num.highlight { background: var(--nm-accent, #06b6d4); color: white; }

        /* ---- Feature card grid ---- */
        .nh-features-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .nh-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border, #e0e0e0);
            background: var(--surface, #fff);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .nh-feature-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .nh-feature-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
        }
        .nh-feature-icon.cyan   { background: #ecfeff; color: var(--nm-accent-hover, #0891b2); }
        .nh-feature-icon.blue   { background: #e3f2fd; color: #1565c0; }
        .nh-feature-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .nh-feature-icon.amber  { background: #fff7ed; color: #c2410c; }
        .nh-feature-card h4 { margin: 0 0 6px; font-size: 15px; color: var(--text, #333); }
        .nh-feature-card p  { margin: 0; font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; }

        /* ---- Numbered steps ---- */
        .nh-steps { display: flex; flex-direction: column; gap: 12px; margin-left: 46px; }
        .nh-step-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 10px 14px; border-radius: 8px;
            background: var(--surface-2, #fafafa);
            font-size: 14px; color: var(--text-muted, #444); line-height: 1.5;
        }
        .nh-step-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--nm-accent, #06b6d4); color: white;
            font-weight: 700; font-size: 13px;
            flex-shrink: 0;
        }

        /* ---- Highlighted section ---- */
        .nh-section-highlight {
            background: #ecfeff;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #a5f3fc;
        }
        .nh-intro {
            font-size: 14px; color: var(--text-muted, #555); line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* ---- Flow row ---- */
        .nh-flow {
            display: flex; align-items: center; gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }
        .nh-flow-step {
            display: flex; align-items: center; justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px; font-weight: 600;
            text-align: center;
        }
        .nh-flow-step.s1 { background: #ecfeff; color: #0e7490; }
        .nh-flow-step.s2 { background: #e3f2fd; color: #1565c0; }
        .nh-flow-step.s3 { background: #e8f5e9; color: #2e7d32; }
        .nh-flow-step.s4 { background: #fff3e0; color: #c2410c; }
        .nh-flow-arrow { padding: 0 8px; color: var(--text-faint, #bbb); font-size: 18px; }

        /* ---- Callouts ---- */
        .nh-tip {
            font-size: 13px !important;
            color: #0e7490 !important;
            background: #ecfeff;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--nm-accent, #06b6d4);
            margin-top: 10px;
        }
        .nh-warn {
            font-size: 13px !important;
            color: #9a3412 !important;
            background: #fff7ed;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #f97316;
            margin-top: 10px;
        }

        /* ---- Keyboard chip ---- */
        .nh-kbd {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            background: var(--surface, #fff);
            border: 1px solid var(--border, #cbd5e1);
            box-shadow: 0 1px 0 rgba(0,0,0,0.04);
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 11.5px;
            color: var(--text-muted, #334155);
        }

        /* ---- Tips grid ---- */
        .nh-tips-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .nh-tip-card {
            display: flex; gap: 12px;
            padding: 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }
        .nh-tip-icon { font-size: 24px; flex-shrink: 0; line-height: 1; }
        .nh-tip-card strong { color: var(--text, #333); }

        /* ---- Pill mock-ups used in copy ---- */
        .nh-pill {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            vertical-align: middle;
        }
        .nh-pill.planned { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .nh-pill.current { background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; }
        .nh-pill.readonly { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }

        /* ---- Responsive ---- */
        @media (max-width: 900px) {
            .nh-sidebar { display: none; }
            .nh-content { padding: 10px 24px 40px; }
            .nh-hero { padding: 30px 24px; }
            .nh-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }
        @media (max-width: 700px) {
            .nh-features-grid { grid-template-columns: 1fr; }
            .nh-tips-grid { grid-template-columns: 1fr; }
        }

        /* Dark mode: darken the hero and flip the pale-cyan chrome tints (active
           nav, section numbers, highlight band, tip) + amber warn callout so they
           don't glow. Feature-icon tiles, flow-step badges and the illustrative
           pill mock-ups stay as data. */
        [data-theme-mode="dark"] .nh-hero {
            background: linear-gradient(135deg, #0b7c91 0%, #0a6274 50%, #0a4f5e 100%);
        }
        [data-theme-mode="dark"] .nh-nav-link.active { background: #0d2b30; color: #67e8f9; }
        [data-theme-mode="dark"] .nh-section-num { background: #0d2b30; color: #67e8f9; }
        [data-theme-mode="dark"] .nh-section-highlight { background: #10262b; }
        [data-theme-mode="dark"] .nh-tip { background: #0d2b30; color: #67e8f9 !important; }
        [data-theme-mode="dark"] .nh-warn { background: #3a2e12; color: #fcd34d !important; }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="nh-container">
        <!-- Sidebar nav -->
        <div class="nh-sidebar">
            <h3><?php echo htmlspecialchars(t('network-mapper.help.sidebar_title')); ?></h3>
            <a href="#overview" class="nh-nav-link active" data-section="overview">
                <span class="nh-nav-num">1</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_overview')); ?>
            </a>
            <a href="#creating" class="nh-nav-link" data-section="creating">
                <span class="nh-nav-num">2</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_creating')); ?>
            </a>
            <a href="#placing" class="nh-nav-link" data-section="placing">
                <span class="nh-nav-num">3</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_placing')); ?>
            </a>
            <a href="#connectors" class="nh-nav-link" data-section="connectors">
                <span class="nh-nav-num">4</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_connectors')); ?>
            </a>
            <a href="#related" class="nh-nav-link" data-section="related">
                <span class="nh-nav-num">5</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_related')); ?>
            </a>
            <a href="#planned" class="nh-nav-link" data-section="planned">
                <span class="nh-nav-num">6</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_planned')); ?>
            </a>
            <a href="#paper" class="nh-nav-link" data-section="paper">
                <span class="nh-nav-num">7</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_paper')); ?>
            </a>
            <a href="#branding" class="nh-nav-link" data-section="branding">
                <span class="nh-nav-num">8</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_branding')); ?>
            </a>
            <a href="#versioning" class="nh-nav-link" data-section="versioning">
                <span class="nh-nav-num">9</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_versioning')); ?>
            </a>
            <a href="#saving" class="nh-nav-link" data-section="saving">
                <span class="nh-nav-num">10</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_saving')); ?>
            </a>
            <a href="#tips" class="nh-nav-link" data-section="tips">
                <span class="nh-nav-num">11</span> <?php echo htmlspecialchars(t('network-mapper.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content -->
        <div class="nh-main" id="helpMain">
            <div class="nh-hero">
                <h2><?php echo htmlspecialchars(t('network-mapper.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('network-mapper.help.hero_subtitle')); ?></p>
            </div>

            <div class="nh-content">

                <!-- 1. Overview -->
                <div class="nh-section" id="overview">
                    <div class="nh-section-header">
                        <span class="nh-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.overview_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.overview_body'); ?></p>
                        </div>
                    </div>

                    <div class="nh-flow">
                        <div class="nh-flow-step s1"><?php echo htmlspecialchars(t('network-mapper.help.flow_create')); ?></div>
                        <div class="nh-flow-arrow">&rarr;</div>
                        <div class="nh-flow-step s2"><?php echo htmlspecialchars(t('network-mapper.help.flow_drag')); ?></div>
                        <div class="nh-flow-arrow">&rarr;</div>
                        <div class="nh-flow-step s3"><?php echo htmlspecialchars(t('network-mapper.help.flow_connect')); ?></div>
                        <div class="nh-flow-arrow">&rarr;</div>
                        <div class="nh-flow-step s4"><?php echo htmlspecialchars(t('network-mapper.help.flow_save')); ?></div>
                    </div>

                    <div class="nh-features-grid">
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon cyan">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="6" height="6"/><rect x="14" y="14" width="6" height="6"/><line x1="10" y1="7" x2="14" y2="14"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.feat_bound_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.feat_bound_body')); ?></p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon blue">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="12" r="3"/><circle cx="18" cy="12" r="3"/><line x1="9" y1="12" x2="15" y2="12"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.feat_prov_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.feat_prov_body')); ?></p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon green">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7L9 18l-5-5"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.feat_autosave_title')); ?></h4>
                            <p><?php echo t('network-mapper.help.feat_autosave_body', ['ctrl' => '<span class="nh-kbd">Ctrl</span>', 's' => '<span class="nh-kbd">S</span>']); ?></p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon amber">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.feat_history_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.feat_history_body')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 2. Creating -->
                <div class="nh-section" id="creating">
                    <div class="nh-section-header">
                        <span class="nh-section-num highlight">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.creating_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.creating_body'); ?></p>
                        </div>
                    </div>
                    <p class="nh-tip"><?php echo t('network-mapper.help.creating_tip'); ?></p>
                </div>

                <!-- 3. Placing nodes -->
                <div class="nh-section" id="placing">
                    <div class="nh-section-header">
                        <span class="nh-section-num">3</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.placing_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.placing_body')); ?></p>
                        </div>
                    </div>

                    <div class="nh-steps">
                        <div class="nh-step-item"><span class="nh-step-num">1</span><div><?php echo htmlspecialchars(t('network-mapper.help.placing_step1')); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">2</span><div><?php echo htmlspecialchars(t('network-mapper.help.placing_step2')); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">3</span><div><?php echo htmlspecialchars(t('network-mapper.help.placing_step3')); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">4</span><div><?php echo t('network-mapper.help.placing_step4', ['del' => '<span class="nh-kbd">Delete</span>']); ?></div></div>
                    </div>

                    <p class="nh-tip"><?php echo t('network-mapper.help.placing_tip1'); ?></p>
                    <p class="nh-tip"><?php echo t('network-mapper.help.placing_tip2'); ?></p>
                </div>

                <!-- 4. Connectors -->
                <div class="nh-section" id="connectors">
                    <div class="nh-section-header">
                        <span class="nh-section-num">4</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.connectors_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.connectors_body')); ?></p>
                        </div>
                    </div>

                    <div class="nh-steps">
                        <div class="nh-step-item"><span class="nh-step-num">1</span><div><?php echo t('network-mapper.help.connectors_step1'); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">2</span><div><?php echo t('network-mapper.help.connectors_step2'); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">3</span><div><?php echo t('network-mapper.help.connectors_step3'); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">4</span><div><?php echo t('network-mapper.help.connectors_step4', ['del' => '<span class="nh-kbd">Delete</span>']); ?></div></div>
                    </div>

                    <p class="nh-tip"><?php echo t('network-mapper.help.connectors_tip'); ?></p>
                </div>

                <!-- 5. Add related objects -->
                <div class="nh-section nh-section-highlight" id="related">
                    <div class="nh-section-header">
                        <span class="nh-section-num highlight">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.related_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.related_body'); ?></p>
                        </div>
                    </div>

                    <div class="nh-features-grid" style="margin-top: 14px;">
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon cyan">&rarr;</div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.related_out_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.related_out_body')); ?></p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon blue">&larr;</div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.related_in_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.related_in_body')); ?></p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon green">&loz;</div>
                            <h4><?php echo htmlspecialchars(t('network-mapper.help.related_ref_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.related_ref_body')); ?></p>
                        </div>
                    </div>

                    <p style="margin-top: 14px;"><?php echo t('network-mapper.help.related_commit'); ?></p>
                    <p class="nh-tip"><?php echo t('network-mapper.help.related_tip1'); ?></p>
                    <p class="nh-tip"><?php echo t('network-mapper.help.related_tip2'); ?></p>
                </div>

                <!-- 6. Planned objects -->
                <div class="nh-section" id="planned">
                    <div class="nh-section-header">
                        <span class="nh-section-num">6</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.planned_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.planned_body_before')); ?><span class="nh-pill planned"><?php echo htmlspecialchars(t('network-mapper.help.planned_pill')); ?></span><?php echo htmlspecialchars(t('network-mapper.help.planned_body_after')); ?></p>
                        </div>
                    </div>
                    <p class="nh-tip"><?php echo t('network-mapper.help.planned_tip'); ?></p>
                </div>

                <!-- 7. Page size guide -->
                <div class="nh-section" id="paper">
                    <div class="nh-section-header">
                        <span class="nh-section-num">7</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.paper_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.paper_body'); ?></p>
                        </div>
                    </div>
                    <p class="nh-tip"><?php echo t('network-mapper.help.paper_tip1'); ?></p>
                    <p class="nh-tip"><?php echo t('network-mapper.help.paper_tip2'); ?></p>
                </div>

                <!-- 8. Header &amp; footer -->
                <div class="nh-section" id="branding">
                    <div class="nh-section-header">
                        <span class="nh-section-num">8</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.branding_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.branding_body')); ?></p>
                        </div>
                    </div>
                    <div class="nh-steps">
                        <div class="nh-step-item"><span class="nh-step-num">1</span><div><?php echo t('network-mapper.help.branding_step1'); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">2</span><div><?php echo t('network-mapper.help.branding_step2'); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">3</span><div><?php echo t('network-mapper.help.branding_step3'); ?></div></div>
                    </div>
                    <p class="nh-tip"><?php echo t('network-mapper.help.branding_tip1'); ?></p>
                    <p class="nh-tip"><?php echo t('network-mapper.help.branding_tip2'); ?></p>
                    <p class="nh-tip"><?php echo t('network-mapper.help.branding_tip3'); ?></p>
                </div>

                <!-- 9. Versioning -->
                <div class="nh-section" id="versioning">
                    <div class="nh-section-header">
                        <span class="nh-section-num">9</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.versioning_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('network-mapper.help.versioning_body_before')); ?><span class="nh-pill current"><?php echo htmlspecialchars(t('network-mapper.help.versioning_pill_current')); ?></span><?php echo htmlspecialchars(t('network-mapper.help.versioning_body_mid')); ?><span class="nh-pill readonly"><?php echo htmlspecialchars(t('network-mapper.help.versioning_pill_readonly')); ?></span><?php echo htmlspecialchars(t('network-mapper.help.versioning_body_after')); ?></p>
                        </div>
                    </div>
                    <div class="nh-steps">
                        <div class="nh-step-item"><span class="nh-step-num">1</span><div><?php echo htmlspecialchars(t('network-mapper.help.versioning_step1')); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">2</span><div><?php echo t('network-mapper.help.versioning_step2'); ?></div></div>
                        <div class="nh-step-item"><span class="nh-step-num">3</span><div><?php echo htmlspecialchars(t('network-mapper.help.versioning_step3')); ?></div></div>
                    </div>
                    <p class="nh-warn"><?php echo t('network-mapper.help.versioning_warn'); ?></p>
                </div>

                <!-- 10. Saving -->
                <div class="nh-section" id="saving">
                    <div class="nh-section-header">
                        <span class="nh-section-num">10</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.saving_title')); ?></h3>
                            <p><?php echo t('network-mapper.help.saving_body', ['ctrl' => '<span class="nh-kbd">Ctrl</span>', 's' => '<span class="nh-kbd">S</span>']); ?></p>
                        </div>
                    </div>
                    <p class="nh-tip"><?php echo t('network-mapper.help.saving_tip'); ?></p>
                    <p class="nh-warn"><?php echo t('network-mapper.help.saving_warn'); ?></p>
                </div>

                <!-- 11. Quick tips -->
                <div class="nh-section" id="tips">
                    <div class="nh-section-header">
                        <span class="nh-section-num">11</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('network-mapper.help.tips_title')); ?></h3>
                        </div>
                    </div>
                    <div class="nh-tips-grid">
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#8984;</span><div><?php echo t('network-mapper.help.tip_ctrls'); ?></div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x2316;</span><div><?php echo t('network-mapper.help.tip_esc'); ?></div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x2715;</span><div><?php echo htmlspecialchars(t('network-mapper.help.tip_deselect')); ?></div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x21BB;</span><div><?php echo htmlspecialchars(t('network-mapper.help.tip_track')); ?></div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x2713;</span><div><?php echo htmlspecialchars(t('network-mapper.help.tip_dedupe')); ?></div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x21AA;</span><div><?php echo htmlspecialchars(t('network-mapper.help.tip_cmdblink')); ?></div></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as the user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.nh-nav-link');
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
