<?php
/**
 * System Help - Custom fields on companies and people (GH #279).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'custom-fields';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What custom fields are for</h3>
            <p>The details your organisation keeps about a company or a person that FreeITSM has no field for.</p>
        </div>
    </div>
    <p><strong>System &rarr; Custom fields</strong> has a tab for <strong>Companies</strong> and one for <strong>People</strong>. <strong>Add</strong> makes a field; the arrows put them in the order they are shown; <strong>Delete</strong> takes a field away, keeping its answers out of sight rather than throwing them away.</p>
    <p>Answers are filled in on the <strong>company</strong> (System &rarr; Companies) and on the <strong>person</strong> (the person's details in Tickets &rarr; Users or Assets &rarr; People). A field ticked <strong>Required</strong> must be filled in when the company or person is added, and cannot be cleared afterwards. Every change is recorded in the company's or the person's history.</p>
</div>

<!-- 2. Kinds -->
<div class="help-section" id="kinds">
    <div class="help-section-header"><?php echo helpSectionNum('kinds'); ?>
        <div>
            <h3>Kinds of field</h3>
        </div>
    </div>
    <ul>
        <li><strong>Text</strong> - one line, or several.</li>
        <li><strong>Number</strong> - with a number of decimal places and a unit if you like (<em>hours</em>, <em>users</em>).</li>
        <li><strong>Date</strong> - a date, a date and time, or a time.</li>
        <li><strong>Yes / no</strong> - with <em>Not set</em> as well, so an unanswered field is never read as No.</li>
        <li><strong>Dropdown</strong> - one of the choices you list.</li>
        <li><strong>Web address</strong> and <strong>email address</strong> - checked that they are one.</li>
    </ul>
    <div class="help-note"><strong>A field's kind is fixed once it has answers.</strong> Its name, help text, choices and whether it is required can still change. To change the kind, delete it and add a new one.</div>
</div>

<!-- 3. People and companies -->
<div class="help-section" id="people">
    <div class="help-section-header"><?php echo helpSectionNum('people'); ?>
        <div>
            <h3>People and companies</h3>
        </div>
    </div>
    <p><strong>Company fields</strong> are one list for every company. <strong>People fields</strong> can be for <strong>every company</strong> or <strong>one company's own</strong> - so one client can have a <em>Badge number</em> the others never see. Which company a people field belongs to is chosen when it is made.</p>
    <p>A person who <strong>moves to another company</strong> stops showing the old company's own fields; their answers are kept, and come back if they move back. A directory - Active Directory, Entra, an address book - never changes custom fields.</p>
</div>

<!-- 4. API -->
<div class="help-section" id="api">
    <div class="help-section-header"><?php echo helpSectionNum('api'); ?>
        <div>
            <h3>The REST API</h3>
        </div>
    </div>
    <p><code>/companies</code> and <code>/users</code> return <code>custom_fields</code> - each field's key (shown under its name on this page) with its answer, or <code>null</code> - and accept the same object when creating, changing or upserting, so an ERP or CRM can keep them in step. The keys never change, even when a field is renamed.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
