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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=4">
    <style>
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; padding-bottom: 60px; }
        .wf-help h2 { font-size: 22px; color: #333; margin: 0 0 4px; }
        .wf-help p { color: #555; line-height: 1.6; }
        .wf-help .lede { font-size: 15px; color: #444; }
        .wf-help h3 { margin: 32px 0 10px; font-size: 16px; color: #333; padding-bottom: 6px; border-bottom: 1px solid #eee; }
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

    <div class="container">
        <div class="tab-content active wf-help">
            <h2><?php echo htmlspecialchars(t('workflow.help.page_title')); ?></h2>
            <p class="lede">Workflows let you automate the things you find yourself doing manually after a ticket arrives: tagging, escalating, assigning, notifying, fanning out to other systems. A workflow listens for an event, optionally filters with conditions, then runs one or more actions in order.</p>

            <h3>1. Anatomy of a workflow</h3>
            <p>Every workflow has three parts:</p>
            <ul>
                <li><strong>Trigger</strong> &mdash; the event that fires the workflow. Examples: <code>ticket.created</code>, <code>ticket.priority_changed</code>, <code>ticket.assigned</code>. Exactly one trigger per workflow.</li>
                <li><strong>Conditions</strong> <em>(optional)</em> &mdash; filters that decide whether the workflow runs. All conditions must match (AND semantics). <em>"Only when priority is Critical AND department is Finance."</em></li>
                <li><strong>Actions</strong> <em>(one or more)</em> &mdash; the things to do when the trigger fires and the conditions pass. Run in order, top to bottom.</li>
            </ul>
            <p>Execution is synchronous &mdash; the workflow runs in the same web request that fired the event. Each run writes a row to the execution log with the full trigger payload, every condition's pass/fail, and every action's result.</p>

            <h3>2. The visual canvas</h3>
            <p>The editor is a dot-grid canvas with three node shapes:</p>
            <ul>
                <li><strong>Trigger node</strong> &mdash; amber pill, pinned at the top centre. One per workflow; can't be deleted.</li>
                <li><strong>Condition nodes</strong> &mdash; orange diamonds. Add one with <em>Add condition</em>. Drag to reorder.</li>
                <li><strong>Action nodes</strong> &mdash; blue rounded rectangles. Add one with <em>Add action</em>. Drag to reorder.</li>
            </ul>
            <p>Connectors are drawn automatically in execution order: trigger &rarr; conditions (top to bottom by y position) &rarr; actions (top to bottom). <strong>Position IS the order</strong> &mdash; drag a condition above another to swap them.</p>
            <p>Click any node to open the detail panel on the right. Click empty canvas to switch the panel back to the workflow-level fields (name / description / active flag / recent runs). Right-click or press Delete to remove the selected node (except the trigger).</p>

            <h3>3. Conditions</h3>
            <p>The condition panel has three controls: <strong>Field</strong>, <strong>Operator</strong>, <strong>Value</strong>. Their behaviour adapts to the chosen field type:</p>
            <h4>Lookup fields (priority, status, department, type, analyst&hellip;)</h4>
            <p>The value control is a <strong>checkbox list of real values</strong> pulled from the lookup table &mdash; not opaque ids. Tick one for an exact match, tick several for OR semantics (e.g. <em>"priority is Critical OR High"</em>). The operator dropdown collapses to <strong>is / is not / is empty / is not empty</strong> &mdash; the multi-select handles the rest.</p>
            <h4>Text fields (subject, requester email, title&hellip;)</h4>
            <p>Plain text input with the full text-operator catalogue: <strong>equals / not equals / is one of / is not one of / contains / does not contain / is empty / is not empty</strong>. <code>gt</code> / <code>lt</code> are <em>not</em> offered &mdash; lexicographic string comparison is a footgun.</p>
            <h4>Numeric fields (ids, durations&hellip;)</h4>
            <p>Numeric operators: <strong>equals / not equals / is one of / is not one of / greater than / less than / is empty / is not empty</strong>. No <em>contains</em> &mdash; substring search on a number is meaningless.</p>

            <h3>4. Actions</h3>
            <p>Eight handlers ship today. Pick an action's type from the dropdown and a labelled form appears below with the right widget per arg (text input, textarea, number input, lookup dropdown):</p>
            <table>
                <tr><th>Type</th><th>What it does</th><th>Key args</th></tr>
                <tr><td><code>log_message</code></td><td>Writes a message into this workflow's execution log. Useful as a placeholder while you scaffold a rule and as a way to verify the engine end-to-end.</td><td>message</td></tr>
                <tr><td><code>set_ticket_status</code></td><td>Changes a ticket's status. Automatically stamps / clears <code>closed_datetime</code> when the new status's <em>is_closed</em> flag flips.</td><td>ticket_id, status_id</td></tr>
                <tr><td><code>set_ticket_priority</code></td><td>Sets a ticket's priority.</td><td>ticket_id, priority_id</td></tr>
                <tr><td><code>assign_ticket</code></td><td>Sets a ticket's owner / assignee. Updates both <code>assigned_analyst_id</code> and <code>owner_id</code> so the right-pane Owner field stays in sync.</td><td>ticket_id, analyst_id</td></tr>
                <tr><td><code>add_ticket_note</code></td><td>Appends a free-text note to the ticket's audit trail. Internal &mdash; never sent to the requester.</td><td>ticket_id, note</td></tr>
                <tr><td><code>send_email</code></td><td>Sends an email to the ticket's requester (or any address) using the ticket's mailbox. Subject is prefixed with <code>[SDREF:&hellip;]</code> so replies thread back automatically.</td><td>ticket_id, to, subject, body</td></tr>
                <tr><td><code>create_task</code></td><td>Spawns a task. Auto-links to the source ticket if a <code>ticket_id</code> is supplied. Status / priority default to the workspace defaults if blank.</td><td>title, description, status_id, priority_id, assignee_id, ticket_id</td></tr>
                <tr><td><code>create_ticket</code></td><td>Creates a new ticket. Useful for fan-out workflows like <em>"new-starter form &rarr; IT + HR + Facilities tickets"</em>.</td><td>subject, body, priority_id, department_id, type_id, assigned_analyst_id, from_email, from_name</td></tr>
            </table>
            <p>Each action's required args are marked with a <code>*</code> in the form. Missing required args cause the action to fail at execution time &mdash; the engine logs the failure to the execution row and stops the rest of the chain.</p>

            <h3>5. Variables &mdash; <code>{{path.to.field}}</code></h3>
            <p>Any free-text action arg supports variable substitution against the trigger's payload. The hint <em>"Supports variables like {{ticket.id}}"</em> appears under each variable-friendly field.</p>
            <p>Common variables for ticket triggers:</p>
            <ul>
                <li><code>{{ticket.id}}</code>, <code>{{ticket.subject}}</code>, <code>{{ticket.priority_id}}</code>, <code>{{ticket.status_id}}</code></li>
                <li><code>{{ticket.department_id}}</code>, <code>{{ticket.type_id}}</code>, <code>{{ticket.assigned_analyst_id}}</code></li>
                <li><code>{{ticket.owner_id}}</code>, <code>{{ticket.created_by}}</code>, <code>{{ticket.requester_email}}</code></li>
                <li>For <code>ticket.status_changed</code> / <code>ticket.priority_changed</code>: also <code>{{old_status_id}}</code>, <code>{{new_status_id}}</code>, <code>{{old_priority_id}}</code>, <code>{{new_priority_id}}</code></li>
                <li>For <code>ticket.assigned</code>: also <code>{{analyst_id}}</code>, <code>{{team_id}}</code></li>
            </ul>
            <div class="tip"><strong>Best practice:</strong> in action <code>ticket_id</code> fields, leave the default <code>{{ticket.id}}</code> rather than typing a specific id &mdash; that way the workflow operates on whichever ticket triggered it, not a fixed one.</div>

            <h3>6. AI co-author</h3>
            <p>Click <strong>AI co-author</strong> on the toolbar and describe the workflow in plain English. The AI returns a structured proposal &mdash; trigger, conditions, actions &mdash; that you can <em>Apply</em> to the canvas or <em>Discard</em>.</p>
            <p>Examples that work well:</p>
            <ul>
                <li><em>"When a Critical-priority ticket is created, add a note saying 'P1 &mdash; please respond within 15 minutes'."</em></li>
                <li><em>"When a ticket from <code>finance@acme.com</code> is created, assign it to me and set priority to High."</em></li>
                <li><em>"When a ticket is closed, send an email to the requester thanking them and asking for feedback."</em></li>
            </ul>
            <p>The AI knows the engine's full catalogue &mdash; every trigger, condition operator, action, and the available lookup-table values &mdash; so it can only propose things that will actually work. If you iterate on an existing workflow (<em>"now only match Finance"</em> / <em>"add an action to log the ticket id"</em>) it edits what's on the canvas rather than starting over.</p>
            <p>Configure the provider (Anthropic / OpenAI), model and API key under <strong>Workflow &rarr; Settings &rarr; AI</strong>. Keys are stored per module so billing and access can be granular.</p>

            <h3>7. Saving and testing</h3>
            <p>The status pip in the toolbar shows <em>Unsaved</em> / <em>Saving&hellip;</em> / <em>Saved</em>. Click <strong>Save</strong> to persist. You can save in-progress drafts with zero actions &mdash; you'll get a soft "this workflow has no actions yet" warning rather than being blocked.</p>
            <p><strong>Test fire</strong> runs the workflow with a synthetic payload generated from its own conditions, so the action path actually executes. The result lands in <em>Recent runs</em> on the workflow detail panel with status (<em>success / failed / skipped</em>) and full step log.</p>
            <p>To test against real data, just do the thing that triggers it &mdash; assign a ticket, change its priority, etc. The dispatch from the host module (Tickets) is live; the execution log shows every fire.</p>

            <h3>8. Wired triggers today</h3>
            <p>The trigger catalogue lists seven events. Wiring from host modules is being rolled out incrementally:</p>
            <table>
                <tr><th>Trigger</th><th>Wired?</th><th>Notes</th></tr>
                <tr><td><code>ticket.created</code></td><td>Yes</td><td>Fires from manual ticket creation; mailbox-pulled tickets land via a separate path that doesn't dispatch yet.</td></tr>
                <tr><td><code>ticket.status_changed</code></td><td>Yes</td><td>Fires whenever the status changes via any path that calls <code>assign_ticket.php</code>.</td></tr>
                <tr><td><code>ticket.priority_changed</code></td><td>Yes</td><td>Same dispatch path as status.</td></tr>
                <tr><td><code>ticket.assigned</code></td><td>Yes</td><td>Fires when the assignee changes (drag-to-folder, right-click menu, dropdown).</td></tr>
                <tr><td><code>form.submitted</code></td><td>Soon</td><td>Forms module dispatch wiring is next.</td></tr>
                <tr><td><code>task.completed</code></td><td>Soon</td><td>Tasks module dispatch wiring is next.</td></tr>
                <tr><td><code>change.approved</code></td><td>Soon</td><td>Changes module dispatch wiring is next.</td></tr>
            </table>

            <h3>9. Engine failures are isolated</h3>
            <div class="callout"><strong>A buggy workflow cannot break the host module's request.</strong> Every dispatch is wrapped in a try/catch and every action's failure is logged to the execution row without aborting the chain. If a workflow stops doing what you expect, check the workflow's <em>Recent runs</em> panel &mdash; failures show up there with the underlying error, not as a 500 on the page that triggered them.</div>

            <h3>10. What's still ahead</h3>
            <ul>
                <li><strong>Tier 3 actions</strong> &mdash; Microsoft Graph: <code>graph_add_to_group</code>, <code>graph_assign_license</code>, <code>graph_disable_user</code>. The new-starter / leaver automations that justify the module's existence vs paid ITSM suites. Reuses the existing tenant OAuth scaffolding.</li>
                <li><strong>Teams / Slack messages</strong> &mdash; channel pings on important events.</li>
                <li><strong>Wire the remaining triggers</strong> &mdash; <code>form.submitted</code>, <code>task.completed</code>, <code>change.approved</code>.</li>
                <li><strong>Dry-run mode</strong> &mdash; run a workflow against a real event but <em>log</em> the actions rather than execute them. So you can see what would have happened before flipping it live.</li>
                <li><strong>Starter recipes</strong> &mdash; clonable templates for the common patterns: new-starter onboarding, P1 incident response, SLA-breach escalation, license-renewal reminder.</li>
                <li><strong>Watchtower integration</strong> &mdash; failed runs surface as attention cards.</li>
                <li><strong>Workflow versioning</strong> &mdash; draft / publish / rollback.</li>
                <li><strong>Streaming AI co-author</strong> &mdash; claude.ai-style live output as the canvas builds.</li>
            </ul>
        </div>
    </div>
</body>
</html>
