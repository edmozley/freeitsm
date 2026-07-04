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
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) { header('Location: ../login.php'); exit; }

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <style>
        /* ---- Layout: sidebar + scrolling main, same shape as other modules' help pages ---- */
        .wfh-container { display: flex; height: calc(100vh - 48px); background: #f5f5f5; }

        .wfh-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
        }
        .wfh-sidebar h3 {
            font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .wfh-nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 13px; color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .wfh-nav-link:hover { background: #f5f5f5; color: #333; }
        .wfh-nav-link.active { background: #fff7ed; color: #b45309; font-weight: 600; }
        .wfh-nav-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px;
            border-radius: 50%;
            background: #f5f5f5; color: #888;
            font-size: 11px; font-weight: 700;
        }
        .wfh-nav-link.active .wfh-nav-num { background: #f59e0b; color: white; }

        .wfh-main { flex: 1; overflow-y: auto; padding: 24px 32px 60px; }

        /* ---- Per-section content styling (preserved from the original page) ---- */
        .wf-help h2 { font-size: 22px; color: #333; margin: 0 0 4px; }
        .wf-help p { color: #555; line-height: 1.6; }
        .wf-help .lede { font-size: 15px; color: #444; }
        .wf-help h3 { margin: 32px 0 10px; font-size: 16px; color: #333; padding-bottom: 6px; border-bottom: 1px solid #eee; scroll-margin-top: 20px; }
        .wf-help h3:first-of-type { margin-top: 22px; }
        .wf-help h4 { margin: 22px 0 6px; font-size: 14px; color: #444; }
        .wf-help ul, .wf-help ol { color: #555; line-height: 1.7; padding-left: 22px; }
        .wf-help li { margin-bottom: 6px; }
        .wf-help code { background: #f4f4f4; padding: 1px 6px; border-radius: 3px; font-size: 12.5px; color: #b45309; }
        .wf-help table { border-collapse: collapse; width: 100%; margin: 14px 0; font-size: 13px; }
        .wf-help table th, .wf-help table td { border: 1px solid #e5e7eb; padding: 8px 10px; text-align: left; vertical-align: top; }
        .wf-help table th { background: #f9fafb; font-weight: 600; color: #374151; }
        .wf-help .callout { background: #fff7ed; border-left: 3px solid #f59e0b; padding: 10px 14px; margin: 14px 0; border-radius: 4px; font-size: 13.5px; color: #92400e; }
        .wf-help .callout strong { color: #78350f; }
        .wf-help .tip { background: #f0f9ff; border-left: 3px solid #0ea5e9; padding: 10px 14px; margin: 14px 0; border-radius: 4px; font-size: 13.5px; color: #075985; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wfh-container">
        <aside class="wfh-sidebar">
            <h3><?php echo htmlspecialchars(t('workflow.help.guide')); ?></h3>
            <a href="#anatomy" class="wfh-nav-link active" data-section="anatomy">
                <span class="wfh-nav-num">1</span> <?php echo htmlspecialchars(t('workflow.help.nav_anatomy')); ?>
            </a>
            <a href="#canvas" class="wfh-nav-link" data-section="canvas">
                <span class="wfh-nav-num">2</span> <?php echo htmlspecialchars(t('workflow.help.nav_canvas')); ?>
            </a>
            <a href="#conditions" class="wfh-nav-link" data-section="conditions">
                <span class="wfh-nav-num">3</span> <?php echo htmlspecialchars(t('workflow.help.nav_conditions')); ?>
            </a>
            <a href="#actions" class="wfh-nav-link" data-section="actions">
                <span class="wfh-nav-num">4</span> <?php echo htmlspecialchars(t('workflow.help.nav_actions')); ?>
            </a>
            <a href="#variables" class="wfh-nav-link" data-section="variables">
                <span class="wfh-nav-num">5</span> <?php echo htmlspecialchars(t('workflow.help.nav_variables')); ?>
            </a>
            <a href="#ai" class="wfh-nav-link" data-section="ai">
                <span class="wfh-nav-num">6</span> <?php echo htmlspecialchars(t('workflow.help.nav_ai')); ?>
            </a>
            <a href="#testing" class="wfh-nav-link" data-section="testing">
                <span class="wfh-nav-num">7</span> <?php echo htmlspecialchars(t('workflow.help.nav_testing')); ?>
            </a>
            <a href="#triggers" class="wfh-nav-link" data-section="triggers">
                <span class="wfh-nav-num">8</span> <?php echo htmlspecialchars(t('workflow.help.nav_triggers')); ?>
            </a>
            <a href="#failures" class="wfh-nav-link" data-section="failures">
                <span class="wfh-nav-num">9</span> <?php echo htmlspecialchars(t('workflow.help.nav_failures')); ?>
            </a>
            <a href="#ahead" class="wfh-nav-link" data-section="ahead">
                <span class="wfh-nav-num">10</span> <?php echo htmlspecialchars(t('workflow.help.nav_ahead')); ?>
            </a>
        </aside>

        <main class="wfh-main">
            <div class="tab-content active wf-help">
            <h2><?php echo htmlspecialchars(t('workflow.help.page_title')); ?></h2>
            <p class="lede"><?php echo htmlspecialchars(t('workflow.help.intro')); ?></p>

            <h3 id="anatomy"><?php echo htmlspecialchars(t('workflow.help.anatomy_heading')); ?></h3>
            <p><?php echo htmlspecialchars(t('workflow.help.anatomy_intro')); ?></p>
            <ul>
                <li><?php echo t('workflow.help.anatomy_trigger'); ?></li>
                <li><?php echo t('workflow.help.anatomy_conditions'); ?></li>
                <li><?php echo t('workflow.help.anatomy_actions'); ?></li>
            </ul>
            <p><?php echo t('workflow.help.anatomy_exec'); ?></p>

            <h3 id="canvas"><?php echo htmlspecialchars(t('workflow.help.canvas_heading')); ?></h3>
            <p><?php echo htmlspecialchars(t('workflow.help.canvas_intro')); ?></p>
            <ul>
                <li><?php echo t('workflow.help.canvas_trigger'); ?></li>
                <li><?php echo t('workflow.help.canvas_condition'); ?></li>
                <li><?php echo t('workflow.help.canvas_action'); ?></li>
            </ul>
            <p><?php echo t('workflow.help.canvas_order'); ?></p>
            <p><?php echo t('workflow.help.canvas_panel'); ?></p>

            <h3 id="conditions"><?php echo htmlspecialchars(t('workflow.help.conditions_heading')); ?></h3>
            <p><?php echo t('workflow.help.conditions_intro'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.conditions_lookup_heading')); ?></h4>
            <p><?php echo t('workflow.help.conditions_lookup_body'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.conditions_text_heading')); ?></h4>
            <p><?php echo t('workflow.help.conditions_text_body'); ?></p>
            <h4><?php echo htmlspecialchars(t('workflow.help.conditions_num_heading')); ?></h4>
            <p><?php echo t('workflow.help.conditions_num_body'); ?></p>

            <h3 id="actions"><?php echo htmlspecialchars(t('workflow.help.actions_heading')); ?></h3>
            <p><?php echo t('workflow.help.actions_intro'); ?></p>
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
            <p><?php echo t('workflow.help.actions_note'); ?></p>

            <h3 id="variables"><?php echo t('workflow.help.variables_heading'); ?></h3>
            <p><?php echo t('workflow.help.variables_intro'); ?></p>
            <p><?php echo htmlspecialchars(t('workflow.help.variables_common')); ?></p>
            <ul>
                <li><?php echo t('workflow.help.variables_li1'); ?></li>
                <li><?php echo t('workflow.help.variables_li2'); ?></li>
                <li><?php echo t('workflow.help.variables_li3'); ?></li>
                <li><?php echo t('workflow.help.variables_li4'); ?></li>
                <li><?php echo t('workflow.help.variables_li5'); ?></li>
            </ul>
            <div class="tip"><?php echo t('workflow.help.variables_tip'); ?></div>

            <h3 id="ai"><?php echo htmlspecialchars(t('workflow.help.ai_heading')); ?></h3>
            <p><?php echo t('workflow.help.ai_intro'); ?></p>
            <p><?php echo htmlspecialchars(t('workflow.help.ai_examples')); ?></p>
            <ul>
                <li><?php echo t('workflow.help.ai_ex1'); ?></li>
                <li><?php echo t('workflow.help.ai_ex2'); ?></li>
                <li><?php echo t('workflow.help.ai_ex3'); ?></li>
            </ul>
            <p><?php echo t('workflow.help.ai_catalogue'); ?></p>
            <p><?php echo t('workflow.help.ai_config'); ?></p>

            <h3 id="testing"><?php echo htmlspecialchars(t('workflow.help.testing_heading')); ?></h3>
            <p><?php echo t('workflow.help.testing_save'); ?></p>
            <p><?php echo t('workflow.help.testing_fire'); ?></p>
            <p><?php echo t('workflow.help.testing_real'); ?></p>

            <h3 id="triggers"><?php echo htmlspecialchars(t('workflow.help.triggers_heading')); ?></h3>
            <p><?php echo t('workflow.help.triggers_intro'); ?></p>
            <table>
                <tr><th><?php echo htmlspecialchars(t('workflow.help.triggers_th_trigger')); ?></th><th><?php echo htmlspecialchars(t('workflow.help.triggers_th_wired')); ?></th><th><?php echo htmlspecialchars(t('workflow.help.triggers_th_notes')); ?></th></tr>
                <tr><td><code>ticket.created</code></td><td><?php echo htmlspecialchars(t('workflow.help.triggers_yes')); ?></td><td><?php echo t('workflow.help.triggers_row1_notes'); ?></td></tr>
                <tr><td><code>ticket.status_changed</code></td><td><?php echo htmlspecialchars(t('workflow.help.triggers_yes')); ?></td><td><?php echo t('workflow.help.triggers_row2_notes'); ?></td></tr>
                <tr><td><code>ticket.priority_changed</code></td><td><?php echo htmlspecialchars(t('workflow.help.triggers_yes')); ?></td><td><?php echo t('workflow.help.triggers_row3_notes'); ?></td></tr>
                <tr><td><code>ticket.assigned</code></td><td><?php echo htmlspecialchars(t('workflow.help.triggers_yes')); ?></td><td><?php echo t('workflow.help.triggers_row4_notes'); ?></td></tr>
                <tr><td><code>form.submitted</code></td><td><?php echo htmlspecialchars(t('workflow.help.triggers_soon')); ?></td><td><?php echo t('workflow.help.triggers_row5_notes'); ?></td></tr>
                <tr><td><code>task.completed</code></td><td><?php echo htmlspecialchars(t('workflow.help.triggers_soon')); ?></td><td><?php echo t('workflow.help.triggers_row6_notes'); ?></td></tr>
                <tr><td><code>change.approved</code></td><td><?php echo htmlspecialchars(t('workflow.help.triggers_soon')); ?></td><td><?php echo t('workflow.help.triggers_row7_notes'); ?></td></tr>
            </table>

            <h3 id="failures"><?php echo htmlspecialchars(t('workflow.help.failures_heading')); ?></h3>
            <div class="callout"><?php echo t('workflow.help.failures_callout'); ?></div>

            <h3 id="ahead"><?php echo htmlspecialchars(t('workflow.help.ahead_heading')); ?></h3>
            <ul>
                <li><?php echo t('workflow.help.ahead_li1'); ?></li>
                <li><?php echo t('workflow.help.ahead_li2'); ?></li>
                <li><?php echo t('workflow.help.ahead_li3'); ?></li>
                <li><?php echo t('workflow.help.ahead_li4'); ?></li>
                <li><?php echo t('workflow.help.ahead_li5'); ?></li>
                <li><?php echo t('workflow.help.ahead_li6'); ?></li>
                <li><?php echo t('workflow.help.ahead_li7'); ?></li>
                <li><?php echo t('workflow.help.ahead_li8'); ?></li>
            </ul>
            </div>
        </main>
    </div>

    <script>
    // Scroll-spy: highlight the active sidebar link as the user scrolls.
    (function () {
        const helpMain = document.querySelector('.wfh-main');
        const navLinks = document.querySelectorAll('.wfh-nav-link');
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
</body>
</html>
