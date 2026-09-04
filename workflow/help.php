<?php
/**
 * Workflows — Help guide.
 *
 * Full coverage: anatomy of a workflow, the visual canvas builder, condition
 * details (lookups, multi-select, operator-per-type filtering), the eight
 * action handlers, variable substitution, the AI co-author, Test fire, and
 * what's still ahead.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) { header('Location: ../auth/login.php'); exit; }

requireModuleAccess('workflow');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=11">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--wf-accent);
            --accent-hover: var(--wf-accent-hover);
            --accent-soft:  var(--wf-accent-soft);
            --on-accent:    var(--wf-on-accent);
        }
    </style>
    <!-- Mobile layer LAST, after this page's own stylesheet AND its inline
         <style> block, or a rule at equal specificity loses on document order
         (Techniques §9). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=131">
</head>
<body data-mobile-module="workflow" data-mobile-page="wf-help">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <aside class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('workflow.help.guide')); ?></h3>
            <a href="#templates" class="help-nav-link" data-section="templates">
                <span class="help-nav-num">&#9733;</span> <?php echo htmlspecialchars(t('workflow.help.nav_templates')); ?>
            </a>
            <a href="#anatomy" class="help-nav-link active" data-section="anatomy">
                <span class="help-nav-num">1</span> <?php echo htmlspecialchars(t('workflow.help.nav_anatomy')); ?>
            </a>
            <a href="#canvas" class="help-nav-link" data-section="canvas">
                <span class="help-nav-num">2</span> <?php echo htmlspecialchars(t('workflow.help.nav_canvas')); ?>
            </a>
            <a href="#conditions" class="help-nav-link" data-section="conditions">
                <span class="help-nav-num">3</span> <?php echo htmlspecialchars(t('workflow.help.nav_conditions')); ?>
            </a>
            <a href="#actions" class="help-nav-link" data-section="actions">
                <span class="help-nav-num">4</span> <?php echo htmlspecialchars(t('workflow.help.nav_actions')); ?>
            </a>
            <a href="#variables" class="help-nav-link" data-section="variables">
                <span class="help-nav-num">5</span> <?php echo htmlspecialchars(t('workflow.help.nav_variables')); ?>
            </a>
            <a href="#ai" class="help-nav-link" data-section="ai">
                <span class="help-nav-num">6</span> <?php echo htmlspecialchars(t('workflow.help.nav_ai')); ?>
            </a>
            <a href="#testing" class="help-nav-link" data-section="testing">
                <span class="help-nav-num">7</span> <?php echo htmlspecialchars(t('workflow.help.nav_testing')); ?>
            </a>
            <a href="#triggers" class="help-nav-link" data-section="triggers">
                <span class="help-nav-num">8</span> <?php echo htmlspecialchars(t('workflow.help.nav_triggers')); ?>
            </a>
            <a href="#failures" class="help-nav-link" data-section="failures">
                <span class="help-nav-num">9</span> <?php echo htmlspecialchars(t('workflow.help.nav_failures')); ?>
            </a>
            <a href="#ahead" class="help-nav-link" data-section="ahead">
                <span class="help-nav-num">10</span> <?php echo htmlspecialchars(t('workflow.help.nav_ahead')); ?>
            </a>
            <a href="help-webhooks.php" class="help-nav-link" style="margin-top:10px; border-top:1px solid var(--border-soft, #eee); padding-top:14px; color:var(--warning-text, #b45309);">
                <span class="help-nav-num">&#128279;</span> <?php echo htmlspecialchars(t('workflow.help.nav_webhooks_deepdive')); ?> &rarr;
            </a>
            <a href="help-ssl.php" class="help-nav-link">
                <span class="help-nav-num">&#128274;</span> <?php echo htmlspecialchars(t('workflow.help.nav_ssl')); ?> &rarr;
            </a>
            <!-- The wiki carries the design reasoning this page deliberately
                 leaves out (why the fire-once ledger exists, what it cost). -->
            <a href="https://github.com/edmozley/freeitsm/wiki/Workflows" target="_blank" rel="noopener noreferrer"
               class="help-nav-link" style="margin-top:10px; border-top:1px solid var(--border-soft, #eee); padding-top:14px;">
                <span class="help-nav-num">&#128214;</span> <?php echo htmlspecialchars(t('workflow.help.nav_wiki')); ?> &#8599;
            </a>
        </aside>

        <main class="help-main">
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('workflow.help.page_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('workflow.help.intro')); ?></p>
            </div>
            <div class="help-content">
            <div class="help-section" id="templates">
                <div class="help-section-header">
                    <span class="help-section-num">&#9733;</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.templates_heading')); ?></h3></div>
                </div>
            <p><?php echo t('workflow.help.templates_intro'); ?></p>
            <p><?php echo t('workflow.help.templates_lookups'); ?></p>
            <div class="help-note"><?php echo t('workflow.help.templates_scheduled'); ?></div>
            <div class="help-note"><?php echo t('workflow.help.templates_callout'); ?></div>

            </div>

            <div class="help-section" id="anatomy">
                <div class="help-section-header">
                    <span class="help-section-num">1</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.anatomy_heading')); ?></h3></div>
                </div>
            <p><?php echo htmlspecialchars(t('workflow.help.anatomy_intro')); ?></p>
            <ul>
                <li><?php echo t('workflow.help.anatomy_trigger'); ?></li>
                <li><?php echo t('workflow.help.anatomy_conditions'); ?></li>
                <li><?php echo t('workflow.help.anatomy_actions'); ?></li>
            </ul>
            <p><?php echo t('workflow.help.anatomy_exec'); ?></p>

            </div>

            <div class="help-section" id="canvas">
                <div class="help-section-header">
                    <span class="help-section-num">2</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.canvas_heading')); ?></h3></div>
                </div>
            <p><?php echo htmlspecialchars(t('workflow.help.canvas_intro')); ?></p>
            <ul>
                <li><?php echo t('workflow.help.canvas_trigger'); ?></li>
                <li><?php echo t('workflow.help.canvas_condition'); ?></li>
                <li><?php echo t('workflow.help.canvas_action'); ?></li>
            </ul>
            <p><?php echo t('workflow.help.canvas_order'); ?></p>
            <p><?php echo t('workflow.help.canvas_panel'); ?></p>

            </div>

            <div class="help-section" id="conditions">
                <div class="help-section-header">
                    <span class="help-section-num">3</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.conditions_heading')); ?></h3></div>
                </div>
            <p><?php echo t('workflow.help.conditions_intro'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.conditions_lookup_heading')); ?></h4>
            <p><?php echo t('workflow.help.conditions_lookup_body'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.conditions_text_heading')); ?></h4>
            <p><?php echo t('workflow.help.conditions_text_body'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.conditions_num_heading')); ?></h4>
            <p><?php echo t('workflow.help.conditions_num_body'); ?></p>

            </div>

            <div class="help-section" id="actions">
                <div class="help-section-header">
                    <span class="help-section-num">4</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.actions_heading')); ?></h3></div>
                </div>
            <p><?php echo t('workflow.help.actions_intro'); ?></p>
            <div class="help-table">
            <table>
                <tr><th><?php echo htmlspecialchars(t('workflow.help.actions_th_type')); ?></th><th><?php echo htmlspecialchars(t('workflow.help.actions_th_does')); ?></th><th><?php echo htmlspecialchars(t('workflow.help.actions_th_args')); ?></th></tr>
                <tr><td><code>log_message</code></td><td><?php echo t('workflow.help.actions_row1_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row1_args')); ?></td></tr>
                <tr><td><code>set_ticket_status</code></td><td><?php echo t('workflow.help.actions_row2_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row2_args')); ?></td></tr>
                <tr><td><code>set_ticket_priority</code></td><td><?php echo t('workflow.help.actions_row3_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row3_args')); ?></td></tr>
                <tr><td><code>assign_ticket</code></td><td><?php echo t('workflow.help.actions_row4_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row4_args')); ?></td></tr>
                <tr><td><code>add_ticket_note</code></td><td><?php echo t('workflow.help.actions_row5_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row5_args')); ?></td></tr>
                <tr><td><code>send_email</code></td><td><?php echo t('workflow.help.actions_row6_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row6_args')); ?></td></tr>
                <tr><td><code>create_task</code></td><td><?php echo t('workflow.help.actions_row7_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row7_args')); ?></td></tr>
                <tr><td><code>create_ticket</code></td><td><?php echo t('workflow.help.actions_row8_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row8_args')); ?></td></tr>
                <tr><td><code>send_webhook</code></td><td><?php echo t('workflow.help.actions_row9_does'); ?></td><td><?php echo htmlspecialchars(t('workflow.help.actions_row9_args')); ?></td></tr>
            </table>
            </div>
            <p><?php echo t('workflow.help.actions_note'); ?></p>
            <div class="help-note"><?php echo t('workflow.help.actions_webhook_callout'); ?></div>

            </div>

            <div class="help-section" id="variables">
                <div class="help-section-header">
                    <span class="help-section-num">5</span>
                    <div><h3><?php echo t('workflow.help.variables_heading'); ?></h3></div>
                </div>
            <p><?php echo t('workflow.help.variables_intro'); ?></p>
            <div class="help-note"><?php echo t('workflow.help.variables_scoped'); ?></div>
            <div class="help-note"><?php echo t('workflow.help.variables_names'); ?></div>
            <div class="help-note"><?php echo t('workflow.help.variables_number'); ?></div>
            <p><?php echo t('workflow.help.variables_full'); ?></p>
            <p><?php echo htmlspecialchars(t('workflow.help.variables_common')); ?></p>
            <ul>
                <li><?php echo t('workflow.help.variables_li1'); ?></li>
                <li><?php echo t('workflow.help.variables_li2'); ?></li>
                <li><?php echo t('workflow.help.variables_li3'); ?></li>
                <li><?php echo t('workflow.help.variables_li4'); ?></li>
                <li><?php echo t('workflow.help.variables_li5'); ?></li>
            </ul>
            <div class="help-note"><?php echo t('workflow.help.variables_tip'); ?></div>

            </div>

            <div class="help-section" id="ai">
                <div class="help-section-header">
                    <span class="help-section-num">6</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.ai_heading')); ?></h3></div>
                </div>
            <p><?php echo t('workflow.help.ai_intro'); ?></p>
            <p><?php echo htmlspecialchars(t('workflow.help.ai_examples')); ?></p>
            <ul>
                <li><?php echo t('workflow.help.ai_ex1'); ?></li>
                <li><?php echo t('workflow.help.ai_ex2'); ?></li>
                <li><?php echo t('workflow.help.ai_ex3'); ?></li>
            </ul>
            <p><?php echo t('workflow.help.ai_catalogue'); ?></p>
            <p><?php echo t('workflow.help.ai_config'); ?></p>

            </div>

            <div class="help-section" id="testing">
                <div class="help-section-header">
                    <span class="help-section-num">7</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.testing_heading')); ?></h3></div>
                </div>
            <p><?php echo t('workflow.help.testing_save'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.testing_dry_heading')); ?></h4>
            <p><?php echo t('workflow.help.testing_dry_body'); ?></p>
            <p><?php echo t('workflow.help.testing_dry_safe'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.testing_fire_heading')); ?></h4>
            <p><?php echo t('workflow.help.testing_fire'); ?></p>
            <p><?php echo t('workflow.help.testing_real'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.testing_log_heading')); ?></h4>
            <p><?php echo t('workflow.help.testing_log_body'); ?></p>
            <div class="help-note"><?php echo t('workflow.help.testing_log_why'); ?></div>

            </div>

            <div class="help-section" id="triggers">
                <div class="help-section-header">
                    <span class="help-section-num">8</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.triggers_heading')); ?></h3></div>
                </div>
            <p><?php echo t('workflow.help.triggers_intro'); ?></p>
            <ul>
                <li><?php echo t('workflow.help.triggers_family_domain'); ?></li>
                <li><?php echo t('workflow.help.triggers_family_crud'); ?></li>
            </ul>
            <p><?php echo t('workflow.help.triggers_picker'); ?></p>

            <h4><?php echo htmlspecialchars(t('workflow.help.triggers_time_heading')); ?></h4>
            <p><?php echo t('workflow.help.triggers_time_body'); ?></p>
            <div class="help-note"><?php echo t('workflow.help.triggers_time_cron'); ?></div>
            <p><?php echo t('workflow.help.triggers_time_once'); ?></p>

            <div class="help-note"><?php echo t('workflow.help.actions_webhook_callout'); ?></div>

            </div>

            <div class="help-section" id="failures">
                <div class="help-section-header">
                    <span class="help-section-num">9</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.failures_heading')); ?></h3></div>
                </div>
            <div class="help-note"><?php echo t('workflow.help.failures_callout'); ?></div>

            </div>

            <div class="help-section" id="ahead">
                <div class="help-section-header">
                    <span class="help-section-num">10</span>
                    <div><h3><?php echo htmlspecialchars(t('workflow.help.ahead_heading')); ?></h3></div>
                </div>
            <ul>
                <li><?php echo t('workflow.help.ahead_li1'); ?></li>
                <li><?php echo t('workflow.help.ahead_li4'); ?></li>
                <li><?php echo t('workflow.help.ahead_li5'); ?></li>
                <li><?php echo t('workflow.help.ahead_li6'); ?></li>
                <li><?php echo t('workflow.help.ahead_li7'); ?></li>
                <li><?php echo t('workflow.help.ahead_li8'); ?></li>
            </ul>
            </div>
            </div>
        </main>
    </div>

    <script>
    // Scroll-spy: highlight the active sidebar link as the user scrolls.
    (function () {
        const helpMain = document.querySelector('.help-main');
        // [data-section] ONLY — the sidebar also holds real page links (the
        // Webhooks and HTTPS-certificates deep-dives). Selecting every
        // .help-nav-link meant the click handler below preventDefault()'d those
        // too, then tried to scroll to getElementById(undefined) — so they
        // silently did nothing. Same selector help-webhooks.php already uses.
        const navLinks = document.querySelectorAll('.help-nav-link[data-section]');
        const sections = [];

        navLinks.forEach(link => {
            const id = link.dataset.section;
            const el = document.getElementById(id);
            if (el) sections.push({ id, el });
        });

        helpMain.addEventListener('scroll', function () {
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
    })();
    </script>
    <script src="../assets/js/mobile.js?v=54"></script>
</body>
</html>
