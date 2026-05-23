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
    <link rel="stylesheet" href="../assets/css/workflow.css?v=1">
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
            <p>This is the engine foundation. The available action in v1 is <code>log_message</code>, which writes to the workflow's execution log — useful as a placeholder while you scaffold a rule, and as a way to verify the engine is firing as expected via the <strong>Test fire</strong> button in the editor.</p>
            <p>Trigger wiring from host modules (Tickets, Forms, Tasks, Changes&hellip;) is being added in subsequent commits. The trigger dropdown lists every catalogued event, but only a subset will actually fire today — synthetic <strong>Test fire</strong> lets you verify the engine without waiting on a host module.</p>

            <h3>Coming next</h3>
            <ul>
                <li><strong>Visual canvas builder</strong> — drag triggers, conditions and actions on a snap-to-grid canvas, draw arrows between them. Reuses the Process Mapper / Network Mapper design language.</li>
                <li><strong>AI co-author</strong> — describe the workflow you want in plain English, watch the canvas scaffold itself; iterate via chat.</li>
                <li><strong>Real action handlers</strong> — set ticket status / priority, send email, create task, add user to AAD group (via the existing OAuth scaffolding from the mailbox integration), Teams message&hellip;</li>
                <li><strong>Dry-run mode</strong> — run a workflow against a real event but log the actions instead of executing them, so you can see what <em>would</em> have happened.</li>
                <li><strong>Watchtower integration</strong> — failed runs surface as attention cards.</li>
                <li><strong>Starter recipes</strong> — clonable templates for the common patterns: new-starter onboarding, P1 incident response, SLA breach escalation, license-renewal reminder.</li>
            </ul>
        </div>
    </div>
</body>
</html>
