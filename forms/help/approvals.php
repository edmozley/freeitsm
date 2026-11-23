<?php
/** Forms help — approving catalogue requests: a person, a team (GH #220), steps and chains (GH #160). */
$helpTopic = 'approvals';
require __DIR__ . '/_top.php';
?>

<?php helpSection('setting', 'Asking for approval',
    'A catalogue form can wait for a yes before it becomes a ticket.'); ?>
    <p>On the Forms list, the shield on a form's row opens <strong>Approval settings</strong>. Tick <strong>Require approval before a ticket is raised</strong>, then set up one or more <strong>steps</strong>. Each step says <strong>Who decides</strong>: <em>A person</em> or <em>A team</em>. A form can instead follow a named <strong>approval chain</strong> (see below).</p>
    <p>Approval only applies to requests made through the self-service portal. A member of staff filling the form in on the Forms page is never held up.</p>
    <div class="help-note">
        <strong>Nothing changes for a form you already have.</strong> It opens as one step, set up exactly as it was, and saving it unchanged keeps it that way. Teams, more steps and chains are extra choices, not replacements.
    </div>
<?php helpSectionEnd(); ?>

<?php helpSection('person', 'A person decides',
    'One named member of staff approves or rejects, as before.'); ?>
    <p>Their first click decides the request. An administrator can also decide it.</p>
    <p><strong>The approver cannot approve a request they made themselves</strong> is an optional tick box, off unless you turn it on. FreeITSM matches on email address, so it applies when a member of staff requests through the portal with the same address they log in with. Their own requests then wait until an administrator moves them to someone else.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('team', 'A team decides',
    'Several different members of a team must agree.'); ?>
    <p>Choose a team and set two numbers:</p>
    <ul>
        <li><strong>Approvals needed</strong> - how many different members must approve, for example 2.</li>
        <li><strong>Rejections needed to decline</strong> - how many must reject before the request is declined. It starts at 1, so one rejection stops it. Raise it if one person should not be able to block a request on their own.</li>
    </ul>
    <p>Each member votes once. Whichever number is reached first decides the request, and the ticket is raised once, however many people are clicking at the same moment. A rejection needs a reason.</p>
    <p>The person who made the request can never vote on it, even if they are in the team. If the window warns that the team is smaller than the approvals needed, its requests would get stuck - choose a bigger team or a smaller number.</p>
    <div class="help-note">
        <strong>Who is in the team is checked at every vote.</strong> Somebody who leaves the team can no longer vote on requests already waiting, and somebody who joins can. Votes already cast still count.
    </div>
<?php helpSectionEnd(); ?>

<?php helpSection('steps', 'More than one step',
    'For example: the IT manager, then finance, then the board when the total is over 5,000.'); ?>
    <p><strong>Add</strong> puts another step under the last one; the arrows change the order and the cross removes a step. Give each step a name if it helps - the approver sees it, and so does the requester ("waiting for Finance, step 2 of 3").</p>
    <ul>
        <li><strong>Steps run one at a time, in order.</strong> A step's approvers are told on their bell when the request reaches them, not before.</li>
        <li><strong>Any rejection ends the request.</strong> Later steps never run, and the form's <em>When rejected</em> actions run as usual.</li>
        <li><strong>When the last step passes</strong>, the request is approved and everything that happens today happens then, once: the ticket, the <em>When approved</em> actions, the workflow event.</li>
        <li><strong>The steps are fixed when the request is made.</strong> Changing them affects new requests only.</li>
    </ul>
    <p><strong>Only when</strong> makes a step conditional: choose a question, how to compare and a value, for example <em>Order total is more than 5000</em>. It is decided when the request is made. A step that does not apply is skipped, and the record says why ("Skipped Step 3 of 3: Board: Order total was 3000"). If no step applies at all, the request is approved straight away.</p>
    <div class="help-note">
        <strong>An answer that cannot be read means the step runs.</strong> If the total is blank, or not a number, a "more than 5000" step is not skipped. A broken amount must never let a request go round the board.
    </div>
    <p>Workflows see each step too: <strong>A catalogue request passes an approval step</strong> fires every time a step is approved and the request moves on, with the step it is now on.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('nobody', 'If nobody can decide',
    'Where a step goes when its approver has left, made the request, or the team is too small.'); ?>
    <p>Each step has <strong>If nobody can decide</strong>. Leave it on <em>Use the default</em>, or choose <em>Send it to</em> a person or a team for that step.</p>
    <p>The default is set under <strong>Forms -> Settings -> Approvals</strong>. Left on <em>Nobody</em>, a step that finds nobody makes the request <strong>Stuck</strong> for an administrator to move or override, exactly as before. Either way, a step is never skipped because nobody could decide it, and the record says when a fallback was used.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('chains', 'Approval chains',
    'The same steps on many forms, set up once.'); ?>
    <p>Under <strong>Forms -> Settings -> Approvals</strong>, <strong>New</strong> makes a named chain - for example <em>Hardware purchase</em> - with steps exactly like a form's. In a form's Approval settings choose <strong>An approval chain</strong> and pick it. The form's own steps are kept, so switching back restores them.</p>
    <p>A chain's <em>Only when</em> names its question by label, so it works on every form with a question of that name. A form without that question runs the step - the same safe rule as a blank answer.</p>
    <p>Changing a chain affects new requests only. A chain cannot be deleted while a form uses it; the list shows which forms do.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('inbox', 'The approvals inbox',
    'Forms → Approvals lists what is waiting on you.'); ?>
    <p>A request with steps shows which step it is on ("Step 2 of 3: Finance") and each step - passed, waiting or skipped - and its record lists who approved each step. A team request shows the team, how far it has got ("1 of 2 approvals") and every vote so far, with names, times and notes. Once you have voted it leaves <em>For me</em> and appears under <em>Decided by me</em>; it stays under <em>All pending</em> until it is decided.</p>
    <p>Team members do not need access to the Forms module to vote - being in a team that approves a form is enough to open the inbox.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('edit', 'Changing the answers before you decide',
    'Correct or complete a request while it waits - every change is recorded.'); ?>
    <p>The requester does not always know everything: the username the new starter will get, the right site, a start date that has moved. <strong>Edit</strong> on a waiting request opens its answers, so you can put them right before you approve instead of approving something wrong or rejecting it to be asked again.</p>
    <p>Every question is listed, including ones the requester left empty. Change what you need, add a reason if it helps, and <strong>Save</strong>. The answers are checked by the same rules as when the request was made - a choice must be on the list, a number must be a number, a required answer cannot be emptied. Lookups and tables cannot be changed here.</p>
    <p><strong>Nothing is changed quietly.</strong> Each change shows on the request's card - who changed which answer, from what, to what, when and why. The requester sees what changed on their portal under <em>Your requests</em> (without your reason, which may be an internal note), and the ticket raised on approval uses the corrected answers and lists the changes.</p>
    <p>The people who can decide a request can edit it: its approver, a member of its approving team, or an administrator. The person who made the request never can, and nobody can once it has been decided.</p>
<?php helpSectionEnd(); ?>
<?php helpSection('admins', 'When an administrator steps in',
    'Override and Move, both with a reason, both written on the request.'); ?>
    <p><strong>An administrator's click is not a vote.</strong> On a team request, an administrator who is not voting sees a note saying their Approve or Reject <em>overrides</em> the team. The note box becomes the reason, and it must be filled in.</p>
    <p>On a request with steps, an override or a move acts on <strong>the step it is on</strong>. Overriding step 1 moves the request to step 2; it never waves the rest through.</p>
    <p><strong>Move</strong> sends a waiting request to another team or a named person, again with a reason. The votes cast so far are kept on the record but no longer count, so the new approvers start from nothing.</p>
    <p>A request that cannot be decided any more - the team has emptied, or too few members are left to reach either number - is marked <strong>Stuck</strong>. It is never waved through on its own; an administrator moves or overrides it.</p>
    <p>Every override and move shows on the request's card with who did it, when and why.</p>
<?php helpSectionEnd(); ?>

<?php require __DIR__ . '/_bottom.php'; ?>
