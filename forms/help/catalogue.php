<?php
/** Forms help - a catalogue item's card: picture, short description and price, Your requests and cancelling (GH #348, PR #349). */
$helpTopic = 'catalogue';
require __DIR__ . '/_top.php';
?>

<?php helpSection('card', 'What the card shows',
    'The form editor\'s <strong>Catalogue</strong> tab sets how an item looks in the portal catalogue.'); ?>
    <p>Open a form and choose the <strong>Catalogue</strong> tab, beside Fields, Preview and What happens next. It sets three things - a picture, a short description and a price - and draws the card beside them as the portal will show it. They are saved by the form's own <strong>Save</strong>.</p>
    <p>In the portal the card shows the picture on top, then the title, the short description and the price. The item's own page shows the price under its title. The forms list shows the price as a pill on the form's row.</p>
    <div class="help-note">
        <strong>Leave the tab empty and nothing changes.</strong> An item with no picture, short description or price shows the title and description, as it always did.
    </div>
    <p>The card is only seen once the form is in the catalogue (the globe on the forms list). If the tab says Database Verification is needed, run <strong>System &rarr; Database Verification</strong> first.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('picture', 'The picture',
    'One picture, shown across the top of the card.'); ?>
    <p>Drag a picture onto the zone, paste one, or click to choose a file. Wide pictures work best (16:9). PNG, JPG, GIF or WebP, up to 4 MB - the same rules as a picture placed on a form. A new form must be saved once before it can have a picture.</p>
    <p>The picture is shown only to the people who can see the item in the catalogue, and to staff with access to Forms. Editing the form keeps it.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('price', 'The price',
    'Optional, one-off or a month.'); ?>
    <p>Leave the price empty for no price; <strong>0</strong> shows as <em>Free</em>. Choose <strong>One-off</strong> or <strong>A month</strong>. The price is in the install's currency (System &rarr; Currencies) unless you change it with <strong>Change</strong>, which lists your favourite currencies first, then every other.</p>
    <p><strong>Each request keeps the price it was made at.</strong> Changing the price later does not change requests already made - the approver, the requester and the ticket an approval raises all see the price that was asked for. A member of staff filling the form in on the Forms page is not buying anything, so their submission keeps no price.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('questions', 'An item with no questions',
    'Some things are just requested - a mouse, a licence.'); ?>
    <p>A form normally needs at least one question before it can be saved. A catalogue item - one with a price, a picture or a short description - may have none: requesting it is the whole request. The portal shows no <em>Save draft</em> button for it, and its ticket has no empty table of answers.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('requests', 'Your requests, and cancelling',
    'What the requester sees on their portal dashboard.'); ?>
    <p><strong>Your requests</strong> lists every catalogue request the person has made, not only those waiting for approval: <em>Awaiting approval</em>, <em>Approved</em> (with a link to its ticket), <em>Declined</em>, <em>Sent</em> (no approval needed) and <em>Cancelled</em>. Forms filled in on a ticket or in a task are not listed.</p>
    <p><strong>A request waiting for approval can be cancelled</strong> by the person who made it - asked for by mistake, or no longer needed. They must give a reason, and the approver sees it. The request is kept, marked <em>Cancelled by the requester</em>, never deleted; it leaves the approvals inbox and can no longer be approved or rejected.</p>
    <p>A request that has been decided, or was sent with no approval, has no Cancel - it is already with the service desk, so the ticket is the place to say so.</p>
    <div class="help-note">
        <strong>Telling the approver.</strong> A cancel fires the workflow trigger <code>catalogue_request.cancelled</code>, with the reason as <code>{{request.reason}}</code> and who was to approve it. Build a workflow on it to email them.
    </div>
<?php helpSectionEnd(); ?>

<?php require __DIR__ . '/_bottom.php'; ?>
