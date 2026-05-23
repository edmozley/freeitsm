<?php
/**
 * Workflows — Help guide (placeholder).
 *
 * Full guide will land when the visual canvas builder and AI co-author
 * are shipped. For now this is a short orientation.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) { header('Location: ../login.php'); exit; }

$current_page = 'help';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=3">
    <style>
        body { overflow: auto; height: auto; }
        .container { max-width: 880px; }
        .wf-help h2 { font-size: 22px; color: #333; margin: 0 0 4px; }
        .wf-help p { color: #555; line-height: 1.6; }
        .wf-help .lede { font-size: 15px; color: #444; }
        .wf-help h3 { margin: 28px 0 8px; font-size: 16px; color: #333; }
        .wf-help ul { color: #555; line-height: 1.7; padding-left: 22px; }
        .wf-help code { background: #f4f4f4; padding: 1px 6px; border-radius: 3px; font-size: 12.5px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="tab-content active wf-help">
            <h2><?php echo htmlspecialchars(t('workflow.help.page_title')); ?></h2>
            <p class="lede"><?php echo htmlspecialchars(t('workflow.help.intro')); ?></p>

            <h3>How a workflow is shaped</h3>
            <p>A workflow has three parts:</p>
            <ul>
                <li><strong>Trigger</strong> — the event that fires the workflow. For example, <code>ticket.created</code> fires whenever a new ticket lands in the system.</li>
                <li><strong>Conditions</strong> (optional) — filter that decides whether to run. <em>"Only when priority equals P1 and department equals Finance."</em> All conditions must match (AND).</li>
                <li><strong>Actions</strong> — one or more things to do when the trigger fires and the conditions match. Actions run in order.</li>
            </ul>

            <h3>What's in this release</h3>
            <ul>
                <li><strong>Visual canvas editor</strong> — drag trigger / condition / action nodes on a snap-to-grid canvas; arrows are auto-routed in execution order. Position IS the order — drag a condition above another to reorder.</li>
                <li><strong>AI co-author</strong> — click the <em>AI co-author</em> button on the toolbar, describe what you want in plain English, and Claude scaffolds the workflow on the canvas. You can iterate ("make it only match Finance" / "add an action to log the ticket id too") and the AI edits what's already there. Requires an Anthropic API key configured under <strong>CMDB &rarr; Settings &rarr; AI Integration</strong> (the workflow co-author reuses that key for now).</li>
                <li><strong>Test fire</strong> — synthetic-payload run that exercises the engine end-to-end so you can verify a rule before host modules are wired up.</li>
            </ul>
            <p>The single available action handler is <code>log_message</code>, which writes a message to the workflow's execution log — useful as a placeholder while you scaffold rules, and what the AI co-author leans on as a stand-in for unimplemented actions like "send email". Trigger wiring from host modules (Tickets, Forms, Tasks, Changes&hellip;) is being added in subsequent commits — the trigger dropdown lists every catalogued event, but only a subset will actually fire today.</p>

            <h3>Coming next</h3>
            <ul>
                <li><strong>Real action handlers</strong> — set ticket status / priority, send email, create task, add user to AAD group (via the existing OAuth scaffolding from the mailbox integration), Teams message&hellip;</li>
                <li><strong>Wire real triggers</strong> — every host module's save flow gets a one-line `dispatch()` call so the catalogued events actually fire.</li>
                <li><strong>Streaming AI co-author</strong> — claude.ai-style live output as the canvas builds, instead of the current one-shot proposal.</li>
                <li><strong>Dry-run mode</strong> — run a workflow against a real event but log the actions instead of executing them, so you can see what <em>would</em> have happened.</li>
                <li><strong>Watchtower integration</strong> — failed runs surface as attention cards.</li>
                <li><strong>Starter recipes</strong> — clonable templates for the common patterns: new-starter onboarding, P1 incident response, SLA breach escalation, license-renewal reminder.</li>
            </ul>
        </div>
    </div>
</body>
</html>
