<?php
/** Forms help - recipes: whole jobs, start to finish (GH #420). Add one by adding a section to the registry and a helpSection() here. */
$helpTopic = 'recipes';
require __DIR__ . '/_top.php';
?>

<?php helpSection('requester', 'Who is a raised ticket for?',
    'Every ticket has a requester - the person it is for, and the person replies go to. When a form raises a ticket, FreeITSM works out who that is in this order.'); ?>
    <div class="help-table">
        <table>
        <thead><tr><th>Where it looks</th><th>When it applies</th></tr></thead>
        <tbody>
            <tr><td><strong>Requester is the person picked in</strong> a question</td><td>When you chose one of the form's <em>pick a person</em> questions on <em>Raise a ticket</em>. This always wins.</td></tr>
            <tr><td>The person who sent the form</td><td>When they were signed in to the self-service portal.</td></tr>
            <tr><td><strong>Requester email</strong> on <em>Raise a ticket</em></td><td>When neither of the above applies - for example an email typed into the step, or an answer such as <code>{{submission.email}}</code>.</td></tr>
        </tbody>
        </table>
    </div>
    <p><strong>A member of staff filling a form in is never the requester.</strong> If you fill in a form for someone, you are not the one with the problem. So when staff use a form, the ticket's requester has to come from a question or an email - otherwise there is nobody to raise it for, and the ticket is not raised.</p>
    <div class="help-note">
        <strong>The editor warns you.</strong> A <em>Raise a ticket</em> step with no way of knowing who it is for shows a yellow note: <em>Who is this ticket for?</em> Choose a question or give an email and it goes away.
    </div>
<?php helpSectionEnd(); ?>

<?php helpSection('self', 'Raise a ticket for whoever sends the form',
    'The everyday case: someone asks for something, and the ticket is theirs.'); ?>
    <ol>
        <li>Open the form and choose the <strong>What happens next</strong> tab.</li>
        <li>Under <strong>When submitted</strong>, add <strong>Raise a ticket</strong> and give it a subject. Answers can go in it, for example <code>New laptop for {{submission.fields.Start date}}</code>.</li>
        <li>If only customers use it, through the portal, that is all: they are the requester.</li>
        <li>If staff may fill it in too, add an <strong>Email</strong> question (<em>Your email address</em>) and set <strong>Requester email</strong> to <code>{{submission.email}}</code> - the first email answer on the form.</li>
    </ol>
    <p>The ticket's description is the answers, laid out as a table, unless you write your own.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('behalf', 'Raise a ticket on behalf of someone else',
    'A manager reporting a problem for their team, or the service desk logging a call for a caller.'); ?>
    <ol>
        <li>Add a <strong>Lookup</strong> question over <strong>People</strong>, labelled something like <em>Who is this for?</em>, and make it required.</li>
        <li>On the <strong>What happens next</strong> tab, add <strong>Raise a ticket</strong>.</li>
        <li>In <strong>Requester is the person picked in</strong>, choose <em>Who is this for?</em></li>
    </ol>
    <p>The ticket is raised for the person picked, whoever filled the form in, and replies go to them. A person question you do not choose there - <em>Please select your favourite colleague</em> - is just an answer, shown on the ticket like any other.</p>
    <div class="help-note">
        <strong>The picked person's details are there too.</strong> <code>{{submission.fields.Who is this for}}</code> is their name; <code>{{submission.lookups.Who is this for.email}}</code> is their email, and <code>.department</code>, <code>.job_title</code>, <code>.employee_id</code> and the rest work the same way. Use them in a subject, an email or a webhook.
    </div>
    <p>People lookups are not offered on the self-service portal - they show a staff directory - so this recipe is for forms staff fill in.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('approval', 'Ask for approval, then raise the ticket',
    'A request that needs a yes - a purchase, an access request - before anyone starts on it.'); ?>
    <ol>
        <li>Put the form in the request catalogue, so customers can ask for it on the portal.</li>
        <li>On the Forms list, the shield on its row opens <strong>Approval settings</strong>. Turn on approval and choose a person or a team (see <a href="approvals.php">Approving catalogue requests</a>).</li>
        <li>On the <strong>What happens next</strong> tab, leave <strong>When approved</strong> empty to raise a ticket in the usual way, or add your own steps - a ticket for IT and another for Facilities, say.</li>
        <li>Put anything for a refusal - an email to the requester - under <strong>When rejected</strong>.</li>
    </ol>
    <div class="help-note">
        <strong>Do not raise the ticket under <em>When submitted</em> on a form that needs approval.</strong> Those steps run before the approver has seen it, so the work would start without their yes. The editor warns you if you do.
    </div>
    <p>Approval only applies to requests made on the portal, so the requester is always the customer who asked. A member of staff filling the form in is never held up.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('elsewhere', 'Send the answers somewhere else',
    'Pass a form on to another system - an onboarding tool, a spreadsheet, a chat channel.'); ?>
    <ol>
        <li>In <strong>Workflows</strong>, make a workflow on <strong>A form submission is received</strong>, with a condition on the form so it only runs for this one.</li>
        <li>Add <strong>Send a webhook</strong> (or <em>Send an email</em>) and build the message from merge codes.</li>
    </ol>
    <div class="help-table">
        <table>
        <thead><tr><th>Merge code</th><th>Gives you</th></tr></thead>
        <tbody>
            <tr><td><code>{{submission.fields.Start date}}</code></td><td>One answer, by the question's label. A lookup gives the name; checkboxes give the ticked choices, comma-separated.</td></tr>
            <tr><td><code>{{submission.lookups.Who is this for.email}}</code></td><td>A detail of the record picked in a lookup - <code>.id</code>, <code>.email</code>, <code>.ticket_code</code>, <code>.hostname</code>, depending on what it looks up.</td></tr>
            <tr><td><code>{{submission.email}}</code></td><td>The first answer to an Email question.</td></tr>
            <tr><td><code>{{submission.id}}</code>, <code>{{form.name}}</code></td><td>The submission's number and the form's name.</td></tr>
        </tbody>
        </table>
    </div>
    <p><strong>Edit with variables</strong>, beside a step's text, lists every code a trigger can fill in. A code that does not exist comes out blank rather than failing, so check a test message before relying on it.</p>
<?php helpSectionEnd(); ?>

<?php require __DIR__ . '/_bottom.php'; ?>
