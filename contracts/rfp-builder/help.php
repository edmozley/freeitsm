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
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.rfp.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--con-accent);
            --accent-hover: var(--con-accent-hover);
            --accent-soft:  var(--con-accent-soft);
            --on-accent:    var(--con-on-accent);
        }

        /* This guide sits one level down inside Contracts, so it keeps a
           breadcrumb above the hero. Everything below the breadcrumb is the
           ordinary house style. */
        .rfp-breadcrumb { font-size: 13px; color: var(--text-dim, #888); padding: 14px 32px 0; }
        .rfp-breadcrumb a { color: var(--text-muted, #666); text-decoration: none; }
        .rfp-breadcrumb a:hover { color: var(--accent); }
        .rfp-breadcrumb span.sep { margin: 0 6px; color: var(--text-faint, #ccc); }

        /* The six RFP phases, shown as a strip rather than a chain: they happen
           in order but you can be in more than one at a time. */
        .rfp-workflow-strip {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 14px;
        }
        .rfp-workflow-strip .step {
            background: var(--surface-2, #fafbfc);
            border: 1px solid var(--border-soft, #eef0f2);
            border-radius: 6px;
            padding: 10px 12px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted, #374151);
        }
        .rfp-workflow-strip .step strong { display: block; color: var(--accent); font-size: 16px; margin-bottom: 4px; }

        .help-content table td.num { text-align: right; font-variant-numeric: tabular-nums; }

        @media (max-width: 900px) {
            .rfp-breadcrumb { padding: 12px 16px 0; }
            .rfp-workflow-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
    <!-- Mobile layer: linked AFTER this page's own <style> so its @media rules win on ties. -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=130">
</head>
<body data-mobile-module="contracts">
    <?php include '../includes/header.php'; ?>

    <div class="help-container">
            <nav class="help-sidebar">
                <h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_getting_started')); ?></h3>
                <a href="#overview" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_overview')); ?></a>
                <a href="#workflow" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_workflow')); ?></a>
                <a href="#cost" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_cost')); ?></a>

                <h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_phases')); ?></h3>
                <a href="#p1" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p1')); ?></a>
                <a href="#p2" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p2')); ?></a>
                <a href="#p3" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p3')); ?></a>
                <a href="#p4" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p4')); ?></a>
                <a href="#p5" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p5')); ?></a>
                <a href="#p6" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_p6')); ?></a>

                <h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_concepts')); ?></h3>
                <a href="#lock" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_lock')); ?></a>
                <a href="#multi-analyst" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_multi_analyst')); ?></a>
                <a href="#caching" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_caching')); ?></a>
                <a href="#audit" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_audit')); ?></a>

                <h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_reference')); ?></h3>
                <a href="#faq" class="help-nav-link"><?php echo htmlspecialchars(t('contracts.rfp.help.nav_faq')); ?></a>
            </nav>

            <main class="help-main">
                <div class="rfp-breadcrumb">
                    <a href="../"><?php echo htmlspecialchars(t('contracts.title')); ?></a><span class="sep">›</span>
                    <a href="./"><?php echo htmlspecialchars(t('contracts.nav.rfp_builder')); ?></a><span class="sep">›</span>
                    <span><?php echo htmlspecialchars(t('contracts.nav.help')); ?></span>
                </div>
                <div class="help-hero">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.help.heading')); ?></h2>
                </div>

                <div class="help-content">

                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_overview')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.overview_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.overview_p2'); ?></p>
                    <div class="help-note"><?php echo t('contracts.rfp.help.overview_tip'); ?></div>
                </div>

                <div class="help-section" id="workflow">
                    <div class="help-section-header">
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_workflow')); ?></h3></div>
                    </div>
                    <div class="rfp-workflow-strip">
                        <div class="step"><strong>1</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_source')); ?></div>
                        <div class="step"><strong>2</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_extract')); ?></div>
                        <div class="step"><strong>3</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_consolidate')); ?></div>
                        <div class="step"><strong>4</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_generate')); ?></div>
                        <div class="step"><strong>5</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_score')); ?></div>
                        <div class="step"><strong>6</strong><?php echo htmlspecialchars(t('contracts.rfp.help.wf_compare')); ?></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.workflow_p'); ?></p>
                </div>

                <div class="help-section" id="cost">
                    <div class="help-section-header">
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_cost')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.cost_intro'); ?></p>
                    <div class="help-table">
                    <table>
                        <thead><tr><th><?php echo htmlspecialchars(t('contracts.rfp.help.cost_col_pass')); ?></th><th><?php echo htmlspecialchars(t('contracts.rfp.help.cost_col_time')); ?></th><th class="num"><?php echo htmlspecialchars(t('contracts.rfp.help.cost_col_tokens')); ?></th><th class="num"><?php echo htmlspecialchars(t('contracts.rfp.help.cost_col_cost')); ?></th></tr></thead>
                        <tbody>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_pass1')); ?></td><td>30–60s</td><td class="num">~2k / 4k each</td><td class="num">£0.05–0.10 each</td></tr>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_pass2')); ?></td><td>60–180s</td><td class="num">~6k / 12k</td><td class="num">£0.20–0.40</td></tr>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_pass3')); ?></td><td>30–90s each</td><td class="num">~2k / 5k each</td><td class="num">£0.05–0.10 each</td></tr>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_pass4')); ?></td><td>15–40s</td><td class="num">~2k / 4k</td><td class="num">£0.04–0.08</td></tr>
                            <tr><td><?php echo htmlspecialchars(t('contracts.rfp.help.cost_framing')); ?></td><td>15–40s each</td><td class="num">~1.5k / 2k each</td><td class="num">£0.03–0.05 each</td></tr>
                        </tbody>
                    </table>
                    </div>
                    <p><?php echo t('contracts.rfp.help.cost_total'); ?></p>
                </div>

                <!-- Phases -->

                <div class="help-section" id="p1">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.p1_title')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.p1_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.p1_p2'); ?></p>
                    <div class="help-note"><?php echo t('contracts.rfp.help.p1_tip'); ?></div>
                </div>

                <div class="help-section" id="p2">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.p2_title')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.p2_p1'); ?></p>
                    <ul>
                        <li><?php echo t('contracts.rfp.help.p2_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p2_li2'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p2_li3'); ?></li>
                    </ul>
                    <p><?php echo t('contracts.rfp.help.p2_p2'); ?></p>
                </div>

                <div class="help-section" id="p3">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.p3_title')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.p3_p1'); ?></p>
                    <ul>
                        <li><?php echo t('contracts.rfp.help.p3_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p3_li2'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p3_li3'); ?></li>
                    </ul>
                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.p3_h_editing')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.p3_editing'); ?></p>
                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.p3_h_conflict')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.p3_conflict'); ?></p>
                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.p3_h_lock')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.p3_lock'); ?></p>
                </div>

                <div class="help-section" id="p4">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.p4_title')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.p4_p1'); ?></p>
                    <ul>
                        <li><?php echo t('contracts.rfp.help.p4_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p4_li2'); ?></li>
                    </ul>
                    <p><?php echo t('contracts.rfp.help.p4_p2'); ?></p>
                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.p4_h_context')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.p4_context'); ?></p>
                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.p4_h_preview')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.p4_preview'); ?></p>
                </div>

                <div class="help-section" id="p5">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.p5_title')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.p5_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.p5_p2'); ?></p>
                    <div class="help-note"><?php echo t('contracts.rfp.help.p5_tip'); ?></div>
                </div>

                <div class="help-section" id="p6">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.p6_title')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.p6_p1'); ?></p>
                    <ul>
                        <li><?php echo t('contracts.rfp.help.p6_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p6_li2'); ?></li>
                        <li><?php echo t('contracts.rfp.help.p6_li3'); ?></li>
                    </ul>
                </div>

                <!-- Concepts -->

                <div class="help-section" id="lock">
                    <div class="help-section-header">
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_lock')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.lock_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.lock_p2'); ?></p>
                    <div class="help-note warn"><?php echo t('contracts.rfp.help.lock_warn'); ?></div>
                </div>

                <div class="help-section" id="multi-analyst">
                    <div class="help-section-header">
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_multi_analyst')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.multi_p1'); ?></p>
                    <ol>
                        <li><?php echo t('contracts.rfp.help.multi_li1'); ?></li>
                        <li><?php echo t('contracts.rfp.help.multi_li2'); ?></li>
                        <li><?php echo t('contracts.rfp.help.multi_li3'); ?></li>
                    </ol>
                    <p><?php echo t('contracts.rfp.help.multi_p2'); ?></p>
                </div>

                <div class="help-section" id="caching">
                    <div class="help-section-header">
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_caching')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.caching_p1'); ?></p>
                    <p><?php echo t('contracts.rfp.help.caching_p2'); ?></p>
                    <div class="help-note"><?php echo t('contracts.rfp.help.caching_tip'); ?></div>
                </div>

                <div class="help-section" id="audit">
                    <div class="help-section-header">
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_audit')); ?></h3></div>
                    </div>
                    <p><?php echo t('contracts.rfp.help.audit_p'); ?></p>
                </div>

                <!-- FAQ -->

                <div class="help-section" id="faq">
                    <div class="help-section-header">
                        <div><h3><?php echo htmlspecialchars(t('contracts.rfp.help.nav_faq')); ?></h3></div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q1')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.faq_a1'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q2')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.faq_a2'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q3')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.faq_a3'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q4')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.faq_a4'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q5')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.faq_a5'); ?></p>

                    <h4><?php echo htmlspecialchars(t('contracts.rfp.help.faq_q6')); ?></h4>
                    <p><?php echo t('contracts.rfp.help.faq_a6'); ?></p>
                </div>

                </div>
            </main>
    </div>
    <script src="../../assets/js/mobile.js?v=53"></script>
</body>
</html>
