<?php
/**
 * RFP Builder — analyst help page (Phase 6 step 6b).
 *
 * In-app guide covering the six-phase workflow, key concepts (lock
 * gate, multi-analyst scoring, hash-skip, prompt caching), and the
 * cost / time expectations for each AI pass. Static — written for
 * FreeITSM's actual implementation, not lifted from the prototype.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('contracts');

$current_page = 'rfp-builder';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.rfp.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { --accent: var(--con-accent, #f59e0b); }

        .page-wrap { padding: 30px 40px; background: var(--app-bg, #f5f5f5); height: calc(100vh - 48px); overflow-y: auto; box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: var(--text-dim, #888); margin-bottom: 8px; }
        .breadcrumb a { color: var(--text-muted, #666); text-decoration: none; }
        .breadcrumb a:hover { color: var(--con-accent, #f59e0b); }
        .breadcrumb span.sep { margin: 0 6px; color: var(--text-faint, #ccc); }

        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: var(--text, #222); }
        .page-header { margin-bottom: 20px; }

        .help-layout {
            display: grid; grid-template-columns: 220px 1fr;
            gap: 24px;
        }
        .help-sidebar {
            background: var(--surface, #fff); border-radius: 10px; padding: 12px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            position: sticky; top: 16px; align-self: start;
            max-height: calc(100vh - 80px); overflow-y: auto;
        }
        .help-sidebar .sidebar-title {
            font-size: 11px; color: var(--text-dim, #999); text-transform: uppercase;
            letter-spacing: 0.5px; padding: 12px 16px 6px; font-weight: 600;
        }
        .help-sidebar a {
            display: block; padding: 7px 16px; font-size: 13px;
            color: var(--text-muted, #555); text-decoration: none;
            border-left: 3px solid transparent;
        }
        .help-sidebar a:hover { color: var(--con-accent, #f59e0b); background: #fffbeb; }

        .help-main { display: flex; flex-direction: column; gap: 18px; }
        .help-card {
            background: var(--surface, #fff); border-radius: 10px; padding: 22px 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            scroll-margin-top: 16px;
        }
        .help-card h2 {
            margin: 0 0 12px 0; font-size: 18px; color: var(--text, #222);
            display: flex; align-items: center; gap: 12px;
        }
        .help-card h2 .step-num {
            background: var(--con-accent, #f59e0b); color: #fff;
            width: 28px; height: 28px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700; flex-shrink: 0;
        }
        .help-card h3 {
            font-size: 14px; color: var(--con-accent-hover, #92400e); margin: 16px 0 6px 0;
            font-weight: 700;
        }
        .help-card p, .help-card li {
            font-size: 14px; line-height: 1.65; color: var(--text-muted, #444);
        }
        .help-card p { margin: 0 0 10px 0; }
        .help-card ul, .help-card ol { margin: 8px 0 14px 22px; }
        .help-card li { margin-bottom: 4px; }
        .help-card code {
            background: var(--surface-hover, #f3f4f6); padding: 1px 5px; border-radius: 3px;
            font-size: 13px; color: var(--text, #1f2937);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .help-card .tip {
            background: var(--success-bg, #ecfdf5); border-left: 4px solid var(--success-accent, #10b981);
            padding: 10px 14px; border-radius: 4px;
            margin: 12px 0; font-size: 13px; color: var(--success-text, #065f46);
        }
        .help-card .warn {
            background: var(--warning-bg, #fff7ed); border-left: 4px solid var(--warning-border, #f59e0b);
            padding: 10px 14px; border-radius: 4px;
            margin: 12px 0; font-size: 13px; color: var(--warning-text, #92400e);
        }
        .help-card .gotcha {
            background: var(--danger-bg, #fef2f2); border-left: 4px solid var(--danger-accent, #ef4444);
            padding: 10px 14px; border-radius: 4px;
            margin: 12px 0; font-size: 13px; color: var(--danger-text, #991b1b);
        }

        .workflow-strip {
            display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px;
            margin: 16px 0;
        }
        .workflow-strip .step {
            background: var(--surface-2, #fafbfc); border: 1px solid var(--border-soft, #eef0f2); border-radius: 6px;
            padding: 10px 12px; text-align: center;
            font-size: 12px; color: var(--text-muted, #374151);
        }
        .workflow-strip .step strong {
            display: block; color: var(--con-accent, #f59e0b); font-size: 16px; margin-bottom: 4px;
        }

        .cost-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 8px 0; }
        .cost-table th, .cost-table td { padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #f0f0f0); text-align: left; }
        .cost-table th { background: var(--surface-2, #fafbfc); color: var(--text-muted, #555); font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }
        .cost-table tbody tr:last-child td { border-bottom: none; }
        .cost-table .num { text-align: right; font-variant-numeric: tabular-nums; }

        @media (max-width: 900px) {
            .help-layout { grid-template-columns: 1fr; }
            .help-sidebar { position: static; max-height: none; }
        }

        /* Off-token pale amber sidebar-hover tint — dark override keeps light mode identical */
        [data-theme-mode="dark"] .help-sidebar a:hover { background: #3a2e12; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../"><?php echo htmlspecialchars(t('contracts.title')); ?></a><span class="sep">›</span>
            <a href="./"><?php echo htmlspecialchars(t('contracts.nav.rfp_builder')); ?></a><span class="sep">›</span>
            <span><?php echo htmlspecialchars(t('contracts.nav.help')); ?></span>
        </div>
        <div class="page-header">
            <h1><?php echo htmlspecialchars(t('contracts.rfp.help.heading')); ?></h1>
        </div>

        <div class="help-layout">
            <nav class="help-sidebar">
                <div class="sidebar-title"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_getting_started')); ?></div>
                <a href="#overview"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_overview')); ?></a>
                <a href="#workflow"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_workflow')); ?></a>
                <a href="#cost"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_cost')); ?></a>

                <div class="sidebar-title"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_phases')); ?></div>
                <a href="#p1"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p1')); ?></a>
                <a href="#p2"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p2')); ?></a>
                <a href="#p3"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p3')); ?></a>
                <a href="#p4"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p4')); ?></a>
                <a href="#p5"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p5')); ?></a>
                <a href="#p6"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p6')); ?></a>

                <div class="sidebar-title"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_concepts')); ?></div>
                <a href="#lock"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_lock')); ?></a>
                <a href="#multi-analyst"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_multi_analyst')); ?></a>
                <a href="#caching"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_caching')); ?></a>
                <a href="#audit"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_audit')); ?></a>

                <div class="sidebar-title"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_reference')); ?></div>
                <a href="#faq"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_faq')); ?></a>
            </nav>

            <main class="help-main">

                <div class="help-card" id="overview">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.nav_overview')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.overview_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.overview_p2'); ?></p>
                    <div class="tip"><?php echo t('contracts.rfp.help.overview_tip'); ?></div>
                </div>

                <div class="help-card" id="workflow">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.nav_workflow')); ?></h2>
                    <div class="workflow-strip">
                        <div class="step"><strong>1</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_source')); ?></div>
                        <div class="step"><strong>2</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_extract')); ?></div>
                        <div class="step"><strong>3</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_consolidate')); ?></div>
                        <div class="step"><strong>4</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_generate')); ?></div>
                        <div class="step"><strong>5</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_score')); ?></div>
                        <div class="step"><strong>6</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_compare')); ?></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.workflow_p'); ?></p>
                </div>

                <div class="help-card" id="cost">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.nav_cost')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.cost_intro'); ?></p>
                    <table class="cost-table">
                        <thead><tr><th><?php echo htmlspecialchars(t('contracts.rfp.help.cost_col_pass')); ?></th><th><?php echo htmlspecialchars(t('contracts.rfp.help.cost_col_time')); ?></th><th class="num"><?php echo htmlspecialchars(t('contracts.rfp.help.cost_col_tokens')); ?></th><th class="num"><?php echo htmlspecialchars(t('contracts.rfp.help.cost_col_cost')); ?></th></tr></thead>
                        <tbody>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_pass1')); ?></td><td>30–60s</td><td class="num">~2k / 4k each</td><td class="num">£0.05–0.10 each</td></tr>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_pass2')); ?></td><td>60–180s</td><td class="num">~6k / 12k</td><td class="num">£0.20–0.40</td></tr>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_pass3')); ?></td><td>30–90s each</td><td class="num">~2k / 5k each</td><td class="num">£0.05–0.10 each</td></tr>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_pass4')); ?></td><td>15–40s</td><td class="num">~2k / 4k</td><td class="num">£0.04–0.08</td></tr>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_framing')); ?></td><td>15–40s each</td><td class="num">~1.5k / 2k each</td><td class="num">£0.03–0.05 each</td></tr>
                        </tbody>
                    </table>
                    <p><?php echo t('contracts.rfp.help.cost_total'); ?></p>
                </div>

                <!-- Phases -->

                <div class="help-card" id="p1">
                    <h2><span class="step-num">1</span><?php echo htmlspecialchars(t('contracts.rfp.help.p1_title')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.p1_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.p1_p2'); ?></p>
                    <div class="tip"><?php echo t('contracts.rfp.help.p1_tip'); ?></div>
                </div>

                <div class="help-card" id="p2">
                    <h2><span class="step-num">2</span><?php echo htmlspecialchars(t('contracts.rfp.help.p2_title')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.p2_p1'); ?></p>
                    <ul>
                        <li><?php echo t('contracts.rfp.help.p2_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p2_li2'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p2_li3'); ?></li>
                    </ul>
                    <p><?php echo t('contracts.rfp.help.p2_p2'); ?></p>
                </div>

                <div class="help-card" id="p3">
                    <h2><span class="step-num">3</span><?php echo htmlspecialchars(t('contracts.rfp.help.p3_title')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.p3_p1'); ?></p>
                    <ul>
                        <li><?php echo t('contracts.rfp.help.p3_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p3_li2'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p3_li3'); ?></li>
                    </ul>
                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.p3_h_editing')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.p3_editing'); ?></p>
                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.p3_h_conflict')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.p3_conflict'); ?></p>
                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.p3_h_lock')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.p3_lock'); ?></p>
                </div>

                <div class="help-card" id="p4">
                    <h2><span class="step-num">4</span><?php echo htmlspecialchars(t('contracts.rfp.help.p4_title')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.p4_p1'); ?></p>
                    <ul>
                        <li><?php echo t('contracts.rfp.help.p4_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p4_li2'); ?></li>
                    </ul>
                    <p><?php echo t('contracts.rfp.help.p4_p2'); ?></p>
                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.p4_h_context')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.p4_context'); ?></p>
                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.p4_h_preview')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.p4_preview'); ?></p>
                </div>

                <div class="help-card" id="p5">
                    <h2><span class="step-num">5</span><?php echo htmlspecialchars(t('contracts.rfp.help.p5_title')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.p5_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.p5_p2'); ?></p>
                    <div class="tip"><?php echo t('contracts.rfp.help.p5_tip'); ?></div>
                </div>

                <div class="help-card" id="p6">
                    <h2><span class="step-num">6</span><?php echo htmlspecialchars(t('contracts.rfp.help.p6_title')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.p6_p1'); ?></p>
                    <ul>
                        <li><?php echo t('contracts.rfp.help.p6_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p6_li2'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p6_li3'); ?></li>
                    </ul>
                </div>

                <!-- Concepts -->

                <div class="help-card" id="lock">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.nav_lock')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.lock_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.lock_p2'); ?></p>
                    <div class="warn"><?php echo t('contracts.rfp.help.lock_warn'); ?></div>
                </div>

                <div class="help-card" id="multi-analyst">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.nav_multi_analyst')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.multi_p1'); ?></p>
                    <ol>
                        <li><?php echo t('contracts.rfp.help.multi_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.multi_li2'); ?></li>
                        <li><?php echo t('contracts.rfp.help.multi_li3'); ?></li>
                    </ol>
                    <p><?php echo t('contracts.rfp.help.multi_p2'); ?></p>
                </div>

                <div class="help-card" id="caching">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.nav_caching')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.caching_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.caching_p2'); ?></p>
                    <div class="tip"><?php echo t('contracts.rfp.help.caching_tip'); ?></div>
                </div>

                <div class="help-card" id="audit">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.nav_audit')); ?></h2>
                    <p><?php echo t('contracts.rfp.help.audit_p'); ?></p>
                </div>

                <!-- FAQ -->

                <div class="help-card" id="faq">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.nav_faq')); ?></h2>

                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q1')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.faq_a1'); ?></p>

                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q2')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.faq_a2'); ?></p>

                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q3')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.faq_a3'); ?></p>

                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q4')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.faq_a4'); ?></p>

                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q5')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.faq_a5'); ?></p>

                    <h3><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q6')); ?></h3>
                    <p><?php echo t('contracts.rfp.help.faq_a6'); ?></p>
                </div>

            </main>
        </div>
    </div>
</body>
</html>
